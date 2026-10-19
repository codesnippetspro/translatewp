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

	/**
	 * Charges the request's items against the client's item budget; a
	 * request over the budget is refused whole.
	 */
	public static function charge_miss_items( string $site_id, string $client_ip, int $items ) {
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
			self::MISS_ITEMS_MAX,
			self::MISS_ITEMS_WINDOW_SECONDS,
			max( 1, $items )
		);
	}

	public static function limit_for_bucket( string $bucket ): int {
		return self::BUCKET_STATUS === $bucket ? self::STATUS_MAX : self::MUTATION_MAX;
	}
}
