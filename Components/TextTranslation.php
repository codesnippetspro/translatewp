<?php

namespace TranslateWP\Components;

/**
 * Text the server translates itself, outside any page a browser opens: an
 * email, for one. Each text is fingerprinted the way the service caches it
 * and looked up there; what the cache does not hold yet is sent in one
 * translation job, and a later lookup finds it.
 */
class TextTranslation {

	public const MARKER = '⟪item_0001⟫';

	private const LOOKUP_BATCH_SIZE    = 100;
	private const POST_TIMEOUT_SECONDS = 30;

	/**
	 * The translations the service holds for the texts.
	 *
	 * @param string[]      $texts  Source texts.
	 * @param string        $locale The translation language.
	 * @param callable|null $lookup fn( string $locale, string[] $hashes ): array|\WP_Error, the service's cache by default.
	 * @return array{map: array<string,string>, missing: string[]}|\WP_Error
	 */
	public static function lookup( array $texts, string $locale, ?callable $lookup = null ) {
		$lookup  = $lookup ?? [ TranslationStore::class, 'remote_lookup_result' ];
		$sources = self::fingerprint( $texts, $locale );
		$map     = [];
		$missing = [];

		foreach ( array_chunk( array_keys( $sources ), self::LOOKUP_BATCH_SIZE ) as $hashes ) {
			$found = call_user_func( $lookup, $locale, $hashes );
			if ( ! is_array( $found ) ) {
				return is_wp_error( $found ) ? $found : new \WP_Error( 'translatewp_lookup_failed', 'The translation lookup failed.' );
			}

			foreach ( $hashes as $hash ) {
				$entries    = TranslationMarkerPayload::parse( is_string( $found[ $hash ] ?? null ) ? $found[ $hash ] : '' );
				$translated = $entries ? trim( (string) reset( $entries ) ) : '';
				if ( '' === $translated ) {
					$missing[] = $sources[ $hash ];
					continue;
				}
				$map[ $sources[ $hash ] ] = $translated;
			}
		}

		return [
			'map'     => $map,
			'missing' => $missing,
		];
	}

	/**
	 * Sends the texts for translation. The translations land in the service's
	 * cache, where a later lookup finds them.
	 *
	 * @param string[]      $texts  Source texts.
	 * @param string        $locale The translation language.
	 * @param callable|null $post   fn( string $path, array $body, int $timeout ): array|\WP_Error, the service by default.
	 * @return true|\WP_Error
	 */
	public static function request( array $texts, string $locale, ?callable $post = null ) {
		$sources = self::fingerprint( $texts, $locale );
		if ( ! $sources ) {
			return true;
		}

		$post        = $post ?? [ TranslationProRetry::class, 'post' ];
		$ai_settings = apply_filters( 'translatewp_translation_ai_settings', TranslationProxySettings::ai_settings( $locale ) );
		$context     = TranslationContext::build();

		$job = call_user_func(
			$post,
			'/v1/translation/jobs',
			apply_filters(
				'translatewp_translation_job_request_body',
				[
					'sourceLang' => TranslationProxySettings::default_locale(),
					'targetLang' => $locale,
					'context'    => $context,
					'aiSettings' => $ai_settings,
				]
			),
			self::POST_TIMEOUT_SECONDS
		);
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$job_id = sanitize_text_field( (string) ( $job['jobId'] ?? '' ) );
		if ( '' === $job_id ) {
			return new \WP_Error( 'translatewp_job_create_failed', 'The translation job could not be created.' );
		}

		$site_id = Sites::registered_id();
		$items   = [];
		foreach ( $sources as $hash => $text ) {
			$items[] = [
				'groupId'       => 'text:' . substr( $hash, 0, 16 ),
				'markerPayload' => self::MARKER . $text,
				'contextKind'   => TranslationFingerprint::CONTEXT_KIND_TEXT_FLOW,
				'cache'         => [
					'siteId' => $site_id,
					'hash'   => $hash,
				],
			];
		}

		$pushed = call_user_func(
			$post,
			'/v1/translation/jobs/' . rawurlencode( $job_id ) . '/batches',
			apply_filters(
				'translatewp_translation_batch_request_body',
				[
					'batchId'    => 'text-' . substr( (string) array_key_first( $sources ), 0, 16 ),
					'items'      => $items,
					'closeInput' => true,
					'context'    => $context,
					'aiSettings' => $ai_settings,
				],
				$job_id,
				$locale
			),
			self::POST_TIMEOUT_SECONDS,
			$locale
		);

		return is_wp_error( $pushed ) ? $pushed : true;
	}

	/**
	 * Each distinct, non-empty text under the fingerprint the service caches
	 * it by for the language.
	 *
	 * @return array<string,string> Fingerprint => source text.
	 */
	private static function fingerprint( array $texts, string $locale ): array {
		$source_lang = TranslationProxySettings::default_locale();
		$ai_settings = TranslationProxySettings::ai_settings( $locale );
		$sources     = [];

		foreach ( $texts as $text ) {
			$text = trim( (string) $text );
			if ( '' === $text ) {
				continue;
			}
			$hash             = TranslationFingerprint::compute_hash( self::MARKER . $text, $source_lang, $locale, TranslationFingerprint::CONTEXT_KIND_TEXT_FLOW, $ai_settings );
			$sources[ $hash ] = $text;
		}

		return $sources;
	}
}
