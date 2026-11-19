<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\PlainText;
use TranslateWP\Components\ProviderHold;
use TranslateWP\Components\CacheVersions;
use TranslateWP\Components\PageExclusion;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationStore;
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
use TranslateWP\Components\RenderedPageExtraction;
use TranslateWP\Components\CrawlerRefillBudget;
use TranslateWP\Components\QueueExecution;
use TranslateWP\Components\ReadinessBudget;

/**
 * Warm-up indexer (C3): enumerates public content into the index queue and
 * drains rendered-page extraction and translation pushes on WP-Cron.
 */
class Indexer {
	public const JOB_POLL_CURSOR_OPTION = 'translatewp_job_poll_cursor';
	public const NEXT_READER_OPTION = 'translatewp_reader_next_at';
	public const SPINE_PENDING_OPTION = 'translatewp_spine_refresh_pending';
	public const SPINE_CHECKED_OPTION = 'translatewp_spine_checked';
	public const LOOPBACK_ERROR_TRANSIENT = 'translatewp_reader_loopback_error';
	public const READER_FAILURE_OPTION = 'translatewp_reader_failure';
	private const TERMINAL_JOB_OUTCOMES = [ 'done', 'cancelled', 'failed', 'expired', 'unknown' ];


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
	public const LAST_DRAIN_OPTION = 'translatewp_index_last_drain';
	private const JOB_POST_TIMEOUT_SECONDS = 30;
	private const FALLBACK_LINK_CAP = 50;
	private const TITLE_CONTEXT_KEY = 'seo:title';
	private const TITLE_MARKER       = '⟪item_0001⟫';
	public const REPAIR_JOB_FIELD = 'purpose';
	public const REPAIR_JOB_PURPOSE = 'background_refresh';
	public const REPAIR_RETRY_OPTION = 'translatewp_background_refresh_retry_at';
	public const REPAIR_RETRY_SECONDS = 7200;
	public const REPAIR_PAGE_LIMIT = 50;
	private const PAGE_FETCH_TIMEOUT_SECONDS = 8;
	private const PAGE_MAX_BYTES = \TranslateWP\Components\BackgroundEngine::MAX_PAGE_BYTES;
	private const PAGE_MAX_REDIRECTS = 3;

	private IndexQueue $queue;

	/**
	 * Monotonic clock, injectable for budget tests.
	 *
	 * @var callable(): float
	 */
	private $clock;

	public function __construct( $plugin_name = '', $plugin_version = '', ?IndexQueue $queue = null, ?callable $clock = null, bool $register_hooks = true ) {
		unset( $plugin_name, $plugin_version );

		$this->queue = $queue ?? new IndexQueue();
		$this->clock = $clock ?? static function (): float {
			return microtime( true );
		};

		if ( ! $register_hooks ) {
			return;
		}

		add_action( 'init', [ IndexQueue::class, 'register_post_type' ], 5 );
		add_action( 'translatewp_languages_saved', [ $this, 'on_languages_saved' ] );
		add_action( 'update_option_page_on_front', [ $this, 'schedule_spine_refresh' ] );
		add_action( 'update_option_show_on_front', [ $this, 'schedule_spine_refresh' ] );
		add_action( 'wp_update_nav_menu', [ $this, 'schedule_spine_refresh' ] );
		add_action( 'save_post_wp_navigation', [ $this, 'schedule_spine_refresh' ] );
	}

	public function ensure_spine(): void {
		if ( ! \TranslateWP\Components\BackgroundSwitch::off() && SiteConnection::is_connected() && ! get_option( self::SPINE_CHECKED_OPTION, false ) ) {
			update_option( self::SPINE_CHECKED_OPTION, true, false );
			$this->schedule_spine_refresh();
		}
	}

	public function schedule_spine_refresh(): void {
		if ( ! \TranslateWP\Components\BackgroundSwitch::off() && SiteConnection::is_connected() ) {
			update_option( self::SPINE_PENDING_OPTION, true, false );
			self::schedule_drain();

		}
	}

	public static function crawler_object_id( int $post_id, string $url ): int {
		return $post_id > 0 || self::NOT_FOUND_OBJECT_ID === $post_id ? $post_id : (int) hexdec( substr( hash( 'sha256', TranslationUrl::canonicalize( $url ) ), 0, 7 ) );
	}

