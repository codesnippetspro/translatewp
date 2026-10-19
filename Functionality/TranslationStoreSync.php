<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\TranslationStore;

/**
 * Wires the translation store into the indexing pipeline and the Pro seam:
 * records the source segments of each pushed page, resolves them to translated
 * text from the API cache on WP-Cron and on admin load, and answers the
 * `translatewp_pro_translated_string` filter the Pro SEO surfaces apply.
 */
class TranslationStoreSync {

	public const BACKFILL_HOOK = 'translatewp_translation_store_backfill';

	public const REQUEUE_CHECK_TRANSIENT = 'translatewp_translation_store_requeue_check';

	private const REQUEUE_CHECK_SECONDS = 600;

	private const MISS_OBJECT_TYPE = 'miss';

	private const BACKFILL_BATCH_SIZE        = 25;
	private const BACKFILL_DELAY_SECONDS     = 30;
	private const BACKFILL_RESCHEDULE_SECONDS = 120;

	/**
	 * A forced retranslation overwrites the API cache asynchronously. Hold the
	 * store back-fill of the refreshed page this long so a resolve reads the new
	 * translation rather than the superseded one still in the cache.
	 */
	private const OVERWRITE_HOLD_SECONDS = 90;

	public function __construct( $plugin_name = '', $plugin_version = '' ) {
		unset( $plugin_name, $plugin_version );

		add_filter( 'translatewp_pro_translated_string', [ $this, 'translated_string' ], 10, 4 );
		add_action( 'translatewp_index_rows_pushed', [ $this, 'record_pushed_rows' ], 10, 3 );
		add_action( self::BACKFILL_HOOK, [ $this, 'run_backfill' ] );
		add_action( 'admin_init', [ $this, 'maybe_schedule_backfill' ] );
		add_action( 'save_post', [ $this, 'on_save_post' ], 20, 2 );
	}

	/**
	 * The Pro seam: return the stored translation of $source_string on the
	 * current URL and locale, else the passed $string unchanged.
	 *
	 * @param mixed  $string        Current value (source-string fallback).
	 * @param string $source_string Source string to translate.
	 * @param string $locale        Target locale.
	 * @param mixed  $object_id     Queried object id of the page being rendered
	 *                              when the caller knows it (so the read keys by
	 *                              post); falls back to get_queried_object_id().
	 */
	public function translated_string( $string, $source_string = '', $locale = '', $object_id = null ) {
		if ( ! is_string( $string ) ) {
			return $string;
		}

		$queried_object_id = is_numeric( $object_id )
			? (int) $object_id
			: ( function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0 );

		$url = $queried_object_id > 0 ? (string) get_permalink( $queried_object_id ) : $this->current_url();

		return TranslationStore::translated_string( $string, (string) $source_string, $url, (string) $locale, $queried_object_id );
	}

	/**
	 * Record the source segments of each just-pushed page as pending
	 * translation, then schedule the back-fill.
	 *
	 * @param string $language    Target locale of the rows.
	 * @param string $source_lang Source locale of the job.
	 * @param array  $rows        Pushed rows of [ url, object_type, object_id, items ].
	 */
	public function record_pushed_rows( $language, $source_lang, $rows ): void {
		if ( ! is_array( $rows ) ) {
			return;
		}

		$language    = (string) $language;
		$source_lang = (string) $source_lang;
		$recorded    = false;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$url         = (string) ( $row['url'] ?? '' );
			$object_type = (string) ( $row['object_type'] ?? '' );
			$object_id   = (int) ( $row['object_id'] ?? 0 );
			if ( '' === $url ) {
				continue;
			}

			// A miss row's object id is a fingerprint-derived synthetic value,
			// not a real post; resolve it back to a post (or a non-post URL
			// entry) from the URL so the store keys it the same way a read does.
			if ( self::MISS_OBJECT_TYPE === $object_type ) {
				$object_id = 0;
			}

			$segments = [];
			foreach ( is_array( $row['items'] ?? null ) ? $row['items'] : [] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$hash    = isset( $item['cache']['hash'] ) ? (string) $item['cache']['hash'] : '';
				$payload = isset( $item['markerPayload'] ) ? (string) $item['markerPayload'] : '';
				if ( '' === $hash || '' === trim( $payload ) ) {
					continue;
				}

				$segments[] = [
					'hash'          => $hash,
					'markerPayload' => $payload,
				];
			}

			if ( $segments && TranslationStore::record_pending( $url, $object_id, $language, $source_lang, $segments ) ) {
				$recorded = true;

				if ( ! empty( $row['retranslate'] ) ) {
					TranslationStore::hold_overwrite( $url, $object_id, $language, self::OVERWRITE_HOLD_SECONDS );
				}
			}
		}

		if ( $recorded ) {
			self::schedule_backfill( self::BACKFILL_DELAY_SECONDS );
		}
	}

	public function run_backfill(): void {
		if ( ! SiteConnection::is_connected() ) {
			return;
		}

		// How much of the back-fill one run takes, and how soon the next run
		// follows while work is left. The defaults trickle, which is fine for a
		// settled site and slow for a first pass.
		$batch      = max( 1, (int) apply_filters( 'translatewp_store_backfill_batch_size', self::BACKFILL_BATCH_SIZE ) );
		$reschedule = max( 0, (int) apply_filters( 'translatewp_store_backfill_reschedule', self::BACKFILL_RESCHEDULE_SECONDS ) );

		$result = TranslationStore::run_backfill_batch( $batch );
		if ( $result['remaining'] > 0 ) {
			self::schedule_backfill( $reschedule );
		}
	}

	public function maybe_schedule_backfill(): void {
		if ( false === get_transient( self::REQUEUE_CHECK_TRANSIENT ) ) {
			set_transient( self::REQUEUE_CHECK_TRANSIENT, 1, self::REQUEUE_CHECK_SECONDS );
			TranslationStore::requeue_orphaned_pending();
		}

		if ( TranslationStore::has_pending_work() ) {
			self::schedule_backfill( 0 );
		}
	}

	public function on_save_post( $post_id, $post ): void {
		unset( $post_id, $post );
		if ( TranslationStore::has_pending_work() ) {
			self::schedule_backfill( self::BACKFILL_DELAY_SECONDS );
		}
	}

	public static function schedule_backfill( int $delay_seconds ): void {
		if ( ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + max( 0, $delay_seconds ), self::BACKFILL_HOOK );
		}
	}

	private function current_url(): string {
		// The 404 page is one object stored under its own address, whatever
		// missing path the visitor typed.
		if ( function_exists( 'is_404' ) && is_404() ) {
			return (string) Indexer::object_url( Indexer::NOT_FOUND_OBJECT_ID, Indexer::NOT_FOUND_OBJECT_TYPE );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';

		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		return home_url( '' !== $path ? $path : '/' );
	}
}
