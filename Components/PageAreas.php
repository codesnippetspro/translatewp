<?php

namespace TranslateWP\Components;

/**
 * The page areas a site can leave untranslated: comments, menus and widgets.
 * Each is a boolean of translations.settings, on unless switched off; the
 * visitor runtime and the indexer skip the elements of an area switched off.
 */
class PageAreas {

	public const AREAS = [ 'comments', 'menus', 'widgets' ];

	public static function setting_key( string $area ): string {
		return 'translate_' . $area;
	}

	/** The areas switched off in the given translation settings, in a fixed order; a missing key means on. */
	public static function excluded( array $translation_settings ): array {
		$excluded = [];
		foreach ( self::AREAS as $area ) {
			$setting = $translation_settings[ self::setting_key( $area ) ] ?? true;
			if ( ! filter_var( $setting, FILTER_VALIDATE_BOOLEAN ) ) {
				$excluded[] = $area;
			}
		}

		return $excluded;
	}
}
