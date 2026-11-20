<?php

namespace TranslateWP\Components;

class CacheVersions {

	private const SETTINGS_PATH = 'cache.settings_version';
	private const LEXICON_PATH  = 'cache.lexicon_version';
	private const LOCALIZER_PATH = 'cache.localizer_version';

	public static function cache_reference( string $site_id, string $hash, string $language = '' ): array {
		return [
			'siteId' => $site_id,
			'hash' => $hash,
			'settingsVersion' => self::settings_version(),
			'lexiconVersion' => self::lexicon_version_for( $language ),
			'lexiconChangedAt' => self::lexicon_changed_at( $language ),
			'settingsChangedAt' => max( 0, (int) Options::get( 'cache.localizer_changed_at', 0 ) ),
		];
	}

	public static function lexicon_version_for( string $language ): int {
		$versions = Options::get( 'cache.lexicon_language_versions', [] );
		return max( 0, (int) ( $versions[ $language ] ?? Options::get( 'cache.lexicon_base_version', self::lexicon_version() ) ) );
	}

	public static function lexicon_changed_at( string $language ): int {
		$times = Options::get( 'cache.lexicon_changed_at', [] );
		return max( 0, (int) ( $times[ $language ] ?? 0 ) );
	}

	public static function job_hint( string $language ): array {
		return [
			'settingsVersion' => self::settings_version(),
			'lexiconVersion' => self::lexicon_version_for( $language ),
		];
	}

	public static function adopt_floor( array $floor, string $language ): bool {
		foreach ( [ 'settingsVersion', 'lexiconVersion' ] as $key ) {
			if ( ! isset( $floor[ $key ] ) || ! is_int( $floor[ $key ] ) || $floor[ $key ] < 0 ) {
				return false;
			}
		}
		return MutationLock::run(
            'cache_versions',
            static function () use ( $floor, $language ): void {
				Options::update( self::SETTINGS_PATH, max( self::settings_version(), $floor['settingsVersion'] ) );
				$versions = Options::get( 'cache.lexicon_language_versions', [] );
				$versions = is_array( $versions ) ? $versions : [];
				$versions[ $language ] = max( self::lexicon_version_for( $language ), $floor['lexiconVersion'] );
				Options::update( 'cache.lexicon_language_versions', $versions );
				ActionLog::record( 'Translation cache revisions resynchronized with the service' );
			}
        );
	}

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
