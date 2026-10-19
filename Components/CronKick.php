<?php

namespace TranslateWP\Components;

/**
 * Cron-stall resilience (P8.3): on hosts whose wp-cron loopback is broken
 * (302/auth redirects), spawned one-shot events queue forever. Admin status
 * routes — where an operator is already waiting — run an overdue handler
 * inline instead, bounded to once per request and guarded by a transient
 * lock against concurrent double-runs. Never wired into visitor routes.
 */
class CronKick {

	private const OVERDUE_SECONDS = 60;
	private const LOCK_TTL_SECONDS = 30;

	public static function maybe_run_due( string $hook, callable $handler ): bool {
		$scheduled = wp_next_scheduled( $hook );
		if ( false === $scheduled ) {
			return false;
		}

		if ( (int) $scheduled > time() - self::OVERDUE_SECONDS ) {
			return false;
		}

		$lock_key = 'translatewp_cron_kick_' . md5( $hook );
		if ( false !== get_transient( $lock_key ) ) {
			return false;
		}
		set_transient( $lock_key, 1, self::LOCK_TTL_SECONDS );

		wp_unschedule_event( (int) $scheduled, $hook );
		$handler();

		return true;
	}
}
