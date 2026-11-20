<?php

namespace TranslateWP\Components;

class CrawlerRefillBudget {

	public const HOURLY_LIMIT = 30;
	private const LOCK_OPTION = 'translatewp_crawler_refill_lock';
	private const COUNTER_TRANSIENT = 'translatewp_crawler_refill_count';
	private const LOCK_SECONDS = 30;

	public static function admit(): bool {
		$now = time();
		if ( ! add_option( self::LOCK_OPTION, $now, '', false ) ) {
			$locked_at = (int) get_option( self::LOCK_OPTION );
			if ( $locked_at > 0 && $locked_at < $now - self::LOCK_SECONDS ) {
				global $wpdb;
				$wpdb->delete(
                    $wpdb->options,
                    [
						'option_name' => self::LOCK_OPTION,
						'option_value' => (string) $locked_at,
					],
                    [ '%s', '%s' ]
                );
				wp_cache_delete( self::LOCK_OPTION, 'options' );
			}
			return false;
		}
		try {
			$counter = get_transient( self::COUNTER_TRANSIENT );
			if ( ! is_array( $counter ) || (int) $counter['until'] <= $now ) {
				$counter = [
					'count' => 0,
					'until' => $now + HOUR_IN_SECONDS,
				];
			}
			if ( (int) $counter['count'] >= self::HOURLY_LIMIT ) {
				return false;
			}
			$counter['count']++;
			set_transient( self::COUNTER_TRANSIENT, $counter, max( 1, (int) $counter['until'] - $now ) );
			return true;
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}
}
