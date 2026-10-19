<?php

namespace TranslateWP\Components;

class SiteLanguageSync {

	public const RETRY_HOOK = 'translatewp_language_sync_retry';
	// Set while a save that should start translation waits on a retried sync.
	private const START_PENDING_OPTION = 'translatewp_language_sync_start_pending';
	private const RETRY_DELAY_SECONDS = 60;

	public static function active_languages_for_sync(): array {
		return TranslationProxySettings::active_lookup_languages();
	}

	/**
	 * Push the active languages to the service. A save of the languages
	 * (wizard step, finish, settings, network apply) also starts translation
	 * through translatewp_languages_saved; a credential sync on connect or
	 * reconnect only fires translatewp_languages_synced and queues nothing.
	 */
	public static function sync_active_languages( bool $start_translation = false ): bool {
		if ( ! SiteConnection::is_connected() ) {
			return false;
		}

		$languages = self::active_languages_for_sync();

		$response = SiteRequests::put(
			'/v1/site/languages',
			[
				'languages' => $languages,
			]
		);

		if ( ! is_wp_error( $response ) ) {
			ActionLog::record( 'Language list sent to the service: ' . implode( ', ', array_map( static fn ( $language ) => (string) ( $language['locale'] ?? $language ), $languages ) ) );
		}
		if ( is_wp_error( $response ) ) {
			error_log( '[TranslateWP] Site language sync failed.' );
			ActionLog::record( 'Language list could not be sent to the service', 'error' );
			if ( $start_translation ) {
				update_option( self::START_PENDING_OPTION, 1, false );
			}
			self::schedule_retry();
			return false;
		}

		do_action( 'translatewp_languages_synced', $languages );
		if ( $start_translation ) {
			delete_option( self::START_PENDING_OPTION );
			do_action( 'translatewp_languages_saved', $languages );
		}

		return true;
	}

	/**
	 * One retry per failure: the warm-up must not be silently skipped
	 * forever when the PUT fails. The retry itself never re-schedules, so a
	 * permanent failure logs once and stops.
	 */
	public static function schedule_retry(): void {
		if ( wp_next_scheduled( self::RETRY_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::RETRY_DELAY_SECONDS, self::RETRY_HOOK );
	}

	public static function retry_sync(): void {
		$languages = self::active_languages_for_sync();

		$response = SiteRequests::put(
			'/v1/site/languages',
			[
				'languages' => $languages,
			]
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[TranslateWP] Site language sync retry failed.' );
			ActionLog::record( 'Language list retry could not be sent to the service', 'error' );
			return;
		}

		do_action( 'translatewp_languages_synced', $languages );
		if ( get_option( self::START_PENDING_OPTION ) ) {
			delete_option( self::START_PENDING_OPTION );
			do_action( 'translatewp_languages_saved', $languages );
		}
	}
}
