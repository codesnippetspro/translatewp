<?php

namespace TranslateWP\Components;

use TranslateWP\Entities\CPTs\Lexicon;
use TranslateWP\Entities\CPTs\Metrics;
use TranslateWP\Functionality\Endpoints\Scanning;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\KeepWarm;
use TranslateWP\Functionality\ReadinessReconcile;
use TranslateWP\Functionality\TranslatedEmails;
use TranslateWP\Functionality\TranslationStoreSync;
/**
 * Canonical cleanup inventory shared by the settings reset endpoint,
 * deactivation and Lyfecycle::uninstall(). The reset deletes the named
 * options of the lists below; the uninstall deletes every row under the
 * plugin's prefixes, so an option added later cannot be left behind.
 */
class CleanupInventory {

	public const SITE_BATCH = 100;

	public const OPTION_PREFIXES = [ 'translatewp_', 'twp_' ];

	// Option rows of each prefix: the option itself and its transient forms.
	private const ROW_PREFIXES = [
		'',
		'_transient_',
		'_transient_timeout_',
		'_site_transient_',
		'_site_transient_timeout_',
	];

	private const DELETE_CHUNK = 500;

	/**
	 * Rows under the plugin's prefixes that only TranslateWP Pro writes. While
	 * the addon is installed they are its data, and its own uninstall removes them.
	 */
	public const PRO_ADDON_ROWS = [
		'translatewp_pro_addon_capabilities_last_known',
		'translatewp_pro_alt_text',
		'translatewp_pro_alt_text_queue',
		'translatewp_pro_indexnow_key',
		'_transient_translatewp_pro_indexnow_debounce',
		'_transient_timeout_translatewp_pro_indexnow_debounce',
		'translatewp_network_ai',
		'translatewp_network_ai_source',
	];

	public const OPTION_ROOTS = [ 'setup', 'site', 'languages', 'translations', 'ai', 'advanced' ];

	public const NETWORK_OPTIONS = [ 'translatewp_network_connect_results', 'translatewp_network_apply_grants', TranslationSwitchNotice::NETWORK_OPTION ];

	public const INFRASTRUCTURE_OPTIONS = [
		'translatewp_version',
		Migrations::TRANSLATED_MARKS_OPTION,
		Migrations::LEXICON_PRIVATE_CACHE_PURGED_OPTION,
		\TranslateWP\Functionality\KeepWarm::PENDING_LANGUAGES_OPTION,
		QuotaHandoff::OPTION,
		'translatewp_alt_text_notice',
		'translatewp_translation_managers',
		Access::ROLE_MAP_OPTION,
		'translatewp_cache',
		'translatewp_rewrite_version',
		'translatewp_settings_revision',
		'translatewp_last_connection_probe',
		'translatewp_pro_capabilities_last_known',
		'translatewp_pro_welcome_seen',
		'translatewp_index_last_drain',
		'translatewp_index_reported_languages',
		'translatewp_index_priority_pins',
		'translatewp_translation_cache_generation',
		'translatewp_translation_cache_generations',
		'translatewp_translation_store_urls',
		'translatewp_translation_store_work',
		AnchorMisses::OPTION,
		TranslationSwitchNotice::SITE_OPTION,
	];

	// Removed only by the uninstall's complete wipe, not by the settings reset.
	public const UNINSTALL_POST_META_KEYS = [ PageExclusion::META_KEY ];

	public const USER_META_KEYS = [
		'translatewp_coexistence_dismissed',
		'translatewp_cache_notice_dismissed',
		'translatewp_finish_setup_dismissed',
	];

	public const POST_META_KEYS = [
		'_translatewp_switcher_placement',
		'_twp_translation_store',
	];

	public const CPT_SLUGS = [
		Lexicon::POST_TYPE,
		Metrics::POST_TYPE,
		IndexQueue::POST_TYPE,
	];
	public const TRANSIENTS = [ 'translatewp_pairing_verified', TranslationStoreSync::REQUEUE_CHECK_TRANSIENT, ReadinessReconcile::CHECK_TRANSIENT ];

