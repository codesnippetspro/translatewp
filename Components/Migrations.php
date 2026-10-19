<?php

namespace TranslateWP\Components;

/**
 * One-time migrations keyed by a stored plugin version marker. Fresh
 * installs seed the marker on activation so they never run migrations;
 * existing installs run each pending migration once on init (priority 5),
 * after WordPress has its rewrite rules. Post types and taxonomies that
 * register at init 10 do not exist yet, so a migration must not build their
 * permalinks.
 * The marker is plugin infrastructure, not user data: the reset/uninstall
 * inventory includes it.
 */
class Migrations {

	public const VERSION_OPTION = 'translatewp_version';
	public const TRANSLATED_MARKS_OPTION = 'translatewp_translated_marks_backfilled';

	private const MIGRATIONS = [
		'0.9.0-beta.1' => 'migrate_switcher_highlight_to_brand',
		'0.9.5'        => 'migrate_drop_source_language_queue',
		'0.9.15'       => 'migrate_collapse_stale_index_rows',
		'0.9.17'       => 'migrate_collapse_canonical_index_rows',
		'0.9.19'       => 'migrate_drop_unindexable_index_rows',
		'0.9.20'       => 'migrate_drop_honest_indexing_setting',
		'0.10.19'      => 'migrate_drop_retired_records',
		'0.10.21'      => 'migrate_0_10_21',
		'1.0.0'        => 'migrate_1_0_0',
	];

	public static function maybe_run(): void {
		$stored = (string) get_option( self::VERSION_OPTION, '' );

		self::backfill_translated_marks();

		if ( '' !== $stored && version_compare( $stored, TRANSLATEWP_VERSION, '>=' ) ) {
			return;
		}

		foreach ( self::MIGRATIONS as $version => $method ) {
			if ( '' === $stored || version_compare( $stored, $version, '<' ) ) {
				self::$method();
			}
		}

		update_option( self::VERSION_OPTION, TRANSLATEWP_VERSION );
		ActionLog::record( '' === $stored ? 'Plugin installed at ' . TRANSLATEWP_VERSION : 'Plugin updated from ' . $stored . ' to ' . TRANSLATEWP_VERSION );
	}

	/**
	 * 0.10.29 counts a page translation as done by a mark the reconcile sets.
	 * Rows completed before the mark existed carry none; they finished under
	 * the old plugin, so they are marked once and the Dashboard opens with its
	 * figure. Keyed to its own marker, not the version, so a site that ran a
	 * pre-release build of 0.10.29 is backfilled too.
	 */
	private static function backfill_translated_marks(): void {
		if ( get_option( self::TRANSLATED_MARKS_OPTION ) ) {
			return;
		}
		update_option( self::TRANSLATED_MARKS_OPTION, time(), false );
		$queue = new IndexQueue();
		$done  = [];
		foreach ( $queue->list_rows() as $row ) {
			if ( IndexQueue::STATUS_DONE === (string) $row['status'] ) {
				$done[] = (int) $row['id'];
			}
		}
		$queue->mark_translated( $done );
	}

	/**
	 * 1.0: a site that used alt text translation, now Pro, is told once; and
	 * rows an earlier version queued for a file a visitor's browser fetched
	 * (a script's data file, say) are dropped, as files are never pages.
	 */
	private static function migrate_1_0_0(): void {
		AltTextNotice::flag_if_affected();
		( new IndexQueue() )->delete_unindexable_rows();
	}

	public static function seed_for_fresh_install(): void {
		update_option( self::VERSION_OPTION, TRANSLATEWP_VERSION );
	}

	private static function migrate_drop_retired_records(): void {
		$switcher = Options::get( 'languages.switcher', [] );
		if ( is_array( $switcher ) && array_key_exists( 'custom_css', $switcher ) ) {
			unset( $switcher['custom_css'] );
			Options::update( 'languages.switcher', $switcher );
		}

		if ( ! is_multisite() ) {
			return;
		}

		delete_site_option( 'translatewp_network_connect_results' );
		delete_site_option( 'translatewp_network_apply_grants' );

		$network = get_site_option( 'translatewp_network_settings_defaults', [] );
		if ( is_array( $network ) && isset( $network['translatewp_languages']['switcher'] ) && is_array( $network['translatewp_languages']['switcher'] ) && array_key_exists( 'custom_css', $network['translatewp_languages']['switcher'] ) ) {
			unset( $network['translatewp_languages']['switcher']['custom_css'] );
			update_site_option( 'translatewp_network_settings_defaults', $network );
		}
	}

	/**
	 * One migration key per release: maybe_run stores TRANSLATEWP_VERSION after
	 * a run, so a key above the shipped version never runs. Every 0.10.21 cleanup
	 * therefore runs under this one key.
	 */
	private static function migrate_0_10_21(): void {
		self::migrate_drop_translation_switch();
		self::migrate_keep_switcher_icon_trigger();
	}

