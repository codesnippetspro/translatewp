<?php

namespace TranslateWP\Components;

class LanguageSlugs {
	public static function valid_locale( string $locale ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.@-]*$/D', $locale );
	}

	public static function valid_slug( string $slug ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9._@-]*$/D', strtolower( $slug ) );
	}

	public static function address_refusal( array $languages ): ?\WP_Error {
		foreach ( $languages as $language ) {
			$locale = is_array( $language ) ? (string) ( $language['locale'] ?? '' ) : '';
			$stored_slug = is_array( $language ) ? (string) ( $language['slug'] ?? '' ) : '';
			$slug = '' !== $stored_slug ? $stored_slug : self::from_locale( $locale );
			if ( ! self::valid_locale( $locale ) || ! self::valid_slug( $slug ) ) {
				return new \WP_Error(
					'invalid_language_address',
					__( 'A language has an invalid code or address. Use letters, numbers, hyphens, underscores, periods or @.', 'translate' ),
					[ 'status' => 400, 'locale' => sanitize_text_field( $locale ) ]
				);
			}
		}

		return null;
	}

	public static function from_locale( string $locale ): string {
		return strtolower( str_replace( '_', '-', trim( $locale ) ) );
	}

	public static function base( string $locale ): string {
		return explode( '-', self::from_locale( $locale ), 2 )[0];
	}

	public static function for_new( string $locale, array $taken ): string {
		$base = self::base( $locale );

		return in_array( $base, $taken, true ) ? self::from_locale( $locale ) : $base;
	}

	private const USUAL_REGIONS = [
		'ar' => 'ar-sa',
		'da' => 'da-dk',
		'de' => 'de-de',
		'en' => 'en-us',
		'es' => 'es-es',
		'fr' => 'fr-fr',
		'it' => 'it-it',
		'ja' => 'ja-jp',
		'ko' => 'ko-kr',
		'nl' => 'nl-nl',
		'pl' => 'pl-pl',
		'pt' => 'pt-pt',
		'ru' => 'ru-ru',
		'sv' => 'sv-se',
		'zh' => 'zh-cn',
	];

	public static function for_setup( string $locale, array $taken ): string {
		$slug = self::for_new( $locale, $taken );

		return in_array( $slug, $taken, true ) ? self::suggest( $locale, $taken ) : $slug;
	}

	public static function suggest( string $locale, array $taken ): string {
		$full = self::from_locale( $locale );
		if ( ! in_array( $full, $taken, true ) ) {
			return $full;
		}

		$regional = self::base( $locale ) === $full ? ( self::USUAL_REGIONS[ $full ] ?? '' ) : '';
		if ( '' !== $regional && ! in_array( $regional, $taken, true ) ) {
			return $regional;
		}

		$number = 2;
		while ( in_array( $full . '-' . $number, $taken, true ) ) {
			++$number;
		}

		return $full . '-' . $number;
	}

	public static function clash( $languages, $previous = [] ): array {
		$languages = is_array( $languages ) ? array_values( array_filter( $languages, 'is_array' ) ) : [];
		$saved     = [];
		foreach ( is_array( $previous ) ? $previous : [] as $language ) {
			if ( is_array( $language ) ) {
				$saved[ (string) ( $language['locale'] ?? '' ) ] = (string) ( $language['slug'] ?? '' );
			}
		}

		$slug = self::duplicate( $languages );
		if ( '' === $slug ) {
			return [];
		}

		$last    = '';
		$refused = '';
		foreach ( $languages as $language ) {
			if ( (string) ( $language['slug'] ?? '' ) !== $slug ) {
				continue;
			}
			$last = (string) ( $language['locale'] ?? '' );
			if ( ( $saved[ $last ] ?? null ) !== $slug ) {
				$refused = $last;
			}
		}
		$refused = '' !== $refused ? $refused : $last;

		return [
			'slug'           => $slug,
			'locale'         => $refused,
			'suggested_slug' => self::suggest( $refused, array_map( static fn( $language ) => (string) ( $language['slug'] ?? '' ), $languages ) ),
		];
	}

	public static function refusal( array $clash ): \WP_Error {
		return new \WP_Error(
			'duplicate_language_slug',
			sprintf(
				/* translators: %s: the address slug two languages share, for example de */
				__( 'Two languages use the address slug "%s". Give each language its own slug, then save.', 'translate' ),
				$clash['slug']
			),
			[
				'status'         => 400,
				'locale'         => $clash['locale'],
				'suggested_slug' => $clash['suggested_slug'],
			]
		);
	}

	public static function duplicate( $languages ): string {
		$seen = [];
		foreach ( is_array( $languages ) ? $languages : [] as $language ) {
			$slug = is_array( $language ) ? (string) ( $language['slug'] ?? '' ) : '';
			if ( '' === $slug ) {
				continue;
			}
			if ( isset( $seen[ $slug ] ) ) {
				return $slug;
			}
			$seen[ $slug ] = true;
		}

		return '';
	}

	public static function repair( array $languages ): array {
		$groups = [];
		foreach ( $languages as $key => $language ) {
			if ( is_array( $language ) && '' !== (string) ( $language['slug'] ?? '' ) ) {
				$groups[ (string) $language['slug'] ][] = $key;
			}
		}

		foreach ( $groups as $slug => $keys ) {
			if ( count( $keys ) < 2 ) {
				continue;
			}

			$keeper = null;
			foreach ( $keys as $key ) {
				if ( ! empty( $languages[ $key ]['default'] ) ) {
					$keeper = $key;
				}
			}
			foreach ( $keys as $key ) {
				if ( null === $keeper && self::from_locale( (string) ( $languages[ $key ]['locale'] ?? '' ) ) === (string) $slug ) {
					$keeper = $key;
				}
			}

			foreach ( $keys as $key ) {
				if ( $key !== $keeper ) {
					$languages[ $key ]['slug'] = self::from_locale( (string) ( $languages[ $key ]['locale'] ?? '' ) );
				}
			}
		}

		return $languages;
	}

	public static function repair_stored(): void {
		$languages = Options::get( 'languages.list', [] );
		if ( ! is_array( $languages ) || '' === self::duplicate( $languages ) ) {
			return;
		}

		Options::update( 'languages.list', self::repair( $languages ) );
	}
}
