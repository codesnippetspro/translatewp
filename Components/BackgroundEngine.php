<?php

namespace TranslateWP\Components;

use TranslateWP\Functionality\CronFallback;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\KeepWarm;
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
	public const PROGRESS_OPTION = 'translatewp_engine_progress';
	public const STATE_OPTION = 'translatewp_engine_state';
	public const WORK_OPTION = 'translatewp_engine_work';
	public const REVISION_OPTION = 'translatewp_engine_revision';
	public const VISITOR_STREAK_OPTION = 'translatewp_engine_visitor_streak';
	public const VISITOR_HINT_OPTION = 'translatewp_engine_visitor_pending';
	public const VISITOR_INTERVAL_SECONDS = 20;
	private const MAX_VISITOR_STREAK = 3;
	public const STORE_TURN_OPTION = 'translatewp_engine_store_turn';
	public const TASKS_OPTION = 'translatewp_engine_tasks';
	public const LAST_WAKE_OPTION = 'translatewp_engine_last_wake';
	public const LAST_VISITOR_WAKE_OPTION = 'translatewp_engine_last_visitor_wake';
	public const LAST_VISITOR_ATTEMPT_OPTION = 'translatewp_engine_visitor_wake_attempt';
	public const LAST_WAKE_ATTEMPT_OPTION = 'translatewp_engine_wake_attempt';
	public const FALLBACK_SECONDS = 300;
	public const INTERVAL_SECONDS = 60;
	public const STALLED_SECONDS = 900;
	private const HEARTBEAT_SECONDS = 900;
	private const PHASE_COUNT = 2;
	private const MAX_UNITS_PER_STEP = 64;
	private static bool $servicing = false;
	private static ?array $step_work = null;
	private static int $trace_input_rows = 0;
	private static string $trace_phase = 'idle';
	private static int $trace_rows = 0;

	public static function install(): void {
		if ( ! SiteConnection::has_credentials() ) {
			return;
		}
		$first = ! get_option( self::STARTED_OPTION, false );
		if ( $first ) {
			update_option( self::STARTED_OPTION, time(), true );
			add_option( IndexQueue::NORMALIZE_CURSOR_OPTION, 0, '', false );
			wp_clear_scheduled_hook( ReadinessReconcile::HOOK );
			wp_clear_scheduled_hook( \TranslateWP\Functionality\TranslationStoreSync::BACKFILL_HOOK );
			wp_clear_scheduled_hook( self::CRON_HOOK );
			self::mark_dirty();
			if ( ! get_option( LexiconChanges::SEEDED_OPTION, false ) || ! get_option( Indexer::SPINE_CHECKED_OPTION, false ) ) {
				wp_schedule_single_event( time() + self::FALLBACK_SECONDS, self::CRON_HOOK );
			}
			self::schedule_wake();
		}
		if ( ! wp_next_scheduled( self::ELIGIBILITY_HOOK ) ) {
			wp_schedule_single_event( time(), self::ELIGIBILITY_HOOK );
		}
	}

	public static function mark_dirty(): void {
		update_option( self::REVISION_OPTION, bin2hex( random_bytes( 8 ) ), false );
	}

	public static function schedule( int $delay = self::INTERVAL_SECONDS ): void {
		if ( ! SiteConnection::is_connected() || BackgroundSwitch::off() ) {
			return;
		}
		$last = (int) get_option( self::LAST_SERVICE_OPTION, 0 );
		$due = max( time() + max( self::INTERVAL_SECONDS, $delay ), $last + self::FALLBACK_SECONDS );
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $next && (int) $next <= $due ) {
			return;
		}
		if ( false !== $next ) {
			wp_unschedule_event( (int) $next, self::CRON_HOOK );
		}
		wp_schedule_single_event( $due, self::CRON_HOOK );
	}

	public static function schedule_wake(): void {
		if ( ! self::$servicing && ! QueueExecution::is_bounded() && ! wp_next_scheduled( self::WAKE_HOOK ) ) {
			wp_schedule_single_event( time(), self::WAKE_HOOK );
		}
	}

	public static function wake(): void {
		self::send_wake( self::LAST_WAKE_OPTION, self::INTERVAL_SECONDS );
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
		if ( BackgroundSwitch::off() || ! SiteConnection::is_connected() || 0 === array_sum( self::pending() ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}
		$last = (int) get_option( self::LAST_SERVICE_OPTION, 0 );
		if ( $last <= time() - self::FALLBACK_SECONDS && ! BackgroundSwitch::off() ) {
			self::step();
			return;
		}
		self::schedule();
	}

	public static function service_step( array $body ): array {
		self::$servicing = true;
		try {
			Migrations::install_engine_if_needed();
			update_option( self::LAST_SERVICE_OPTION, time(), false );
			BackgroundSwitch::set_paused( true === ( $body['pause'] ?? false ) );
			return self::step( is_array( $body['pages'] ?? null ) ? $body['pages'] : [] );
		} finally {
			self::$servicing = false;
		}
	}

	public static function step( array $pages = [] ): array {
		global $wpdb;
		$tracing = defined( 'TRANSLATEWP_ENGINE_TRACE' );
		$started = microtime( true );
		$queries = (int) ( $wpdb->num_queries ?? 0 );
		self::$trace_phase = 'idle';
		self::$trace_input_rows = 0;
		self::$trace_rows = 0;
		QueueExecution::reset_trace();
		QueueExecution::begin_step();
		try {
			$response = self::run_step( $pages, $started );
		} finally {
			self::$step_work = null;
			QueueExecution::end_step();
		}
		if ( $tracing ) {
			$response['trace'] = [
				'phase' => self::$trace_phase,
				'rows' => self::$trace_rows,
				'inputRows' => self::$trace_input_rows,
				'queries' => max( 0, (int) ( $wpdb->num_queries ?? 0 ) - $queries ),
				'ms' => round( ( microtime( true ) - $started ) * 1000, 2 ),
				'remote' => QueueExecution::trace_remote(),
			];
		}
		return $response;
	}

	private static function run_step( array $pages, float $started ): array {
		if ( BackgroundSwitch::off() ) {
			self::$trace_phase = 'paused';
			return self::snapshot( 'paused' );
		}
		if ( ! SiteConnection::is_connected() || 0 === array_sum( self::pending() ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return [
				'state' => 'done',
				'pending' => [
					'read' => 0,
					'push' => 0,
					'refill' => 0,
					'visitor' => 0,
				],
				'v' => 1,
			];
		}
		self::$trace_phase = 'busy';
		$response = self::snapshot( 'busy' );
		$visitor_hint = self::visitor_hint();
		QueueExecution::run(
			static function () use ( $pages, $visitor_hint, &$response ): void {
				if ( ! SiteConnection::is_connected() ) {
					$response = self::snapshot( 'done' );
					return;
				}
				$indexer = new Indexer( '', '', null, null, false );
				$budget = QueueExecution::budget();
				ProviderHold::refresh();
				if ( ! Indexer::cancel_unbatched_job() ) {
					$response = self::snapshot( 'more' );
					$response['nextDue'] = time() + self::VISITOR_INTERVAL_SECONDS;
					return;
				}
				$indexer->poll_provider_probe();
				if ( ProviderHold::waiting() ) {
					$response = self::snapshot( 'more' );
					self::$step_work = null;
					$response['pending'] = self::pending();
					$hold = ProviderHold::state();
					$response['nextDue'] = ! empty( $hold['probe'] ) ? time() + 15 : $hold['due'];
					return;
				}
				$inputs = self::start_inputs();
				$visitor_rows = 0;
				if ( ! empty( self::work_state()['pending']['visitor'] ) || '' !== $visitor_hint ) {
					$visitor_ids = array_column( ( new IndexQueue() )->engine_rows( 'visitor_push', Indexer::drain_batch_size() ), 'id' );
					if ( $visitor_ids ) {
						$pushed = $indexer->step_push( $budget, $visitor_ids );
						$visitor_rows += $pushed['processed'] + $pushed['failed'];
					}
					$ready = ReadinessReconcile::step_rows( true );
					if ( $ready && ! QueueExecution::expired() ) {
						$keys = array_map( static fn ( array $row ): string => TranslationUrl::canonicalize( $row['url'] ) . '|' . $row['language'], $ready );
						self::complete( $indexer, $budget, $keys, $ready );
						$visitor_rows += self::$trace_rows;
					}
				}
				if ( ! empty( self::work_state()['needs_normalization'] ) ) {
					add_option( IndexQueue::NORMALIZE_CURSOR_OPTION, 0, '', false );
					( new IndexQueue() )->normalize_legacy_rows();
				}
				$inputs = self::apply_inputs( $inputs );
				self::$trace_input_rows = $inputs['rows'];
				if ( $inputs['rows'] ) {
					self::$trace_phase = 'maintenance';
				}
				self::$step_work = null;
				$total_rows = ! QueueExecution::expired() && $pages && self::service_reads() ? $indexer->step_read( $budget, $pages ) : 0;
				if ( $total_rows > 0 ) {
					self::$trace_phase = 'read';
				}
				$total_rows += $inputs['rows'] + $visitor_rows;
				$queue = new IndexQueue();
				$marking = false !== get_option( KeepWarm::REBUILD_CURSOR, false );
				if ( ! $marking ) {
					$queue->reclaim_stale();
				}
				$work = [
					'visitorPush' => [],
					'visitorReady' => [],
					'backgroundPush' => [],
					'backgroundReady' => [],
					'read' => false,
				];
				$selection = [];
				$refill_pending = $marking || $inputs['rows'] > 0 || self::pending()['refill'] > 0;
				$idle_turns = 0;
				for ( $unit = 0; ! $marking && $unit < self::MAX_UNITS_PER_STEP && ! QueueExecution::expired() && ! BackgroundSwitch::off(); ++$unit ) {
					if ( ProviderHold::waiting() ) {
						break;
					}
					self::$trace_rows = 0;
					$work = self::phase_work( $queue, $selection, $refill_pending, $inputs['languages'] );
					$streak = (int) get_option( self::VISITOR_STREAK_OPTION, 0 );
					$visitor_turn = ! $work['background'] || $streak < self::MAX_VISITOR_STREAK;
					$is_visitor = false;
					$worked = false;
					$phases = [
						'visitor_push' => $visitor_turn ? $work['visitorPush'] : [],
						'visitor_ready' => $visitor_turn ? $work['visitorReady'] : [],
						'background_push' => $work['backgroundPush'],
						'background_ready' => $work['backgroundReady'],
					];
					foreach ( $phases as $phase => $rows ) {
						if ( ! $rows || QueueExecution::expired() ) {
							continue;
						}
						self::$trace_phase = $phase;
						if ( str_ends_with( $phase, '_push' ) && isset( $selection[ $phase ] ) ) {
							array_splice( $selection[ $phase ], 0, count( $rows ) );
							if ( ! $selection[ $phase ] && ! empty( $selection[ $phase . '_more' ] ) ) {
								unset( $selection[ $phase ] );
							}
						} else {
							unset( $selection[ $phase ] );
						}
						if ( false !== strpos( $phase, '_push' ) ) {
							unset( $selection[ 'visitor_push' === $phase ? 'visitor_ready' : 'background_ready' ] );
							$result = $indexer->step_push( $budget, $rows );
							self::$trace_rows = $result['processed'] + $result['failed'];
						} else {
							unset( $selection['read'], $selection['background_push'], $selection['visitor_push'] );
							self::complete( $indexer, $budget, 'visitor_ready' === $phase ? $work['visitorKeys'] : null, $rows );
						}
						if ( self::$trace_rows > 0 ) {
							$worked = true;
							$is_visitor = 0 === strpos( $phase, 'visitor_' );
							break;
						}
					}
					if ( ! $worked && ! QueueExecution::expired() && $work['read'] ) {
						unset( $selection['read'], $selection['background_push'] );
						self::$trace_phase = 'read';
						self::$trace_rows = $indexer->step_read( $budget, $pages );
						$worked = self::$trace_rows > 0;
					}
					if ( ! $worked && ! QueueExecution::expired() ) {
						$selection = [];
						self::$trace_phase = 'maintenance';
						$phase = max( 0, (int) get_option( self::PHASE_OPTION, 1 ) ) % self::PHASE_COUNT;
						update_option( self::PHASE_OPTION, ( $phase + 1 ) % self::PHASE_COUNT, false );
						if ( 0 === $phase ) {
							self::complete( $indexer, $budget );
							$worked = self::$trace_rows > 0;
						} else {
							$changes = new LexiconChanges( '', '', false );
							$changes->seed();
							$indexer->ensure_spine();
							$worked = CronFallback::run_refill_maintenance();
							if ( ! $worked && ! QueueExecution::expired() ) {
								( new IndexQueue() )->maintain_duplicates();
							}
						}
					}
					update_option( self::VISITOR_STREAK_OPTION, $is_visitor ? min( self::MAX_VISITOR_STREAK, $streak + 1 ) : 0, false );
					$total_rows += self::$trace_rows;
					$idle_turns = $worked ? 0 : $idle_turns + 1;
					if ( $idle_turns >= self::PHASE_COUNT ) {
						break;
					}
				}
				self::$step_work = null;
				if ( ! $marking && ! QueueExecution::expired() ) {
					$indexer->report_completion();
				}
				self::$trace_rows = $total_rows;
				update_option( self::LAST_STEP_OPTION, time(), false );
				update_option( Indexer::LAST_DRAIN_OPTION, time(), false );
				self::$step_work = null;
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
				$next_due = ProviderHold::waiting() ? max( time() + 15, ProviderHold::state()['due'] ) : (int) ( self::work_state()['next'] ?? 0 );
				if ( ! $pending['visitor'] && $inputs['due'] > time() && ! $total_rows && ! ProviderHold::waiting() ) {
					$next_due = $inputs['due'];
				}
				if ( 'more' === $response['state'] && $next_due > time() ) {
					$response['nextDue'] = $next_due;
				}
				$actionable = array_merge( $work['visitorPush'], array_column( $work['visitorReady'], 'id' ), $work['backgroundPush'], array_column( $work['backgroundReady'], 'id' ) );
				if ( ! $actionable && $work['read'] ) {
					$actionable = array_column( ( new IndexQueue() )->pending_read_rows(), 'id' );
				}
				self::record_progress( $response, $actionable, $total_rows );
				if ( 'more' === $response['state'] && ! $total_rows && ! isset( $response['nextDue'] ) ) {
					$reader_due = (int) get_option( Indexer::NEXT_READER_OPTION, 0 );
					$poll_due = time() + self::VISITOR_INTERVAL_SECONDS;
					$deadlines = [];
					if ( $pending['read'] ) {
						$deadlines[] = ! self::service_reads() && $reader_due > time() ? $reader_due : $poll_due;
					}
					if ( $pending['push'] || $work['backgroundReady'] || $work['visitorReady'] ) {
						$deadlines[] = $poll_due;
					}
					$store_due = TranslationStore::next_work_at();
					if ( $store_due ) {
						$deadlines[] = $store_due > time() ? $store_due : $poll_due;
					}
					$response['nextDue'] = $deadlines ? min( $deadlines ) : $poll_due;
				}
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
			},
			max( 0.01, QueueExecution::STEP_SECONDS - ( microtime( true ) - $started ) - min( 3.0, 1.5 * ( self::work_state()['aggregate_seconds'] ?? 0.0 ) ) )
		);
		if ( '' !== self::visitor_hint() ) {
			$response['pending']['visitor'] = max( 1, $response['pending']['visitor'] );
			$response['pending']['push'] = max( $response['pending']['push'], $response['pending']['visitor'] );
			if ( 'done' === $response['state'] ) {
				$response['state'] = 'more';
			}
		}
		if ( 'done' === $response['state'] ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		} else {
			self::schedule();
		}
		return BackgroundSwitch::off() ? self::snapshot( 'paused' ) : $response;
	}

	private static function record_progress( array &$response, array $ids, int $changes ): void {
		if ( 'done' === $response['state'] || $changes > 0 || ! $ids || isset( $response['nextDue'] ) ) {
			delete_option( self::PROGRESS_OPTION );
			return;
		}
		$rows = array_slice( array_values( array_unique( $ids ) ), 0, 8 );
		$previous = (array) get_option( self::PROGRESS_OPTION, [] );
		$count = ( $previous['rows'] ?? [] ) === $rows ? (int) $previous['count'] + 1 : 1;
		update_option(
            self::PROGRESS_OPTION,
            [
				'rows' => $rows,
				'count' => $count,
			],
            false
        );
		if ( $count >= 3 ) {
			$response['stalled'] = [
				'phase' => self::$trace_phase,
				'rows' => $rows,
			];
		}
	}

	private static function visitor_hint(): string {
		wp_cache_delete( self::VISITOR_HINT_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return (string) get_option( self::VISITOR_HINT_OPTION, '' );
	}

	public static function visitor_wake(): void {
		self::send_wake( self::LAST_VISITOR_WAKE_OPTION, self::VISITOR_INTERVAL_SECONDS );
	}

	private static function send_wake( string $option, int $interval ): void {
		if ( ! SiteConnection::is_connected() || self::$servicing || QueueExecution::is_bounded() ) {
			return;
		}
		MutationLock::run(
			$option,
			static function () use ( $option, $interval ): void {
				$attempt = self::LAST_VISITOR_WAKE_OPTION === $option ? self::LAST_VISITOR_ATTEMPT_OPTION : self::LAST_WAKE_ATTEMPT_OPTION;
				wp_cache_delete( $attempt, 'options' );
				wp_cache_delete( $option, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				if ( max( (int) get_option( $option, 0 ), (int) get_option( $attempt, 0 ) ) > time() - $interval ) {
					return;
				}
				update_option( $attempt, time(), false );
				if ( SiteRequests::notify( '/v1/background/wake', (object) [] ) ) {
					update_option( $option, time(), false );
				}
			},
			0
		);
	}

	private static function complete( Indexer $indexer, ReadinessBudget $budget, ?array $visitor_keys = null, ?array $rows = null ): void {
		$reconcile = new ReadinessReconcile( '', '', $indexer, null, null, false );
		$reconcile->step_run( $budget, null !== $visitor_keys, $rows );
		self::$trace_rows = $reconcile->step_rows_seen();
		if ( ! QueueExecution::expired() ) {
			TranslationStore::run_backfill_batch( QueueMetadata::MAX_ROWS, null, $visitor_keys, true );
		}
	}

	private static function phase_work( IndexQueue $queue, array &$selection, bool $refill_pending, array $blocked_languages ): array {
		$visitor_ready = $selection['visitor_ready'] ??= ReadinessReconcile::step_rows( true );
		$background_ready = $selection['background_ready'] ??= ReadinessReconcile::step_rows();
		foreach ( [ 'visitor_push', 'background_push' ] as $kind ) {
			if ( ! isset( $selection[ $kind ] ) ) {
				$excluded = 'background_push' === $kind ? $blocked_languages : [];
				$selection[ $kind ] = in_array( '*', $excluded, true ) ? [] : $queue->engine_rows( $kind, QueueMetadata::MAX_ROWS, 0, null, $excluded );
				$selection[ $kind . '_more' ] = count( $selection[ $kind ] ) === QueueMetadata::MAX_ROWS;
			}
		}
		$visitor = array_slice( $selection['visitor_push'], 0, Indexer::drain_batch_size() );
		$background = array_slice( $selection['background_push'], 0, Indexer::drain_batch_size() );
		$read = $selection['read'] ??= (bool) get_option( Indexer::SPINE_PENDING_OPTION, false )
			|| ( ( self::service_reads() || (int) get_option( Indexer::NEXT_READER_OPTION, 0 ) <= time() ) && [] !== $queue->pending_read_rows() );
		return [
			'visitorPush' => array_column( $visitor, 'id' ),
			'visitorReady' => $visitor_ready,
			'visitorKeys' => array_map( static fn ( array $row ): string => TranslationUrl::canonicalize( $row['url'] ) . '|' . $row['language'], $visitor_ready ),
			'backgroundPush' => array_column( $background, 'id' ),
			'backgroundReady' => $background_ready,
			'read' => $read,
			'background' => [] !== $background || [] !== $background_ready || $read || $refill_pending,
		];
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

	private static function start_inputs(): array {
		$hooks = [ KeepWarm::SETTINGS_DEBOUNCE_HOOK, KeepWarm::LEXICON_DEBOUNCE_HOOK, KeepWarm::VERSIONED_REFILL_HOOK ];
		$blocked = [];
		$rows = 0;
		$due = 0;
		foreach ( self::scheduled_tasks() as $event ) {
			if ( ! in_array( $event['hook'], $hooks, true ) ) {
				continue;
			}
			if ( $event['at'] <= time() ) {
				if ( ! QueueExecution::expired() && wp_unschedule_event( $event['at'], $event['hook'], $event['args'] ) ) {
					if ( KeepWarm::VERSIONED_REFILL_HOOK === $event['hook'] ) {
						self::defer_task( $event['hook'], $event['args'] );
					} else {
						do_action( $event['hook'], ...$event['args'] );
					}
				}
			} else {
				$due = $due ? min( $due, $event['at'] ) : $event['at'];
				$languages = [ '*' ];
				if ( KeepWarm::SETTINGS_DEBOUNCE_HOOK === $event['hook'] ) {
					$languages = get_option( KeepWarm::PENDING_LANGUAGES_OPTION, [] );
				} elseif ( KeepWarm::LEXICON_DEBOUNCE_HOOK === $event['hook'] ) {
					$languages = get_option( KeepWarm::LEXICON_LANGUAGES, [ '*' ] );
				}
				$blocked = array_merge( $blocked, (array) $languages );
			}
		}
		while ( ! QueueExecution::expired() && self::run_deferred_task( [ KeepWarm::SETTINGS_DEBOUNCE_HOOK, KeepWarm::LEXICON_DEBOUNCE_HOOK ] ) ) {
			continue;
		}
		return [
			'languages' => array_values( array_unique( $blocked ) ),
			'due' => $due,
			'rows' => $rows,
		];
	}

	private static function apply_inputs( array $inputs ): array {
		while ( ! QueueExecution::expired() && self::run_deferred_task( [ KeepWarm::VERSIONED_REFILL_HOOK ] ) ) {
			continue;
		}
		( new LexiconChanges( '', '', false ) )->seed();
		$settings = get_option( KeepWarm::REBUILD_CURSOR, false );
		if ( false !== $settings ) {
			$inputs['rows'] += QueueExecution::run_input_turn(
				static function () use ( $settings ): int {
					$marked = 0;
					$indexer = new Indexer( '', '', null, null, false );
					while ( false !== $settings && QueueExecution::budget()->remaining_seconds() > 0.3 * QueueExecution::STEP_SECONDS ) {
						$marked += $indexer->resume_settings_refill( $settings );
						$next = get_option( KeepWarm::REBUILD_CURSOR, false );
						if ( $next === $settings ) {
							break;
						}
						$settings = $next;
					}
					return $marked;
				}
			);
			if ( false !== get_option( KeepWarm::REBUILD_CURSOR, false ) ) {
				$inputs['languages'][] = '*';
			}
		}
		$rows = $inputs['rows'];
		$due = $inputs['due'];
		$blocked = $inputs['languages'];
		foreach ( [ LexiconChanges::SEED_CURSOR_OPTION, IndexQueue::DEFERRED_REFILLS_OPTION ] as $option ) {
			while ( ! QueueExecution::expired() && false !== ( $before = get_option( $option, false ) ) ) {
				if ( LexiconChanges::SEED_CURSOR_OPTION === $option ) {
					LexiconChanges::run_seed_batch();
				} else {
					( new IndexQueue() )->resume_deferred_refills();
				}
				if ( get_option( $option, false ) === $before ) {
					return [
						'languages' => [ '*' ],
						'due' => time() + 15,
						'rows' => $rows,
					];
				}
			}
		}
		return [
			'languages' => array_values( array_unique( $blocked ) ),
			'due' => $due,
			'rows' => $rows,
		];
	}

	public static function run_deferred_task( ?array $only_hooks = null ): bool {
		$tasks = (array) get_option( self::TASKS_OPTION, [] );
		if ( null !== $only_hooks ) {
			$tasks = array_filter( $tasks, static fn( array $task ): bool => in_array( $task['hook'], $only_hooks, true ) );
		}
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
			&& ( ! empty( get_option( self::STATE_OPTION, [] )['stalled'] ) || ( $last <= time() - self::STALLED_SECONDS && array_sum( self::pending() ) > 0 ) );
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
		$pending = self::work_state()['pending'];
		$pending['refill'] += (int) (bool) get_option( Indexer::UNBATCHED_JOB_OPTION, false ) + (int) ( false !== get_option( IndexQueue::NORMALIZE_CURSOR_OPTION, false ) )
			+ (int) ( false !== get_option( IndexQueue::DEFERRED_REFILLS_OPTION, false ) )
			+ (int) ( false !== get_option( LexiconChanges::SEED_CURSOR_OPTION, false ) )
			+ (int) ( ! get_option( LexiconChanges::SEEDED_OPTION, false ) )
			+ (int) ( false !== get_option( KeepWarm::REBUILD_CURSOR, false ) )
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

	public static function work_state(): array {
		if ( null !== self::$step_work ) {
			return self::$step_work;
		}
		wp_cache_delete( self::REVISION_OPTION, 'options' );
		wp_cache_delete( self::WORK_OPTION, 'options' );
		$revision = get_option( self::REVISION_OPTION, '' );
		$state = get_option( self::WORK_OPTION, [] );
		if ( is_array( $state ) && isset( $state['pending'] ) && ( $state['revision'] ?? null ) === $revision ) {
			if ( QueueExecution::in_step() ) {
				self::$step_work = $state;
			}
			return $state;
		}
		$locked = MutationLock::run(
            'queue_rows',
            static function () use ( &$state ): void {
				wp_cache_delete( self::REVISION_OPTION, 'options' );
				$started = microtime( true );
				$state = ( new IndexQueue() )->engine_aggregate();
				$state['aggregate_seconds'] = microtime( true ) - $started;
				$state['revision'] = get_option( self::REVISION_OPTION, '' );
				update_option( self::WORK_OPTION, $state, false );
			}
        );
		if ( $locked && QueueExecution::in_step() ) {
			self::$step_work = $state;
		}
		return $locked ? $state : [
			'pending' => [
				'read' => 0,
				'push' => 1,
				'refill' => 0,
				'visitor' => 0,
			],
			'next' => time(),
		];
	}
}