	public static function refill_crawler_page( int $post_id, string $url, string $language ): void {
		if ( \TranslateWP\Components\BackgroundSwitch::off() || ! SiteConnection::is_connected() || 0 >= $post_id || 'publish' !== get_post_status( $post_id ) || PageExclusion::is_excluded( $post_id ) || ! in_array( $language, self::target_languages( null ), true ) ) {
			return;
		}
		$url = (string) get_permalink( $post_id );
		$queue = new IndexQueue();
		foreach ( $queue->failed_items( $language ) as $failed ) {
			if ( (int) ( $failed['object_id'] ?? 0 ) === $post_id ) {
				return;
			}
		}
		$key = 'twp_crawler_refill_' . md5( TranslationUrl::canonicalize( $url ) . '|' . $language );
		if ( get_transient( $key ) || ! CrawlerRefillBudget::admit() ) {
			return;
		}
		set_transient( $key, true, self::RESCHEDULE_DELAY_SECONDS );
		$type = $post_id > 0 ? (string) get_post_type( $post_id ) : ( self::NOT_FOUND_OBJECT_ID === $post_id ? self::NOT_FOUND_OBJECT_TYPE : 'archive' );
		$id = self::crawler_object_id( $post_id, $url );
		$row = [
			'object_type' => $type,
			'object_id' => $id,
			'url' => $url,
			'page_title' => $post_id > 0 ? PlainText::title( $post_id ) : '',
			'language' => $language,
			'priority' => self::PRIORITY_HOME,
		];
		if ( ! $queue->read_items( $type, $id ) ) {
			$queue->enqueue_read( [ $row ] );
		}
		$queue->enqueue( [ $row ] );
		$queue->enqueue_rewarm( [ $row ] );
		self::schedule_drain();
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
	public function enqueue_read_rows( bool $translate = false ): int {
		$this->queue->delete_unpublished_rows();
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

		if ( $translate ) {
			usort(
				$rows,
				static function ( array $left, array $right ): int {
					$priority = $left['priority'] <=> $right['priority'];
					return 0 !== $priority ? $priority : ( $left['object_id'] <=> $right['object_id'] );
				}
			);
			$rows = array_slice( $rows, 0, self::REPAIR_PAGE_LIMIT );
		}
		$queued = $rows ? $this->queue->enqueue_read( $rows ) : 0;
		if ( $translate ) {
			$this->queue->translate_after_reading( $rows );
		}
		if ( $queued > 0 ) {
			self::schedule_drain();

		}
		return $queued;
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
		$languages = self::target_languages( $languages );
		if ( ! $languages ) {
			return 0;
		}

		$read_rows = $this->queue->read_rows();
		if ( ! $read_rows ) {
			return $this->enqueue_for_languages( $languages );
		}

		$queued = $this->fan_out_read_rows( $read_rows, $languages, $force );
		\TranslateWP\Components\ActionLog::record( sprintf( '%s queued %d page translations for %s', $force ? 'Forced retranslation' : 'Translation run', $queued, implode( ', ', $languages ) ) );

		\TranslateWP\Components\TranslationProxyRateLimit::seed_completed_languages( Sites::registered_id() );
		$reported = get_option( self::REPORTED_OPTION, [] );
		$reported = is_array( $reported ) ? $reported : [];
		update_option( self::REPORTED_OPTION, array_values( array_diff( $reported, $languages ) ) );

		if ( $queued > 0 ) {
			self::schedule_drain();

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

		if ( $force && QueueExecution::in_step() && 1 === count( $read_rows ) && count( $languages ) > 1 ) {
			$source = reset( $read_rows );
			$payloads = [];
			foreach ( $languages as $language ) {
				$items = $this->stamp_hashes( $language, $source['items'], $source_lang );
				if ( $items ) {
					$payloads[ $language ] = (string) wp_json_encode( $items );
				}
			}
			$promoted = $this->queue->promote_read_translations( $source, $payloads );
			$queued += count( $promoted );
			$languages = array_diff( $languages, $promoted );
		}
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
						'promote_extract' => $force,
					]
				);
				if ( false !== $row_id ) {
					if ( $force ) {
						$this->queue->track_refill( (int) $row_id );
					}
					$queued++;
				}
			}
		}

		return $queued;
	}

	/** The target languages to translate into: the given or active ones, minus the source. */
	private static function target_languages( ?array $languages ): array {
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
				return ( (int) $post_id <= 0 || 'publish' === get_post_status( (int) $post_id ) ) && ! PageExclusion::is_excluded( (int) $post_id );
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

		$menu_ids = array_merge( $menu_ids, $this->block_navigation_ids() );
		$fallback = $menu_ids ? $menu_ids : $this->front_page_link_ids();
		if ( ! $fallback ) {
			$fallback = $this->sitemap_ids( $types );
		}
		$spine_ids = array_merge( $spine_ids, $fallback );

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

	private function block_navigation_ids(): array {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return [];
		}
		$ids = [];
		$visit = static function ( array $blocks ) use ( &$visit, &$ids ): void {
			foreach ( $blocks as $block ) {
				if ( in_array( $block['blockName'] ?? '', [ 'core/navigation-link', 'core/navigation-submenu' ], true ) ) {
					$id = (int) ( $block['attrs']['id'] ?? 0 );
					$url = (string) ( $block['attrs']['url'] ?? '' );
					if ( 0 >= $id && '' !== $url ) {
						$host = (string) wp_parse_url( $url, PHP_URL_HOST );
						if ( '' === $host || (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) === $host ) {
							$id = (int) url_to_postid( $url );
						}
					}
					if ( 0 < $id ) {
						$ids[] = $id;
					}
				}
				$visit( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [] );
			}
		};
		foreach ( get_posts(
            [
				'post_type' => 'wp_navigation',
				'post_status' => 'publish',
				'numberposts' => self::FALLBACK_LINK_CAP,
			]
        ) as $navigation ) {
			$visit( parse_blocks( $navigation->post_content ) );
		}
		return array_values( array_unique( $ids ) );
	}

	private function sitemap_ids( array $types ): array {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
			return [];
		}
		$provider = wp_sitemaps_get_server()->registry->get_provider( 'posts' );
		if ( ! $provider ) {
			return [];
		}
		$ids = [];
		foreach ( $types as $type ) {
			foreach ( $provider->get_url_list( 1, $type ) as $entry ) {
				$id = (int) url_to_postid( (string) ( $entry['loc'] ?? '' ) );
				if ( 0 < $id ) {
					$ids[] = $id;
				}
				if ( count( $ids ) >= self::FALLBACK_LINK_CAP ) {
					return array_values( array_unique( $ids ) );
				}
			}
		}
		return array_values( array_unique( $ids ) );
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

		$html = $this->fetch_page( $front_url );
		if ( is_wp_error( $html ) ) {
			$error_data = $html->get_error_data();
			if ( 'page_redirected' === $html->get_error_code() && ! empty( $error_data['target'] ) ) {
				update_post_meta( (int) $row['id'], '_twp_redirect_target', $error_data['target'] );
			}
			$this->reader_failed( $html->get_error_code() );
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

		\TranslateWP\Components\TranslationProxyRateLimit::seed_completed_languages( Sites::registered_id() );
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

		$languages = self::target_languages( null );
		if ( $languages && $this->fan_out_read_rows( [ $read_row ], $languages, true ) > 0 ) {
			self::schedule_drain();

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
				'id' => (int) $row['id'],
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
		foreach ( $rows as $row ) {
			$key = $row['object_type'] . ':' . $row['object_id'];
			$reads[ $key ] = $this->queue->read_row_for_object( (string) $row['object_type'], (int) $row['object_id'] );
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
	public function job_finished_for_row( int $row_id, array &$known, bool $poll = true ): bool {
		return in_array( $this->job_outcome_for_row( $row_id, $known, $poll ), self::TERMINAL_JOB_OUTCOMES, true );
	}

	/**
	 * What became of the job a row was pushed to: 'done', 'cancelled',
	 * 'unknown' (the service no longer has it, or none was recorded) or
	 * 'open'. One status call per job per pass through $known.
	 */
	public function job_outcome_for_row( int $row_id, array &$known, bool $poll = true ): string {
		$job_id = $this->queue->job_id( $row_id );
		if ( '' === $job_id ) {
			return $this->queue->job_outcome( $row_id );
		}
		if ( ! isset( $known[ $job_id ] ) && $poll ) {
			$known[ $job_id ] = $this->read_job_outcome( $job_id, \TranslateWP\Components\SiteRequests::DEFAULT_TIMEOUT_SECONDS );
		}
		$outcome = $known[ $job_id ] ?? 'open';
		return 'unavailable' === $outcome ? 'open' : $outcome;
	}

	private function read_job_outcome( string $job_id, float $timeout ): string {
		$status = SiteRequests::get( '/v1/translation/jobs/' . rawurlencode( $job_id ) . '/status', [], $timeout );
		if ( is_wp_error( $status ) ) {
			$data = (array) $status->get_error_data();
			$state = in_array( (int) ( $data['status'] ?? 0 ), [ 404, 410 ], true ) ? 'unknown' : 'unavailable';
		} else {
			$state = (string) ( $status['state'] ?? '' );
			$state = in_array( $state, [ 'finished', 'completed' ], true ) ? 'done' : ( 'canceled' === $state ? 'cancelled' : $state );
			if ( 'done' === $state || 'cancelled' === $state ) {
				$items = $status['items'] ?? null;
				$reason = ProviderHold::reason( (string) ( $status['failure']['code'] ?? '' ) );
				$watch = get_option( ProviderHold::WATCH_OPTION, [] );
				$own = true === ( $status['byok'] ?? false ) || ( $watch['job'] ?? '' ) === $job_id || ( ProviderHold::state()['probe'] ?? '' ) === $job_id;
				if ( ! is_array( $items ) && $own ) {
					$events = SiteRequests::get( '/v1/site/job-events', [ 'limit' => 100 ], $timeout );
					if ( is_wp_error( $events ) ) {
						return 'unavailable';
					}
					foreach ( $events['events'] ?? [] as $event ) {
						if ( ( $event['detail']['jobId'] ?? '' ) !== $job_id || 'provider_error' !== ( $event['kind'] ?? '' ) ) {
							continue;
						}
						$reason = ProviderHold::reason( (string) ( $event['reasonCode'] ?? '' ) );
						if ( '' !== $reason ) {
							break;
						}
					}
				}
				if ( $own && '' !== $reason ) {
					if ( ( get_option( ProviderHold::WATCH_OPTION, [] )['job'] ?? null ) === $job_id ) {
						delete_option( ProviderHold::WATCH_OPTION );
					}
					ProviderHold::reject( $reason, (int) ( $status['failure']['retryAfterSeconds'] ?? 0 ) );
					$this->queue->retry_provider_job( $job_id );
					return 'failed';
				}
				if ( $own && ! is_array( $items ) && 'done' === $state ) {
					// Older status replies confirm that the job closed, without confirming a translated item.
					$state = 'unknown';
				}
				if ( is_array( $items ) && 0 === ( $items['succeeded'] ?? null ) && ( $items['failed'] ?? 0 ) > 0 ) {
					$state = 'failed';
				}
			}
			$state = 'open' === $state || in_array( $state, self::TERMINAL_JOB_OUTCOMES, true ) ? $state : 'unavailable';
		}
		if ( in_array( $state, self::TERMINAL_JOB_OUTCOMES, true ) ) {
			$this->queue->finish_job( $job_id, $state );
		}
		return $state;
	}

	public function poll_provider_probe(): void {
		$hold = ProviderHold::state();
		$watch = get_option( ProviderHold::WATCH_OPTION, [] );
		if ( ! empty( $watch['job'] ) && ! ProviderHold::waiting() ) {
			$outcome = $this->read_job_outcome( $watch['job'], self::JOB_POST_TIMEOUT_SECONDS );
			if ( 'done' === $outcome ) {
				ProviderHold::verified();
			} elseif ( in_array( $outcome, self::TERMINAL_JOB_OUTCOMES, true ) || time() >= $watch['started'] + 900 ) {
				delete_option( ProviderHold::WATCH_OPTION );
			}
		}
		if ( empty( $hold['probe'] ) ) {
			return;
		}
		$outcome = $this->read_job_outcome( $hold['probe'], self::JOB_POST_TIMEOUT_SECONDS );
		if ( 'unknown' === $outcome ) {
			$answers = TranslationStore::remote_lookup( $hold['language'], [ $hold['hash'] ] );
			if ( ! empty( $answers[ $hold['hash'] ] ) ) {
				$outcome = 'done';
			}
		}
		if ( 'done' === $outcome ) {
			ProviderHold::clear_probe( $hold['probe'] );
		} elseif ( ( in_array( $outcome, self::TERMINAL_JOB_OUTCOMES, true ) || $hold['started'] + 900 <= time() ) && ( ProviderHold::state()['probe'] ?? null ) === $hold['probe'] ) {
			ProviderHold::reject( $hold['reason'] );
		}
	}

	public function poll_remembered_jobs( \TranslateWP\Components\ReadinessBudget $budget, ?array $rows = null ): array {
		$groups = [];
		if ( null === $rows ) {
			$groups = $this->queue->remembered_jobs();
		} else {
			foreach ( $rows as $row ) {
				$id = $this->queue->job_id( (int) $row['id'] );
				if ( '' !== $id ) {
					$groups[ $id ][] = (int) $row['id'];
				}
			}
		}
		$pending = get_option( \TranslateWP\Components\Migrations::JOB_CLEANUP_OPTION, false );
		$ids = array_values( array_unique( array_merge( null === $rows && is_array( $pending ) ? $pending : [], array_keys( $groups ) ) ) );
		$known = [];
		$last = array_search( get_option( self::JOB_POLL_CURSOR_OPTION, '' ), $ids, true );
		$job_count = count( $ids );
		$start = false === $last ? 0 : ( $last + 1 ) % max( 1, $job_count );
		for ( $offset = 0; $offset < $job_count; ++$offset ) {
			$id = $ids[ ( $start + $offset ) % $job_count ];
			if ( ! isset( $groups[ $id ] ) ) {
				$pending = is_array( $pending ) ? array_values( array_diff( $pending, [ $id ] ) ) : $pending;
				continue;
			}
			$timeout = $budget->claim_status_timeout();
			if ( $timeout <= 0 || QueueExecution::expired() ) {
				break;
			}
			$known[ $id ] = $this->read_job_outcome( $id, $timeout );
			update_option( self::JOB_POLL_CURSOR_OPTION, $id, false );
			if ( ProviderHold::waiting() ) {
				break;
			}
			if ( 'unavailable' !== $known[ $id ] && is_array( $pending ) ) {
				$pending = array_values( array_diff( $pending, [ $id ] ) );
			}
		}
		if ( is_array( $pending ) ) {
			update_option( \TranslateWP\Components\Migrations::JOB_CLEANUP_OPTION, $pending, false );
		}
		return $known;
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
		if ( \TranslateWP\Components\BackgroundSwitch::off() || QueueExecution::is_bounded() ) {
			return;
		}
		\TranslateWP\Components\BackgroundEngine::schedule( $delay_seconds );
		\TranslateWP\Components\BackgroundEngine::schedule_wake();
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

	public function drain_tick(): array {
		$result = [
			'processed' => 0,
			'failed' => 0,
			'released' => 0,
		];
		QueueExecution::run(
            function () use ( &$result ): void {
                update_option( self::LAST_DRAIN_OPTION, time(), false );
                $result = $this->step_push( QueueExecution::budget() );
            }
        );
		return $result;
	}

	public function step_read( ReadinessBudget $budget, array $pages = [] ): int {
		if ( ! SiteConnection::is_connected() || $budget->remaining_seconds() <= 0 ) {
			return 0;
		}
		if ( get_option( self::SPINE_PENDING_OPTION, false ) ) {
			delete_option( self::SPINE_PENDING_OPTION );
			$this->enqueue_read_rows( true );
		}
		$supplied = \TranslateWP\Components\BackgroundEngine::service_reads();
		if ( ! $supplied && (int) get_option( self::NEXT_READER_OPTION, 0 ) > time() ) {
			return 0;
		}
		$by_url = array_column( $pages, null, 'url' );
		$processed = 0;
		$candidates = $this->queue->pending_read_rows( $supplied && $by_url ? 50 : 3 );
		$stored = [];
		if ( $supplied ) {
			foreach ( $by_url as $url => $page ) {
				if ( 200 !== (int) ( $page['status'] ?? 0 ) || ! empty( $page['error'] ) || empty( $page['html'] ) ) {
					continue;
				}
				$ids = [];
				foreach ( $candidates as $candidate ) {
					if ( $url === $candidate['url'] && '' === $candidate['language'] ) {
						// The source row controls whether its language rows enter a refill.
						$ids = [];
						break;
					}
					if ( $url === $candidate['url'] && '' !== $candidate['language'] && $this->public_read_row( $candidate ) ) {
						$ids[] = $candidate['id'];
					}
				}
				if ( count( $ids ) < 2 ) {
					continue;
				}
				try {
					$items = RenderedPageExtraction::extract( $page['html'] );
				} catch ( \RuntimeException $error ) {
					continue;
				}
				if ( $items ) {
					$stored += array_fill_keys( $this->queue->store_supplied_extractions( $ids, $url, $items ), true );
				}
			}
		}
		$processed += count( $stored );
		foreach ( $candidates as $position => $candidate ) {
			if ( $by_url && $position >= \TranslateWP\Components\BackgroundEngine::MAX_READ_PAGES && ! isset( $by_url[ $candidate['url'] ] ) ) {
				continue;
			}
			if ( isset( $stored[ $candidate['id'] ] ) ) {
				continue;
			}
			if ( $budget->remaining_seconds() <= 0 ) {
				break;
			}
			$url = TranslationUrl::rebase_to_home( $candidate['url'] );
			if ( $supplied && $this->public_read_row( array_merge( $candidate, [ 'url' => $url ] ) ) && ! isset( $by_url[ $url ] ) ) {
				continue;
			}
			if ( IndexQueue::STAGE_EXTRACT !== get_post_meta( $candidate['id'], '_twp_stage', true ) ) {
				continue;
			}
			$rows = $this->queue->claim_stage_rows( [ $candidate['id'] ], IndexQueue::STAGE_EXTRACT );
			if ( ! $rows ) {
				continue;
			}
			if ( ! $supplied && 0 === $processed ) {
				update_option( self::NEXT_READER_OPTION, time() + self::RESCHEDULE_DELAY_SECONDS, false );
			}
			$processed += $this->extract_pending_page( $rows[0], $by_url[ $url ] ?? [] );
			if ( $supplied && '' === $candidate['language'] ) {
				update_meta_cache( 'post', array_column( $candidates, 'id' ) );
			}
		}
		return $processed;
	}

	public function step_push( ReadinessBudget $budget, ?array $ids = null ): array {
		$started = ( $this->clock )();
		$result = [
			'processed' => 0,
			'failed' => 0,
			'released' => 0,
		];
		if ( ! SiteConnection::is_connected() || $budget->remaining_seconds() <= 0 ) {
			return $result;
		}
		if ( ProviderHold::waiting() || ProviderHold::watching() ) {
			return $result;
		}
		if ( ProviderHold::state() && null !== $ids ) {
			$ids = array_slice( $ids, 0, 1 );
		}
		$rows = null === $ids ? $this->queue->claim_batch( IndexQueue::STAGE_PUSH, self::drain_batch_size() ) : $this->queue->claim_rows( $ids );
		$groups = [];
		foreach ( $rows as $row ) {
			$items = json_decode( (string) $row['payload'], true );
			if ( ! is_array( $items ) || ! $items ) {
				$this->queue->fail( (int) $row['id'], 'invalid_stored_payload' );
				$result['failed']++;
				continue;
			}
			$this->add_row_to_groups( $groups, $row, $items );
		}
		foreach ( $groups as $group ) {
			if ( QueueExecution::expired() || $budget->remaining_seconds() <= 0 || ( $this->clock )() - $started >= self::drain_tick_budget() ) {
				foreach ( $group['rows'] as $row ) {
					$this->queue->release( (int) $row['id'] );
					$result['released']++;
				}
				continue;
			}
			$this->push_language_rows( (string) $group['language'], $group['rows'], $result, (bool) $group['retranslate'] );
		}
		if ( $result['processed'] > 0 && ! QueueExecution::expired() ) {
			$this->report_completed_languages( $this->queue->counts() );
		}
		self::schedule_drain( self::RESCHEDULE_DELAY_SECONDS );
		return $result;
	}

	private function extract_pending_page( array $row, array $page ): int {
		if ( self::MISS_OBJECT_TYPE === $row['object_type'] ) {
			if ( json_decode( (string) $row['payload'], true ) ) {
				$this->queue->store_payload( (int) $row['id'], (string) $row['payload'] );
			} else {
				$this->queue->fail( (int) $row['id'], 'missing_source_groups' );
			}
			return 1;
		}
		if ( \TranslateWP\Components\BackgroundEngine::service_reads() ) {
			$status = (int) ( $page['status'] ?? 0 );
			if ( ! $this->public_read_row( $row ) || 'redirected_away' === ( $page['error'] ?? '' ) ) {
				$html = new \WP_Error( 'page_redirected', 'page_redirected' );
			} elseif ( ! empty( $page['error'] ) ) {
				$reason = $status >= 400 ? 'page_fetch_' . $status : 'page_' . sanitize_key( $page['error'] );
				$html = new \WP_Error( $reason, $reason );
			} elseif ( 200 !== $status && ! ( 404 === $status && self::NOT_FOUND_OBJECT_TYPE === $row['object_type'] ) ) {
				$html = new \WP_Error( 'page_fetch_' . $status, 'page_fetch_failed' );
			} else {
				$html = (string) ( $page['html'] ?? '' );
			}
		} else {
			$html = $this->fetch_page( (string) $row['url'], self::NOT_FOUND_OBJECT_TYPE === $row['object_type'] );
		}
		if ( is_string( $html ) && '' === trim( $html ) ) {
			$html = new \WP_Error( 'page_not_html', 'page_not_html' );
		}
		if ( is_wp_error( $html ) ) {
			if ( QueueExecution::is_budget_error( $html ) ) {
				$this->queue->release( (int) $row['id'] );
				return 1;
			}
			$error_data = $html->get_error_data();
			if ( 'page_redirected' === $html->get_error_code() && ! empty( $error_data['target'] ) ) {
				update_post_meta( (int) $row['id'], '_twp_redirect_target', $error_data['target'] );
			}
			$this->reader_failed( $html->get_error_code() );
			if ( preg_match( '/^(?:page_tls_failed|page_fetch_(?:timeout|failed|401|403|408|429|5[0-9]{2}))$/', $html->get_error_code() ) ) {
				$this->queue->defer_reader( (int) $row['id'], $html->get_error_code() );
			} else {
				$this->queue->fail( (int) $row['id'], $html->get_error_code() );
			}
			return 1;
		}
		try {
			$items = RenderedPageExtraction::extract( $html );
			if ( ! empty( $row['payload'] ) && json_decode( $row['payload'], true ) === $items ) {
				$items = [];
			}
			if ( ! $this->submit_extraction( (int) $row['id'], $items ) ) {
				$this->queue->fail( (int) $row['id'], 'page_store_failed' );
				return 1;
			}
			$old_url = (string) get_post_meta( $row['id'], '_twp_url', true );
			if ( $old_url !== $row['url'] ) {
				update_post_meta( $row['id'], '_twp_url', $row['url'], $old_url );
			}
			delete_post_meta( (int) $row['id'], '_twp_reader_not_before' );
			delete_post_meta( (int) $row['id'], '_twp_reader_retries' );
			delete_transient( self::LOOPBACK_ERROR_TRANSIENT );
			delete_option( self::READER_FAILURE_OPTION );
		} catch ( \RuntimeException $error ) {
			$this->reader_failed( $error->getMessage() );
			$this->queue->fail( (int) $row['id'], $error->getMessage() );
		}
		return 1;
	}

	public function report_completion(): void {
		$this->queue->settle_refills();
		$this->report_completed_languages( $this->queue->counts() );
	}

	public function wanted_pages(): array {
		$wanted = [];
		foreach ( $this->queue->pending_read_rows() as $row ) {
			if ( ! $this->public_read_row( $row ) ) {
				continue;
			}
			$wanted[ $row['url'] ] = [ 'url' => $row['url'] ];
		}
		return array_values( $wanted );
	}

	private function public_read_row( array $row ): bool {
		$home = wp_parse_url( home_url( '/' ) );
		$url = wp_parse_url( $row['url'] );
		return 'miss' !== $row['object_type'] && TranslationUrl::is_indexable_url( $row['url'] )
			&& ( $url['host'] ?? '' ) === ( $home['host'] ?? '' ) && ( $url['scheme'] ?? '' ) === ( $home['scheme'] ?? '' )
			&& ( $url['port'] ?? null ) === ( $home['port'] ?? null ) && ! isset( $url['user'] ) && ! isset( $url['pass'] )
			&& ( $row['object_id'] <= 0 || 'publish' === get_post_status( $row['object_id'] ) );
	}

	private function reader_failed( string $code ): void {
		set_transient( self::LOOPBACK_ERROR_TRANSIENT, $code, self::LAST_DRAIN_STALE_SECONDS );
		update_option( self::READER_FAILURE_OPTION, $code, false );
		\TranslateWP\Components\ActionLog::record( 'The background reader could not read a page: ' . $code, 'warning' );
	}

	private function fetch_page( string $url, bool $expect_not_found = false ) {
		if ( ! TranslationUrl::is_indexable_url( $url ) ) {
			return new \WP_Error( 'page_redirected', 'page_redirected' );
		}
		$original = wp_parse_url( $url );
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $original ) || empty( $original['host'] ) || 0 !== strcasecmp( $original['host'], $home['host'] )
			|| ( $original['scheme'] ?? '' ) !== $home['scheme'] || ( $original['port'] ?? null ) !== ( $home['port'] ?? null )
			|| isset( $original['user'] ) || isset( $original['pass'] ) ) {
			return new \WP_Error( 'page_redirected', 'page_redirected' );
		}
		$started = microtime( true );
		for ( $redirects = 0; $redirects <= self::PAGE_MAX_REDIRECTS; $redirects++ ) {
			$remaining = self::PAGE_FETCH_TIMEOUT_SECONDS - ( microtime( true ) - $started );
			if ( $remaining <= 0 ) {
				return new \WP_Error( 'page_fetch_timeout', 'page_fetch_timeout' );
			}
			$response = wp_remote_get(
                $url,
                [
					'timeout' => $remaining,
					'redirection' => 0,
					'limit_response_size' => self::PAGE_MAX_BYTES,
					'sslverify' => true,
					'reject_unsafe_urls' => true,
					'cookies' => [],
				]
            );
			if ( is_wp_error( $response ) ) {
				$code = ( microtime( true ) - $started >= self::PAGE_FETCH_TIMEOUT_SECONDS || false !== stripos( $response->get_error_message(), 'timed out' ) || false !== stripos( $response->get_error_message(), 'cURL error 28' ) ) ? 'page_fetch_timeout' : ( false !== stripos( $response->get_error_message(), 'certificate' ) || false !== stripos( $response->get_error_message(), 'cURL error 60' ) ? 'page_tls_failed' : 'page_fetch_failed' );
				return new \WP_Error( $code, $code );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status >= 300 && $status < 400 ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				$url = \WP_Http::make_absolute_url( $location, $url );
				$target = wp_parse_url( $url );
				if ( '' === $location || $redirects >= self::PAGE_MAX_REDIRECTS || ! is_array( $target )
					|| ! in_array( strtolower( $target['scheme'] ?? '' ), [ 'http', 'https' ], true )
					|| isset( $target['user'] ) || isset( $target['pass'] )
					|| preg_replace( '/^www\./i', '', strtolower( $target['host'] ?? '' ) ) !== preg_replace( '/^www\./i', '', strtolower( $original['host'] ) )
					|| ( $target['port'] ?? null ) !== ( $original['port'] ?? null )
					|| untrailingslashit( $target['path'] ?? '/' ) !== untrailingslashit( $original['path'] ?? '/' )
					|| ( $target['query'] ?? '' ) !== ( $original['query'] ?? '' ) ) {
					return new \WP_Error( 'page_redirected', 'page_redirected', [ 'target' => $url ] );
				}
				continue;
			}
			if ( ( $status < 200 || $status >= 300 ) && ! ( 404 === $status && $expect_not_found ) ) {
				return new \WP_Error( 'page_fetch_' . $status, 'page_fetch_' . $status );
			}
			$html = (string) wp_remote_retrieve_body( $response );
			$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
			if ( strlen( $html ) >= self::PAGE_MAX_BYTES || ( '' !== $type && ! preg_match( '~text/html|application/xhtml\+xml~i', $type ) ) ) {
				return new \WP_Error( 'page_not_html', 'page_not_html' );
			}
			return $html;
		}
		return new \WP_Error( 'page_redirected', 'page_redirected' );
	}

	private function push_language_rows( string $language, array $language_rows, array &$result, bool $retranslate = false ): void {
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
		if ( ProviderHold::waiting() || ProviderHold::watching() ) {
			foreach ( $language_rows as $row ) {
				$this->queue->release( (int) $row['id'] );
				$result['released']++;
			}
			return;
		}
		$probe = (bool) ProviderHold::state();
		$site_id = Sites::registered_id();
		$readiness_items = [];
		foreach ( $language_rows as &$language_row ) {
			if ( self::MISS_OBJECT_TYPE === (string) ( $language_row['object_type'] ?? '' ) ) {
				foreach ( $language_row['items'] as &$miss_item ) {
					if ( empty( $miss_item['cache']['hash'] ) ) {
						$miss_item = $this->stamp_hashes( $language, [ $miss_item ] )[0];
					}
				}
				unset( $miss_item );
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
			if ( ! empty( $language_row['retranslate'] ) || ! empty( $language_row['retranslating'] ) ) {
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

		$store_rows = [];
		foreach ( $language_rows as $language_row ) {
			$store_row          = $language_row;
			$store_row['items'] = $readiness_items[ (int) $language_row['id'] ] ?? [];
			$store_rows[]       = $store_row;
		}

		if ( ! $batch_items ) {
			$completed = $this->queue->complete_delivery(
                [
					'language' => $language,
					'rows' => $language_rows,
					'items' => $readiness_items,
					'translated' => true,
				]
            );
			$result['processed'] += $completed;
			$this->announce_pushed_rows( $language, $store_rows );
			return;
		}

		$repair = $this->queue->requires_background_refresh( (int) $language_rows[0]['id'] );
		if ( $probe ) {
			ProviderHold::reserve_probe();
		}
		$job = $this->create_job( $language, $repair, $probe );
		if ( '' === $job['id'] ) {
			foreach ( $language_rows as $language_row ) {
				$this->release_row_claims( $language, $language_row['items'] );
				if ( ! empty( $job['deferred'] ) ) {
					if ( ! empty( $job['retryAt'] ) ) {
						$this->queue->defer_until( (int) $language_row['id'], (int) $job['retryAt'] );
					} else {
						$this->queue->release( (int) $language_row['id'] );
					}
					$result['released']++;
				} else {
					$this->queue->fail( $language_row['id'], $job['reason'] );
					$result['failed']++;
				}
			}
			return;
		}
		$job_id = $job['id'];
		foreach ( $batch_items as &$item ) {
			$item['cache'] = CacheVersions::cache_reference( $site_id, $item['cache']['hash'], $language );
		}
		unset( $item );
		if ( ! $this->queue->remember_batch_versions( array_column( $store_rows, 'id' ) ) ) {
			foreach ( $language_rows as $row ) {
				$this->queue->release( (int) $row['id'] );
				$result['released']++;
			}
			return;
		}
		foreach ( $store_rows as &$store_row ) {
			foreach ( $store_row['items'] as &$item ) {
				$item['cache'] = CacheVersions::cache_reference( $site_id, $item['cache']['hash'], $language );
			}
			unset( $item );
		}
		unset( $store_row );

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

		if ( $probe ) {
			ProviderHold::probe( $job_id, $language, (string) $batch_items[0]['cache']['hash'] );
			$base_body['items'] = array_slice( $base_body['items'], 0, 1 );
			$base_body['retranslate'] = true;
		}
		$batch_body = apply_filters(
			'translatewp_translation_batch_request_body',
			$base_body,
			$job_id,
			$language
		);

		if ( $probe && empty( $batch_body['byok'] ) && ! \TranslateWP\Components\QuotaHandoff::wants_own_providers( $job_id ) ) {
			SiteRequests::post( '/v1/translation/jobs/' . rawurlencode( $job_id ) . '/cancel', [] );
			ProviderHold::reject( ProviderHold::state()['reason'] );
			foreach ( $language_rows as $row ) {
				$this->release_row_claims( $language, $row['items'] );
				$this->queue->release( (int) $row['id'] );
				$result['released']++;
			}
			return;
		}
		$pushed = TranslationProRetry::post(
			'/v1/translation/jobs/' . rawurlencode( $job_id ) . '/batches',
			$batch_body,
			self::JOB_POST_TIMEOUT_SECONDS,
			$language,
			$repair
		);

		if ( is_wp_error( $pushed ) ) {
			$provider_refusal = $this->hold_for_refusal( $pushed );
			if ( $provider_refusal && ! QueueExecution::expired() ) {
				SiteRequests::post( '/v1/translation/jobs/' . rawurlencode( $job_id ) . '/cancel', [] );
			}
			if ( $provider_refusal || $probe || QueueExecution::is_budget_error( $pushed ) ) {
				foreach ( $language_rows as $row ) {
					$this->release_row_claims( $language, $row['items'] );
					$this->queue->release( (int) $row['id'] );
					$result['released']++;
				}
				return;
			}
			$reason = TranslationProviderFailure::reason( $pushed, $pushed->get_error_code() );
			\TranslateWP\Components\ActionLog::record( sprintf( 'A translation batch for %s was refused: %s (%d rows)', $language, $reason, count( $language_rows ) ), 'error' );
			foreach ( $language_rows as $language_row ) {
				$this->release_row_claims( $language, $language_row['items'] );
				$this->queue->fail( $language_row['id'], $reason );
				$result['failed']++;
			}
			return;
		}

		if ( $probe ) {
			foreach ( $language_rows as $row ) {
				$this->release_row_claims( $language, $row['items'] );
				$this->queue->release( (int) $row['id'] );
				$result['released']++;
			}
			return;
		}
		$completed = $this->queue->complete_delivery(
            [
				'language' => $language,
				'rows' => $language_rows,
				'items' => $readiness_items,
				'job_id' => $job_id,
				'keep_payload' => ! empty( $job['byok'] ) || ! empty( $batch_body['byok'] ),
			]
        );
		if ( ! empty( $batch_body['byok'] ) || ! empty( $job['byok'] ) ) {
			ProviderHold::watch( $job_id );
		}
		$result['processed'] += $completed;
		if ( count( $language_rows ) !== $completed ) {
			foreach ( $language_rows as $row ) {
				if ( $this->queue->release( (int) $row['id'] ) ) {
					$result['released']++; }
			}
		}

		$this->announce_pushed_rows( $language, $store_rows );
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
		$result = [
			'processed' => 0,
			'failed' => 0,
			'released' => 0,
		];
		QueueExecution::run(
            function () use ( &$result, $ids, $budget_seconds ): void {
                $result = $this->drain_rows_unlocked( $ids, $budget_seconds );
            },
            $budget_seconds
        );
		return $result;
	}

	private function drain_rows_unlocked( array $ids, float $budget_seconds ): array {
		$started = ( $this->clock )();
		$result  = [
			'processed' => 0,
			'failed'    => 0,
			'released'  => 0,
		];

		if ( ! SiteConnection::is_connected() || ProviderHold::state() || ProviderHold::watching() ) {
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
			$item['cache'] = CacheVersions::cache_reference( $site_id, $hash, $language );
			$stamped[]     = $item;
		}

		return $stamped;
	}

	private function add_row_to_groups( array &$groups, array $row, array $items ): void {
		$language    = (string) $row['language'];
		$retranslate = ! empty( $row['retranslate'] );
		$repair = $this->queue->requires_background_refresh( (int) $row['id'] );
		$key         = $language . '|' . ( $retranslate ? '1' : '0' ) . '|' . ( $repair ? '1' : '0' );

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
			'retranslating' => ! empty( $row['retranslating'] ),
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

	public static function schedule_inline_drain( array $ids, bool $allow_inline = true ): string {
		if ( \TranslateWP\Components\BackgroundSwitch::off() ) {
			return 'cron';
		}
		$can_finish = function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
		$inline = $allow_inline && $can_finish && self::acquire_inline_slot();
		register_shutdown_function(
			static function () use ( $ids, $can_finish, $inline ): void {
				try {
					$finished = $can_finish && ( function_exists( 'fastcgi_finish_request' ) ? fastcgi_finish_request() : litespeed_finish_request() );
					if ( $finished && $inline ) {
						( new self( '', '', null, null, false ) )->drain_rows( array_map( 'intval', $ids ) );
					}
				} finally {
					if ( $inline ) {
						self::release_inline_slot();
					}
					\TranslateWP\Components\BackgroundEngine::visitor_wake();
				}
			}
		);
		return $inline ? 'inline' : ( $can_finish ? 'capped' : 'cron' );
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

		$last_step = (int) get_option( \TranslateWP\Components\BackgroundEngine::LAST_STEP_OPTION, 0 );
		return [
			'readerError' => (string) get_option( self::READER_FAILURE_OPTION, get_transient( self::LOOPBACK_ERROR_TRANSIENT ) ),
			'firstPassDone'      => $first_pass_done,
			'languages'          => $languages,
			'read'               => $this->queue->read_counts(),
			'changedPages'       => $this->queue->changed_since_read_count(),
			'scan'               => $this->queue->read_scan_progress(),
			'drainScheduled'     => (bool) wp_next_scheduled( \TranslateWP\Components\BackgroundEngine::CRON_HOOK ),
			'background' => \TranslateWP\Components\BackgroundEngine::snapshot( \TranslateWP\Components\BackgroundSwitch::off() ? 'paused' : 'more' ),
			'scheduledTasksLate' => $this->scheduled_tasks_late( $counts ),
			'lastRun'            => $last_step > 0 ? $last_step : $this->last_drain_run(),
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
		$due_at = $this->queue->next_due_at();
		if ( ! SiteConnection::is_connected() || ! $this->has_pending( $counts ) || ( null !== $due_at && $due_at > time() ) ) {
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
					'id'         => (string) $item['id'],
					'language'   => (string) $item['language'],
					'url'        => (string) $item['url'],
					'reason'     => (string) $item['reason'],
					'objectType' => (string) $item['object_type'],
					'objectId'   => (string) $item['object_id'],
					'attempts'   => (int) $item['attempts'],
				];
			},
			$this->queue->failed_items()
		);
	}

	private function hold_for_refusal( \WP_Error $error ): bool {
		$data = $error->get_error_data();
		$code = (string) ( $data['payload']['code'] ?? $data['payload']['error'] ?? '' );
		$reason = str_starts_with( $code, 'byok_' ) ? ProviderHold::reason( $code ) : '';
		if ( '' === $reason ) {
			return false;
		}
		ProviderHold::reject( $reason, (int) ( $data['payload']['retryAfterSeconds'] ?? 0 ) );
		return true;
	}

	/**
	 * Opens a job for the language. On failure the reason names what the
	 * service said: a used-up plan allowance, an hourly cap, or a plain
	 * transport failure, so the page's line can say which.
	 *
	 * @return array{id:string,reason:string}
	 */
	private function create_job( string $language, bool $repair = false, bool $probe = false ): array {
		$retry_at = (int) get_option( self::REPAIR_RETRY_OPTION, 0 );
		if ( $repair && $retry_at > time() ) {
			return [
				'id' => '',
				'reason' => '',
				'deferred' => true,
				'retryAt' => $retry_at,
			];
		}
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

		if ( $probe && empty( $job_body['byok'] ) && ! \TranslateWP\Components\QuotaHandoff::wants_own_providers( '' ) ) {
			return [
				'id' => '',
				'reason' => '',
				'deferred' => true,
			];
		}
		if ( $repair && '' !== self::REPAIR_JOB_FIELD ) {
			$job_body[ self::REPAIR_JOB_FIELD ] = self::REPAIR_JOB_PURPOSE;
		}

		$response = TranslationProRetry::post(
			'/v1/translation/jobs',
			$job_body,
			self::JOB_POST_TIMEOUT_SECONDS,
			$language,
			$repair
		);

		if ( is_wp_error( $response ) ) {
			return [
				'id'     => '',
				'reason' => self::job_failure_reason( $response ),
				'deferred' => $this->hold_for_refusal( $response ) || $probe || QueueExecution::is_budget_error( $response ),
			];
		}

		if ( $repair && self::REPAIR_JOB_PURPOSE !== ( $response[ self::REPAIR_JOB_FIELD ] ?? null ) ) {
			$job_id = sanitize_text_field( (string) ( $response['jobId'] ?? '' ) );
			if ( '' !== $job_id ) {
				SiteRequests::post( '/v1/translation/jobs/' . rawurlencode( $job_id ) . '/cancel', [] );
			}
			$retry_at = time() + self::REPAIR_RETRY_SECONDS;
			update_option( self::REPAIR_RETRY_OPTION, $retry_at, false );
			return [
				'id' => '',
				'reason' => '',
				'deferred' => true,
				'retryAt' => $retry_at,
			];
		}
		if ( $repair ) {
			delete_option( self::REPAIR_RETRY_OPTION );
		}

		return [
			'id'     => sanitize_text_field( (string) ( $response['jobId'] ?? '' ) ),
			'byok' => ! empty( $job_body['byok'] ) || ! empty( $response['byok'] ) || \TranslateWP\Components\QuotaHandoff::wants_own_providers( (string) ( $response['jobId'] ?? '' ) ),
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
			if ( $count['pending_push'] > 0 || $count['pending_extract'] > 0 ) {
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
				\TranslateWP\Components\TranslationProxyRateLimit::complete_first_pass( Sites::registered_id(), (string) $language );
				$reported[] = (string) $language;
				$changed    = true;
			}
		}

		if ( $changed ) {
			update_option( self::REPORTED_OPTION, array_values( $reported ) );
		}
	}
}
