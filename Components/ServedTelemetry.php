<?php

namespace TranslateWP\Components;

class ServedTelemetry {

	private const MAX_BODY_BYTES = 2048;
	private const MAX_MS         = 180000;
	private const MAX_COUNT      = 100000;
	private const WINDOW_SECONDS = 60;
	private const CLIENT_MAX     = 12;
	private const SITE_MAX       = 120;
	private const FORWARD_TIMEOUT_SECONDS = 2;

	public static function record( $request ) {
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return self::invalid();
		}
		$event = json_decode( $body, true, 4 );
		if ( ! is_array( $event ) || ! self::valid( $event ) ) {
			return self::invalid();
		}

		$site_id = Sites::registered_id();
		if ( '' === $site_id || '' === SiteRequests::bearer_token() ) {
			return new \WP_Error( 'site_not_connected', 'Site is not connected', [ 'status' => 503 ] );
		}
		$client_ip = TranslationProxyClientIp::resolve();
		if ( '' === $client_ip ) {
			return new \WP_Error( 'rest_forbidden', 'Client could not be established', [ 'status' => 403 ] );
		}
		$site_limit = TranslationProxyStorage::increment_rate_limit(
			'served_telemetry_site',
			$site_id,
			'site',
			self::SITE_MAX,
			self::WINDOW_SECONDS
		);
		if ( is_wp_error( $site_limit ) ) {
			return $site_limit;
		}
		$client_limit = TranslationProxyStorage::increment_rate_limit(
			'served_telemetry',
			$site_id,
			$client_ip,
			self::CLIENT_MAX,
			self::WINDOW_SECONDS
		);
		if ( is_wp_error( $client_limit ) ) {
			return $client_limit;
		}

		SiteRequests::post( '/v1/translation/telemetry/served', $event, self::FORWARD_TIMEOUT_SECONDS );
		return new \WP_REST_Response( null, 204 );
	}

	private static function valid( array &$event ): bool {
		$enums = [
			'kind'    => [ 'switch', 'mutation' ],
			'path'    => [ 'ready', 'cold', 'unknown' ],
			'outcome' => [ 'complete', 'partial', 'error', 'timeout', 'cancelled', 'no_source' ],
		];
		$counts = [ 'sourceCount', 'appliedCount', 'failedGroups', 'pendingGroups' ];
		$times  = [ 'firstAppliedMs', 'completedMs', 'elapsedMs' ];
		if ( array_key_exists( 'firstVisibleAppliedMs', $event ) ) {
			$times[] = 'firstVisibleAppliedMs';
		}
		$fields = array_merge( [ 'version', 'sampleRate' ], array_keys( $enums ), $counts, $times );
		if ( count( $event ) !== count( $fields ) || array_diff( array_keys( $event ), $fields ) ) {
			return false;
		}
		if ( 1 !== ( $event['version'] ?? null ) || 0.03125 !== ( $event['sampleRate'] ?? null ) ) {
			return false;
		}
		foreach ( $enums as $key => $allowed ) {
			if ( ! isset( $event[ $key ] ) || ! is_string( $event[ $key ] ) ) {
				return false;
			}
			$event[ $key ] = sanitize_text_field( $event[ $key ] );
			if ( ! in_array( $event[ $key ], $allowed, true ) ) {
				return false;
			}
		}
		foreach ( array_merge( $counts, $times ) as $key ) {
			if ( ! array_key_exists( $key, $event ) ) {
				return false;
			}
			$value = $event[ $key ];
			if ( null === $value && in_array( $key, [ 'firstAppliedMs', 'completedMs', 'firstVisibleAppliedMs' ], true ) ) {
				continue;
			}
			$max = in_array( $key, $times, true ) ? self::MAX_MS : self::MAX_COUNT;
			if ( ! is_int( $value ) || $value < 0 || $value > $max ) {
				return false;
			}
			$event[ $key ] = absint( $value );
		}
		if ( $event['appliedCount'] > $event['sourceCount'] ) {
			return false;
		}
		if ( null !== $event['firstAppliedMs'] && $event['firstAppliedMs'] > $event['elapsedMs'] ) {
			return false;
		}
		if ( isset( $event['firstVisibleAppliedMs'] )
			&& ( null === $event['firstAppliedMs'] || $event['firstVisibleAppliedMs'] < $event['firstAppliedMs']
				|| $event['firstVisibleAppliedMs'] > $event['elapsedMs'] ) ) {
			return false;
		}
		if ( 'complete' === $event['outcome'] ) {
			return $event['completedMs'] === $event['elapsedMs'] && $event['sourceCount'] > 0
				&& $event['appliedCount'] === $event['sourceCount']
				&& 0 === $event['failedGroups'] && 0 === $event['pendingGroups'];
		}
		if ( 'no_source' === $event['outcome'] && ( $event['sourceCount'] || $event['appliedCount'] ) ) {
			return false;
		}
		return null === $event['completedMs'];
	}

	private static function invalid(): \WP_Error {
		return new \WP_Error( 'invalid_telemetry', 'Invalid served observation', [ 'status' => 400 ] );
	}
}
