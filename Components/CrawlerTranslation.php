<?php

namespace TranslateWP\Components;

/**
 * The complete translation of one page, for a crawler. The segments the site
 * last read from the page are fingerprinted for the language and looked up
 * in the service's cache; the map exists only when the cache holds every
 * one of them. Nothing is translated here: a segment the cache does not hold
 * yet is left to the normal indexing, and the caller serves the original.
 */
class CrawlerTranslation {

	// The service takes at most this many fingerprints in one lookup.
	private const LOOKUP_BATCH_SIZE = 100;

	// A complete answer is reused this long; an incomplete one only briefly,
	// so a burst of crawler requests does not spend the lookup rate limit.
	private const COMPLETE_TTL   = 600;
	private const INCOMPLETE_TTL = 60;

	private const CACHE_PREFIX = 'twp_crawler_map_';

	/**
	 * The page's source text => translated text map, [] unless complete.
	 *
	 * @param int           $post_id The page; 0 for the posts index home page.
	 * @param string        $locale  The translation language.
	 * @param callable|null $lookup  fn( string $locale, string[] $hashes ): array|\WP_Error, the service's cache by default.
	 */
	public static function map( int $post_id, string $locale, ?callable $lookup = null ): array {
		$cached = get_transient( self::cache_key( $post_id, $locale ) );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map = self::build( $post_id, $locale, $lookup ?? [ TranslationStore::class, 'remote_lookup_result' ] );
		set_transient( self::cache_key( $post_id, $locale ), $map, $map ? self::COMPLETE_TTL : self::INCOMPLETE_TTL );

		return $map;
	}

	public static function forget( int $post_id, string $locale ): void {
		delete_transient( self::cache_key( $post_id, $locale ) );
	}

	private static function build( int $post_id, string $locale, callable $lookup ): array {
		$object_type = $post_id > 0
			? (string) get_post_type( $post_id )
			: ( \TranslateWP\Functionality\Indexer::NOT_FOUND_OBJECT_ID === $post_id ? \TranslateWP\Functionality\Indexer::NOT_FOUND_OBJECT_TYPE : 'home' );
		$items       = ( new IndexQueue() )->read_items( $object_type, $post_id );
		if ( ! $items ) {
			return [];
		}

		$source_lang = TranslationProxySettings::default_locale();
		$ai_settings = TranslationProxySettings::ai_settings( $locale );
		$sources     = [];
		foreach ( $items as $item ) {
			$payload = (string) ( $item['markerPayload'] ?? '' );
			if ( '' === $payload ) {
				continue;
			}
			$context_kind     = (string) ( $item['contextKind'] ?? TranslationFingerprint::CONTEXT_KIND_TEXT_FLOW );
			$hash             = TranslationFingerprint::compute_hash( $payload, $source_lang, $locale, $context_kind, $ai_settings );
			$sources[ $hash ] = $payload;
		}

		$map = [];
		foreach ( array_chunk( array_keys( $sources ), self::LOOKUP_BATCH_SIZE ) as $hashes ) {
			$found = call_user_func( $lookup, $locale, $hashes );
			if ( ! is_array( $found ) ) {
				return [];
			}

			foreach ( $hashes as $hash ) {
				$translated = $found[ $hash ] ?? null;
				if ( ! is_string( $translated ) || '' === trim( $translated ) ) {
					return [];
				}

				$translated_entries = TranslationMarkerPayload::parse( $translated );
				foreach ( TranslationMarkerPayload::parse( $sources[ $hash ] ) as $id => $source_text ) {
					$source_text     = trim( $source_text );
					$translated_text = trim( (string) ( $translated_entries[ $id ] ?? '' ) );
					if ( '' === $source_text ) {
						continue;
					}
					if ( '' === $translated_text ) {
						return [];
					}
					$map[ $source_text ] = $translated_text;
				}
			}
		}

		return $map;
	}

	private static function cache_key( int $post_id, string $locale ): string {
		return self::CACHE_PREFIX . md5( $post_id . '|' . $locale . '|' . TranslationStore::cache_generation_for( $locale ) );
	}
}