	public const SCHEDULED_HOOKS = [
		SiteLanguageSync::RETRY_HOOK,
		Scanning::SCAN_HOOK,
		Indexer::DRAIN_HOOK,
		ReadinessReconcile::HOOK,
		KeepWarm::LEXICON_DEBOUNCE_HOOK,
		KeepWarm::SETTINGS_DEBOUNCE_HOOK,
		KeepWarm::REWARM_DEBOUNCE_HOOK,
		TranslationStoreSync::BACKFILL_HOOK,
		TranslatedEmails::PREWARM_HOOK,
		TranslatedEmails::DELIVER_HOOK,
	];

	public static function option_names(): array {
		return array_map(
			static fn ( string $root ): string => 'translatewp_' . $root,
			self::OPTION_ROOTS
		);
	}

	public static function wipe_options(): void {
		foreach ( self::option_names() as $option_name ) {
			delete_option( $option_name );
		}
		foreach ( self::INFRASTRUCTURE_OPTIONS as $option_name ) {
			delete_option( $option_name );
		}
	}

	public static function wipe_post_meta(): void {
		foreach ( self::POST_META_KEYS as $meta_key ) {
			delete_metadata( 'post', 0, $meta_key, '', true );
		}
	}

	public static function wipe_network_options(): void {
		foreach ( self::NETWORK_OPTIONS as $option_name ) {
			delete_site_option( $option_name );
		}
	}

	public static function wipe_cpt_content(): void {
		foreach ( self::CPT_SLUGS as $post_type ) {
			self::delete_posts_of_type( $post_type );
		}
	}

