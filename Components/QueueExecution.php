<?php

namespace TranslateWP\Components;

class QueueExecution {

	public const STEP_SECONDS = 8.0;
	private const MIN_HTTP_SECONDS = 0.01;
	private static bool $active = false;
	private static ?ReadinessBudget $budget = null;

	public static function run( callable $work ): bool {
		if ( BackgroundSwitch::off() || self::expired() ) {
			return false;
		}
		if ( self::$active ) {
			$work();
			return true;
		}
		return MutationLock::run(
			'index_drain',
			static function () use ( $work ): void {
				if ( BackgroundSwitch::off() ) {
					return;
				}
				self::$active = true;
				self::$budget = new ReadinessBudget( self::STEP_SECONDS );
				add_filter( 'http_request_args', [ self::class, 'bound_http_args' ], PHP_INT_MAX, 1 );
				add_filter( 'pre_http_request', [ self::class, 'stop_expired_request' ], PHP_INT_MAX, 1 );
				try {
					$work();
				} finally {
					remove_filter( 'http_request_args', [ self::class, 'bound_http_args' ], PHP_INT_MAX );
					remove_filter( 'pre_http_request', [ self::class, 'stop_expired_request' ], PHP_INT_MAX );
					self::$budget = null;
					self::$active = false;
				}
			},
			0
		);
	}

	public static function bound_http_args( array $args ): array {
		if ( null !== self::$budget ) {
			$args['timeout'] = max( self::MIN_HTTP_SECONDS, min( (float) ( $args['timeout'] ?? self::STEP_SECONDS ), self::$budget->remaining_seconds() ) );
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
			|| ( self::is_bounded() && ( false !== stripos( $error->get_error_message(), 'timed out' ) || false !== strpos( $error->get_error_message(), 'cURL error 28' ) ) );
	}

	public static function is_bounded(): bool {
		return null !== self::$budget;
	}
}
