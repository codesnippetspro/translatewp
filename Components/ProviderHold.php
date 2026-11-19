<?php

namespace TranslateWP\Components;

class ProviderHold {
	public const OPTION = 'translatewp_provider_hold';
	public const WATCH_OPTION = 'translatewp_provider_watch';

	public static function state(): array {
		return (array) get_option( self::OPTION, [] );
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
