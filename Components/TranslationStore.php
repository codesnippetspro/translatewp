<?php

namespace TranslateWP\Components;

/**
 * Server-side store of finished translations so server-rendered SEO surfaces
 * can read them per URL and per language. Core WordPress storage only: post
 * meta per post and locale for singular pages, one bounded option for non-post
 * URLs. The site records the source segments it pushed to the translation API
 * (as pending, keyed by group hash), then a back-fill resolves each hash to its
 * translated text through the API cache and writes the flat
 * source -> translated map the Pro seam reads.
 */
class TranslationStore {

	public const POST_META_KEY = '_twp_translation_store';
	public const URL_OPTION    = 'translatewp_translation_store_urls';
	public const WORK_OPTION   = 'translatewp_translation_store_work';

	/**
	 * Generation counter bumped whenever the operator forces a retranslation.
	 * The frontend namespaces its local hydration cache by this value so a
	 * retranslated page's stale browser copy is bypassed on the next load.
	 */
	public const CACHE_GENERATION_OPTION = 'translatewp_translation_cache_generation';

	// Per-language generations {locale: int}: a retranslation bumps only the
	// languages it touched, so the others keep their browser cache. The option
	// above is the site-wide fallback for languages never bumped.
	public const CACHE_GENERATIONS_OPTION = 'translatewp_translation_cache_generations';

	private const SCHEMA_VERSION = 1;

	private const MAX_MAP_ENTRIES     = 400;
	private const MAX_PENDING_HASHES  = 120;
	private const MAX_TEXT_LENGTH     = 5000;
	private const MAX_PAYLOAD_LENGTH  = 16384;
	private const MAX_HASH_LENGTH     = 256;
	private const MAX_URL_ENTRIES     = 200;
	private const MAX_WORK_ENTRIES    = 500;
	private const LOOKUP_BATCH_SIZE   = 100;

	/** Back-fill passes a row may miss the cache before its pending hashes are dropped. */
	public const MAX_BACKFILL_ATTEMPTS = 30;

	private const LOCK_TIMEOUT_SECONDS = 5;

	private static int $lock_depth = 0;

	/**
	 * Per-request read cache: internal storage key -> flat source->translated
	 * map. Invalidated on every write so a back-fill within the same request is
	 * seen by a later read.
	 *
	 * @var array<string,array<string,string>>
	 */
	private static array $read_cache = [];

	/**
	 * Record the source segments of one indexed URL and locale as pending
	 * translation. Segments are [ hash, markerPayload ] pairs.
	 */
	public static function record_pending( string $url, int $post_id, string $locale, string $source_lang, array $segments ): bool {
		$url    = trim( $url );
		$locale = trim( $locale );
		if ( '' === $url || '' === $locale || ! $segments ) {
			return false;
		}

		$target = self::resolve_target( $url, $post_id );

		self::locked(
			static function () use ( $target, $url, $locale, $source_lang, $segments ): void {
				$entry = self::read_entry( $target, $locale );

				$entry['source_lang'] = '' !== $source_lang ? $source_lang : (string) $entry['source_lang'];
				$entry['url']         = $url;

				foreach ( $segments as $segment ) {
					$hash    = isset( $segment['hash'] ) ? (string) $segment['hash'] : '';
					$payload = isset( $segment['markerPayload'] ) ? (string) $segment['markerPayload'] : '';

					if ( '' === $hash || strlen( $hash ) > self::MAX_HASH_LENGTH
						|| '' === trim( $payload ) || strlen( $payload ) > self::MAX_PAYLOAD_LENGTH ) {
						continue;
					}

					// Bounded pending list: the oldest hash gives way to the newcomer.
					if ( ! isset( $entry['pending'][ $hash ] )
						&& count( $entry['pending'] ) >= self::MAX_PENDING_HASHES ) {
						$oldest = array_key_first( $entry['pending'] );
						if ( null !== $oldest ) {
							unset( $entry['pending'][ $oldest ] );
						}
					}

					$entry['pending'][ $hash ] = $payload;
				}

				$entry['stored_at'] = self::now();
				self::write_entry( $target, $locale, $entry );
				self::enqueue_work( $target, $locale );
			}
		);

		return true;
	}

