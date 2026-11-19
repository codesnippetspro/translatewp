<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\QueueExecution;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Components\ReadinessBudget;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\TranslationReadiness;

class ReadinessReconcile {

	public const HOOK = 'translatewp_readiness_reconcile';
	public const CHECK_TRANSIENT = 'translatewp_readiness_reconcile_check';
	public const CURSOR_OPTION = 'translatewp_readiness_reconcile_cursor';
	public const VISITOR_CURSOR_OPTION = 'translatewp_visitor_readiness_cursor';
	public const STEP_CURSOR_OPTION = 'translatewp_step_readiness_cursor';
	public const MAX_COLD_ATTEMPTS = 3;
	public const MAX_DEAD_ATTEMPTS = 3;

	private const GRACE_SECONDS = 120;
	private const MAX_JOB_AGE_SECONDS = 900;
	private const DEAD_BACKOFF_SECONDS = 600;

	private int $rows_seen = 0;

	public function step_rows_seen(): int {
		return $this->rows_seen;
	}
	private ?array $step_translated = null;
	private ?array $step_batch = null;
	private Indexer $indexer;
	private $lookup;
	private $clock;

	public function __construct( $plugin_name = '', $plugin_version = '', ?Indexer $indexer = null, ?callable $lookup = null, ?callable $clock = null, bool $register_hooks = true ) {
		unset( $plugin_name, $plugin_version, $register_hooks );
		$this->indexer = $indexer ?? new Indexer( '', '', null, null, false );
		$this->lookup  = $lookup;
		$this->clock   = $clock ?? static fn (): int => time();
	}

	public function maybe_schedule(): void {
		\TranslateWP\Components\BackgroundEngine::install();
	}

	public function run(): void {
		QueueExecution::run(
            function (): void {
                $this->step_run( QueueExecution::budget() );
            }
        );
	}

	public static function step_rows( bool $visitor_only = false ): array {
		$queue = new IndexQueue();
		$option = $visitor_only ? self::VISITOR_CURSOR_OPTION : self::STEP_CURSOR_OPTION;
		$position = (array) get_option( $option, [] );
		$offset = max( 0, (int) ( $position['offset'] ?? 0 ) );
		$rows = $queue->engine_rows( $visitor_only ? 'visitor_ready' : 'background_ready', 50, $offset );
		if ( ! $rows && $offset > 0 ) {
			$offset = 0;
			$rows = $queue->engine_rows( $visitor_only ? 'visitor_ready' : 'background_ready', 50 );
		}
		if ( (int) ( $position['cursor'] ?? 0 ) > 0 ) {
			$resume = $queue->engine_rows( $visitor_only ? 'visitor_ready' : 'background_ready', 1, 0, (int) $position['row'] );
			$rows = array_merge( $resume, array_values( array_filter( $rows, static fn ( array $row ): bool => (int) $row['id'] !== (int) $position['row'] ) ) );
		}
		return array_slice( $rows, 0, 50 );
	}

	public function step_run( ReadinessBudget $budget, bool $visitor_only = false, ?array $selected = null ): void {
		$this->rows_seen = 0;
		if ( ! SiteConnection::is_connected() || $budget->remaining_seconds() <= 0 ) {
			return;
		}
		$option = $visitor_only ? self::VISITOR_CURSOR_OPTION : self::STEP_CURSOR_OPTION;
		$position = (array) get_option( $option, [] );
		$offset = max( 0, (int) ( $position['offset'] ?? 0 ) );
		$rows = $selected ?? self::step_rows( $visitor_only );
		$this->step_batch = $rows;
		$this->rows_seen = 0;
		$this->step_translated = [];
		try {
			$result = $this->reconcile( (int) ( $position['cursor'] ?? 0 ), $budget );
		} finally {
			$this->step_batch = null;
		}
		$translated = $this->step_translated;
		$this->step_translated = null;
		if ( $translated ) {
			$this->indexer->mark_translated( array_values( array_unique( $translated ) ) );
		}
		if ( empty( $result['lookupUnavailable'] ) && ! QueueExecution::expired() ) {
			$this->rows_seen += ( new IndexQueue() )->defer_confirmations( $rows );
		}
		$this->rows_seen += count( $translated ) + $result['requeued'] + $result['failed'] + $result['retried'];
		$next = (int) ( $result['cursor'] ?? 0 );
		if ( $rows ) {
			update_option(
                $option,
                [
					'offset' => 0,
					'row' => (int) $rows[ 0 === $next ? count( $rows ) - 1 : 0 ]['id'],
					'cursor' => $next,
				],
                false
            );
		}
	}

	public static function batches_per_pass( int $batches ): int {
		return max( 1, (int) ceil( $batches / 6 ) );
	}

