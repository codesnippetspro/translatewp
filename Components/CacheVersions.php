<?php

namespace TranslateWP\Components;

class CacheVersions {

	private const SETTINGS_PATH = 'cache.settings_version';
	private const LEXICON_PATH  = 'cache.lexicon_version';
	private const LOCALIZER_PATH = 'cache.localizer_version';

	public static function settings_version(): int {
		return max( 0, (int) Options::get( self::SETTINGS_PATH, 0 ) );
	}

	public static function lexicon_version(): int {
		return max( 0, (int) Options::get( self::LEXICON_PATH, 0 ) );
	}

	public static function bump_settings_version(): int {
		$next = self::settings_version() + 1;
		Options::update( self::SETTINGS_PATH, $next );

		return $next;
	}

	public static function bump_lexicon_version(): int {
		$next = self::lexicon_version() + 1;
		Options::update( self::LEXICON_PATH, $next );

		return $next;
	}

	/**
	 * The localizer version: date, time and unit formatting toggles. Bumped on
	 * save; stored translations made under an older version are reported as
	 * waiting for a retranslation instead of being invalidated.
	 */
	public static function localizer_version(): int {
		return max( 0, (int) Options::get( self::LOCALIZER_PATH, 0 ) );
	}

	public static function bump_localizer_version(): int {
		$next = self::localizer_version() + 1;
		Options::update( self::LOCALIZER_PATH, $next );

		return $next;
	}
}
