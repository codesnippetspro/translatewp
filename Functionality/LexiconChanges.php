<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\CacheVersions;
use TranslateWP\Components\DebouncedTask;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Components\LoopbackKick;
use TranslateWP\Components\Options;
use TranslateWP\Components\PlainText;
use TranslateWP\Components\MutationLock;
use TranslateWP\Components\TranslationProxySettings;
use TranslateWP\Entities\CPTs\Lexicon;

class LexiconChanges {

	public const SEEDED_OPTION = 'translatewp_lexicon_changes_seeded';
	public const SNAPSHOTS_OPTION = 'translatewp_lexicon_change_snapshots';
	private static array $pending = [];

	public function __construct( $plugin_name = '', $plugin_version = '' ) {
		unset( $plugin_name, $plugin_version );
		add_action( 'init', [ $this, 'seed' ], 20 );
		add_action( 'save_post_lexicon', [ $this, 'edited' ] );
		add_action( 'before_delete_post', [ $this, 'deleting' ], 10, 2 );
		add_action( 'added_post_meta', [ $this, 'meta_changed' ], 10, 4 );
		add_action( 'updated_post_meta', [ $this, 'meta_changed' ], 10, 4 );
		add_action( 'deleted_post_meta', [ $this, 'meta_changed' ], 10, 4 );
		add_action( 'shutdown', [ self::class, 'flush' ], PHP_INT_MAX );
		add_filter( 'rest_request_after_callbacks', [ $this, 'after_request' ], PHP_INT_MAX );
		add_action( 'translatewp_localizer_settings_changed', [ $this, 'localizer_changed' ] );
	}

	public function seed(): void {
		if ( ! get_option( self::SEEDED_OPTION, false ) ) {
			MutationLock::run(
                'cache_versions',
                function (): void {
					$this->seed_once();
				}
            );
		}
	}

	private function seed_once(): void {
		if ( get_option( self::SEEDED_OPTION, false ) ) {
			return;
		}
		$times = Options::get( 'cache.lexicon_changed_at', [] );
		$times = is_array( $times ) ? $times : [];
		$snapshots = [];
		Options::update( 'cache.lexicon_base_version', CacheVersions::lexicon_version() );
		foreach ( get_posts(
            [
				'post_type' => Lexicon::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => -1,
			]
        ) as $post ) {
			$snapshot = self::snapshot( $post->ID );
			$snapshots[ $post->ID ] = $snapshot;
			$modified = strtotime( $post->post_modified_gmt . ' UTC' );
			if ( false !== $modified && $modified > 0 ) {
				foreach ( self::languages( $snapshot ) as $language ) {
					$times[ $language ] = max( (int) ( $times[ $language ] ?? 0 ), $modified );
				}
			}
		}
		Options::update( 'cache.lexicon_changed_at', $times );
		update_option( self::SNAPSHOTS_OPTION, $snapshots, false );
		update_option( self::SEEDED_OPTION, true, false );
		$queue = new IndexQueue();
		$rows = $queue->rows_before_lexicon_changes( $times );
		if ( $rows && $queue->enqueue_retranslate( $rows, false ) > 0 ) {
			TranslationStore::bump_cache_generation( array_values( array_unique( array_column( $rows, 'language' ) ) ) );
			Indexer::schedule_drain();
			LoopbackKick::fire();
		}
	}

	public function edited( int $post_id ): void {
		if ( ! wp_is_post_revision( $post_id ) && ! wp_is_post_autosave( $post_id ) ) {
			self::$pending[ $post_id ] = self::$pending[ $post_id ] ?? [
				'at' => time(),
				'deleted' => false,
			];
		}
	}

	public function deleting( int $post_id, $post ): void {
		if ( $post instanceof \WP_Post && Lexicon::POST_TYPE === $post->post_type ) {
			self::$pending[ $post_id ] = [
				'at' => time(),
				'deleted' => true,
				'before' => self::snapshot( $post_id ),
			];
		}
	}

	public function meta_changed( $meta_id, int $post_id, string $key, $value ): void {
		unset( $meta_id, $value );
		if ( Lexicon::LANGUAGES_META_KEY === $key && Lexicon::POST_TYPE === get_post_type( $post_id ) ) {
			$this->edited( $post_id );
		}
	}

	public function after_request( $response ) {
		self::flush();
		return $response;
	}

	public static function flush(): void {
		if ( self::$pending ) {
			MutationLock::run(
                'cache_versions',
                static function (): void {
					self::flush_pending();
				}
            );
		}
	}

	private static function flush_pending(): void {
		if ( ! self::$pending ) {
			return;
		}
		$pending = self::$pending;
		self::$pending = [];
		$snapshots = get_option( self::SNAPSHOTS_OPTION, [] );
		$snapshots = is_array( $snapshots ) ? $snapshots : [];
		$times = Options::get( 'cache.lexicon_changed_at', [] );
		$times = is_array( $times ) ? $times : [];
		$versions = Options::get( 'cache.lexicon_language_versions', [] );
		$versions = is_array( $versions ) ? $versions : [];
		$changed = [];
		foreach ( $pending as $id => $edit ) {
			$old = $snapshots[ $id ] ?? ( $edit['before'] ?? null );
			$new = $edit['deleted'] ? null : self::snapshot( (int) $id );
			if ( $old !== $new ) {
				foreach ( array_unique( array_merge( self::languages( $old ), self::languages( $new ) ) ) as $language ) {
					$times[ $language ] = max( (int) ( $times[ $language ] ?? 0 ), (int) $edit['at'] );
					$changed[ $language ] = true;
				}
			}
			if ( null === $new ) {
				unset( $snapshots[ $id ] );
			} else {
				$snapshots[ $id ] = $new;
			}
		}
		foreach ( array_keys( $changed ) as $language ) {
			$versions[ $language ] = CacheVersions::lexicon_version_for( $language ) + 1;
		}
		Options::update( 'cache.lexicon_changed_at', $times );
		Options::update( 'cache.lexicon_language_versions', $versions );
		update_option( self::SNAPSHOTS_OPTION, $snapshots, false );
		if ( $changed ) {
			do_action( 'translatewp_lexicon_changed' );
		}
	}

	public function localizer_changed(): void {
		MutationLock::run(
			'cache_versions',
			static function (): void {
				Options::update( 'cache.localizer_changed_at', time() );
				CacheVersions::bump_settings_version();
				DebouncedTask::schedule( KeepWarm::VERSIONED_REFILL_HOOK );
			}
		);
	}

	private static function snapshot( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || Lexicon::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$languages = Lexicon::languages_of( $id );
		sort( $languages );
		return [
			'status' => $post->post_status,
			'term' => PlainText::decode( trim( $post->post_title ) ),
			'description' => trim( $post->post_content ),
			'languages' => $languages,
		];
	}

	private static function languages( ?array $snapshot ): array {
		if ( null === $snapshot || 'publish' !== $snapshot['status'] ) {
			return [];
		}
		return ! empty( $snapshot['languages'] ) ? $snapshot['languages'] : array_values( array_diff( TranslationProxySettings::active_lookup_languages(), [ TranslationProxySettings::default_locale() ] ) );
	}
}