	/**
	 * The closed switcher shows the selected language's flag and native name
	 * by default. A site that already saved switcher settings keeps
	 * the TranslateWP icon it had until the owner picks another option; a site
	 * with no saved switcher settings gets the new default. The same holds for
	 * the network switcher defaults.
	 */
	private static function migrate_keep_switcher_icon_trigger(): void {
		$switcher = Options::get( 'languages.switcher', null );
		if ( is_array( $switcher ) && [] !== $switcher && ! array_key_exists( 'trigger', $switcher ) ) {
			$switcher['trigger'] = 'icon';
			Options::update( 'languages.switcher', $switcher );
		}

		if ( ! is_multisite() ) {
			return;
		}

		$network = get_site_option( 'translatewp_network_settings_defaults', [] );
		if ( is_array( $network ) && isset( $network['translatewp_languages']['switcher'] ) && is_array( $network['translatewp_languages']['switcher'] ) && [] !== $network['translatewp_languages']['switcher'] && ! array_key_exists( 'trigger', $network['translatewp_languages']['switcher'] ) ) {
			$network['translatewp_languages']['switcher']['trigger'] = 'icon';
			update_site_option( 'translatewp_network_settings_defaults', $network );
		}
	}

	/**
	 * The "Translate the site for visitors" switch is gone: translation
	 * is on wherever the plugin is active. A site that stored the switch off
	 * translates again after the update, so drop the stored key, the network
	 * value and the network group state that held it. A switch stored off
	 * leaves a marker so the owner is told once that translation is on
	 * (TranslationSwitchNotice).
	 */
	private static function migrate_drop_translation_switch(): void {
		$settings = Options::get( 'translations.settings', [] );
		if ( is_array( $settings ) && array_key_exists( 'translation_enabled', $settings ) ) {
			if ( ! $settings['translation_enabled'] ) {
				TranslationSwitchNotice::mark_site();
			}
			unset( $settings['translation_enabled'] );
			Options::update( 'translations.settings', $settings );
		}

		if ( ! is_multisite() ) {
			return;
		}

		$network = get_site_option( 'translatewp_network_settings_defaults', [] );
		if ( is_array( $network ) && isset( $network['translatewp_translations']['settings'] ) && is_array( $network['translatewp_translations']['settings'] ) && array_key_exists( 'translation_enabled', $network['translatewp_translations']['settings'] ) ) {
			if ( ! $network['translatewp_translations']['settings']['translation_enabled'] ) {
				TranslationSwitchNotice::mark_network();
			}
			unset( $network['translatewp_translations']['settings']['translation_enabled'] );
			update_site_option( 'translatewp_network_settings_defaults', $network );
		}

		$scope = get_site_option( 'translatewp_network_settings_scope', null );
		if ( is_array( $scope ) && isset( $scope['groups'] ) && is_array( $scope['groups'] ) && array_key_exists( 'translation_switch', $scope['groups'] ) ) {
			unset( $scope['groups']['translation_switch'] );
			update_site_option( 'translatewp_network_settings_scope', $scope );
		}
	}

	private static function migrate_switcher_highlight_to_brand(): void {
		$switcher = Options::get( 'languages.switcher', [] );
		if ( ! is_array( $switcher ) ) {
			return;
		}

		// Provenance rule: a stored value exactly equal to the old shipped
		// default is treated as the default and migrates; any other value
		// is user-customized and is never touched.
		if ( '#2031CA' === ( $switcher['highlight_bg'] ?? '' ) ) {
			$switcher['highlight_bg'] = '#0e3ecd';
			Options::update( 'languages.switcher', $switcher );
		}
	}

	/**
	 * PR #103 stopped enqueuing the source (default) locale, but installs that
	 * warmed up earlier keep its old rows, which sit forever as a source->source
	 * job the API never resolves. Delete them so the source language stops
	 * showing on the Indexing tab.
	 */
	private static function migrate_drop_source_language_queue(): void {
		$default = TranslationProxySettings::default_locale();
		if ( '' === $default || 'auto' === $default ) {
			return;
		}

		( new IndexQueue() )->delete_language_rows( $default );
	}

	/**
	 * The indexed pages table moved to one row per page per language. Installs
	 * warmed up before 0.9.15 can hold several queue rows for the same page URL
	 * and language, left by an earlier warm-up run and the visitor miss path.
	 * Collapse them to the newest row so the table shows each page once.
	 */
	private static function migrate_collapse_stale_index_rows(): void {
		( new IndexQueue() )->collapse_stale_duplicates();
	}

	/**
	 * The 0.9.15 collapse keyed rows by their raw stored URL, so a scheme,
	 * trailing slash or `lang` query param difference (the visitor miss path
	 * and warm-up indexing can each store a URL differently) left duplicate
	 * rows for the same page. Re-run the collapse now that it keys by
	 * TranslationUrl::canonicalize(), deleting the older of each pair.
	 */
	private static function migrate_collapse_canonical_index_rows(): void {
		( new IndexQueue() )->collapse_stale_duplicates();
	}

	/**
	 * The indexer and the miss-report path now refuse to record a WordPress
	 * preview/builder or admin URL as a page (TranslationUrl::is_indexable_url).
	 * Installs warmed up or visited before the guard existed can still hold
	 * queue rows keyed to such a URL, which showed up on the Pages tab as an
	 * indexed page. Delete them.
	 */
	private static function migrate_drop_unindexable_index_rows(): void {
		( new IndexQueue() )->delete_unindexable_rows();
	}

	/**
	 * The honest_indexing SEO setting is no longer used, so languages.seo no
	 * longer carries it. Drop the stored key from installs that saved it,
	 * leaving the rest of the SEO option untouched.
	 */
	private static function migrate_drop_honest_indexing_setting(): void {
		$seo = Options::get( 'languages.seo', [] );
		if ( ! is_array( $seo ) || ! array_key_exists( 'honest_indexing', $seo ) ) {
			return;
		}

		unset( $seo['honest_indexing'] );
		Options::update( 'languages.seo', $seo );
	}
}
