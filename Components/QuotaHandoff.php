<?php

namespace TranslateWP\Components;

/**
 * The switch to the site's own AI providers once the plan's translation
 * quota is exhausted: site-wide state, the jobs pinned to the own providers,
 * and the providers themselves, answered by the Pro addon through a filter.
 */
class QuotaHandoff {

	public const OPTION       = 'translatewp_quota_handoff';
	public const SETTING_PATH = 'translations.settings.own_providers_after_quota';
	// A pin outlives any job the service still runs; a job the site never closed is forgotten after this.
	public const PIN_MAX_AGE_SECONDS = 7 * DAY_IN_SECONDS;
	// Each pin is its own option row, written and deleted on its own, so two
	// requests pinning or releasing at once never overwrite each other, and a
	// flushed object cache cannot lose one (a transient could).
	public const PIN_OPTION_PREFIX = 'translatewp_quota_pin_';

	private const CODE_LIMIT_EXCEEDED = 'limit_exceeded';
	private const RECHECK_SECONDS     = HOUR_IN_SECONDS;

	public static function enabled(): bool {
		return (bool) Options::get( self::SETTING_PATH, false );
	}

	public static function set_enabled( bool $enabled ): void {
		Options::update( self::SETTING_PATH, $enabled );
	}

	/**
	 * The own providers that serve a language, or [] when the addon holds
	 * none for it. The Pro addon answers the filter with its decrypted chain.
	 */
	public static function providers_for( string $language ): array {
		$providers = apply_filters( 'translatewp_quota_handoff_providers', [], $language );

		return is_array( $providers ) ? array_values( $providers ) : [];
	}

	/**
	 * Whether the handoff is on now: switched on by a quota refusal and not
	 * yet past the quota window's end. Without a known end the switch holds
	 * for an hour, then the next refusal or success decides again.
	 */
	public static function is_active(): bool {
		$state = self::state();
		if ( empty( $state['since'] ) ) {
			return false;
		}
		$until = is_string( $state['until'] ?? null ) ? strtotime( $state['until'] ) : false;
		if ( false !== $until ) {
			return time() < $until;
		}

		return time() < (int) $state['since'] + self::RECHECK_SECONDS;
	}

	public static function until(): ?string {
		$until = self::state()['until'] ?? null;

		return is_string( $until ) && '' !== $until ? $until : null;
	}

	public static function activate( ?string $window_ends_at ): void {
		update_option(
			self::OPTION,
			[
				'since' => time(),
				'until' => is_string( $window_ends_at ) && '' !== $window_ends_at ? $window_ends_at : null,
			],
			false
		);
	}

	public static function pin_job( string $job_id ): void {
		if ( '' === $job_id ) {
			return;
		}
		update_option( self::pin_key( $job_id ), time() + self::PIN_MAX_AGE_SECONDS, false );
	}

	/** A job the site closed or cancelled needs its pin no longer. */
	public static function release_job( string $job_id ): void {
		if ( '' !== $job_id ) {
			delete_option( self::pin_key( $job_id ) );
		}
	}

	public static function job_uses_own_providers( string $job_id ): bool {
		if ( '' === $job_id ) {
			return false;
		}
		$expires_at = (int) get_option( self::pin_key( $job_id ), 0 );
		if ( 0 === $expires_at ) {
			return false;
		}
		if ( $expires_at <= time() ) {
			delete_option( self::pin_key( $job_id ) );

			return false;
		}

		return true;
	}

	private static function pin_key( string $job_id ): string {
		return self::PIN_OPTION_PREFIX . md5( $job_id );
	}

	/**
	 * Whether a request should carry the own providers: a pinned job always,
	 * a new job while the handoff is active.
	 */
	public static function wants_own_providers( string $job_id ): bool {
		if ( ! self::enabled() ) {
			return false;
		}

		return self::job_uses_own_providers( $job_id ) || ( '' === $job_id && self::is_active() );
	}

	/** The job id a service path addresses, '' for the job collection. */
	public static function job_id_of_path( string $path ): string {
		return preg_match( '#^/v1/translation/jobs/([^/]+)/#', $path, $match ) ? rawurldecode( $match[1] ) : '';
	}

	public static function is_quota_refusal( $response ): bool {
		if ( ! is_wp_error( $response ) ) {
			return false;
		}
		$data = $response->get_error_data();

		return is_array( $data )
			&& 429 === (int) ( $data['status'] ?? 0 )
			&& self::CODE_LIMIT_EXCEEDED === (string) ( $data['payload']['code'] ?? '' );
	}

	public static function window_end_of( \WP_Error $response ): ?string {
		$quota = $response->get_error_data()['payload']['quota'] ?? null;
		$until = is_array( $quota ) ? ( $quota['window_ends_at'] ?? null ) : null;

		return is_string( $until ) && '' !== $until ? $until : null;
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	private static function state(): array {
		$state = get_option( self::OPTION, [] );

		return is_array( $state ) ? $state : [];
	}
}
