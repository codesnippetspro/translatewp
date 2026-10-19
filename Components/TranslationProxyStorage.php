<?php

namespace TranslateWP\Components;

/**
 * Transient-backed TTL store for the translation proxy: segment dedupe
 * claims, rate-limit counters, and the short-lived job→language map.
 * Core APIs only — WordPress owns expiry (expired transients are purged on
 * access and by core's daily scheduled cleanup). Claim/increment operations
 * are read-modify-write: the rare concurrent double-claim is harmless
 * because the remote API dedupes pushed segments, and a lost counter
 * increment only makes a rate limit marginally more permissive.
 */
class TranslationProxyStorage {

	private const KEY_PREFIX = 'twp_px_';
	private const CACHE_GROUP = 'translatewp_proxy';

	public static function purge_expired(): void {
		// Core purges expired transients (on access + daily cleanup).
	}

	public static function claim_segment( string $site_id, string $target_lang, string $segment_hash ) {
		if ( '' === $site_id || '' === $target_lang || '' === $segment_hash ) {
			return new \WP_Error(
				'invalid_parameter',
				'Segment claim parameters are invalid',
				[ 'status' => 400 ]
			);
		}

		$key = self::segment_claim_key( $site_id, $target_lang, $segment_hash );
		$ttl = TranslationProxyDedupe::window_seconds();

		// Atomic test-and-set on a persistent object cache (Redis/Memcached):
		// wp_cache_add fails if the key already exists, closing the
		// get-then-set race two concurrent misses would otherwise slip
		// through. The transient is the durable mirror + the fallback when no
		// persistent cache backend is present.
		if ( wp_using_ext_object_cache() ) {
			if ( ! wp_cache_add( $key, 1, self::CACHE_GROUP, $ttl ) ) {
				return new \WP_Error(
					'duplicate_segment',
					'Segment already queued for this site and target language',
					[ 'status' => 409 ]
				);
			}
			set_transient( $key, 1, $ttl );
			return true;
		}

		if ( false !== get_transient( $key ) ) {
			return new \WP_Error(
				'duplicate_segment',
				'Segment already queued for this site and target language',
				[ 'status' => 409 ]
			);
		}

		if ( ! set_transient( $key, 1, $ttl ) ) {
			return self::storage_error( 'Segment claim storage failed' );
		}

		return true;
	}

	public static function release_segment( string $site_id, string $target_lang, string $segment_hash ): void {
		if ( '' === $site_id || '' === $target_lang || '' === $segment_hash ) {
			return;
		}

		$key = self::segment_claim_key( $site_id, $target_lang, $segment_hash );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, self::CACHE_GROUP );
		}
		delete_transient( $key );
	}

	public static function release_segments( string $site_id, string $target_lang, array $segment_hashes ): void {
		foreach ( $segment_hashes as $segment_hash ) {
			self::release_segment( $site_id, $target_lang, (string) $segment_hash );
		}
	}

	public static function increment_rate_limit( string $bucket, string $site_id, string $client_ip, int $max, int $window_seconds, int $amount = 1 ) {
		if ( '' === $bucket || '' === $client_ip || $max < 1 || $window_seconds < 1 || $amount < 1 ) {
			return new \WP_Error(
				'invalid_parameter',
				'Rate limit parameters are invalid',
				[ 'status' => 400 ]
			);
		}

		$key       = self::rate_limit_key( $bucket, $site_id, $client_ip, $window_seconds );
		$reset_key = self::rate_limit_reset_key( $key );
		$now       = time();
		$current   = get_transient( $key );
		$ends_at   = (int) get_transient( $reset_key );

		// A window is fixed from its first request: later requests, refused or
		// not, never move its end.
		if ( false === $current || $ends_at <= $now ) {
			$current = 0;
			$ends_at = $now + $window_seconds;
			if ( ! set_transient( $reset_key, $ends_at, $window_seconds ) ) {
				return self::storage_error( 'Rate limit storage failed' );
			}
		}

		$count = (int) $current + $amount;
		if ( $count > $max ) {
			return new \WP_Error(
				'rate_limited',
				'Too many translation requests',
				[
					'status'      => 429,
					'retry_after' => max( 1, $ends_at - $now ),
				]
			);
		}

		if ( ! set_transient( $key, $count, max( 1, $ends_at - $now ) ) ) {
			return self::storage_error( 'Rate limit storage failed' );
		}

		return true;
	}

	public static function remember_job_languages( string $job_id, string $source_lang, string $target_lang ) {
		if ( '' === $job_id || '' === $source_lang || '' === $target_lang ) {
			return new \WP_Error(
				'invalid_parameter',
				'Job language parameters are invalid',
				[ 'status' => 400 ]
			);
		}

		$stored = set_transient(
			self::job_lang_key( $job_id ),
			[
				'sourceLang' => $source_lang,
				'targetLang' => $target_lang,
			],
			self::JOB_LANG_SECONDS
		);

		if ( ! $stored ) {
			return self::storage_error( 'Job language storage failed' );
		}

		return true;
	}

	public static function job_languages( string $job_id ): ?array {
		if ( '' === $job_id ) {
			return null;
		}

		$value = get_transient( self::job_lang_key( $job_id ) );
		if ( ! is_array( $value ) ) {
			return null;
		}

		$source_lang = (string) ( $value['sourceLang'] ?? '' );
		$target_lang = (string) ( $value['targetLang'] ?? '' );
		if ( '' === $source_lang || '' === $target_lang ) {
			return null;
		}

		return [
			'sourceLang' => $source_lang,
			'targetLang' => $target_lang,
		];
	}

	public static function forget_job_languages( string $job_id ): void {
		if ( '' === $job_id ) {
			return;
		}

		delete_transient( self::job_lang_key( $job_id ) );
	}

	public const JOB_LANG_SECONDS = 130;

	public static function segment_claim_key( string $site_id, string $target_lang, string $segment_hash ): string {
		return self::KEY_PREFIX . 'seg_' . md5( $site_id . '|' . $target_lang . '|' . $segment_hash );
	}

	public static function job_lang_key( string $job_id ): string {
		return self::KEY_PREFIX . 'job_' . md5( $job_id );
	}

	public static function rate_limit_key( string $bucket, string $site_id, string $client_ip, int $window_seconds ): string {
		return self::KEY_PREFIX . 'rate_' . md5( $bucket . '|' . $site_id . '|' . $client_ip . '|' . $window_seconds );
	}

	public static function rate_limit_reset_key( string $rate_limit_key ): string {
		return $rate_limit_key . '_end';
	}

	private static function storage_error( string $message ): \WP_Error {
		return new \WP_Error(
			'storage_unavailable',
			$message,
			[ 'status' => 503 ]
		);
	}
}
