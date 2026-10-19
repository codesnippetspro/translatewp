<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\TranslationReadiness;

class ReadinessReconcile {

	public const HOOK = 'translatewp_readiness_reconcile';
	public const CHECK_TRANSIENT = 'translatewp_readiness_reconcile_check';
	public const CURSOR_OPTION = 'translatewp_readiness_reconcile_cursor';
	public const MAX_COLD_ATTEMPTS = 3;
	public const MAX_DEAD_ATTEMPTS = 3;

	private const CHECK_SECONDS = 600;
	private const CATCH_UP_SECONDS = 60;
	// A scheduled pass proves a sixth of the site, bounded by time rather than
	// by count: as many look-ups as fit in forty seconds, every minute. A site
	// is walked in six passes while the service answers within the budget.
	private const PASS_SECONDS = 40;
	private const GRACE_SECONDS = 120;
	private const DEAD_BACKOFF_SECONDS = 600;

	private Indexer $indexer;
	private $lookup;
	private $clock;

	public function __construct( $plugin_name = '', $plugin_version = '', ?Indexer $indexer = null, ?callable $lookup = null, ?callable $clock = null, bool $register_hooks = true ) {
		unset( $plugin_name, $plugin_version );
		$this->indexer = $indexer ?? new Indexer();
		$this->lookup  = $lookup;
		$this->clock   = $clock ?? 'time';
		if ( $register_hooks ) {
			add_action( 'init', [ $this, 'maybe_schedule' ] );
			add_action( self::HOOK, [ $this, 'run' ] );
		}
	}

	public function maybe_schedule(): void {
		if ( ! SiteConnection::is_connected() || false !== get_transient( self::CHECK_TRANSIENT ) ) {
			return;
		}
		// How often an unattended site re-checks. A first pass has thousands of
		// segments to prove, so a site with work outstanding may want this far
		// shorter than the steady-state ten minutes.
		// A site larger than one pass is walked a pass a minute until it is
		// covered; a site one pass covers keeps the steady ten minutes.
		$batches  = TranslationReadiness::batch_count( $this->indexer->page_rows() );
		$steady   = $batches <= self::batches_per_pass( $batches ) ? self::CHECK_SECONDS : self::CATCH_UP_SECONDS;
		$interval = max( 5, (int) apply_filters( 'translatewp_readiness_reconcile_interval', $steady ) );
		set_transient( self::CHECK_TRANSIENT, 1, $interval );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	public function run(): void {
		if ( ! SiteConnection::is_connected() ) {
			return;
		}
		// Unattended, one batch per ten minutes would take half a day to walk a
		// large site; the scheduled pass proves a share of it each time.
		$batches = TranslationReadiness::batch_count( $this->indexer->page_rows() );
		$per_pass = static fn ( int $value ): int => max( $value, self::batches_per_pass( $batches ) );
		$seconds  = static fn ( float $value ): float => $value > 0 ? $value : (float) self::PASS_SECONDS;
		add_filter( 'translatewp_readiness_batches_per_pass', $per_pass );
		add_filter( 'translatewp_readiness_pass_seconds', $seconds );
		try {
			$result = $this->reconcile( (int) get_option( self::CURSOR_OPTION, 0 ) );
		} finally {
			remove_filter( 'translatewp_readiness_batches_per_pass', $per_pass );
			remove_filter( 'translatewp_readiness_pass_seconds', $seconds );
		}
		update_option( self::CURSOR_OPTION, (int) ( $result['cursor'] ?? 0 ) );
	}

	public static function batches_per_pass( int $batches ): int {
		return max( 1, (int) ceil( $batches / 6 ) );
	}

	public function reconcile( int $cursor = 0 ): array {
		$rows   = $this->indexer->page_rows();
		$result = TranslationReadiness::refresh( $rows, $cursor, $this->lookup );
		$now    = (int) call_user_func( $this->clock );
		$ids    = array_unique( array_merge( $result['rowIds'], $result['legacyRowIds'] ) );
		$by_id  = [];
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}
		$cold = [];
		$ready = [];
		$jobs = [];
		$translated = [];
		// A done row counts as translated once its job finished or every one of
		// its segments is proven; one status call per job per pass.
		foreach ( $rows as $row ) {
			if ( IndexQueue::STATUS_DONE !== (string) $row['status'] || ! empty( $row['translated'] ) ) {
				continue;
			}
			// A finished job is evidence; so are proven segments. A cancelled,
			// forgotten or unrecorded job is not: the proofs decide for those.
			if ( TranslationReadiness::is_ready( $row ) || 'done' === $this->indexer->job_outcome_for_row( (int) $row['id'], $jobs ) ) {
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
			} elseif ( $this->indexer->job_finished_for_row( (int) $id, $jobs )
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
