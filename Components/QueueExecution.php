<?php

namespace TranslateWP\Components;

class QueueExecution {

	public const LAST_REDUCED_OPTION = 'translatewp_reduced_queue_last_run';
	public const PHASE_OPTION = 'translatewp_reduced_queue_phase';
	public const READ_HOOK = 'translatewp_reduced_queue_read';
	public const PUSH_HOOK = 'translatewp_reduced_queue_push';
	public const READINESS_HOOK = 'translatewp_reduced_queue_readiness';
	public const STORE_HOOK = 'translatewp_reduced_queue_store';
	public const REDUCED_SECONDS = 2.5;
	public const INTERVAL_SECONDS = 60;
	private const MIN_HTTP_SECONDS = 0.01;
	private const PHASES = [ self::READ_HOOK, self::PUSH_HOOK, self::READINESS_HOOK, self::STORE_HOOK ];

	private static bool $active = false;
	private static ?ReadinessBudget $budget = null;

	public static function run( callable $work ): bool {
		if ( self::$active ) {
			$work();
			return true;
		}
		return MutationLock::run(
			'index_drain',
			static function () use ( $work ): void {
				self::$active = true;
				try {
					$work();
				} finally {
					self::$active = false;
				}
			},
			0
		);
	}

	public static function run_reduced(): bool {
		return self::run(
			static function (): void {
				if ( LoopbackKick::healthy() ) {
					return;
				}
				if ( (int) get_option( self::LAST_REDUCED_OPTION, 0 ) > time() - self::INTERVAL_SECONDS ) {
					return;
				}
				update_option( self::LAST_REDUCED_OPTION, time(), false );
				$phase = max( 0, (int) get_option( self::PHASE_OPTION, 0 ) ) % count( self::PHASES );
				update_option( self::PHASE_OPTION, ( $phase + 1 ) % count( self::PHASES ), false );
				self::$budget = new ReadinessBudget( self::REDUCED_SECONDS );
				add_filter( 'http_request_args', [ self::class, 'bound_http_args' ], PHP_INT_MAX, 1 );
				add_filter( 'pre_http_request', [ self::class, 'stop_expired_request' ], PHP_INT_MAX, 1 );
				try {
					do_action( self::PHASES[ $phase ], self::$budget );
				} finally {
					remove_filter( 'http_request_args', [ self::class, 'bound_http_args' ], PHP_INT_MAX );
					remove_filter( 'pre_http_request', [ self::class, 'stop_expired_request' ], PHP_INT_MAX );
					self::$budget = null;
				}
			}
		);
	}

	public static function bound_http_args( array $args ): array {
		if ( null !== self::$budget ) {
			$args['timeout'] = max( self::MIN_HTTP_SECONDS, min( (float) ( $args['timeout'] ?? self::REDUCED_SECONDS ), self::$budget->remaining_seconds() ) );
		}
		return $args;
	}

	public static function stop_expired_request( $response ) {
		return self::expired()
			? new \WP_Error( 'queue_budget_exhausted', 'The background work time window ended.' )
			: $response;
	}

	public static function budget(): ?ReadinessBudget {
		return self::$budget;
	}

	public static function expired(): bool {
		return null !== self::$budget && self::$budget->remaining_seconds() <= 0;
	}

	public static function is_budget_error( \WP_Error $error ): bool {
		return self::expired() || 'queue_budget_exhausted' === $error->get_error_code()
			|| ( self::is_reduced() && ( false !== stripos( $error->get_error_message(), 'timed out' ) || false !== strpos( $error->get_error_message(), 'cURL error 28' ) ) );
	}

	public static function is_reduced(): bool {
		return null !== self::$budget;
	}
}
