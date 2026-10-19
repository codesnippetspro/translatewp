<?php

namespace TranslateWP\Components;

/**
 * Reads the API's authoritative metering (GET /v1/site/usage) with the site
 * bearer token. The API is the source of truth because it meters every
 * translation and every visitor lookup regardless of whether it ran in a
 * visitor's browser or through background indexing.
 *
 * The per-site figures of dashboard_usage() degrade to zeroed totals on an
 * outage or a disconnected site, never an error. The quota() read gives the
 * account's shared allowance as the percentage still available, the same on
 * every site of the account. It reads 100 whenever the allowance is not
 * enforced, and also when the read fails, the answer the API gives when its
 * own read fails. Only a successful read is cached, briefly, so the Dashboard
 * does not call the API on every load.
 */
class SiteUsage {

	private const USAGE_PATH = '/v1/site/usage';

	private const FULL_PERCENT = 100;

	private const QUOTA_TIMEOUT_SECONDS = 5;

	private const QUOTA_CACHE_KEY = 'translatewp_account_quota';

	private const QUOTA_CACHE_SECONDS = 60;

	public static function dashboard_usage(): array {
		$response = SiteRequests::get( self::USAGE_PATH );

		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return self::empty_usage();
		}

		return self::normalize( $response );
	}

	public static function quota(): array {
		$cached = get_transient( self::QUOTA_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = SiteRequests::get( self::USAGE_PATH, [], self::QUOTA_TIMEOUT_SECONDS );
		$quota    = is_array( $response ) && is_array( $response['quota'] ?? null ) ? $response['quota'] : null;

		if ( null === $quota || ! is_numeric( $quota['percent_available'] ?? null ) ) {
			return self::full_quota();
		}

		$result = self::full_quota();
		if ( true === ( $quota['enforced'] ?? false ) ) {
			$window_ends_at = $quota['window_ends_at'] ?? null;
			$result         = [
				'percentAvailable' => min( self::FULL_PERCENT, max( 0, (int) round( (float) $quota['percent_available'] ) ) ),
				'windowEndsAt'     => is_string( $window_ends_at ) && '' !== $window_ends_at ? $window_ends_at : null,
				'enforced'         => true,
			];
		}

		set_transient( self::QUOTA_CACHE_KEY, $result, self::QUOTA_CACHE_SECONDS );

		return $result;
	}

	private static function full_quota(): array {
		return [
			'percentAvailable' => self::FULL_PERCENT,
			'windowEndsAt'     => null,
			'enforced'         => false,
		];
	}

	private static function normalize( array $response ): array {
		$tokens_today     = max( 0, (int) ( $response['tokensToday'] ?? 0 ) );
		$tokens_month     = max( 0, (int) ( $response['tokensThisMonth'] ?? 0 ) );
		$tokens_yesterday = max( 0, (int) ( $response['tokensYesterday'] ?? 0 ) );
		$hits_today       = max( 0, (int) ( $response['cacheHitsToday'] ?? 0 ) );
		$misses_today     = max( 0, (int) ( $response['cacheMissesToday'] ?? 0 ) );
		$hits_yesterday   = max( 0, (int) ( $response['cacheHitsYesterday'] ?? 0 ) );
		$misses_yesterday = max( 0, (int) ( $response['cacheMissesYesterday'] ?? 0 ) );

		$daily = is_array( $response['daily'] ?? null ) ? $response['daily'] : [];
		$trend = [];
		foreach ( $daily as $point ) {
			if ( ! is_array( $point ) ) {
				continue;
			}

			$date      = (string) ( $point['date'] ?? '' );
			$timestamp = strtotime( $date . ' UTC' );
			$label     = false !== $timestamp ? gmdate( 'M j', $timestamp ) : $date;

			$trend[] = [
				'period'      => $label,
				'totalTokens' => max( 0, (int) ( $point['totalTokens'] ?? 0 ) ),
				'cachedHits'  => max( 0, (int) ( $point['cacheHits'] ?? 0 ) ),
			];
		}

		$vs_yesterday = $tokens_yesterday > 0
			? round( ( $tokens_today - $tokens_yesterday ) / $tokens_yesterday * 100, 1 )
			: 0.0;

		// The ratio spans the last two calendar days so a quiet morning does not
		// swing it to an extreme.
		$lookups_48h = $hits_today + $misses_today + $hits_yesterday + $misses_yesterday;
		$ratio       = $lookups_48h > 0 ? (int) round( ( $hits_today + $hits_yesterday ) / $lookups_48h * 100 ) : 0;

		return [
			'tokensToday'       => $tokens_today,
			'tokensThisMonth'   => $tokens_month,
			'storedSegments'    => max( 0, (int) ( $response['storedSegments'] ?? 0 ) ),
			'translationItemsThisMonth' => max( 0, (int) ( $response['translationItemsThisMonth'] ?? 0 ) ),
			'tokensVsYesterday' => $vs_yesterday,
			'tokenTrend'        => $trend,
			'cacheHitsToday'    => $hits_today,
			'cacheHitRatio'     => $ratio,
		];
	}

	private static function empty_usage(): array {
		return [
			'tokensToday'       => 0,
			'tokensThisMonth'   => 0,
			'storedSegments'    => 0,
			'translationItemsThisMonth' => 0,
			'tokensVsYesterday' => 0.0,
			'tokenTrend'        => [],
			'cacheHitsToday'    => 0,
			'cacheHitRatio'     => 0,
		];
	}
}