	/**
	 * Resolve the pending hashes of one URL and locale through the API cache and
	 * write the source -> translated pairs into the map. Returns the number of
	 * hashes resolved. Any lookup failure leaves the entry untouched. The lookup
	 * is fn( string $locale, array $hashes ): array<string,?string> and defaults
	 * to the API cache.
	 */
	public static function resolve_pending( string $url, int $post_id, string $locale, ?callable $lookup = null ): int {
		$locale = trim( $locale );
		if ( '' === $locale ) {
			return 0;
		}

		$target = self::resolve_target( $url, $post_id );
		$entry  = self::read_entry( $target, $locale );
		if ( ! $entry['pending'] ) {
			return 0;
		}

		// No resolution while a retranslation hold is in force.
		if ( (int) $entry['hold_until'] > self::now() ) {
			return 0;
		}

		$lookup     = $lookup ?? [ self::class, 'remote_lookup' ];
		$hashes     = array_keys( $entry['pending'] );
		$translated = [];

		foreach ( array_chunk( $hashes, self::LOOKUP_BATCH_SIZE ) as $chunk ) {
			$items = call_user_func( $lookup, $locale, $chunk );
			if ( ! is_array( $items ) || ! $items ) {
				continue;
			}

			foreach ( $chunk as $hash ) {
				$translated_payload = $items[ $hash ] ?? null;
				if ( is_string( $translated_payload ) && '' !== trim( $translated_payload ) ) {
					$translated[ $hash ] = $translated_payload;
				}
			}
		}

		if ( ! $translated ) {
			return 0;
		}

		// The look-ups ran unlocked; the merge re-reads the entry so segments
		// recorded by another request in the meantime are kept.
		$complete = false;
		$resolved = (int) self::locked(
			static function () use ( $target, $locale, $translated, &$complete ): int {
				$entry    = self::read_entry( $target, $locale );
				$resolved = 0;

				foreach ( $translated as $hash => $translated_payload ) {
					if ( ! isset( $entry['pending'][ $hash ] ) ) {
						continue;
					}

					self::merge_translated( $entry, (string) $entry['pending'][ $hash ], $translated_payload );
					unset( $entry['pending'][ $hash ] );
					TranslationProxyDedupe::release_segment( Sites::registered_id(), $locale, (string) $hash );
					$resolved++;
				}

				if ( $resolved > 0 ) {
					$entry['stored_at'] = self::now();
					self::write_entry( $target, $locale, $entry );
					$complete = ! $entry['pending'];
				}

				return $resolved;
			}
		);

		// Announced outside the lock, once, when the page's last pending
		// segment is stored. Listeners get the source address of the page.
		if ( $complete ) {
			do_action( 'translatewp_pro_translation_stored', LanguageUrl::source( $url ), $locale );
		}

		return $resolved;
	}

	/**
	 * The flat source -> translated map for a URL and locale (empty when none).
	 * The entry is read under both its post-id key and its canonical-URL key and
	 * merged, so the read finds the translation whichever key the write resolved
	 * to.
	 */
	public static function for_url( string $url, string $locale, int $post_id = 0 ): array {
		$url     = trim( $url );
		$locale  = trim( $locale );
		$post_id = $post_id > 0 ? $post_id : self::resolve_post_id( $url );
		$url_key = '' !== $url ? self::url_key( $url ) : '';
		$key     = 'post:' . $post_id . '|url:' . $url_key . '|' . $locale;

		if ( ! array_key_exists( $key, self::$read_cache ) ) {
			self::$read_cache[ $key ] = self::read_merged_map( $url, $post_id, $locale );
		}

		return self::$read_cache[ $key ];
	}

	/**
	 * The source -> translated map for a page read under both its post-id key
	 * and its canonical-URL key, merged. A visitor miss recorded where
	 * url_to_postid() resolves to 0 (a REST or cron drain) lands under the URL
	 * key, while a front-end SEO read carries the queried post id; keying a read
	 * to only one of the two would miss an entry written under the other. The
	 * post-keyed entry wins on any overlapping source string.
	 */
	private static function read_merged_map( string $url, int $post_id, string $locale ): array {
		$map = [];

		if ( '' !== $url ) {
			$url_entry = self::read_entry(
				[
					'post_id' => 0,
					'url'     => $url,
				],
				$locale
			);
			$map = $url_entry['map'];
		}

		if ( $post_id > 0 ) {
			$post_entry = self::read_entry(
				[
					'post_id' => $post_id,
					'url'     => $url,
				],
				$locale
			);
			$map = array_merge( $map, $post_entry['map'] );
		}

		return $map;
	}

