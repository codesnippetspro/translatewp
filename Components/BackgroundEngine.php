<?php

namespace TranslateWP\Components;

use TranslateWP\Functionality\CronFallback;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\LexiconChanges;
use TranslateWP\Functionality\ReadinessReconcile;

class BackgroundEngine {

	public const MAX_READ_PAGES = 3;
	public const MAX_PAGE_BYTES = 2097152;
	public const CRON_HOOK = 'translatewp_index_queue_drain';
	public const WAKE_HOOK = 'translatewp_background_wake';
	public const ELIGIBILITY_HOOK = 'translatewp_background_eligibility';
	public const LAST_SERVICE_OPTION = 'translatewp_engine_last_service';
	public const STARTED_OPTION = 'translatewp_engine_started';
	public const LAST_STEP_OPTION = 'translatewp_engine_last_step';
	public const PHASE_OPTION = 'translatewp_engine_phase';
	public const STATE_OPTION = 'translatewp_engine_state';
	public const VISITOR_STREAK_OPTION = 'translatewp_engine_visitor_streak';
	public const VISITOR_HINT_OPTION = 'translatewp_engine_visitor_pending';
	public const VISITOR_INTERVAL_SECONDS = 20;
	private const MAX_VISITOR_STREAK = 3;
	public const STORE_TURN_OPTION = 'translatewp_engine_store_turn';
	public const TASKS_OPTION = 'translatewp_engine_tasks';
	public const LAST_WAKE_OPTION = 'translatewp_engine_last_wake';
	public const FALLBACK_SECONDS = 300;
	public const INTERVAL_SECONDS = 60;
	public const STALLED_SECONDS = 900;
	private const HEARTBEAT_SECONDS = 900;
	private const PHASE_COUNT = 2;

	public static function install(): void {
		if ( ! get_option( self::STARTED_OPTION, false ) ) {
			update_option( self::STARTED_OPTION, time(), false );
		}
		if ( ! BackgroundSwitch::off() ) {
			self::schedule();
			self::schedule_wake();
		}
		if ( ! wp_next_scheduled( self::ELIGIBILITY_HOOK ) ) {
			wp_schedule_single_event( time(), self::ELIGIBILITY_HOOK );
		}
	}

	public static function schedule( int $delay = self::INTERVAL_SECONDS ): void {
		$due = time() + max( self::INTERVAL_SECONDS, $delay );
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( false === $next || $due < (int) $next ) {
			if ( false !== $next ) {
				wp_unschedule_event( (int) $next, self::CRON_HOOK );
			}
			wp_schedule_single_event( $due, self::CRON_HOOK );
		}
	}

	public static function schedule_wake(): void {
		if ( ! wp_next_scheduled( self::WAKE_HOOK ) ) {
			wp_schedule_single_event( time(), self::WAKE_HOOK );
		}
	}

	public static function wake(): void {
		if ( self::claim_wake( self::INTERVAL_SECONDS ) ) {
			SiteRequests::post( '/v1/background/wake', (object) [], 3 );
		}
	}