	/**
	 * Delete every post of one type, with its meta and term relationships, in
	 * batches of direct queries. Uninstall used get_posts( -1 ) plus one
	 * wp_delete_post() per row, which on a site with thousands of queue or
	 * metric rows ran past max_execution_time in the delete-plugin request
	 * (issue 54). These plugin CPTs have no revisions, comments or attachments,
	 * so the three tables below are the whole footprint.
	 *
	 * @return int Number of posts deleted.
	 */
	public static function delete_posts_of_type( string $post_type, int $batch = 500 ): int {
		global $wpdb;

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		$suspended = function_exists( 'wp_suspend_cache_addition' ) ? wp_suspend_cache_addition() : false;
		if ( function_exists( 'wp_suspend_cache_addition' ) ) {
			wp_suspend_cache_addition( true );
		}

		$batch   = max( 1, $batch );
		$deleted = 0;

		do {
			$ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC LIMIT %d",
						$post_type,
						$batch
					)
				)
			);

			if ( ! $ids ) {
				break;
			}

			$in = implode( ',', $ids );
			// $in is a list of integers cast above.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
			$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$in})" );
			$removed = $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" );
			// phpcs:enable

			// A failed or empty delete would select the same IDs forever.
			if ( ! $removed ) {
				break;
			}

			// Drop any cached copy of these posts so nothing reads a deleted row.
			if ( function_exists( 'clean_post_cache' ) ) {
				foreach ( $ids as $id ) {
					clean_post_cache( $id );
				}
			} elseif ( function_exists( 'wp_cache_set_last_changed' ) ) {
				wp_cache_set_last_changed( 'posts' );
			}

			$found    = count( $ids );
			$deleted += (int) $removed;
		} while ( $found === $batch );

		if ( function_exists( 'wp_suspend_cache_addition' ) ) {
			wp_suspend_cache_addition( $suspended );
		}

		return $deleted;
	}

	public static function wipe_transients(): void {
		foreach ( self::TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}
	}

	/** Every event of each hook, whatever its arguments; wp_clear_scheduled_hook() leaves events with arguments. */
	public static function wipe_scheduled_hooks(): void {
		foreach ( self::SCHEDULED_HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, null, 'translatewp' );
			}
		}
	}

	/** Users are shared by every site of a network, so this runs once per install. */
	public static function wipe_user_meta(): void {
		foreach ( self::USER_META_KEYS as $meta_key ) {
			delete_metadata( 'user', 0, $meta_key, '', true );
		}
	}

	/** Runs $callback on every site of the network, switched to it, in pages of SITE_BATCH. */
	public static function each_site( callable $callback ): void {
		$offset = 0;
		do {
			$site_ids = get_sites(
				[
					'fields'     => 'ids',
					'number'     => self::SITE_BATCH,
					'offset'     => $offset,
					'network_id' => get_current_network_id(),
				]
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					$callback( (int) $site_id );
				} finally {
					restore_current_blog();
				}
			}

			$offset += self::SITE_BATCH;
			$found   = count( $site_ids );
		} while ( self::SITE_BATCH === $found );
	}

	/**
	 * Deletes every option, transient and site transient row of the current
	 * site under the plugin's prefixes. On a single site the site options are
	 * rows of the same table, so they go too.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function wipe_options_by_prefix( bool $keep_pro_addon_rows ): int {
		global $wpdb;

		$patterns = self::like_patterns();
		$where    = self::like_clause( 'option_name', count( $patterns ) );
		// The concatenated clauses hold only placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = (array) $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE {$where}", $patterns )
		);
		$names = self::owned( $names, $keep_pro_addon_rows );

		foreach ( array_chunk( $names, self::DELETE_CHUNK ) as $chunk ) {
			$in = self::placeholders( count( $chunk ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN ({$in})", $chunk ) );
		}
		// phpcs:enable

		foreach ( $names as $name ) {
			wp_cache_delete( $name, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return count( $names );
	}

	/**
	 * Deletes every network option and site transient of the current network
	 * under the plugin's prefixes.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function wipe_network_options_by_prefix( bool $keep_pro_addon_rows ): int {
		global $wpdb;

		$network_id = get_current_network_id();
		$patterns   = self::like_patterns();
		$where      = self::like_clause( 'meta_key', count( $patterns ) );
		// The concatenated clauses hold only placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->sitemeta} WHERE site_id = %d AND {$where}",
				array_merge( [ $network_id ], $patterns )
			)
		);
		$names = self::owned( $names, $keep_pro_addon_rows );

		foreach ( array_chunk( $names, self::DELETE_CHUNK ) as $chunk ) {
			$in = self::placeholders( count( $chunk ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key IN ({$in})",
					array_merge( [ $network_id ], $chunk )
				)
			);
		}
		// phpcs:enable

		foreach ( $names as $name ) {
			wp_cache_delete( $network_id . ':' . $name, 'site-options' );
		}
		wp_cache_delete( $network_id . ':notoptions', 'site-options' );

		return count( $names );
	}

	/** Everything the plugin stored on the current site, for the uninstall. */
	public static function wipe_site_by_prefix( bool $keep_pro_addon_rows ): void {
		self::wipe_scheduled_hooks();
		self::wipe_options_by_prefix( $keep_pro_addon_rows );
		// With a persistent object cache transients live only in the cache.
		self::wipe_transients();
		self::wipe_post_meta();
		foreach ( self::UNINSTALL_POST_META_KEYS as $meta_key ) {
			delete_metadata( 'post', 0, $meta_key, '', true );
		}
		self::wipe_cpt_content();
	}

	public static function wipe_all(): void {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			self::each_site(
				static function (): void {
					self::wipe_site();
				}
			);

			self::wipe_network_options();
			return;
		}

		self::wipe_site();
	}

	private static function like_patterns(): array {
		global $wpdb;

		$patterns = [];
		foreach ( self::OPTION_PREFIXES as $prefix ) {
			foreach ( self::ROW_PREFIXES as $row_prefix ) {
				$patterns[] = $wpdb->esc_like( $row_prefix . $prefix ) . '%';
			}
		}

		return $patterns;
	}

	private static function like_clause( string $column, int $count ): string {
		return '(' . implode( ' OR ', array_fill( 0, $count, $column . ' LIKE %s' ) ) . ')';
	}

	private static function placeholders( int $count ): string {
		return implode( ',', array_fill( 0, $count, '%s' ) );
	}

	private static function owned( array $names, bool $keep_pro_addon_rows ): array {
		$names = array_map( 'strval', $names );

		return array_values( $keep_pro_addon_rows ? array_diff( $names, self::PRO_ADDON_ROWS ) : $names );
	}

	private static function wipe_site(): void {
		self::wipe_options();
		self::wipe_post_meta();
		self::wipe_cpt_content();
		self::wipe_transients();
		self::wipe_scheduled_hooks();
	}
}
