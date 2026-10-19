<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\PageExclusion;
use TranslateWP\Components\CacheVersions;
use TranslateWP\Components\DebouncedTask;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\TranslationProxySettings;
use TranslateWP\Components\TranslationStore;

class KeepWarm {

	public const LEXICON_DEBOUNCE_HOOK  = 'translatewp_lexicon_stale_debounce';
	public const SETTINGS_DEBOUNCE_HOOK = 'translatewp_settings_rebuild_debounce';
	public const PENDING_LANGUAGES_OPTION = 'translatewp_settings_rebuild_languages';
	private const ALL_LANGUAGES = '*';
	public const REWARM_DEBOUNCE_HOOK  = 'translatewp_rewarm_debounce';

	// The three settings groups. Model settings enter every
	// fingerprint, so saving them marks every translation stale (behind the
	// cost confirmation). Lexicon terms and localizer toggles are
	// versions: saving them changes nothing until the owner retranslates.
	public const TRANSLATION_AFFECTING_OPTION_PATHS = [
		'translations.style',
		'translations.context',
	];

	/** The keys of translations.settings that shape the model output. */
	public const MODEL_SETTING_KEYS = [
		'preserve_branded_terms',
	];

	/** The keys of translations.settings the localizer version tracks. */
	public const LOCALIZER_SETTING_KEYS = [
		'localized_date_time_formats',
		'enable_units_conversion',
	];

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		add_action( 'translatewp_lexicon_changed', [ $this, 'schedule_lexicon_stale_mark' ] );
		add_action(
			'translatewp_translation_affecting_settings_saved',
			[ $this, 'schedule_settings_rebuild' ],
			10,
			1
		);
		add_action( self::LEXICON_DEBOUNCE_HOOK, [ $this, 'mark_lexicon_stale' ] );
		add_action( 'translatewp_localizer_settings_saved', [ $this, 'mark_localizer_stale' ] );
		add_action( self::SETTINGS_DEBOUNCE_HOOK, [ $this, 'rebuild_after_settings_change' ] );
		add_action( 'save_post', [ $this, 'on_save_post' ], 10, 2 );
		add_action( self::REWARM_DEBOUNCE_HOOK, [ $this, 'rewarm_saved_object' ], 10, 2 );
	}

	public function on_save_post( int $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		$post_type = (string) $post->post_type;
		if ( ! in_array( $post_type, $this->indexable_post_types(), true ) ) {
			return;
		}

		if ( PageExclusion::is_excluded( $post_id ) ) {
			return;
		}

		DebouncedTask::schedule( self::REWARM_DEBOUNCE_HOOK, [ $post_id, $post_type ] );
	}

	public function rewarm_saved_object( int $post_id, string $post_type ): void {
		if ( ! SiteConnection::is_connected() ) {
			return;
		}

		$languages = TranslationProxySettings::active_lookup_languages();
		if ( ! $languages ) {
			return;
		}

		// The page may have been protected or excluded since the save was debounced.
		if ( PageExclusion::is_excluded( $post_id ) ) {
			return;
		}

		$url = get_permalink( $post_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}

		$page_title  = \TranslateWP\Components\PlainText::title( (int) $post_id );
		$modified_at = self::modified_at( $post_id );
		$queue       = new IndexQueue();

		if ( null !== $queue->reread_for_rewarm( $post_type, $post_id, $url, $page_title, $modified_at ) ) {
			return;
		}

		$rows = [];
		foreach ( $languages as $language ) {
			$rows[] = [
				'object_type' => $post_type,
				'object_id'   => $post_id,
				'url'         => $url,
				'page_title'  => $page_title,
				'language'    => $language,
				'priority'    => Indexer::PRIORITY_DEFAULT,
			];
		}

		if ( $queue->enqueue_rewarm( $rows, $modified_at ) > 0 ) {
			Indexer::schedule_drain();
		}
	}

	/** The object's last save as a Unix timestamp, 0 when unknown. */
	private static function modified_at( int $post_id ): int {
		$modified_gmt = (string) get_post_field( 'post_modified_gmt', $post_id );
		if ( '' === $modified_gmt || 0 === strpos( $modified_gmt, '0000-00-00' ) ) {
			return 0;
		}

		$modified = strtotime( $modified_gmt . ' UTC' );

		return false !== $modified && $modified > 0 ? $modified : 0;
	}

	private function indexable_post_types(): array {
		$types = get_post_types( [ 'public' => true ], 'names' );
		unset( $types['attachment'] );

		$types = apply_filters( 'translatewp/indexer_post_types', array_values( $types ) );

		return is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ) ) ) : [];
	}

	public function schedule_lexicon_stale_mark(): void {
		DebouncedTask::schedule( self::LEXICON_DEBOUNCE_HOOK );
	}

	public function mark_lexicon_stale(): void {
		CacheVersions::bump_lexicon_version();
	}

	/**
	 * Debounce the retranslation a settings change calls for.
	 *
	 * @param array $languages The translation languages the change touched;
	 *                         empty means every one of them.
	 */
	public function schedule_settings_rebuild( $languages = [] ): void {
		// Changes inside one debounce window add up to one retranslation: the
		// languages are gathered here, and one change for every language wins.
		$languages = is_array( $languages ) ? array_values( array_map( 'strval', $languages ) ) : [];
		$pending   = get_option( self::PENDING_LANGUAGES_OPTION, null );
		if ( ! $languages || ( is_array( $pending ) && in_array( self::ALL_LANGUAGES, $pending, true ) ) ) {
			$pending = [ self::ALL_LANGUAGES ];
		} else {
			$pending = array_values( array_unique( array_merge( is_array( $pending ) ? $pending : [], $languages ) ) );
		}
		update_option( self::PENDING_LANGUAGES_OPTION, $pending, false );
		DebouncedTask::schedule( self::SETTINGS_DEBOUNCE_HOOK );
	}

	/**
	 * The save that changed the brief, style or model asked first and said the
	 * site is translated again; this is where it happens, once for every
	 * language the changes in the window touched, never through the repair
	 * loop.
	 */
	public function rebuild_after_settings_change(): void {
		$pending = get_option( self::PENDING_LANGUAGES_OPTION, null );
		delete_option( self::PENDING_LANGUAGES_OPTION );
		$pending = is_array( $pending ) ? $pending : [ self::ALL_LANGUAGES ];
		CacheVersions::bump_settings_version();
		if ( SiteConnection::is_connected() ) {
			( new Indexer() )->retranslate_everything( in_array( self::ALL_LANGUAGES, $pending, true ) ? [] : $pending );
		}
	}

	public function mark_localizer_stale(): void {
		CacheVersions::bump_localizer_version();
	}

	/**
	 * Queue a forced retranslation of every finished page translated under an
	 * older lexicon or localizer version, so the new terms or formats reach
	 * the pages that already exist. Returns how many rows were queued.
	 */
	public static function retranslate_stale( string $group ): int {
		if ( ! SiteConnection::is_connected() ) {
			return 0;
		}

		$queue = new IndexQueue();
		$rows  = $queue->stale_rows( $group );
		if ( ! $rows ) {
			return 0;
		}

		$queued = $queue->enqueue_retranslate( $rows );
		if ( $queued > 0 ) {
			$languages = [];
			foreach ( $rows as $row ) {
				$languages[ (string) $row['language'] ] = true;
			}
			TranslationStore::bump_cache_generation( array_keys( $languages ) );
		}

		return $queued;
	}

	public static function apply_lexicon_now(): int {
		return self::retranslate_stale( IndexQueue::STALE_GROUP_LEXICON );
	}

	public static function apply_localizer_now(): int {
		return self::retranslate_stale( IndexQueue::STALE_GROUP_LOCALIZER );
	}
}