	/**
	 * The Pro seam resolver: exact match on the source string, then a
	 * normalised-whitespace match, else the passed value unchanged.
	 */
	public static function translated_string( string $string, string $source_string, string $url, string $locale, int $post_id = 0 ): string {
		if ( '' === trim( $source_string ) || '' === trim( $locale ) ) {
			return $string;
		}

		$map = self::for_url( $url, $locale, $post_id );
		if ( ! $map ) {
			return $string;
		}

		if ( isset( $map[ $source_string ] ) ) {
			return TranslationWhitespace::trim( (string) $map[ $source_string ] );
		}

		$needle = self::normalize( $source_string );
		foreach ( $map as $stored_source => $translated ) {
			if ( self::normalize( (string) $stored_source ) === $needle ) {
				return TranslationWhitespace::trim( (string) $translated );
			}
		}

		return $string;
	}

	/**
	 * The stored state of one page's own title string for a locale, for the
	 * admin surfaces and the page-map read: 'stored' once the title's
	 * translation is in the map, 'pending' while its hash is still queued for
	 * back-fill (under either storage key), and 'missing' when neither holds.
	 * The title is server-only, with no visitor-path fallback.
	 */
	public static function title_state( string $url, string $title, string $locale, int $post_id = 0 ): string {
		$title  = trim( $title );
		$locale = trim( $locale );
		if ( '' === $title || '' === $locale ) {
			return 'missing';
		}

		$map = self::for_url( $url, $locale, $post_id );
		if ( isset( $map[ $title ] ) ) {
			return 'stored';
		}

		$needle = self::normalize( $title );
		foreach ( $map as $stored_source => $translated ) {
			unset( $translated );
			if ( self::normalize( (string) $stored_source ) === $needle ) {
				return 'stored';
			}
		}

		$url          = trim( $url );
		$resolved     = $post_id > 0 ? $post_id : self::resolve_post_id( $url );
		$url_pending  = '' !== $url
			? self::read_entry(
				[
					'post_id' => 0,
					'url'     => $url,
				],
				$locale
			)['pending']
			: [];
		$post_pending = $resolved > 0
			? self::read_entry(
				[
					'post_id' => $resolved,
					'url'     => $url,
				],
				$locale
			)['pending']
			: [];
		if ( $url_pending || $post_pending ) {
			return 'pending';
		}

		return 'missing';
	}

	public static function pending_count( string $url, string $locale, int $post_id = 0 ): int {
		$entry = self::read_entry( self::resolve_target( trim( $url ), $post_id ), trim( $locale ) );

		return count( $entry['pending'] );
	}

	public static function stored_at( string $url, string $locale, int $post_id = 0 ): int {
		$entry = self::read_entry( self::resolve_target( trim( $url ), $post_id ), trim( $locale ) );

		return (int) $entry['stored_at'];
	}

	/**
	 * The number of stored translation segments for one URL and locale: the
	 * resolved source -> translated pairs plus any still-pending group hashes.
	 * The "how many segments will be resent" figure the retranslate cost guard
	 * shows before it confirms.
	 */
	public static function segment_count( string $url, string $locale, int $post_id = 0 ): int {
		return count( self::for_url( $url, $locale, $post_id ) ) + self::pending_count( $url, $locale, $post_id );
	}

	/**
	 * Remove the stored translations of one URL and locale from BOTH storage
	 * keys (the post meta per post+locale for a singular page and the bounded
	 * option entry for a non-post URL) and drop its back-fill work item, so the
	 * next back-fill repopulates the entry from the freshly overwritten API
	 * cache. Returns the pending group hashes it dropped, for the caller to
	 * invalidate against the translation proxy.
	 */
	public static function clear_entry( string $url, int $post_id, string $locale ): array {
		$url    = trim( $url );
		$locale = trim( $locale );
		if ( '' === $locale ) {
			return [];
		}

		$resolved_post_id = $post_id > 0 ? $post_id : self::resolve_post_id( $url );

		return self::locked(
			static function () use ( $url, $locale, $resolved_post_id ): array {
				return self::clear_entry_locked( $url, $locale, $resolved_post_id );
			}
		);
	}

