<?php

namespace TranslateWP\Components;

class ProviderHold {
	public const OPTION = 'translatewp_provider_hold';
	public const WATCH_OPTION = 'translatewp_provider_watch';

	public static function state(): array {
		return (array) get_option( self::OPTION, [] );
	}

	private static function fingerprint(): string {
		$languages = TranslationProxySettings::target_languages();
		sort( $languages, SORT_STRING );
		$chains = [];
		foreach ( $languages as $language ) {
			$chains[ $language ] = QuotaHandoff::providers_for( $language );
		}
		return hash_hmac( 'sha256', (string) wp_json_encode( $chains ), wp_salt( 'auth' ) );
	}

	public static function refresh(): bool {
		$hold = self::state();
		if ( ! $hold ) {
			return false;
		}
		$fingerprint = self::fingerprint();
		if ( ! isset( $hold['fingerprint'] ) ) {
			$hold['fingerprint'] = $fingerprint;
			update_option( self::OPTION, $hold, false );
			return false;
		}
		if ( hash_equals( $hold['fingerprint'], $fingerprint ) ) {
			return false;
		}
		return self::release_settings_hold();
	}

	private static function release_settings_hold(): bool {
		if ( ! delete_option( self::OPTION ) ) {
			return false;
		}
		update_option( self::WATCH_OPTION, [ 'recheck' => true ], false );
		BackgroundEngine::schedule_wake();
		return true;
	}

	public static function needs_recheck(): bool {
		return ! empty( get_option( self::WATCH_OPTION, [] )['recheck'] );
	}

	public static function settings_saved(): void {
		if ( self::state() && self::release_settings_hold() ) {
			BackgroundEngine::wake();
		}
	}

	public static function check_now( string $token ): void {
		$claimed = false;
		MutationLock::run(
			'provider_check',
			static function () use ( $token, &$claimed ): void {
				$hold = self::state();
				if ( ! $hold || self::check_token( $hold ) !== $token || ! empty( $hold['probe'] ) || ! empty( $hold['manual'] ) ) {
					return;
				}
				$hold['due'] = time();
				$hold['manual'] = true;
				update_option( self::OPTION, $hold, false );
				$claimed = true;
			}
		);
		if ( $claimed ) {
			BackgroundEngine::step();
		}
	}

	public static function check_token( array $hold ): string {
		return hash( 'sha256', (string) wp_json_encode( [ $hold['id'] ?? '', $hold['due'] ?? 0 ] ) );
	}

	public static function reason( string $code ): string {
		switch ( $code ) {
			case 'invalid_key':
			case 'byok_invalid_key':
				return 'invalid_key';
			case 'model_unavailable':
			case 'byok_model_unavailable':
			case 'byok_model_unsupported':
				return 'model_unavailable';
		}
		return '';
	}

	public static function reject( string $reason, int $retry = 0 ): void {
		$reason = self::reason( $reason );
		if ( '' === $reason ) {
			return;
		}
		$hold = self::state();
		if ( ! empty( $hold['due'] ) && $hold['due'] > time() && empty( $hold['probe'] ) && empty( $hold['reserved'] ) ) {
			return;
		}
		delete_option( self::WATCH_OPTION );
		$delay = min( 21600, max( 900, $retry, 2 * (int) ( $hold['delay'] ?? 0 ) ) );
		update_option(
            self::OPTION,
            [
				'id' => wp_generate_uuid4(),
				'fingerprint' => self::fingerprint(),
				'reason' => $reason,
				'delay' => $delay,
				'due' => time() + $delay,
			],
            false
        );
	}

	public static function waiting(): bool {
		$hold = self::state();
		return $hold && ( $hold['due'] > time() || ! empty( $hold['probe'] ) );
	}

	public static function watching(): bool {
		return ! empty( get_option( self::WATCH_OPTION, [] )['job'] );
	}

	public static function watch( string $job_id ): void {
		if ( (int) ( get_option( self::WATCH_OPTION, [] )['verifiedUntil'] ?? 0 ) <= time() ) {
			update_option(
                self::WATCH_OPTION,
                [
					'job' => $job_id,
					'started' => time(),
				],
                false
            );
		}
	}

	public static function verified(): void {
		update_option( self::WATCH_OPTION, [ 'verifiedUntil' => time() + 900 ], false );
	}

	public static function reserve_probe(): void {
		$hold = self::state();
		$hold['due'] = time() + $hold['delay'];
		$hold['reserved'] = true;
		update_option( self::OPTION, $hold, false );
	}

	public static function request_error(): ?\WP_Error {
		self::refresh();
		$hold = self::state();
		return $hold ? new \WP_Error(
			'provider_waiting',
			__( 'Translation is waiting for the AI provider.', 'translate' ),
			[
				'status' => 503,
				'retryAfterSeconds' => max( 1, $hold['due'] - time() ),
			]
		) : null;
	}

	public static function probe( string $job_id, string $language, string $hash ): void {
		$hold = self::state();
		$hold['probe'] = $job_id;
		$hold['started'] = time();
		$hold['language'] = $language;
		$hold['hash'] = $hash;
		update_option( self::OPTION, $hold, false );
	}

	public static function clear_probe( string $job_id ): void {
		if ( ( self::state()['probe'] ?? null ) === $job_id ) {
			delete_option( self::OPTION );
			self::verified();
		}
	}
}
