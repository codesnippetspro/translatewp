<?php

namespace TranslateWP\Components;

class TranslationProxyRateLimit {

	public const BUCKET_STATUS     = 'status';
	public const BUCKET_MUTATION   = 'mutation';
	public const BUCKET_MISS_ITEMS = 'miss_items';

	private const WINDOW_SECONDS = 60;
	private const STATUS_MAX     = 240;
	// Misses are deduplicated and cheap per call (Rule 0 endpoint spec §4):
	// a text-heavy page's coalesced miss reports must fit one visitor's
	// per-minute budget alongside push-batch/create-job/cancel traffic.
	private const MUTATION_MAX   = 300;
	// One client's accepted miss items per window, on top of its request
	// bucket.
	public const MISS_ITEMS_WINDOW_SECONDS = 600;
	public const MISS_ITEMS_MAX            = 400;
	public const FIRST_PASS_ITEMS_MAX = 2000;
	public const FIRST_PASS_OPTION = 'translatewp_miss_first_pass';
	private const FIRST_PASS_SECONDS = 14 * DAY_IN_SECONDS;

	public static function check( string $bucket, string $site_id, string $client_ip ) {
		if ( '' === $client_ip ) {
			return new \WP_Error(
				'client_ip_unavailable',
				'Client IP could not be established',
				[ 'status' => 403 ]
			);
		}

		$max = self::BUCKET_STATUS === $bucket ? self::STATUS_MAX : self::MUTATION_MAX;

		return TranslationProxyStorage::increment_rate_limit(
			$bucket,
			$site_id,
			$client_ip,
			$max,
			self::WINDOW_SECONDS
		);
	}

	public static function charge_miss_items( string $site_id, string $client_ip, int $items, int $limit = self::MISS_ITEMS_MAX ) {
		if ( '' === $client_ip ) {
			return new \WP_Error(
				'client_ip_unavailable',
				'Client IP could not be established',
				[ 'status' => 403 ]
			);
		}

		return TranslationProxyStorage::increment_rate_limit(
			self::BUCKET_MISS_ITEMS,
			$site_id,
			$client_ip,
			$limit,
			self::MISS_ITEMS_WINDOW_SECONDS,
			max( 1, $items )
		);
	}

	public static function miss_items_limit( string $site_id, string $language ): int {
		$records = get_option( self::FIRST_PASS_OPTION, [] );
		if ( ! is_array( $records ) || '' === $site_id || '' === $language ) {
			return self::MISS_ITEMS_MAX;
		}
		if ( isset( $records[ $site_id ] ) && ! is_array( $records[ $site_id ] ) ) {
			return self::MISS_ITEMS_MAX;
		}
		$reported = get_option( 'translatewp_index_reported_languages', [] );
		if ( ! is_array( $reported ) ) {
			return self::MISS_ITEMS_MAX;
		}
		foreach ( $reported as $reported_language ) {
			if ( ! is_string( $reported_language ) || '' === $reported_language ) {
				return self::MISS_ITEMS_MAX;
			}
			self::complete_first_pass( $site_id, $reported_language );
		}
		$records = get_option( self::FIRST_PASS_OPTION, [] );
		if ( in_array( $language, $reported, true ) ) {
			return self::MISS_ITEMS_MAX;
		}
		if ( ! array_key_exists( $language, $records[ $site_id ] ?? [] ) ) {
			$records[ $site_id ][ $language ] = [
				'observed_at' => time(),
				'completed' => false,
			];
			update_option( self::FIRST_PASS_OPTION, $records, false );
			return self::MISS_ITEMS_MAX;
		}
		$record = $records[ $site_id ][ $language ];
		if ( ! is_array( $record ) || ! isset( $record['observed_at'], $record['completed'] )
			|| ! is_int( $record['observed_at'] ) || ! is_bool( $record['completed'] )
			|| $record['completed'] || $record['observed_at'] <= 0 || $record['observed_at'] > time()
			|| time() - $record['observed_at'] >= self::FIRST_PASS_SECONDS ) {
			return self::MISS_ITEMS_MAX;
		}
		return self::FIRST_PASS_ITEMS_MAX;
	}

	public static function complete_first_pass( string $site_id, string $language ): void {
		$records = get_option( self::FIRST_PASS_OPTION, [] );
		if ( ! is_array( $records ) || '' === $site_id || '' === $language
			|| ( isset( $records[ $site_id ] ) && ! is_array( $records[ $site_id ] ) ) ) {
			return;
		}
		$record = $records[ $site_id ][ $language ] ?? null;
		if ( is_array( $record ) && true === ( $record['completed'] ?? null ) ) {
			return;
		}
		$records[ $site_id ][ $language ] = [
			'observed_at' => is_array( $record ) && is_int( $record['observed_at'] ?? null ) ? $record['observed_at'] : time(),
			'completed' => true,
		];
		update_option( self::FIRST_PASS_OPTION, $records, false );
	}

	public static function limit_for_bucket( string $bucket ): int {
		return self::BUCKET_STATUS === $bucket ? self::STATUS_MAX : self::MUTATION_MAX;
	}
}