	private static function clear_entry_locked( string $url, string $locale, int $resolved_post_id ): array {
		$hashes = [];

		if ( $resolved_post_id > 0 ) {
			self::forget_cached_post_meta( $resolved_post_id );
			$all = get_post_meta( $resolved_post_id, self::POST_META_KEY, true );
			$all = is_array( $all ) ? $all : [];
			if ( isset( $all[ $locale ] ) ) {
				$entry  = self::normalize_entry( $all[ $locale ] );
				$hashes = array_merge( $hashes, array_keys( $entry['pending'] ) );
				unset( $all[ $locale ] );
				if ( $all ) {
					update_post_meta( $resolved_post_id, self::POST_META_KEY, $all );
				} else {
					delete_post_meta( $resolved_post_id, self::POST_META_KEY );
				}
			}
			self::forget_work( 'post:' . $resolved_post_id, $locale );
		}

		if ( '' !== $url ) {
			$urls = self::read_url_option();
			$key  = self::url_key( $url );
			if ( isset( $urls[ $key ]['locales'][ $locale ] ) ) {
				$entry  = self::normalize_entry( $urls[ $key ]['locales'][ $locale ] );
				$hashes = array_merge( $hashes, array_keys( $entry['pending'] ) );
				unset( $urls[ $key ]['locales'][ $locale ] );
				if ( empty( $urls[ $key ]['locales'] ) ) {
					unset( $urls[ $key ] );
				}
				update_option( self::URL_OPTION, $urls );
			}
			self::forget_work( 'url:' . $key, $locale );
		}

		self::flush_read_cache();

		return array_values( array_unique( $hashes ) );
	}

	/**
	 * Drain up to $limit recorded (URL, locale) work items, resolving each. A
	 * work item leaves the queue only once every hash it holds is resolved; one
	 * that still has misses stays for a later pass, so a translation that is
	 * slow to reach the API cache (a newly added locale, a busy provider) is
	 * never permanently abandoned. The queue stays bounded by MAX_WORK_ENTRIES.
	 * The lookup is fn( string $locale, array $hashes ): array<string,?string>.
	 *
	 * @return array{resolved:int,remaining:int}
	 */
	public static function run_backfill_batch( int $limit, ?callable $lookup = null ): array {
		$batch    = array_slice( self::read_work(), 0, max( 1, $limit ), true );
		$resolved = 0;
		$done     = [];
		$carry    = [];

		foreach ( $batch as $key => $item ) {
			$resolved += self::resolve_pending( (string) $item['url'], (int) $item['post_id'], (string) $item['locale'], $lookup );

			if ( 0 === self::pending_count( (string) $item['url'], (string) $item['locale'], (int) $item['post_id'] ) ) {
				$done[ $key ] = $item;
				continue;
			}

			// A row gives up after MAX_BACKFILL_ATTEMPTS misses: its pending hashes are dropped.
			$item['attempts'] = (int) ( $item['attempts'] ?? 0 ) + 1;
			if ( $item['attempts'] >= self::MAX_BACKFILL_ATTEMPTS ) {
				self::drop_pending( (string) $item['url'], (int) $item['post_id'], (string) $item['locale'] );
				$done[ $key ] = $item;
				continue;
			}

			$carry[ $key ] = $item;
		}

		// Work recorded by other requests during this pass is kept: the queue is
		// re-read and only this pass's rows are removed or moved behind the rest.
		$remaining = (int) self::locked(
			static function () use ( $done, $carry ): int {
				$work = self::read_work();

				foreach ( $done as $key => $item ) {
					if ( 0 === self::pending_count( (string) $item['url'], (string) $item['locale'], (int) $item['post_id'] ) ) {
						unset( $work[ $key ] );
					}
				}

				foreach ( array_keys( $carry ) as $key ) {
					unset( $work[ $key ] );
				}

				$work += $carry;
				self::write_work( $work );

				return count( $work );
			}
		);

		return [
			'resolved'  => $resolved,
			'remaining' => $remaining,
		];
	}

