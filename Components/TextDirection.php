<?php

namespace TranslateWP\Components;

/**
 * Writing direction of a locale, by primary language subtag.
 */
class TextDirection {

	private const RTL_PRIMARY_SUBTAGS = [ 'ar', 'he', 'iw', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv', 'ckb', 'prs', 'ks' ];

	public static function is_rtl( string $locale ): bool {
		$primary = strtolower( (string) preg_split( '/[-_]/', $locale )[0] );

		return in_array( $primary, self::RTL_PRIMARY_SUBTAGS, true );
	}
}
