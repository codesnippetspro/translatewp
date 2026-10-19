<?php

namespace TranslateWP\Components;

class TranslationProxySettings {

	public static function ai_settings( string $language = '' ): array {
		return TranslationAiSettings::build( $language );
	}

	public static function active_lookup_languages(): array {
		$configured = Options::get( 'languages.list', [] );
		$languages  = [];

		if ( ! is_array( $configured ) ) {
			return [];
		}

		foreach ( $configured as $language ) {
			if ( ! is_array( $language ) || empty( $language['active'] ) ) {
				continue;
			}

			$locale = isset( $language['locale'] ) ? trim( (string) $language['locale'] ) : '';
			if ( '' === $locale ) {
				continue;
			}

			$languages[] = $locale;
		}

		sort( $languages );

		return array_values( array_unique( $languages ) );
	}

	public static function configured_locales(): array {
		return self::active_lookup_languages();
	}

	public static function default_locale(): string {
		$configured = Options::get( 'languages.list', [] );
		if ( ! is_array( $configured ) ) {
			return 'auto';
		}

		$first_active = '';
		foreach ( $configured as $language ) {
			if ( ! is_array( $language ) || empty( $language['active'] ) ) {
				continue;
			}

			$locale = isset( $language['locale'] ) ? trim( (string) $language['locale'] ) : '';
			if ( '' === $locale ) {
				continue;
			}

			if ( ! empty( $language['default'] ) ) {
				return $locale;
			}

			if ( '' === $first_active ) {
				$first_active = $locale;
			}
		}

		return '' !== $first_active ? $first_active : 'auto';
	}

	public static function is_configured_language( string $language ): bool {
		$language = trim( $language );
		if ( '' === $language ) {
			return false;
		}

		return in_array( $language, self::active_lookup_languages(), true );
	}

	/**
	 * A language the site translates into: configured and active, and not the
	 * site's own language, which is the source and never a target.
	 */
	public static function is_target_language( string $language ): bool {
		$language = trim( $language );
		return self::is_configured_language( $language ) && self::default_locale() !== $language;
	}

	public static function target_languages(): array {
		return array_values(
			array_filter(
				self::active_lookup_languages(),
				static fn ( string $language ): bool => self::is_target_language( $language )
			)
		);
	}
}