	/**
	 * Queue every stored page and language that still holds pending segments
	 * but has no back-fill work item, so a page whose work item was lost is
	 * resolved instead of reading as pending forever. Returns the number of
	 * entries re-queued.
	 */
	public static function requeue_orphaned_pending(): int {
		return (int) self::locked(
			static function (): int {
				$requeued = 0;
				$post_ids = get_posts(
					[
						'post_type'        => 'any',
						'post_status'      => 'any',
						'meta_key'         => self::POST_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'fields'           => 'ids',
						'posts_per_page'   => self::MAX_WORK_ENTRIES,
						'no_found_rows'    => true,
						'suppress_filters' => true,
					]
				);

				foreach ( is_array( $post_ids ) ? $post_ids : [] as $post_id ) {
					$post_id = (int) $post_id;
					if ( $post_id <= 0 ) {
						continue;
					}

					self::forget_cached_post_meta( $post_id );
					$all = get_post_meta( $post_id, self::POST_META_KEY, true );
					foreach ( is_array( $all ) ? $all : [] as $locale => $raw_entry ) {
						$entry = self::normalize_entry( $raw_entry );
						if ( $entry['pending'] && self::enqueue_missing_work( 'post:' . $post_id, $post_id, $entry['url'], (string) $locale ) ) {
							$requeued++;
						}
					}
				}

				foreach ( self::read_url_option() as $key => $url_entry ) {
					$locales = is_array( $url_entry['locales'] ?? null ) ? $url_entry['locales'] : [];
					foreach ( $locales as $locale => $raw_entry ) {
						$entry = self::normalize_entry( $raw_entry );
						if ( $entry['pending'] && self::enqueue_missing_work( 'url:' . $key, 0, (string) ( $url_entry['url'] ?? '' ), (string) $locale ) ) {
							$requeued++;
						}
					}
				}

				return $requeued;
			}
		);
	}

	private static function enqueue_missing_work( string $cache_key, int $post_id, string $url, string $locale ): bool {
		if ( isset( self::read_work()[ $cache_key . '|' . $locale ] ) ) {
			return false;
		}

		self::enqueue_work(
			[
				'post_id'   => $post_id,
				'url'       => $url,
				'cache_key' => $cache_key,
			],
			$locale
		);

		return true;
	}

	/**
	 * Forget one entry's pending hashes without touching its stored map.
	 */
	public static function drop_pending( string $url, int $post_id, string $locale ): void {
		$locale = trim( $locale );
		if ( '' === $locale ) {
			return;
		}

		$target = self::resolve_target( trim( $url ), $post_id );

		self::locked(
			static function () use ( $target, $locale ): void {
				$entry = self::read_entry( $target, $locale );
				if ( ! $entry['pending'] ) {
					return;
				}

				$entry['pending'] = [];
				self::write_entry( $target, $locale, $entry );
			}
		);
	}

	/**
	 * Hold back-fill resolution of one URL and locale for $seconds so a forced
	 * retranslation's asynchronous cache overwrite lands before the store reads
	 * it. A negative or zero duration clears the hold. The entry must already
	 * exist (recorded pending); holding a missing entry does nothing.
	 */
	public static function hold_overwrite( string $url, int $post_id, string $locale, int $seconds ): void {
		$locale = trim( $locale );
		if ( '' === $locale ) {
			return;
		}

		$target = self::resolve_target( trim( $url ), $post_id );

		self::locked(
			static function () use ( $target, $locale, $seconds ): void {
				$entry = self::read_entry( $target, $locale );
				if ( ! $entry['pending'] && ! $entry['map'] ) {
					return;
				}

				$entry['hold_until'] = $seconds > 0 ? self::now() + $seconds : 0;
				self::write_entry( $target, $locale, $entry );
			}
		);
	}

	/**
	 * The site-wide fallback generation a language uses until it is bumped
	 * individually. The frontend keys any language absent from the per-language
	 * map by this value, and writes it under the same value, so an unbumped
	 * language keeps a consistent, warm local cache.
	 */
	public static function cache_generation_fallback(): int {
		return max( 1, (int) get_option( self::CACHE_GENERATION_OPTION, 1 ) );
	}

	/**
	 * The cache generation for one language. The frontend keys its local
	 * hydration cache by this value so a retranslated page's stale browser copy
	 * is skipped after the language's generation is bumped. A language never
	 * bumped falls back to the legacy site-wide generation, then to 1.
	 */
	public static function cache_generation_for( string $language ): int {
		$map = self::cache_generations();
		if ( isset( $map[ $language ] ) ) {
			return max( 1, (int) $map[ $language ] );
		}

		return max( 1, (int) get_option( self::CACHE_GENERATION_OPTION, 1 ) );
	}

	/**
	 * The per-language generation map the frontend receives, folded over the
	 * legacy site-wide generation so every active language resolves to a value.
	 */
	public static function cache_generations( array $languages = [] ): array {
		$stored = get_option( self::CACHE_GENERATIONS_OPTION, [] );
		$map    = is_array( $stored ) ? $stored : [];

		$resolved = [];
		foreach ( $languages as $language ) {
			$resolved[ $language ] = self::cache_generation_for( $language );
		}
		foreach ( $map as $language => $value ) {
			$resolved[ $language ] = max( 1, (int) $value );
		}

		return $resolved;
	}

