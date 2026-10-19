<?php

namespace TranslateWP\Components;

/**
 * A short, rotating record of what the plugin did on this site, for support.
 * Entries never carry request bodies, tokens or visitor data.
 */
class ActionLog {

	public const OPTION = 'translatewp_action_log';
	public const LIMIT  = 100;

	public static function record( string $message, string $level = 'info' ): void {
		// uninstall.php runs without the formatting functions; nothing is worth recording there.
		if ( ! function_exists( 'sanitize_text_field' ) ) {
			return;
		}
		$entries   = self::entries();
		$entries[] = [
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'level'   => in_array( $level, [ 'info', 'warning', 'error' ], true ) ? $level : 'info',
			'message' => substr( sanitize_text_field( $message ), 0, 300 ),
		];
		if ( count( $entries ) > self::LIMIT ) {
			$entries = array_slice( $entries, -self::LIMIT );
		}
		update_option( self::OPTION, $entries, false );
	}

	public static function entries(): array {
		$stored = get_option( self::OPTION, [] );
		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : [];
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	public static function text(): string {
		$lines = [];
		foreach ( self::entries() as $entry ) {
			$lines[] = sprintf( '%s [%s] %s', $entry['time'] ?? '', strtoupper( (string) ( $entry['level'] ?? 'info' ) ), $entry['message'] ?? '' );
		}
		return implode( "\n", $lines );
	}
}
