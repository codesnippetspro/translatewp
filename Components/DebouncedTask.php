<?php

namespace TranslateWP\Components;

class DebouncedTask {

	public const DEBOUNCE_SECONDS = 120;

	public static function schedule( string $hook, array $args = [] ): void {
		$timestamp = wp_next_scheduled( $hook, $args );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook, $args );
			$timestamp = wp_next_scheduled( $hook, $args );
		}

		wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, $hook, $args );
	}
}