	/**
	 * The highest generation across every language and the legacy site-wide
	 * value. Kept for callers that want one number rather than the map.
	 */
	public static function cache_generation(): int {
		$max = max( 1, (int) get_option( self::CACHE_GENERATION_OPTION, 1 ) );
		foreach ( self::cache_generations() as $value ) {
			$max = max( $max, (int) $value );
		}

		return $max;
	}

	/**
	 * Advance the cache generation for the given languages so only those
	 * languages' visitors miss their superseded local entries; other languages
	 * keep their warm local cache. An empty list bumps the legacy site-wide
	 * generation, which raises every language's fallback. Returns the highest
	 * generation afterwards.
	 */
	public static function bump_cache_generation( array $languages = [] ): int {
		if ( ! $languages ) {
			$next = max( 1, (int) get_option( self::CACHE_GENERATION_OPTION, 1 ) ) + 1;
			update_option( self::CACHE_GENERATION_OPTION, $next );

			return self::cache_generation();
		}

		$stored = get_option( self::CACHE_GENERATIONS_OPTION, [] );
		$map    = is_array( $stored ) ? $stored : [];

		foreach ( $languages as $language ) {
			$current      = isset( $map[ $language ] ) ? (int) $map[ $language ] : self::cache_generation_for( $language );
			$map[ $language ] = max( 1, $current ) + 1;
		}
		update_option( self::CACHE_GENERATIONS_OPTION, $map );

		return self::cache_generation();
	}

	public static function has_pending_work(): bool {
		return (bool) self::read_work();
	}

	/**
	 * Whether the site holds any stored translation at all: true once the
	 * first page entry was written for any language.
	 */
	public static function has_translations(): bool {
		$urls = get_option( self::URL_OPTION, [] );

		return is_array( $urls ) && [] !== $urls;
	}

	/**
	 * Post the pending hashes to the API cache and return [ hash => payload ].
	 * The single canonical home for the store's lookup call; hydration is set so
	 * the read never inflates language-demand metering. Any error yields [].
	 *
	 * @return array<string,?string>
	 */
	public static function remote_lookup( string $locale, array $hashes ): array {
		$items = self::remote_lookup_result( $locale, $hashes );

		return is_wp_error( $items ) ? [] : $items;
	}

