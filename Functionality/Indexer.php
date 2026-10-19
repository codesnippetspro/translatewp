<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\PlainText;
use TranslateWP\Components\PageExclusion;
use TranslateWP\Components\PageCachePurge;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Components\LoopbackKick;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\SiteRequests;
use TranslateWP\Components\Sites;
use TranslateWP\Components\TranslationContext;
use TranslateWP\Components\TranslationFingerprint;
use TranslateWP\Components\TranslationProRetry;
use TranslateWP\Components\TranslationProviderFailure;
use TranslateWP\Components\TranslationProxyDedupe;
use TranslateWP\Components\TranslationProxySettings;
use TranslateWP\Components\TranslationReadiness;
use TranslateWP\Components\TranslationUrl;

/**
 * Warm-up indexer (C3): enumerates public content into the index queue and
 * drains the push stage on WP-Cron. Extraction itself happens in the admin
 * browser with the production DOM extractor (fingerprint parity); this class
 * never parses DOM.
 */
class Indexer {

	public const DRAIN_HOOK = 'translatewp_index_queue_drain';

	public const BATCH_SIZE = 10;
	public const TICK_BUDGET_SECONDS = 20;
	public const RESCHEDULE_DELAY_SECONDS = 60;
	private const MISS_OBJECT_TYPE        = 'miss';

	public const SCHEDULED_LATE_INTERVALS = 2;
	public const LAST_DRAIN_STALE_SECONDS = 600;

	public const INLINE_BUDGET_SECONDS = 2.5;
	public const INLINE_MAX_CONCURRENT = 2;
	public const INLINE_SLOT_TTL_SECONDS = 30;
	public const INLINE_SLOT_TRANSIENT = 'translatewp_inline_drain_slots';

	public const PRIORITY_PINNED  = 0;
	public const PRIORITY_DEFAULT = 100;

	public const NOT_FOUND_OBJECT_TYPE = 'not_found';
	public const NOT_FOUND_OBJECT_ID   = -1;
	// An address no page answers, so the site serves its own 404 page there.
	public const NOT_FOUND_PATH = 'translatewp-page-not-found';
	// The home page is read and translated first, behind only a pinned page.
	public const PRIORITY_HOME    = 50;
	private const PINS_OPTION = 'translatewp_index_priority_pins';
	private const REPORTED_OPTION = 'translatewp_index_reported_languages';
	public const FIRST_PASS_OPTION = 'translatewp_first_pass_done_at';
	private const LAST_DRAIN_OPTION = 'translatewp_index_last_drain';
	private const JOB_POST_TIMEOUT_SECONDS = 30;
	private const FALLBACK_LINK_CAP = 50;
	private const TITLE_CONTEXT_KEY = 'seo:title';
	private const TITLE_MARKER       = '⟪item_0001⟫';

	private IndexQueue $queue;

	/**
	 * Monotonic clock, injectable for budget tests.
	 *
	 * @var callable(): float
	 */
	private $clock;

	public function __construct( $plugin_name = '', $plugin_version = '', ?IndexQueue $queue = null, ?callable $clock = null ) {
		unset( $plugin_name, $plugin_version );

		$this->queue = $queue ?? new IndexQueue();
		$this->clock = $clock ?? static function (): float {
			return microtime( true );
		};

		add_action( 'init', [ IndexQueue::class, 'register_post_type' ], 5 );
		add_action( self::DRAIN_HOOK, [ $this, 'drain_tick' ] );
		add_action( 'translatewp_languages_saved', [ $this, 'on_languages_saved' ] );
	}

	/**
	 * Languages chosen post-connect (spec C3 trigger): enumerate and enqueue.
	 *
	 * @param mixed $languages Active locales just synced to the API.
	 */
	public function on_languages_saved( $languages ): void {
		if ( ! is_array( $languages ) || ! $languages ) {
			return;
		}

		$this->start_translation( array_map( 'strval', $languages ) );
	}

	/**
	 * Queue every spine page for reading, one row per page with no
	 * language. The admin tab extracts them; nothing is sent to the service.
	 */
	public function enqueue_read_rows(): int {
		$pins = get_option( self::PINS_OPTION, [] );
		$pins = is_array( $pins ) ? array_map( 'intval', $pins ) : [];
		$rows = [];

		foreach ( $this->enumerate_objects() as $post_id => $post_type ) {
			$url = self::object_url( (int) $post_id, (string) $post_type );
			if ( ! is_string( $url ) || '' === $url || ! TranslationUrl::is_indexable_url( $url ) ) {
				continue;
			}

			$priority = $this->base_priority( (int) $post_id, (string) $post_type, $pins );
			$priority = (int) apply_filters( 'translatewp/indexer_priority', $priority, (int) $post_id, $post_type );

			$rows[] = [
				'object_type' => $post_type,
				'object_id'   => (int) $post_id,
				'url'         => $url,
				'page_title'  => self::object_title( (int) $post_id, (string) $post_type ),
				'language'    => IndexQueue::READ_LANGUAGE,
				'priority'    => $priority,
			];
		}

		return $rows ? $this->queue->enqueue_read( $rows ) : 0;
	}

	/** Queue only the pages saved since they were read for a fresh reading. */
	public function reread_changed(): int {
		return $this->queue->reread_changed();
	}

	/**
	 * Translation starts from what was read. Every read page is
	 * fanned out into one push row per target language, each item carrying
	 * the fingerprint for that language, and the drain is kicked. A page and
	 * language that already hold a live row are left alone unless $force is
	 * set (a fresh read of the site). A site with nothing read yet falls back
	 * to the per-language extraction.
	 */
	public function start_translation( ?array $languages = null, bool $force = false ): int {
		$languages = $this->target_languages( $languages );
		if ( ! $languages ) {
			return 0;
		}

		$read_rows = $this->queue->read_rows();
		if ( ! $read_rows ) {
			return $this->enqueue_for_languages( $languages );
		}

		$queued = $this->fan_out_read_rows( $read_rows, $languages, $force );
		\TranslateWP\Components\ActionLog::record( sprintf( '%s queued %d page translations for %s', $force ? 'Forced retranslation' : 'Translation run', $queued, implode( ', ', $languages ) ) );

		$reported = get_option( self::REPORTED_OPTION, [] );
		$reported = is_array( $reported ) ? $reported : [];
		update_option( self::REPORTED_OPTION, array_values( array_diff( $reported, $languages ) ) );

		if ( $queued > 0 ) {
			self::schedule_drain();
			LoopbackKick::fire();
		}

		return $queued;
	}

