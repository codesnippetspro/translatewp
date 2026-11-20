<?php

namespace TranslateWP\Components;

class QueueExecution {

	public const STEP_SECONDS = 8.0;
	private const MAX_STATUS_CALLS = 20;
	private const MIN_HTTP_SECONDS = 0.01;
	private static bool $active = false;
	private static ?int $query_start = null;
	private const QUERY_WORK_LIMIT = 240;

	public static function begin_step(): void {
		global $wpdb;
		self::$query_start = (int) ( $wpdb->num_queries ?? 0 );
	}

	public static function in_step(): bool {
		return null !== self::$query_start;
	}

	public static function end_step(): void {
		self::$query_start = null;
	}

	public static function run_input_turn( callable $work ) {
		global $wpdb;
		$before = (int) ( $wpdb->num_queries ?? 0 );
		try {
			return $work();
		} finally {
			if ( null !== self::$query_start ) {
				self::$query_start += (int) ( $wpdb->num_queries ?? 0 ) - $before;
			}
		}
	}

	public static function query_budget_spent(): bool {
		global $wpdb;
		return null !== self::$query_start && (int) ( $wpdb->num_queries ?? 0 ) - self::$query_start >= self::QUERY_WORK_LIMIT;
	}
	private static int $remote = 0;

	public static function reset_trace(): void {
		self::$remote = 0;
	}

	public static function trace_remote(): int {
		return self::$remote;
	}
	private static ?ReadinessBudget $budget = null;

	public static function run( callable $work, float $seconds = self::STEP_SECONDS ): bool {
		if ( BackgroundSwitch::off() || self::expired() ) {
			return false;
		}
		if ( self::$active ) {
			$parent = self::$budget;
			self::$budget = new ReadinessBudget( min( $seconds, $parent->remaining_seconds() ) );
			try {
				$work();
				return true;
			} finally {
				self::$budget = $parent;
			}
		}
		return MutationLock::run(
			'index_drain',
			static function () use ( $work, $seconds ): void {
				if ( BackgroundSwitch::off() ) {
					return;
				}
				self::$active = true;
				self::$budget = new ReadinessBudget( min( self::STEP_SECONDS, max( self::MIN_HTTP_SECONDS, $seconds ) ) );
				self::$budget->set_status_limit( self::MAX_STATUS_CALLS );
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
		if ( defined( 'TRANSLATEWP_ENGINE_TRACE' ) ) {
			++self::$remote;
		}
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
		return null !== self::$budget && ( self::query_budget_spent() || self::$budget->remaining_seconds() <= 0 );
	}

	public static function is_budget_error( \WP_Error $error ): bool {
		return self::expired() || 'queue_budget_exhausted' === $error->get_error_code()
			|| ( self::is_bounded() && ( false !== stripos( $error->get_error_message(), 'timed out' ) || false !== strpos( $error->get_error_message(), 'cURL error 28' ) ) );
	}

	public static function is_bounded(): bool {
		return null !== self::$budget;
	}
}