	public static function refresh_eligibility(): void {
		if ( SiteConnection::is_connected() ) {
			ProEligibility::maybe_refresh();
		}
		if ( ! wp_next_scheduled( self::ELIGIBILITY_HOOK ) ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::ELIGIBILITY_HOOK );
		}
	}

	public static function cron(): void {
		if ( BackgroundSwitch::off() ) {
			return;
		}
		$last = (int) get_option( self::LAST_SERVICE_OPTION, 0 );
		if ( $last <= time() - self::FALLBACK_SECONDS && ! BackgroundSwitch::off() ) {
			self::step();
		}
		self::schedule();
	}

	public static function service_step( array $body ): array {
		update_option( self::LAST_SERVICE_OPTION, time(), false );
		BackgroundSwitch::set_paused( true === ( $body['pause'] ?? false ) );
		return self::step( is_array( $body['pages'] ?? null ) ? $body['pages'] : [] );
	}

	public static function step( array $pages = [] ): array {
		if ( BackgroundSwitch::off() ) {
			return self::snapshot( 'paused' );
		}
		$response = self::snapshot( 'busy' );
		$visitor_hint = self::visitor_hint();
		QueueExecution::run(
			static function () use ( $pages, $visitor_hint, &$response ): void {
				if ( ! SiteConnection::is_connected() ) {
					$response = self::snapshot( 'done' );
					return;
				}
				$work = self::phase_work();
				$streak = (int) get_option( self::VISITOR_STREAK_OPTION, 0 );
				$visitor_turn = ! $work['background'] || $streak < self::MAX_VISITOR_STREAK;
				$indexer = new Indexer( '', '', null, null, false );
				$budget = QueueExecution::budget();
				$is_visitor = false;
				if ( $visitor_turn && $work['visitorPush'] ) {
					$is_visitor = true;
					$indexer->step_push( $budget, $work['visitorPush'] );
				} elseif ( $visitor_turn && $work['visitorReady'] ) {
					$is_visitor = true;
					self::complete( $indexer, $budget, $work['visitorKeys'] );
				} elseif ( $work['backgroundPush'] ) {
					$indexer->step_push( $budget, $work['backgroundPush'] );
				} elseif ( $work['read'] ) {
					$indexer->step_read( $budget, $pages );
				} else {
					$phase = max( 0, (int) get_option( self::PHASE_OPTION, 1 ) ) % self::PHASE_COUNT;
					update_option( self::PHASE_OPTION, ( $phase + 1 ) % self::PHASE_COUNT, false );
					if ( 0 === $phase ) {
						self::complete( $indexer, $budget );
					} else {
						$changes = new LexiconChanges( '', '', false );
						$changes->seed();
						$indexer->ensure_spine();
						if ( ! CronFallback::run_refill_maintenance() && ! QueueExecution::expired() ) {
							( new IndexQueue() )->maintain_duplicates();
						}
					}
				}
				update_option( self::VISITOR_STREAK_OPTION, $is_visitor ? min( self::MAX_VISITOR_STREAK, $streak + 1 ) : 0, false );
				update_option( self::LAST_STEP_OPTION, time(), false );
				update_option( Indexer::LAST_DRAIN_OPTION, time(), false );
				$pending = self::pending( false );
				if ( 0 === $pending['visitor'] ) {
					MutationLock::run(
                        'queue_rows',
                        static function () use ( $visitor_hint ): void {
							if ( self::visitor_hint() === $visitor_hint ) {
								delete_option( self::VISITOR_HINT_OPTION );
							}
						},
                        0
                    );
				}
				if ( '' !== self::visitor_hint() ) {
					$pending['visitor'] = max( 1, $pending['visitor'] );
					$pending['push'] = max( $pending['push'], $pending['visitor'] );
				}
				$response = [
					'state' => array_sum( $pending ) > 0 ? 'more' : 'done',
					'pending' => $pending,
					'v' => 1,
				];
				$error = sanitize_key( (string) get_option( Indexer::READER_FAILURE_OPTION, '' ) );
				if ( '' !== $error ) {
					$response['error'] = [
						'code' => $error,
						'message' => __( 'The site page reader is waiting. Check the Indexing page for details.', 'translate' ),
					];
				}
				if ( self::service_reads() ) {
					$response['want'] = $indexer->wanted_pages();
				}
				update_option( self::STATE_OPTION, $response, false );
			}
		);
		if ( '' !== self::visitor_hint() ) {
			$response['pending']['visitor'] = max( 1, $response['pending']['visitor'] );
			$response['pending']['push'] = max( $response['pending']['push'], $response['pending']['visitor'] );
			if ( 'done' === $response['state'] ) {
				$response['state'] = 'more';
			}
		}
		self::schedule( 'done' === $response['state'] ? self::HEARTBEAT_SECONDS : self::INTERVAL_SECONDS );
		return BackgroundSwitch::off() ? self::snapshot( 'paused' ) : $response;
	}

	private static function visitor_hint(): string {
		wp_cache_delete( self::VISITOR_HINT_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return (string) get_option( self::VISITOR_HINT_OPTION, '' );
	}

	public static function visitor_wake(): void {
		if ( self::claim_wake( self::VISITOR_INTERVAL_SECONDS ) ) {
			SiteRequests::notify( '/v1/background/wake', (object) [] );
		}
	}

	private static function claim_wake( int $interval ): bool {
		if ( ! SiteConnection::is_connected() ) {
			return false;
		}
		$send = false;
		MutationLock::run(
            'engine_wake',
            static function () use ( $interval, &$send ): void {
				wp_cache_delete( self::LAST_WAKE_OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				if ( (int) get_option( self::LAST_WAKE_OPTION, 0 ) > time() - $interval ) {
					return;
				}
				update_option( self::LAST_WAKE_OPTION, time(), false );
				$send = true;
			},
            0
        );
		return $send;
	}

	private static function complete( Indexer $indexer, ReadinessBudget $budget, ?array $visitor_keys = null ): void {
		$store_turn = (bool) get_option( self::STORE_TURN_OPTION, false );
		update_option( self::STORE_TURN_OPTION, ! $store_turn, false );
		if ( $store_turn ) {
			TranslationStore::run_backfill_batch( 1, null, $visitor_keys );
		} else {
			( new ReadinessReconcile( '', '', $indexer, null, null, false ) )->step_run( $budget, null !== $visitor_keys );
		}
		if ( ! QueueExecution::expired() ) {
			$indexer->report_completion();
		}
	}

	private static function phase_work(): array {
		$queue = new IndexQueue();
		$queue->reclaim_stale();
		$work = [
			'visitorPush' => [],
			'visitorReady' => false,
			'visitorKeys' => [],
			'backgroundPush' => [],
			'read' => false,
			'background' => false,
		];
		foreach ( $queue->list_rows() as $row ) {
			$visitor = 'miss' === $row['object_type'] && ! $queue->requires_background_refresh( $row['id'] );
			if ( IndexQueue::STAGE_PUSH === $row['stage'] && IndexQueue::STATUS_PENDING === $row['status']
				&& (int) get_post_meta( $row['id'], '_twp_reader_not_before', true ) <= time() ) {
				$work[ $visitor ? 'visitorPush' : 'backgroundPush' ][] = $row['id'];
			}
			if ( $visitor && IndexQueue::STATUS_DONE === $row['status'] && ! $row['translated'] ) {
				$work['visitorReady'] = true;
				$work['visitorKeys'][] = TranslationUrl::canonicalize( $row['url'] ) . '|' . $row['language'];
			}
			if ( ! $visitor && IndexQueue::STATUS_DEAD !== $row['status'] && ! $row['translated'] ) {
				$work['background'] = true;
			}
		}
		foreach ( [ 'visitorPush', 'backgroundPush' ] as $kind ) {
			usort( $work[ $kind ], static fn ( int $left, int $right ): int => (int) get_post_meta( $left, '_twp_priority', true ) <=> (int) get_post_meta( $right, '_twp_priority', true ) );
			$work[ $kind ] = array_slice( $work[ $kind ], 0, Indexer::drain_batch_size() );
		}
		$work['read'] = (bool) get_option( Indexer::SPINE_PENDING_OPTION, false )
			|| ( (int) get_option( Indexer::NEXT_READER_OPTION, 0 ) <= time() && [] !== $queue->pending_read_rows() );
		$pending = self::pending();
		$work['background'] = $work['background'] || $work['read'] || $pending['refill'] > 0;
		return $work;
	}

	public static function defer_task( string $hook, array $args = [] ): void {
		MutationLock::run(
            'engine_tasks',
            static function () use ( $hook, $args ): void {
				wp_cache_delete( self::TASKS_OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				$tasks = (array) get_option( self::TASKS_OPTION, [] );
				$tasks[ md5( $hook . (string) wp_json_encode( $args ) ) ] = [
					'hook' => $hook,
					'args' => $args,
					'revision' => bin2hex( random_bytes( 8 ) ),
				];
				update_option( self::TASKS_OPTION, $tasks, false );
			}
        );
		self::schedule();
		self::schedule_wake();
	}

	public static function run_deferred_task(): bool {
		$tasks = (array) get_option( self::TASKS_OPTION, [] );
		$key = key( $tasks );
		$task = null !== $key ? $tasks[ $key ] : null;
		if ( ! is_array( $task ) || ! in_array( $task['hook'] ?? '', \TranslateWP\Functionality\KeepWarm::BACKGROUND_HOOKS, true ) ) {
			return false;
		}
		do_action( $task['hook'], ...$task['args'] );
		MutationLock::run(
            'engine_tasks',
            static function () use ( $key, $task ): void {
				wp_cache_delete( self::TASKS_OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				$tasks = (array) get_option( self::TASKS_OPTION, [] );
				if ( ( $tasks[ $key ] ?? null ) === $task ) {
					unset( $tasks[ $key ] );
					update_option( self::TASKS_OPTION, $tasks, false );
				}
			}
        );
		return true;
	}

	public static function stalled(): bool {
		$last = max( (int) get_option( self::LAST_STEP_OPTION, 0 ), (int) get_option( self::STARTED_OPTION, 0 ) );
		return ! BackgroundSwitch::off() && SiteConnection::is_connected()
			&& $last <= time() - self::STALLED_SECONDS && array_sum( self::pending() ) > 0;
	}

	public static function scheduled_tasks(): array {
		$tasks = [];
		$cron = _get_cron_array();
		foreach ( is_array( $cron ) ? $cron : [] as $at => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				if ( ! in_array( $hook, \TranslateWP\Functionality\KeepWarm::BACKGROUND_HOOKS, true ) ) {
					continue;
				}
				foreach ( $events as $event ) {
					$tasks[] = [
						'at' => (int) $at,
						'hook' => $hook,
						'args' => (array) $event['args'],
					];
				}
			}
		}
		return $tasks;
	}

	public static function service_reads(): bool {
		return (bool) apply_filters( 'translatewp_engine_service_reads', false );
	}

	public static function snapshot( string $state ): array {
		$saved = (array) get_option( self::STATE_OPTION, [] );
		return [
			'state' => $state,
			'pending' => [
				'read' => max( 0, (int) ( $saved['pending']['read'] ?? 0 ) ),
				'push' => max( 0, (int) ( $saved['pending']['push'] ?? 0 ) ),
				'refill' => max( 0, (int) ( $saved['pending']['refill'] ?? 0 ) ),
				'visitor' => max( 0, (int) ( $saved['pending']['visitor'] ?? 0 ) ),
			],
			'v' => 1,
		];
	}

	public static function pending( bool $include_hint = true ): array {
		$queue = new IndexQueue();
		$pending = [
			'read' => (int) $queue->read_counts()['pending'],
			'push' => 0,
			'refill' => 0,
			'visitor' => 0,
		];
		foreach ( $queue->counts() as $counts ) {
			$pending['read'] += (int) $counts['pending_extract'];
			$pending['push'] += (int) $counts['pending_push'] + (int) $counts['confirming'];
			$pending['refill'] += (int) $counts['retranslating'];
		}
		foreach ( $queue->list_rows() as $row ) {
			if ( 'miss' === $row['object_type'] && IndexQueue::STAGE_PUSH === $row['stage']
				&& in_array( $row['status'], [ IndexQueue::STATUS_PENDING, IndexQueue::STATUS_CLAIMED ], true )
				&& ! $queue->requires_background_refresh( $row['id'] ) ) {
				$pending['visitor']++;
			}
		}
		$pending['refill'] += (int) ( false !== get_option( IndexQueue::DEFERRED_REFILLS_OPTION, false ) )
			+ (int) ( false !== get_option( LexiconChanges::SEED_CURSOR_OPTION, false ) )
			+ (int) ( ! get_option( LexiconChanges::SEEDED_OPTION, false ) )
			+ (int) ( ! get_option( Indexer::SPINE_CHECKED_OPTION, false ) )
			+ (int) (bool) get_option( Indexer::SPINE_PENDING_OPTION, false )
			+ (int) TranslationStore::has_pending_work()
			+ count( (array) get_option( self::TASKS_OPTION, [] ) )
			+ count( self::scheduled_tasks() );
		if ( $include_hint && '' !== self::visitor_hint() ) {
			$pending['visitor'] = max( 1, $pending['visitor'] );
			$pending['push'] = max( $pending['push'], $pending['visitor'] );
		}
		return $pending;
	}
}