	/**
	 * Copy read pages into one push row per target language, each item carrying
	 * that language's fingerprint. A page and language that already hold a live
	 * row are left alone unless $force is set. Returns the rows queued.
	 */
	private function fan_out_read_rows( array $read_rows, array $languages, bool $force ): int {
		$source_lang = TranslationProxySettings::default_locale();
		$queued      = 0;

		foreach ( $languages as $language ) {
			$ai_settings = TranslationProxySettings::ai_settings( $language );

			foreach ( $read_rows as $read_row ) {
				if ( ! $force && $this->queue->has_live_row( $read_row['object_type'], $read_row['object_id'], $language ) ) {
					continue;
				}

				$items = $this->stamp_hashes( $language, $read_row['items'], $source_lang, $ai_settings );

				if ( ! $items ) {
					continue;
				}

				$row_id = $this->queue->enqueue_push(
					[
						'object_type' => $read_row['object_type'],
						'object_id'   => $read_row['object_id'],
						'url'         => $read_row['url'],
						'page_title'  => $read_row['page_title'],
						'language'    => $language,
						'priority'    => $read_row['priority'],
						'payload'     => (string) wp_json_encode( $items ),
					]
				);
				if ( false !== $row_id ) {
					$queued++;
				}
			}
		}

		return $queued;
	}