	public function reconcile( int $cursor = 0, ?ReadinessBudget $budget = null ): array {
		$result = [
			'cursor' => $cursor,
			'language' => '',
			'hashes' => [],
			'rowIds' => [],
			'legacyRowIds' => [],
			'requeued' => 0,
			'failed' => 0,
			'retried' => 0,
			'translated' => 0,
		];
		QueueExecution::run(
            function () use ( &$result, $cursor, $budget ): void {
                $result = $this->reconcile_unlocked( $cursor, $budget );
            }
        );
		return $result;
	}

	private function reconcile_unlocked( int $cursor, ?ReadinessBudget $budget ): array {
		$budget = $budget ?? new ReadinessBudget();
		$rows = $this->step_batch ?? $this->indexer->page_rows();
		$jobs = $this->indexer->poll_remembered_jobs( $budget, $this->step_batch );
		$result = TranslationReadiness::refresh( $rows, $cursor, $this->lookup, $budget, null !== $this->step_batch );
		if ( ! empty( $result['lookupUnavailable'] ) ) {
			return $result + [
				'requeued' => 0,
				'failed' => 0,
				'retried' => 0,
				'translated' => 0,
			];
		}
		$now    = (int) call_user_func( $this->clock );
		$ids    = array_unique( array_merge( $result['rowIds'], $result['legacyRowIds'] ) );
		$by_id  = [];
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}
		$cold = [];
		$ready = [];
		$translated = [];
		// A done row counts as translated once its job finished or every one of
		// its segments is proven; one status call per job per pass.
		foreach ( $rows as $row ) {
			if ( IndexQueue::STATUS_DONE !== (string) $row['status'] || ! empty( $row['translated'] ) ) {
				continue;
			}
			// A finished job is evidence; so are proven segments. A cancelled,
			// forgotten or unrecorded job is not: the proofs decide for those.
			if ( ! empty( $row['retranslating'] ) ) {
				if ( 'open' === $this->indexer->job_outcome_for_row( (int) $row['id'], $jobs, false ) || ! TranslationReadiness::is_ready( $row ) ) {
					continue;
				}
				$object_id = 'miss' === $row['object_type'] ? 0 : (int) $row['object_id'];
				TranslationStore::hold_overwrite( $row['url'], $object_id, $row['language'], 0 );
				TranslationStore::resolve_pending( $row['url'], $object_id, $row['language'], static fn ( string $language, array $hashes ): array => array_intersect_key( $result['answers'][ $language ] ?? [], array_fill_keys( $hashes, true ) ), $budget );
				if ( TranslationStore::pending_count( $row['url'], $row['language'], $object_id ) > 0 ) {
					continue;
				}
			}
			if ( TranslationReadiness::is_ready( $row ) || 'done' === $this->indexer->job_outcome_for_row( (int) $row['id'], $jobs, false ) ) {
				$translated[] = (int) $row['id'];
			}
		}
		if ( null !== $this->step_translated ) {
			$this->step_translated = array_merge( $this->step_translated, $translated );
		} else {
			$this->indexer->mark_translated( $translated );
		}
		foreach ( $ids as $id ) {
			$row = $by_id[ (int) $id ] ?? null;
			if ( ! is_array( $row ) || (int) $row['updated'] > $now - self::GRACE_SECONDS ) {
				continue;
			}
			if ( 'open' === $this->indexer->job_outcome_for_row( (int) $id, $jobs, false ) && (int) $row['updated'] <= $now - self::MAX_JOB_AGE_SECONDS ) {
				$cold[] = $row + [ 'priority' => Indexer::PRIORITY_DEFAULT ];
			} elseif ( TranslationReadiness::is_ready( $row ) ) {
				$ready[] = (int) $id;
			} elseif ( $this->indexer->job_finished_for_row( (int) $id, $jobs, false )
				&& ! TranslationReadiness::sweep_incomplete( $row ) ) {
				// A page is cold only once the job that translates it has finished
				// and the readiness sweep has checked every one of its hashes; under
				// a busy service translation outlasts any fixed grace period and the
				// sweep needs several passes to cover a large page.
				$cold[] = $row + [ 'priority' => Indexer::PRIORITY_DEFAULT ];
			}
		}
		$this->indexer->reset_cold_retries( $ready );
		$healed = $this->indexer->self_heal_cold( $cold, self::MAX_COLD_ATTEMPTS );
		$result += [
			'requeued' => 0,
			'failed' => 0,
			'retried' => 0,
			'translated' => count( $translated ),
		];
		$result['requeued'] = $healed['requeued'];
		$result['failed']   = $healed['failed'];
		$result['retried']  = $this->indexer->retry_dead_automatically( self::MAX_DEAD_ATTEMPTS, self::DEAD_BACKOFF_SECONDS, $now );
		return $result;
	}
}
