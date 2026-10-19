<?php

namespace TranslateWP\Components;

/**
 * The pages whose visitors did not find the anchored switcher's element, so
 * the Dashboard can name them. A bounded, most-recent-first list of page
 * addresses the front end reports; a page is listed once.
 */
class AnchorMisses {

	public const OPTION = 'translatewp_anchor_misses';

	public const MAX_PAGES = 20;

	public static function record( string $url ): void {
		$url = TranslationUrl::canonicalize( esc_url_raw( $url ) );
		if ( '' === $url ) {
			return;
		}
		$list = self::pages();
		$list = array_values( array_filter( $list, static fn( $entry ) => ( $entry['url'] ?? '' ) !== $url ) );
		array_unshift(
			$list,
			[
				'url'  => $url,
				'seen' => time(),
			]
		);
		update_option( self::OPTION, array_slice( $list, 0, self::MAX_PAGES ), false );
	}

	/**
	 * The recorded pages, most recent first.
	 *
	 * @return array<int, array{url: string, seen: int}>
	 */
	public static function pages(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$pages = [];
		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['url'] ?? null ) && '' !== $entry['url'] ) {
				$pages[] = [
					'url'  => $entry['url'],
					'seen' => (int) ( $entry['seen'] ?? 0 ),
				];
			}
		}
		return $pages;
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