	/** The target languages to translate into: the given or active ones, minus the source. */
	private function target_languages( ?array $languages ): array {
		$languages = $languages ?? TranslationProxySettings::active_lookup_languages();
		$languages = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $languages ) ) ) ) );
		$default   = TranslationProxySettings::default_locale();
		if ( '' !== $default && 'auto' !== $default ) {
			$languages = array_values(
				array_filter(
					$languages,
					static function ( string $language ) use ( $default ): bool {
						return $language !== $default;
					}
				)
			);
		}

		return $languages;
	}

	/** One drain batch now, for the loopback kick; nothing when a batch is already running. */
	public function drain_now(): array {
		$result = [
			'ran' => false,
			'processed' => 0,
			'failed' => 0,
			'released' => 0,
		];
		$ran    = LoopbackKick::run_locked(
			function () use ( &$result ): void {
				$result = $this->drain_tick() + [ 'ran' => true ];
			}
		);
		$result['ran'] = $ran;

		return $result;
	}

	/**
	 * Post types eligible for warm-up. Configurable, never hardcoded.
	 */
	public function indexable_post_types(): array {
		$types = get_post_types( [ 'public' => true ], 'names' );
		unset( $types['attachment'] );

		$types = apply_filters( 'translatewp/indexer_post_types', array_values( $types ) );

		return is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ) ) ) : [];
	}

	/**
	 * Object ids in scope, as [ id => post_type ].
	 * The Free plugin ships only this minimal spine enumerator; the Pro
	 * addon extends enumeration to full-site through the objects filter.
	 */
	private function enumerate_objects(): array {
		$types   = $this->indexable_post_types();
		$objects = apply_filters( 'translatewp/indexer_objects', $this->spine_objects( $types ), $types );
		if ( ! is_array( $objects ) ) {
			return [];
		}

		// Applied after the filter, so pages another plugin adds are held to
		// the same rule. Key 0 is the posts-page home, which is no post.
		return array_filter(
			$objects,
			static function ( $post_id ): bool {
				return ! PageExclusion::is_excluded( (int) $post_id );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * The site spine: front page + published nav-menu targets. When no
	 * nav/footer menu yields a target, fall back to the rendered front
	 * page's same-host internal links.
	 */
	private function spine_objects( array $types ): array {
		$spine_ids = [];

		$front_page = $this->front_page_id();
		if ( $front_page > 0 ) {
			$spine_ids[] = $front_page;
		}

		$menu_ids = [];
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = wp_get_nav_menu_items( $menu );
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( 'post_type' === ( $item->type ?? '' ) && (int) ( $item->object_id ?? 0 ) > 0 ) {
					$menu_ids[] = (int) $item->object_id;
				}
			}
		}

		$spine_ids = array_merge( $spine_ids, $menu_ids ? $menu_ids : $this->front_page_link_ids() );

		$objects = $front_page > 0 ? [] : [ 0 => 'home' ];
		// The "page not found" page is part of every site a visitor can reach.
		$objects[ self::NOT_FOUND_OBJECT_ID ] = self::NOT_FOUND_OBJECT_TYPE;
		foreach ( array_values( array_unique( $spine_ids ) ) as $post_id ) {
			if ( 'publish' !== get_post_status( $post_id ) ) {
				continue;
			}

			$post_type = (string) get_post_type( $post_id );
			if ( ! in_array( $post_type, $types, true ) ) {
				continue;
			}

			$objects[ $post_id ] = $post_type;
		}

		return $objects;
	}

	public static function object_url( int $post_id, string $post_type ) {
		if ( 'home' === $post_type ) {
			return home_url( '/' );
		}
		if ( self::NOT_FOUND_OBJECT_TYPE === $post_type ) {
			return home_url( '/' . self::NOT_FOUND_PATH . '/' );
		}

		return get_permalink( $post_id );
	}

	public static function object_title( int $post_id, string $post_type ): string {
		if ( 'home' === $post_type ) {
			return PlainText::site_name();
		}
		if ( self::NOT_FOUND_OBJECT_TYPE === $post_type ) {
			return __( 'Page not found', 'translate' );
		}

		return PlainText::title( $post_id );
	}

	private function base_priority( int $post_id, string $post_type, array $pins ): int {
		if ( in_array( $post_id, $pins, true ) ) {
			return self::PRIORITY_PINNED;
		}

		$is_home = 'home' === $post_type || ( $post_id > 0 && $post_id === $this->front_page_id() );

		return $is_home ? self::PRIORITY_HOME : self::PRIORITY_DEFAULT;
	}

	private function front_page_id(): int {
		return 'posts' === get_option( 'show_on_front', 'page' ) ? 0 : (int) get_option( 'page_on_front', 0 );
	}

	/**
	 * Fallback spine source: same-host internal links on the rendered front
	 * page, resolved to object ids, capped.
	 */
	private function front_page_link_ids(): array {
		$front_page = $this->front_page_id();
		$front_url  = $front_page > 0 ? get_permalink( $front_page ) : home_url( '/' );
		if ( ! is_string( $front_url ) || '' === $front_url ) {
			return [];
		}

		// Only ever fetch our own front page — never let a filtered permalink /
		// home_url send this server-side request to another host (SSRF guard).
		if ( (string) parse_url( $front_url, \PHP_URL_HOST ) !== (string) parse_url( home_url( '/' ), \PHP_URL_HOST ) ) {
			return [];
		}

		$response = wp_remote_get( $front_url, [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) ) {
			return [];
		}

		$html = (string) wp_remote_retrieve_body( $response );
		if ( '' === $html ) {
			return [];
		}

		$home_host = (string) parse_url( home_url( '/' ), \PHP_URL_HOST );
		$ids       = [];

		foreach ( $this->extract_link_hrefs( $html ) as $href ) {
			if ( count( $ids ) >= self::FALLBACK_LINK_CAP ) {
				break;
			}

			$link_host = (string) parse_url( $href, \PHP_URL_HOST );
			if ( '' !== $link_host && $link_host !== $home_host ) {
				continue;
			}

			$post_id = (int) url_to_postid( $href );
			if ( $post_id > 0 && ! in_array( $post_id, $ids, true ) ) {
				$ids[] = $post_id;
			}
		}

		return $ids;
	}

	/**
	 * Collect anchor href values from markup in document order.
	 *
	 * @return string[]
	 */
	private function extract_link_hrefs( string $html ): array {
		if ( class_exists( '\WP_HTML_Tag_Processor' ) ) {
			$hrefs     = [];
			$processor = new \WP_HTML_Tag_Processor( $html );
			while ( $processor->next_tag( 'a' ) ) {
				$href = (string) $processor->get_attribute( 'href' );
				if ( '' !== $href ) {
					$hrefs[] = $href;
				}
			}

			return $hrefs;
		}

		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $matches );

		return $matches[1] ?? [];
	}

	/**
	 * Enumerate in-scope objects and enqueue one extract row per object and
	 * language. Returns the number of newly enqueued rows.
	 */
	public function enqueue_for_languages( ?array $languages = null ): int {
		$languages = $languages ?? TranslationProxySettings::active_lookup_languages();
		$languages = array_values( array_filter( array_map( 'trim', $languages ) ) );

		// The source (default) locale is the untranslated original: never enqueue
		// it for extraction or translation. A row for it warms a source->source
		// job the API cannot resolve, so the language sits forever "Translating"
		// and never completes.
		$default = TranslationProxySettings::default_locale();
		if ( '' !== $default && 'auto' !== $default ) {
			$languages = array_values(
				array_filter(
					$languages,
					static function ( string $language ) use ( $default ): bool {
						return $language !== $default;
					}
				)
			);
		}

		if ( ! $languages ) {
			return 0;
		}

		$pins = get_option( self::PINS_OPTION, [] );
		$pins = is_array( $pins ) ? array_map( 'intval', $pins ) : [];

		$rows = [];
		foreach ( $this->enumerate_objects() as $post_id => $post_type ) {
			$url = self::object_url( (int) $post_id, (string) $post_type );
			if ( ! is_string( $url ) || '' === $url || ! TranslationUrl::is_indexable_url( $url ) ) {
				continue;
			}

			$page_title = self::object_title( (int) $post_id, (string) $post_type );

			$priority = $this->base_priority( (int) $post_id, (string) $post_type, $pins );
			/**
			 * Priority resolver: manual pins beat everything; global
			 * language popularity slots between pins and the rest once
			 * the demand data is exposed to the plugin.
			 */
			$priority = (int) apply_filters( 'translatewp/indexer_priority', $priority, (int) $post_id, $post_type );

			foreach ( $languages as $language ) {
				$rows[] = [
					'object_type' => $post_type,
					'object_id'   => (int) $post_id,
					'url'         => $url,
					'page_title'  => $page_title,
					'language'    => $language,
					'priority'    => $priority,
				];
			}
		}

		if ( ! $rows ) {
			return 0;
		}

		$reported = get_option( self::REPORTED_OPTION, [] );
		$reported = is_array( $reported ) ? $reported : [];
		update_option( self::REPORTED_OPTION, array_values( array_diff( $reported, $languages ) ) );

		$enqueued = $this->queue->enqueue( $rows );
		self::schedule_drain();

		return $enqueued;
	}

	public function claim_extract_batch( int $limit = self::BATCH_SIZE ): array {
		return $this->queue->claim_batch( IndexQueue::STAGE_EXTRACT, $limit );
	}

	public function submit_extraction( int $id, array $items, bool $is_final = true ): bool {
		// A non-final batch buffers on the row and stays in the extract stage; the
		// final batch stores the whole payload (or completes an empty page). An
		// empty final submission is a finished, translation-free page, not a
		// failure.
		$stored = $this->queue->append_extraction( $id, $items, $is_final );

		if ( $stored && $is_final && $items ) {
			self::schedule_drain();
		}

		if ( $stored && $is_final ) {
			$this->translate_reread_page( $id );
		}

		return $stored;
	}

	/**
	 * A page read again because it changed is translated again as soon as the
	 * new reading lands; unchanged text hits the service cache.
	 */
	private function translate_reread_page( int $id ): void {
		$read_row = $this->queue->take_translate_on_read( $id );
		if ( null === $read_row ) {
			return;
		}

		$languages = $this->target_languages( null );
		if ( $languages && $this->fan_out_read_rows( [ $read_row ], $languages, true ) > 0 ) {
			self::schedule_drain();
			LoopbackKick::fire();
		}
	}

	/**
	 * Revive one dead page and claim it for a fresh extraction in the admin tab.
	 * Returns the claimed row, or null when the row is not a failed page.
	 */
	public function reextract_row( int $id ): ?array {
		return $this->queue->revive_failed_row( $id );
	}

	public function mark_extract_failed( int $id, string $reason ): bool {
		return $this->queue->fail( $id, $reason );
	}

	/**
	 * Manual priority pins (C3): replace the pin set and re-prioritize any
	 * queued rows so pinned objects drain first.
	 */
	public function set_priority_pins( array $object_ids ): array {
		$object_ids = array_values( array_unique( array_filter( array_map( 'intval', $object_ids ) ) ) );
		update_option( self::PINS_OPTION, $object_ids );
		$this->queue->reprioritize( $object_ids, self::PRIORITY_PINNED, self::PRIORITY_DEFAULT );

		return $object_ids;
	}

	public function priority_pins(): array {
		$pins = get_option( self::PINS_OPTION, [] );

		return is_array( $pins ) ? array_values( array_map( 'intval', $pins ) ) : [];
	}

	public function failed_items( ?string $language = null ): array {
		return $this->queue->failed_items( $language );
	}

	/**
	 * Re-queue the dead rows (optionally one language) with their attempts
	 * reset and schedule a drain if anything was revived.
	 */
	public function retry_failed( ?string $language = null ): int {
		$revived = $this->queue->retry_failed( $language );
		if ( $revived > 0 ) {
			self::schedule_drain();
			LoopbackKick::fire();
		}

		return $revived;
	}

	public function clear_failed( ?string $language = null ): int {
		return $this->queue->clear_failed( $language );
	}

	/**
	 * Re-queue the given pages for a forced retranslation pass and schedule a
	 * drain. Each row is built by the caller as [ object_type, object_id, url,
	 * page_title, language, priority ]. Returns how many rows were (re)queued.
	 */
	public function retranslate( array $rows ): int {
		$queued = $this->queue->enqueue_retranslate( $rows );
		if ( $queued > 0 ) {
			self::schedule_drain();
		}

		return $queued;
	}

	/**
	 * Translate every indexed page again, of every translation language or of
	 * the given ones: the explicit path a saved change to the brief, style or
	 * model takes, and the "Retranslate all" action. Returns the rows queued
	 * and the languages touched.
	 */
	public function retranslate_everything( array $only_languages = [] ): array {
		$rows      = [];
		$purges    = [];
		$languages = [];
		foreach ( $this->page_rows() as $row ) {
			$language = (string) $row['language'];
			if ( ! IndexQueue::is_target_row_language( $language ) || ( $only_languages && ! in_array( $language, $only_languages, true ) ) ) {
				continue;
			}
			$languages[ $language ] = true;
			$rows[]                 = [
				'object_type' => (string) $row['object_type'],
				'object_id'   => (int) $row['object_id'],
				'url'         => (string) $row['url'],
				'page_title'  => (string) $row['page_title'],
				'language'    => $language,
				'priority'    => self::PRIORITY_DEFAULT,
			];
			$purges[]               = [
				'url'     => (string) $row['url'],
				'post_id' => self::MISS_OBJECT_TYPE === (string) $row['object_type'] ? 0 : (int) $row['object_id'],
				'locale'  => $language,
			];
		}

		$requeued = $this->retranslate( $rows );
		$site_id  = Sites::registered_id();
		foreach ( $purges as $purge ) {
			$hashes = TranslationStore::clear_entry( $purge['url'], $purge['post_id'], $purge['locale'] );
			if ( '' !== $site_id && $hashes ) {
				TranslationProxyDedupe::release_segments( $site_id, $purge['locale'], $hashes );
			}
		}
		if ( $languages ) {
			TranslationStore::bump_cache_generation( array_keys( $languages ) );
		}

		return [
			'requeued'  => $requeued,
			'languages' => array_keys( $languages ),
		];
	}

	/**
	 * Heal cold pages. A page with a site reading is pushed again from it as an
	 * ordinary push: the service serves the pieces it already holds from its
	 * cache and translates only what is missing, and no page waits for an
	 * extraction nobody runs. A page without a reading falls back to a fresh
	 * extraction. Pages only a visitor opened are left to the visitor path.
	 */
	public function self_heal_cold( array $rows, int $max_attempts ): array {
		$reads = [];
		foreach ( $this->queue->read_rows() as $read_row ) {
			$reads[ $read_row['object_type'] . ':' . $read_row['object_id'] ] = $read_row;
		}

		$result    = [
			'requeued' => 0,
			'failed'   => 0,
		];
		$reextract = [];
		foreach ( $rows as $row ) {
			if ( self::MISS_OBJECT_TYPE === (string) $row['object_type'] ) {
				continue;
			}
			$read_row = $reads[ $row['object_type'] . ':' . $row['object_id'] ] ?? null;
			if ( null === $read_row ) {
				$reextract[] = $row;
				continue;
			}
			if ( ! $this->queue->charge_cold_retry( (int) $row['id'], $max_attempts ) ) {
				++$result['failed'];
				continue;
			}
			$result['requeued'] += $this->fan_out_read_rows( [ $read_row ], [ (string) $row['language'] ], true );
		}

		if ( $reextract ) {
			$fallback            = $this->queue->requeue_cold( $reextract, $max_attempts );
			$result['requeued'] += $fallback['requeued'];
			$result['failed']   += $fallback['failed'];
		}

		if ( $result['requeued'] > 0 ) {
			self::schedule_drain();
			LoopbackKick::fire();
		}
		return $result;
	}

	/**
	 * Whether the translation job a row was pushed in has finished. A row with
	 * no remembered job, or a job the service no longer knows, counts as
	 * finished; a failed status read does not.
	 *
	 * @param int   $row_id Queue row whose job is checked.
	 * @param array $known  Job states already read during one reconcile pass.
	 */
	public function job_finished_for_row( int $row_id, array &$known ): bool {
		return in_array( $this->job_outcome_for_row( $row_id, $known ), [ 'done', 'cancelled', 'unknown' ], true );
	}

	/**
	 * What became of the job a row was pushed to: 'done', 'cancelled',
	 * 'unknown' (the service no longer has it, or none was recorded) or
	 * 'open'. One status call per job per pass through $known.
	 */
	public function job_outcome_for_row( int $row_id, array &$known ): string {
		$job_id = $this->queue->job_id( $row_id );
		if ( '' === $job_id ) {
			return 'unknown';
		}
		if ( ! isset( $known[ $job_id ] ) ) {
			$status = SiteRequests::get( '/v1/translation/jobs/' . rawurlencode( $job_id ) . '/status' );
			if ( is_wp_error( $status ) ) {
				$data             = (array) $status->get_error_data();
				$known[ $job_id ] = 404 === (int) ( $data['status'] ?? 0 ) ? 'unknown' : 'open';
			} else {
				$state            = (string) ( $status['state'] ?? '' );
				$known[ $job_id ] = in_array( $state, [ 'done', 'cancelled' ], true ) ? $state : 'open';
			}
		}
		return $known[ $job_id ];
	}

	public function reset_cold_retries( array $ids ): void {
		$this->queue->reset_cold_retries( $ids );
	}

	public function mark_translated( array $ids ): void {
		$this->queue->mark_translated( $ids );
	}

	public function retry_dead_automatically( int $max_attempts, int $backoff_seconds, int $now ): int {
		$revived = $this->queue->retry_dead_automatically( $max_attempts, $backoff_seconds, $now );
		if ( $revived > 0 ) {
			self::schedule_drain();
			LoopbackKick::fire();
		}
		return $revived;
	}

	/**
	 * Every queue row for the admin page list: object identity, url/title,
	 * language, stage/status, and the retranslate flag.
	 */
	public function page_rows( bool $include_read = false ): array {
		$rows = $this->queue->list_rows();
		if ( $include_read ) {
			foreach ( $this->queue->read_rows() as $read ) {
				$rows[] = $read + [
					'language' => '',
					'status'   => IndexQueue::STATUS_DONE,
					'reason'   => '',
					'updated'  => 0,
				];
			}
		}

		return $rows;
	}

	/**
	 * Drop duplicate queue rows for the same page and language, keeping the
	 * newest of each. Returns how many stale rows were removed.
	 */
	public function collapse_stale_duplicates(): int {
		return $this->queue->collapse_stale_duplicates();
	}

	public static function schedule_drain( int $delay_seconds = 0 ): void {
		if ( ! wp_next_scheduled( self::DRAIN_HOOK ) ) {
			wp_schedule_single_event( time() + $delay_seconds, self::DRAIN_HOOK );
		}
	}

	/**
	 * How many rows one drain claims. Filterable so a site can trade a longer
	 * tick for fewer waits between ticks.
	 */
	public static function drain_batch_size(): int {
		return max( 1, (int) apply_filters( 'translatewp_drain_batch_size', self::BATCH_SIZE ) );
	}

	/** How long one drain may run before it releases what it has not started. */
	public static function drain_tick_budget(): float {
		return max( 1.0, (float) apply_filters( 'translatewp_drain_tick_budget', self::TICK_BUDGET_SECONDS ) );
	}

	/** How long to wait before the next drain while work is still queued. */
	public static function drain_reschedule_delay(): int {
		return max( 0, (int) apply_filters( 'translatewp_drain_reschedule_delay', self::RESCHEDULE_DELAY_SECONDS ) );
	}

	/**
	 * One cron tick: claim up to BATCH_SIZE push rows and relay each stored
	 * payload through the remote job pipeline within the tick budget.
	 */
	public function drain_tick(): array {
		$started = ( $this->clock )();
		$result  = [
			'processed' => 0,
			'failed'    => 0,
			'released'  => 0,
		];

		if ( ! SiteConnection::is_connected() ) {
			return $result;
		}

		update_option( self::LAST_DRAIN_OPTION, time() );

		$rows = $this->queue->claim_batch( IndexQueue::STAGE_PUSH, self::drain_batch_size() );

		// Group rows per language and retranslate flag: one job + one batch push
		// per group per tick instead of one HTTP round-trip per row. Retranslate
		// rows form their own batch so the forced-overwrite flag never charges a
		// normal warm-up row a fresh translation.
		$groups = [];
		foreach ( $rows as $index => $row ) {
			if ( ( ( $this->clock )() - $started ) >= self::drain_tick_budget() ) {
				foreach ( array_slice( $rows, $index ) as $unprocessed ) {
					$this->queue->release( (int) $unprocessed['id'] );
					$result['released']++;
				}
				break;
			}

			$items = json_decode( (string) $row['payload'], true );
			if ( ! is_array( $items ) || ! $items ) {
				$this->queue->fail( (int) $row['id'], 'invalid_stored_payload' );
				$result['failed']++;
				continue;
			}

			$this->add_row_to_groups( $groups, $row, $items );
		}

		foreach ( $groups as $group ) {
			$this->push_language_rows( (string) $group['language'], $group['rows'], $result, (bool) $group['retranslate'] );
		}

		$counts = $this->queue->counts();
		$this->report_completed_languages( $counts );

		if ( $this->has_pending( $counts ) ) {
			self::schedule_drain( self::drain_reschedule_delay() );
		}

		return $result;
	}

	/**
	 * Push one language's claimed rows as a single job/batch; charges
	 * failures and completes successes on the queue.
	 *
	 * @param string $language      Target language of every row.
	 * @param array  $language_rows Rows of [ id, items ].
	 * @param array  $result        Drain counters, mutated in place.
	 * @param bool   $retranslate   Force the API to overwrite its cache for this
	 *                              batch instead of treating a fingerprint match
	 *                              as a cache hit.
	 */
	private function push_language_rows( string $language, array $language_rows, array &$result, bool $retranslate = false ): void {
		$job = $this->create_job( $language );
		if ( '' === $job['id'] ) {
			foreach ( $language_rows as $language_row ) {
				$this->release_row_claims( $language, $language_row['items'] );
				$this->queue->fail( $language_row['id'], $job['reason'] );
				$result['failed']++;
			}
			return;
		}
		$job_id = $job['id'];

		// Every segment leaves under the fingerprint this site computes for
		// it (its source language, the target and its own model profile),
		// whatever hash the extracting browser attached; the miss proxy, the
		// store look-ups and the visitor cache all key on that same
		// fingerprint. An index row then claims every segment for the dedupe
		// window before it leaves, and a segment another request already
		// holds in flight (a visitor's job) stays out of this batch: the store
		// back-fill resolves it from the cache once that job lands. A miss row
		// arrives with the claims its proxy request took and is pushed as it
		// is. Claims are released on failure here and by the store as each
		// hash resolves.
		$site_id = Sites::registered_id();
		$readiness_items = [];
		foreach ( $language_rows as &$language_row ) {
			if ( self::MISS_OBJECT_TYPE === (string) ( $language_row['object_type'] ?? '' ) ) {
				$readiness_items[ (int) $language_row['id'] ] = $language_row['items'];
				continue;
			}
			$title = trim( (string) ( $language_row['page_title'] ?? '' ) );
			if ( '' !== $title ) {
				$language_row['items'][] = [
					'groupId'       => 'seo:' . self::TITLE_CONTEXT_KEY,
					'markerPayload' => self::TITLE_MARKER . $title,
					'contextKey'    => self::TITLE_CONTEXT_KEY,
					'contextKind'   => TranslationFingerprint::CONTEXT_KIND_ATTRIBUTE,
				];
			}
			$language_row['items'] = $this->stamp_hashes( $language, $language_row['items'] );
			$readiness_items[ (int) $language_row['id'] ] = $language_row['items'];
			// A forced retranslation overwrites whatever is in flight or
			// cached, so it pushes every segment.
			if ( ! empty( $language_row['retranslate'] ) ) {
				continue;
			}
			$owned = [];
			foreach ( $language_row['items'] as $item ) {
				$hash = is_array( $item ) ? (string) ( $item['cache']['hash'] ?? '' ) : '';
				if ( '' !== $hash ) {
					$claim = TranslationProxyDedupe::claim_segment( $site_id, $language, $hash );
					if ( is_wp_error( $claim ) && 'duplicate_segment' === $claim->get_error_code() ) {
						continue;
					}
				}
				$owned[] = $item;
			}
			$language_row['items'] = $owned;
		}
		unset( $language_row );

		$batch_items = [];
		$page_titles = [];
		foreach ( $language_rows as $language_row ) {
			// A combined batch can span several pages; tag each row's items with
			// its own page so the API attributes a failure to the right one.
			$page_url = TranslationUrl::canonicalize( (string) ( $language_row['url'] ?? '' ) );
			foreach ( $language_row['items'] as $item ) {
				if ( '' !== $page_url && is_array( $item ) ) {
					$item['pageUrl'] = $page_url;
				}
				$batch_items[] = $item;
			}

			$page_title = (string) ( $language_row['page_title'] ?? '' );
			if ( '' !== $page_title ) {
				$page_titles[] = $page_title;
			}
		}

		if ( ! $batch_items ) {
			foreach ( $language_rows as $language_row ) {
				TranslationReadiness::record(
					(int) $language_row['id'],
					$language,
					$readiness_items[ (int) $language_row['id'] ] ?? [],
					! in_array( (string) ( $language_row['object_type'] ?? '' ), [ self::MISS_OBJECT_TYPE ], true )
				);
				$this->queue->complete( $language_row['id'] );
				// Nothing was sent: every segment was already translated.
				$this->queue->mark_translated( [ (int) $language_row['id'] ] );
				$result['processed']++;
			}
			return;
		}

		$base_body = [
			'batchId'    => 'index-' . (int) $language_rows[0]['id'],
			'items'      => $batch_items,
			'closeInput' => true,
			// Warm-up work yields to text a visitor is waiting on.
			'origin'     => 'index',
			'context'    => TranslationContext::build( $page_titles ),
			'aiSettings' => apply_filters( 'translatewp_translation_ai_settings', TranslationProxySettings::ai_settings( $language ) ),
		];

		if ( $retranslate ) {
			$base_body['retranslate'] = true;
		}

		$batch_body = apply_filters(
			'translatewp_translation_batch_request_body',
			$base_body,
			$job_id,
			$language
		);

		$pushed = TranslationProRetry::post(
			'/v1/translation/jobs/' . rawurlencode( $job_id ) . '/batches',
			$batch_body,
			self::JOB_POST_TIMEOUT_SECONDS,
			$language
		);

		if ( is_wp_error( $pushed ) ) {
			$reason = TranslationProviderFailure::reason( $pushed, $pushed->get_error_code() );
			\TranslateWP\Components\ActionLog::record( sprintf( 'A translation batch for %s was refused: %s (%d rows)', $language, $reason, count( $language_rows ) ), 'error' );
			foreach ( $language_rows as $language_row ) {
				$this->release_row_claims( $language, $language_row['items'] );
				$this->queue->fail( $language_row['id'], $reason );
				$result['failed']++;
			}
			return;
		}

		$pushed_rows = [];
		foreach ( $language_rows as $language_row ) {
			$this->queue->remember_job( (int) $language_row['id'], $job_id );
			TranslationReadiness::record(
				(int) $language_row['id'],
				$language,
				$readiness_items[ (int) $language_row['id'] ] ?? [],
				! in_array( (string) ( $language_row['object_type'] ?? '' ), [ self::MISS_OBJECT_TYPE ], true )
			);
			$this->queue->complete( $language_row['id'] );
			$result['processed']++;
			$pushed_rows[] = $language_row;
		}

		$this->announce_pushed_rows( $language, $pushed_rows );
	}

	/**
	 * Announce a language's just-pushed rows so the translation store can
	 * record their source segments for later back-fill from the API cache.
	 */
	private function announce_pushed_rows( string $language, array $pushed_rows ): void {
		if ( ! $pushed_rows ) {
			return;
		}

		do_action(
			'translatewp_index_rows_pushed',
			$language,
			TranslationProxySettings::default_locale(),
			$pushed_rows
		);
	}

	/**
	 * Release the miss-proxy dedupe claims held by a row's items so a later
	 * visitor miss can re-enqueue the segments after the row fails.
	 */
	private function release_row_claims( string $language, array $items ): void {
		$site_id = Sites::registered_id();
		foreach ( $items as $item ) {
			$hash = (string) ( $item['cache']['hash'] ?? '' );
			if ( '' !== $hash ) {
				TranslationProxyDedupe::release_segment( $site_id, $language, $hash );
			}
		}
	}

	/**
	 * D61 inline fast-path: drain EXACTLY the given queue rows within a tight
	 * budget — a visitor is waiting. Rows that don't fit the budget release
	 * back to the queue without an attempt charge; cron remains primary.
	 */
	public function drain_rows( array $ids, float $budget_seconds = self::INLINE_BUDGET_SECONDS ): array {
		$started = ( $this->clock )();
		$result  = [
			'processed' => 0,
			'failed'    => 0,
			'released'  => 0,
		];

		if ( ! SiteConnection::is_connected() ) {
			return $result;
		}

		$rows = $this->queue->claim_rows( $ids );

		$groups = [];
		foreach ( $rows as $index => $row ) {
			if ( ( ( $this->clock )() - $started ) >= $budget_seconds ) {
				foreach ( array_slice( $rows, $index ) as $unprocessed ) {
					$this->queue->release( (int) $unprocessed['id'] );
					$result['released']++;
				}
				return $result;
			}

			$items = json_decode( (string) $row['payload'], true );
			if ( ! is_array( $items ) || ! $items ) {
				$this->queue->fail( (int) $row['id'], 'invalid_stored_payload' );
				$result['failed']++;
				continue;
			}

			$this->add_row_to_groups( $groups, $row, $items );
		}

		foreach ( $groups as $group ) {
			if ( ( ( $this->clock )() - $started ) >= $budget_seconds ) {
				foreach ( $group['rows'] as $group_row ) {
					$this->queue->release( $group_row['id'] );
					$result['released']++;
				}
				continue;
			}

			$this->push_language_rows( (string) $group['language'], $group['rows'], $result, (bool) $group['retranslate'] );
		}

		return $result;
	}

	/**
	 * Bucket one claimed push row into its language + retranslate group. Rows
	 * carrying the retranslate flag are kept in a separate batch so the
	 * forced-overwrite flag applies only to the pages the operator asked to
	 * refresh.
	 *
	 * @param array $groups Groups keyed by language and flag, mutated in place.
	 * @param array $row    One claimed push row.
	 * @param array $items  The row's decoded push items.
	 */
	/**
	 * Give every item the fingerprint this site computes for it in the given
	 * target language, dropping empty items and repeats of the same
	 * fingerprint.
	 *
	 * @param string      $language    Target language of the items.
	 * @param array       $items       Items with markerPayload and contextKind.
	 * @param string|null $source_lang Source locale; the site default when null.
	 * @param array|null  $ai_settings Model profile; the language's when null.
	 */
	private function stamp_hashes( string $language, array $items, ?string $source_lang = null, ?array $ai_settings = null ): array {
		$source_lang = $source_lang ?? TranslationProxySettings::default_locale();
		$ai_settings = $ai_settings ?? TranslationProxySettings::ai_settings( $language );
		$site_id     = Sites::registered_id();
		$stamped     = [];
		$seen        = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$marker_payload = (string) ( $item['markerPayload'] ?? '' );
			$context_kind   = (string) ( $item['contextKind'] ?? TranslationFingerprint::CONTEXT_KIND_TEXT_FLOW );
			if ( '' === $marker_payload ) {
				continue;
			}
			$hash = TranslationFingerprint::compute_hash( $marker_payload, $source_lang, $language, $context_kind, $ai_settings );
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;
			$item['cache'] = [
				'siteId' => $site_id,
				'hash'   => $hash,
			];
			$stamped[]     = $item;
		}

		return $stamped;
	}

	private function add_row_to_groups( array &$groups, array $row, array $items ): void {
		$language    = (string) $row['language'];
		$retranslate = ! empty( $row['retranslate'] );
		$key         = $language . '|' . ( $retranslate ? '1' : '0' );

		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = [
				'language'    => $language,
				'retranslate' => $retranslate,
				'rows'        => [],
			];
		}

		$groups[ $key ]['rows'][] = [
			'id'          => (int) $row['id'],
			'items'       => $items,
			'url'         => (string) ( $row['url'] ?? '' ),
			'page_title'  => (string) ( $row['page_title'] ?? '' ),
			'object_type' => (string) ( $row['object_type'] ?? '' ),
			'object_id'   => (int) ( $row['object_id'] ?? 0 ),
			'retranslate' => $retranslate,
		];
	}

	/**
	 * Acquire an inline-drain slot. A transient counter caps concurrent
	 * inline drains far below PHP-FPM's worker pool (the D61 occupancy
	 * bound); the TTL auto-releases slots leaked by crashed requests.
	 */
	public static function acquire_inline_slot(): bool {
		$count = (int) get_transient( self::INLINE_SLOT_TRANSIENT );
		if ( $count >= self::INLINE_MAX_CONCURRENT ) {
			return false;
		}

		return set_transient( self::INLINE_SLOT_TRANSIENT, $count + 1, self::INLINE_SLOT_TTL_SECONDS );
	}

	/**
	 * D61: run the inline fast-path for the given rows after the response is
	 * flushed. FPM-only (fastcgi_finish_request); everywhere else the already
	 * fired spawn_cron owns the work. Returns the chosen mode for tests.
	 */
	public static function schedule_inline_drain( array $ids ): string {
		if ( ! function_exists( 'fastcgi_finish_request' ) ) {
			return 'cron';
		}

		if ( ! self::acquire_inline_slot() ) {
			return 'capped';
		}

		register_shutdown_function(
			static function () use ( $ids ): void {
				try {
					fastcgi_finish_request();
					( new self() )->drain_rows( array_map( 'intval', $ids ) );
				} finally {
					self::release_inline_slot();
				}
			}
		);

		return 'inline';
	}

	public static function release_inline_slot(): void {
		$count = (int) get_transient( self::INLINE_SLOT_TRANSIENT );
		if ( $count > 1 ) {
			set_transient( self::INLINE_SLOT_TRANSIENT, $count - 1, self::INLINE_SLOT_TTL_SECONDS );
		} else {
			delete_transient( self::INLINE_SLOT_TRANSIENT );
		}
	}

	/**
	 * Queue status per language plus completion flags — the "indexing n/m"
	 * source of truth for the admin UI.
	 */
	public function status(): array {
		$counts    = $this->queue->counts();
		$reported  = get_option( self::REPORTED_OPTION, [] );
		$reported  = is_array( $reported ) ? $reported : [];
		$rows      = $this->queue->list_rows();
		$indexed   = [];
		$progress  = [];
		$languages = [];

		// Progress counts the pages indexing covers, once each per language. A
		// page only a visitor opened (miss rows alone, no index or read row)
		// still translates but never holds its language back from complete; a
		// miss row standing in for an indexed page counts only while no page
		// row exists for that page and language.
		$page_rows = [];
		foreach ( array_merge( $rows, $this->queue->read_rows() ) as $row ) {
			if ( self::MISS_OBJECT_TYPE !== (string) $row['object_type'] ) {
				$url             = TranslationUrl::canonicalize( (string) $row['url'] );
				$indexed[ $url ] = true;
				if ( '' !== (string) ( $row['language'] ?? '' ) ) {
					$page_rows[ $url . '|' . (string) $row['language'] ] = true;
				}
			}
		}

		foreach ( $rows as $row ) {
			$language = (string) $row['language'];
			if ( '' === $language ) {
				continue;
			}
			if ( self::MISS_OBJECT_TYPE === (string) $row['object_type'] ) {
				$url = TranslationUrl::canonicalize( (string) $row['url'] );
				if ( ! isset( $indexed[ $url ] ) || isset( $page_rows[ $url . '|' . $language ] ) ) {
					continue;
				}
			}

			$progress[ $language ] = $progress[ $language ] ?? [
				'translated' => 0,
				'dead'       => 0,
				'total'      => 0,
			];
			$progress[ $language ]['total']++;
			if ( IndexQueue::STATUS_DEAD === (string) $row['status'] ) {
				$progress[ $language ]['dead']++;
			} elseif ( ! empty( $row['translated'] ) ) {
				$progress[ $language ]['translated']++;
			}
		}

		$first_pass_done     = (int) get_option( self::FIRST_PASS_OPTION, 0 ) > 0;
		$languages_with_pages = 0;
		$languages_settled    = 0;
		$any_translated       = false;
		foreach ( $counts as $language => $count ) {
			$language_progress      = $progress[ $language ] ?? [
				'translated' => 0,
				'dead'       => 0,
				'total'      => 0,
			];
			$settled                = $language_progress['total'] > 0
				&& $language_progress['translated'] + $language_progress['dead'] >= $language_progress['total'];
			if ( $language_progress['total'] > 0 ) {
				++$languages_with_pages;
				$languages_settled += $settled ? 1 : 0;
				$any_translated     = $any_translated || $language_progress['translated'] > 0;
			}
			$languages[ $language ] = $count + [
				'complete'      => $this->is_language_complete( $count ),
				'reported'      => in_array( $language, $reported, true ),
				'ready'         => $language_progress['translated'],
				'readyTotal'    => $language_progress['total'],
				'readyComplete' => $settled,
			];
		}
		// The first pass is over once every language with pages has nothing in
		// flight and at least one of them translated something.
		$all_settled = $languages_with_pages > 0 && $languages_settled === $languages_with_pages && $any_translated;
		if ( $all_settled && ! $first_pass_done ) {
			update_option( self::FIRST_PASS_OPTION, time(), false );
			$first_pass_done = true;
		}

		return [
			'firstPassDone'      => $first_pass_done,
			'languages'          => $languages,
			'read'               => $this->queue->read_counts(),
			'changedPages'       => $this->queue->changed_since_read_count(),
			'scan'               => $this->queue->read_scan_progress(),
			'drainScheduled'     => (bool) wp_next_scheduled( self::DRAIN_HOOK ),
			'scheduledTasksLate' => $this->scheduled_tasks_late( $counts ),
			'lastRun'            => $this->last_drain_run(),
			'failed'             => $this->failed_items_for_status(),
		];
	}

	/**
	 * True when push rows are waiting but the drain is not being serviced: our
	 * own event is not scheduled at all, is overdue by more than two intervals,
	 * or the last drain ran more than the stale window ago. A broken WP-Cron
	 * shows up here even when the event was never left scheduled, which a plain
	 * overdue check misses. Rows still waiting for extraction do not count: the
	 * admin tab extracts them, not a scheduled task, and the page list already
	 * says so per language.
	 */
	public function scheduled_tasks_late( array $counts ): bool {
		if ( ! SiteConnection::is_connected() || ! $this->has_pending( $counts ) ) {
			return false;
		}

		// A drain that ran inside the stale window means the work is moving,
		// whether a scheduled event, a page hit or the loopback kick ran it;
		// a host with no cron at all is not late while its kicks keep draining.
		$last_run = $this->last_drain_run();
		if ( null !== $last_run && $last_run > time() - self::LAST_DRAIN_STALE_SECONDS ) {
			return false;
		}

		$next = wp_next_scheduled( self::DRAIN_HOOK );
		if ( false === $next ) {
			return true;
		}

		if ( (int) $next <= time() - self::SCHEDULED_LATE_INTERVALS * self::RESCHEDULE_DELAY_SECONDS ) {
			return true;
		}

		return null !== $last_run;
	}

	private function last_drain_run(): ?int {
		$stored = (int) get_option( self::LAST_DRAIN_OPTION, 0 );

		return $stored > 0 ? $stored : null;
	}

	/**
	 * Dead rows shaped for the admin UI: camelCase object fields, the page url,
	 * and the stored failure reason.
	 */
	private function failed_items_for_status(): array {
		return array_map(
			static function ( array $item ): array {
				return [
					'id'         => (int) $item['id'],
					'language'   => (string) $item['language'],
					'url'        => (string) $item['url'],
					'reason'     => (string) $item['reason'],
					'objectType' => (string) $item['object_type'],
					'objectId'   => (int) $item['object_id'],
					'attempts'   => (int) $item['attempts'],
				];
			},
			$this->queue->failed_items()
		);
	}

	/**
	 * Opens a job for the language. On failure the reason names what the
	 * service said: a used-up plan allowance, an hourly cap, or a plain
	 * transport failure, so the page's line can say which.
	 *
	 * @return array{id:string,reason:string}
	 */
	private function create_job( string $language ): array {
		$job_body = apply_filters(
			'translatewp_translation_job_request_body',
			[
				// The visitor pipeline fingerprints with the site default
				// locale as sourceLang — the warm-up must match it.
				'sourceLang' => TranslationProxySettings::default_locale(),
				'targetLang' => $language,
				'context'    => TranslationContext::build(),
				'aiSettings' => apply_filters( 'translatewp_translation_ai_settings', TranslationProxySettings::ai_settings( $language ) ),
			]
		);

		$response = TranslationProRetry::post(
			'/v1/translation/jobs',
			$job_body,
			self::JOB_POST_TIMEOUT_SECONDS
		);

		if ( is_wp_error( $response ) ) {
			return [
				'id'     => '',
				'reason' => self::job_failure_reason( $response ),
			];
		}

		return [
			'id'     => sanitize_text_field( (string) ( $response['jobId'] ?? '' ) ),
			'reason' => '',
		];
	}

	/**
	 * The queue reason for a refused job: quota_exceeded for a used-up plan
	 * allowance, rate_limited for an hourly cap, job_create_failed otherwise.
	 */
	public static function job_failure_reason( \WP_Error $error ): string {
		$data    = (array) $error->get_error_data();
		$status  = (int) ( $data['status'] ?? 0 );
		$payload = is_array( $data['payload'] ?? null ) ? $data['payload'] : [];
		$code    = sanitize_key( (string) ( $payload['code'] ?? '' ) );

		if ( 'limit_exceeded' === $code ) {
			return 'quota_exceeded';
		}
		if ( 429 === $status || 'rate_limited' === $code ) {
			return 'rate_limited';
		}

		return 'job_create_failed';
	}

	/**
	 * A drained queue counts as first-pass complete only if something was
	 * actually warmed — an all-dead queue must not end the quota-free window.
	 */
	private function is_language_complete( array $count ): bool {
		return $count['done'] > 0
			&& 0 === $count['pending_extract']
			&& 0 === $count['pending_push']
			&& $count['done'] + $count['dead'] === $count['total'];
	}

	private function has_pending( array $counts ): bool {
		foreach ( $counts as $count ) {
			if ( $count['pending_push'] > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * First-pass completion: languages whose queue fully drained are reported
	 * to the API once (stamps first_pass_completed_at; the API stamps
	 * initial_index_completed_at when every language is done).
	 */
	private function report_completed_languages( array $counts ): void {
		$reported = get_option( self::REPORTED_OPTION, [] );
		$reported = is_array( $reported ) ? $reported : [];
		$changed  = false;

		foreach ( $counts as $language => $count ) {
			if ( ! $this->is_language_complete( $count ) || in_array( $language, $reported, true ) ) {
				continue;
			}

			$response = SiteRequests::post(
				'/v1/site/index-complete',
				[ 'language' => (string) $language ],
				self::JOB_POST_TIMEOUT_SECONDS
			);

			if ( ! is_wp_error( $response ) ) {
				$reported[] = (string) $language;
				$changed    = true;
			}
		}

		if ( $changed ) {
			update_option( self::REPORTED_OPTION, array_values( $reported ) );
			PageCachePurge::purge_all( 'first_pass_complete' );
		}
	}
}