	/**
	 * The same look-up, keeping the failure: a refused or failed request is a
	 * WP_Error rather than an empty answer, so a caller can tell a rate limit
	 * from a cache miss.
	 *
	 * @return array<string,?string>|\WP_Error
	 */
	public static function remote_lookup_result( string $locale, array $hashes ) {
		$site_id = Sites::registered_id();
		if ( '' === $site_id || ! $hashes ) {
			return [];
		}

		$response = SiteRequests::post(
			'/v1/translation/cache/lookup-batch',
			[
				'siteId'    => $site_id,
				'language'  => $locale,
				'hashes'    => array_values( $hashes ),
				'hydration' => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return is_array( $response ) && is_array( $response['items'] ?? null ) ? $response['items'] : [];
	}

	public static function flush_read_cache(): void {
		self::$read_cache = [];
	}

	private static function merge_translated( array &$entry, string $source_payload, string $translated_payload ): void {
		$source_entries     = TranslationMarkerPayload::parse( $source_payload );
		$translated_entries = TranslationMarkerPayload::parse( $translated_payload );

		foreach ( $source_entries as $id => $source_text ) {
			$translated_text = $translated_entries[ $id ] ?? '';
			if ( ! TranslationWhitespace::has_translatable_text( $source_text )
				|| ! TranslationWhitespace::has_translatable_text( $translated_text )
				|| strlen( $source_text ) > self::MAX_TEXT_LENGTH
				|| strlen( $translated_text ) > self::MAX_TEXT_LENGTH ) {
				continue;
			}

			// Bounded map: the oldest pair gives way to the newcomer.
			if ( ! isset( $entry['map'][ $source_text ] )
				&& count( $entry['map'] ) >= self::MAX_MAP_ENTRIES ) {
				$oldest = array_key_first( $entry['map'] );
				if ( null !== $oldest ) {
					unset( $entry['map'][ $oldest ] );
				}
			}

			$entry['map'][ $source_text ] = $translated_text;
		}
	}

	private static function normalize( string $value ): string {
		$collapsed = preg_replace( '/\s+/u', ' ', $value );

		return trim( is_string( $collapsed ) ? $collapsed : $value );
	}

	/**
	 * The internal storage target for a URL: its post id (0 for a non-post URL)
	 * and the read-cache key.
	 *
	 * @return array{post_id:int,url:string,cache_key:string}
	 */
	private static function resolve_target( string $url, int $post_id ): array {
		$post_id = $post_id > 0 ? $post_id : self::resolve_post_id( $url );

		return [
			'post_id'   => $post_id,
			'url'       => $url,
			'cache_key' => $post_id > 0 ? 'post:' . $post_id : 'url:' . self::url_key( $url ),
		];
	}

	public static function resolve_post_id( string $url ): int {
		if ( '' === $url ) {
			return 0;
		}

		// url_to_postid() needs the permalink without the switcher parameter (see url_key).
		$post_id = (int) url_to_postid( self::without_switcher_param( $url ) );
		if ( $post_id > 0 ) {
			return $post_id;
		}

		// url_to_postid() does not resolve the home URL of a static front page.
		$front_page = (int) get_option( 'page_on_front', 0 );
		if ( $front_page > 0
			&& ( self::same_url( $url, home_url( '/' ) ) || self::same_url( $url, (string) get_permalink( $front_page ) ) ) ) {
			return $front_page;
		}

		return 0;
	}

	private static function same_url( string $left, string $right ): bool {
		return TranslationUrl::canonicalize( $left ) === TranslationUrl::canonicalize( $right );
	}

	/**
	 * The URL with only the language switcher parameter removed, so
	 * url_to_postid() resolves a translated page (…?lang=de) to the same post as
	 * its untranslated permalink. Scheme, host, path and any other query are
	 * preserved, unlike the https-forcing canonical form url_to_postid() could
	 * not match.
	 */
	private static function without_switcher_param( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['query'] ) ) {
			return $url;
		}

		$params = [];
		parse_str( (string) $parts['query'], $params );
		if ( ! array_key_exists( TranslationUrl::LANG_QUERY_PARAM, $params ) ) {
			return $url;
		}

		unset( $params[ TranslationUrl::LANG_QUERY_PARAM ] );

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$host   = (string) ( $parts['host'] ?? '' );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = (string) ( $parts['path'] ?? '' );
		$query  = $params ? '?' . http_build_query( $params ) : '';

		return $scheme . $host . $port . $path . $query;
	}

	private static function url_key( string $url ): string {
		return md5( TranslationUrl::canonicalize( $url ) );
	}

	private static function empty_entry(): array {
		return [
			'v'           => self::SCHEMA_VERSION,
			'source_lang' => '',
			'url'         => '',
			'stored_at'   => 0,
			'hold_until'  => 0,
			'map'         => [],
			'pending'     => [],
		];
	}

	private static function normalize_entry( $entry ): array {
		if ( ! is_array( $entry ) ) {
			return self::empty_entry();
		}

		return [
			'v'           => self::SCHEMA_VERSION,
			'source_lang' => (string) ( $entry['source_lang'] ?? '' ),
			'url'         => (string) ( $entry['url'] ?? '' ),
			'stored_at'   => (int) ( $entry['stored_at'] ?? 0 ),
			'hold_until'  => (int) ( $entry['hold_until'] ?? 0 ),
			'map'         => is_array( $entry['map'] ?? null ) ? $entry['map'] : [],
			'pending'     => is_array( $entry['pending'] ?? null ) ? $entry['pending'] : [],
		];
	}

	private static function read_entry( array $target, string $locale ): array {
		if ( $target['post_id'] > 0 ) {
			self::forget_cached_post_meta( (int) $target['post_id'] );
			$all = get_post_meta( $target['post_id'], self::POST_META_KEY, true );
			$all = is_array( $all ) ? $all : [];

			return self::normalize_entry( $all[ $locale ] ?? null );
		}

		$urls = self::read_url_option();
		$key  = self::url_key( $target['url'] );

		return self::normalize_entry( $urls[ $key ]['locales'][ $locale ] ?? null );
	}

