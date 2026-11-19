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
	private const DEAD_BACKOFF_SECONDS = 600;

	private ?int $step_row_id = null;
	private Indexer $indexer;
	private $lookup;
	private $clock;

	public function __construct( $plugin_name = '', $plugin_version = '', ?Indexer $indexer = null, ?callable $lookup = null, ?callable $clock = null, bool $register_hooks = true ) {
		unset( $plugin_name, $plugin_version, $register_hooks );
		$this->indexer = $indexer ?? new Indexer( '', '', null, null, false );
		$this->lookup  = $lookup;
		$this->clock   = $clock ?? 'time';
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

	public function step_run( ReadinessBudget $budget, bool $visitor_only = false ): void {
		$rows = $this->indexer->page_rows();
		if ( $visitor_only ) {
			$queue = new IndexQueue();
			$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => 'miss' === $row['object_type'] && IndexQueue::STATUS_DONE === $row['status'] && ! $queue->requires_background_refresh( $row['id'] ) && ! $row['translated'] ) );
		}
		if ( ! SiteConnection::is_connected() || ! $rows || $budget->remaining_seconds() <= 0 ) {
			return;
		}
		$option = $visitor_only ? self::VISITOR_CURSOR_OPTION : self::STEP_CURSOR_OPTION;
		$position = (array) get_option( $option, [] );
		$offset = max( 0, (int) ( $position['offset'] ?? 0 ) ) % count( $rows );
		foreach ( $rows as $index => $row ) {
			if ( (int) ( $position['row'] ?? 0 ) === (int) $row['id'] ) {
				$offset = 0 < (int) ( $position['cursor'] ?? 0 ) ? $index : ( $index + 1 ) % count( $rows );
				break;
			}
		}
		$this->step_row_id = (int) $rows[ $offset ]['id'];
		$cursor = (int) ( $position['row'] ?? 0 ) === $this->step_row_id ? (int) ( $position['cursor'] ?? 0 ) : 0;
		try {
			$result = $this->reconcile( $cursor, $budget );
		} finally {
			$this->step_row_id = null;
		}
		$next_cursor = (int) ( $result['cursor'] ?? 0 );
		update_option(
            self::STEP_CURSOR_OPTION,
            [
				'offset' => 0 === $next_cursor ? ( $offset + 1 ) % count( $rows ) : $offset,
				'row' => (int) $rows[ $offset ]['id'],
				'cursor' => $next_cursor,
			],
            false
        );
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
		$jobs = $this->indexer->poll_remembered_jobs( $budget );
		$rows   = $this->indexer->page_rows();
		if ( null !== $this->step_row_id ) {
			$rows = array_values( array_filter( $rows, fn ( array $row ): bool => (int) $row['id'] === $this->step_row_id ) );
		}
		$result = TranslationReadiness::refresh( $rows, $cursor, $this->lookup, $budget );
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
				if ( 'done' !== $this->indexer->job_outcome_for_row( (int) $row['id'], $jobs, false ) || ! TranslationReadiness::is_ready( $row ) ) {
					continue;
				}
				$object_id = 'miss' === $row['object_type'] ? 0 : (int) $row['object_id'];
				TranslationStore::hold_overwrite( $row['url'], $object_id, $row['language'], 0 );
				TranslationStore::resolve_pending( $row['url'], $object_id, $row['language'], null, $budget );
				if ( TranslationStore::pending_count( $row['url'], $row['language'], $object_id ) > 0 ) {
					continue;
				}
			}
			if ( TranslationReadiness::is_ready( $row ) || 'done' === $this->indexer->job_outcome_for_row( (int) $row['id'], $jobs, false ) ) {
				$translated[] = (int) $row['id'];
			}
		}
		$this->indexer->mark_translated( $translated );
		foreach ( $ids as $id ) {
			$row = $by_id[ (int) $id ] ?? null;
			if ( ! is_array( $row ) || (int) $row['updated'] > $now - self::GRACE_SECONDS ) {
				continue;
			}
			if ( TranslationReadiness::is_ready( $row ) ) {
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
