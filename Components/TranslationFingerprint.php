<?php

namespace TranslateWP\Components;

class TranslationFingerprint {

	// group-v4 keys the fingerprint on the model profile only: the settings
	// that change what the model writes. Lexicon terms and the localizer
	// toggles ride to the service as instructions but never enter the hash,
	// so editing a term or a date format leaves every cached segment valid
	// until the site owner retranslates.
	public const SCHEMA_VERSION = 'group-v4';

	/**
	 * The settings keys that shape the model output and therefore the hash.
	 * Everything else in the AI settings is versioned instead.
	 */
	public const MODEL_SETTING_KEYS = [
		'context',
		'writing_style',
		'tone',
		'formality',
		'creativity',
		'preserve_branded_terms',
	];

	public const CONTEXT_KIND_TEXT_FLOW = 'text-flow';
	public const CONTEXT_KIND_ALT       = 'alt';
	public const CONTEXT_KIND_ATTRIBUTE = 'attribute';

	private const JSON_ENCODE_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

	public static function allowed_context_kinds(): array {
		return [
			self::CONTEXT_KIND_TEXT_FLOW,
			self::CONTEXT_KIND_ALT,
			self::CONTEXT_KIND_ATTRIBUTE,
		];
	}

	public static function is_allowed_context_kind( string $context_kind ): bool {
		return in_array( $context_kind, self::allowed_context_kinds(), true );
	}

	public static function compute_hash(
		string $marker_payload,
		string $source_lang,
		string $target_lang,
		string $context_kind,
		array $ai_settings
	): string {
		$canonical = self::canonical_string(
			$marker_payload,
			$source_lang,
			$target_lang,
			$context_kind,
			$ai_settings
		);

		return hash( 'sha256', $canonical );
	}

	public static function verify_hash(
		string $submitted_hash,
		string $marker_payload,
		string $source_lang,
		string $target_lang,
		string $context_kind,
		array $ai_settings
	): bool {
		$computed = self::compute_hash(
			$marker_payload,
			$source_lang,
			$target_lang,
			$context_kind,
			$ai_settings
		);

		return hash_equals( $computed, $submitted_hash );
	}

	public static function canonical_string(
		string $marker_payload,
		string $source_lang,
		string $target_lang,
		string $context_kind,
		array $ai_settings
	): string {
		$payload = [
			'schemaVersion' => self::SCHEMA_VERSION,
			'markerPayload' => $marker_payload,
			'sourceLang'    => $source_lang,
			'targetLang'    => $target_lang,
			'contextKind'   => $context_kind,
			'settings'      => self::model_profile( $ai_settings ),
		];

		return self::stable_stringify( $payload );
	}

	/**
	 * The subset of the AI settings the fingerprint is keyed on, in the order
	 * the keys were given (the canonical form sorts them anyway).
	 */
	public static function model_profile( array $ai_settings ): array {
		$profile = [];
		foreach ( self::MODEL_SETTING_KEYS as $key ) {
			if ( array_key_exists( $key, $ai_settings ) ) {
				$profile[ $key ] = $ai_settings[ $key ];
			}
		}

		return $profile;
	}

	public static function stable_stringify( $value ): string {
		if ( is_array( $value ) ) {
			if ( self::is_list_array( $value ) ) {
				$items = array_map(
					static function ( $item ): string {
						return self::stable_stringify( $item );
					},
					$value
				);

				return '[' . implode( ',', $items ) . ']';
			}

			$keys = array_keys( $value );
			sort( $keys, SORT_STRING );

			$entries = [];
			foreach ( $keys as $key ) {
				$entries[] = json_encode( (string) $key, self::JSON_ENCODE_OPTIONS ) . ':' . self::stable_stringify( $value[ $key ] );
			}

			return '{' . implode( ',', $entries ) . '}';
		}

		return json_encode( $value, self::JSON_ENCODE_OPTIONS );
	}

	private static function is_list_array( array $value ): bool {
		if ( [] === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