	private static function write_entry( array $target, string $locale, array $entry ): void {
		if ( $target['post_id'] > 0 ) {
			$all = get_post_meta( $target['post_id'], self::POST_META_KEY, true );
			$all = is_array( $all ) ? $all : [];
			$all[ $locale ] = $entry;
			update_post_meta( $target['post_id'], self::POST_META_KEY, $all );
			self::flush_read_cache();

			return;
		}

		$urls = self::read_url_option();
		$key  = self::url_key( $target['url'] );
		if ( ! isset( $urls[ $key ] ) || ! is_array( $urls[ $key ] ) ) {
			$urls[ $key ] = [
				'url'     => $target['url'],
				'locales' => [],
			];
		}

		$urls[ $key ]['url']                = $target['url'];
		$urls[ $key ]['locales'][ $locale ] = $entry;
		$urls[ $key ]['updated_at']         = self::now();

		update_option( self::URL_OPTION, self::enforce_url_bound( $urls ), false );
		self::flush_read_cache();
	}

	private static function read_url_option(): array {
		self::forget_cached_option( self::URL_OPTION );
		$urls = get_option( self::URL_OPTION, [] );

		return is_array( $urls ) ? $urls : [];
	}

	private static function enforce_url_bound( array $urls ): array {
		if ( count( $urls ) <= self::MAX_URL_ENTRIES ) {
			return $urls;
		}

		uasort(
			$urls,
			static function ( $left, $right ): int {
				return (int) ( $right['updated_at'] ?? 0 ) <=> (int) ( $left['updated_at'] ?? 0 );
			}
		);

		return array_slice( $urls, 0, self::MAX_URL_ENTRIES, true );
	}

	private static function enqueue_work( array $target, string $locale ): void {
		self::locked(
			static function () use ( $target, $locale ): void {
				$work = self::read_work();
				$key  = $target['cache_key'] . '|' . $locale;

				$work[ $key ] = [
					'post_id' => (int) $target['post_id'],
					'url'     => (string) $target['url'],
					'locale'  => $locale,
				];

				if ( count( $work ) > self::MAX_WORK_ENTRIES ) {
					$work = array_slice( $work, -self::MAX_WORK_ENTRIES, self::MAX_WORK_ENTRIES, true );
				}

				self::write_work( $work );
			}
		);
	}

	private static function forget_work( string $cache_key, string $locale ): void {
		self::locked(
			static function () use ( $cache_key, $locale ): void {
				$work = self::read_work();
				unset( $work[ $cache_key . '|' . $locale ] );
				self::write_work( $work );
			}
		);
	}

	private static function read_work(): array {
		self::forget_cached_option( self::WORK_OPTION );
		$work = get_option( self::WORK_OPTION, [] );

		return is_array( $work ) ? $work : [];
	}

	private static function write_work( array $work ): void {
		if ( ! $work ) {
			delete_option( self::WORK_OPTION );

			return;
		}

		update_option( self::WORK_OPTION, $work, false );
	}

	/**
	 * Run a store mutation under a database lock shared by every request of
	 * this site. Stored values are re-read inside the lock, so two requests
	 * writing at the same time cannot overwrite each other's entries. When the
	 * lock cannot be taken in time the mutation still runs.
	 *
	 * @return mixed The mutation's return value.
	 */
	private static function locked( callable $mutation ) {
		global $wpdb;

		if ( self::$lock_depth > 0 || ! is_object( $wpdb ) ) {
			return $mutation();
		}

		$name     = 'translatewp_store_' . md5( ( isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '' ) . '|' . (string) $wpdb->prefix );
		$acquired = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT_SECONDS ) );

		self::$lock_depth++;
		try {
			return $mutation();
		} finally {
			self::$lock_depth--;
			if ( $acquired ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
			self::flush_read_cache();
		}
	}

	/** Outside a locked mutation reads keep using the request's caches. */
	private static function forget_cached_option( string $name ): void {
		if ( 0 === self::$lock_depth ) {
			return;
		}

		wp_cache_delete( $name, 'options' );

		foreach ( [ 'alloptions', 'notoptions' ] as $bucket ) {
			$cached = wp_cache_get( $bucket, 'options' );
			if ( is_array( $cached ) && array_key_exists( $name, $cached ) ) {
				wp_cache_delete( $bucket, 'options' );
			}
		}
	}

	private static function forget_cached_post_meta( int $post_id ): void {
		if ( self::$lock_depth > 0 ) {
			wp_cache_delete( (string) $post_id, 'post_meta' );
		}
	}

	private static function now(): int {
		return time();
	}
}
