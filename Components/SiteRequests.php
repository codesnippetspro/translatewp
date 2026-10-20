<?php

namespace TranslateWP\Components;

class SiteRequests {

	private const DEFAULT_TIMEOUT_SECONDS = 15;
	private const MAX_JOB_EVENTS_LIMIT    = 500;

	public static function bearer_token(): string {
		if ( 'invalidated' === SiteConnection::connection_status() ) {
			return '';
		}

		$token = Options::get( 'site.token', '' );
		$token = is_string( $token ) ? trim( $token ) : '';
		$token = apply_filters( 'translatewp_site_bearer_token', $token );

		return is_string( $token ) ? trim( $token ) : '';
	}

	public static function auth_headers(): array {
		return self::auth_headers_for_token( self::bearer_token() );
	}

	private static function auth_headers_for_token( string $token ): array {
		if ( '' === $token ) {
			return [];
		}

		$headers = [
			'Authorization'         => 'Bearer ' . $token,
			'X-TranslateWP-Version' => defined( 'TRANSLATEWP_VERSION' ) ? (string) TRANSLATEWP_VERSION : '',
		];

		$filtered = apply_filters( 'translatewp_site_request_headers', $headers );

		return is_array( $filtered ) ? $filtered : $headers;
	}

	/**
	 * Ask the service whether the stored credentials are still accepted,
	 * signed with the token the site already holds. This bypasses the
	 * invalidated gate on bearer_token() so a wrongly stored rejection can be
	 * repaired, and it never changes connection state itself: it returns the
	 * verdict for SiteConnection::verify_connection() to act on. A transport
	 * error, a timeout, a maintenance page, or any non-auth status is reported
	 * as inconclusive so the verdict is left untouched.
	 *
	 * @return array{verdict:string,registered_url:string} The verdict is one of
	 *               'connected', 'revoked', or 'inconclusive'; registered_url is
	 *               the address the service holds for the site, when it reports
	 *               one, for a canonical comparison against the current address.
	 */
	public static function probe_connection(): array {
		$token = Options::get( 'site.token', '' );
		$token = is_string( $token ) ? trim( $token ) : '';
		if ( '' === $token || '' === Sites::registered_id() ) {
			return [
				'verdict'        => 'inconclusive',
				'registered_url' => '',
			];
		}

		$url      = add_query_arg( [ 'limit' => 1 ], RemoteApi::endpoint( Sites::status_probe_path() ) );
		$response = wp_remote_get(
			$url,
			[
				'timeout' => self::DEFAULT_TIMEOUT_SECONDS,
				'headers' => array_merge(
					[ 'Accept' => 'application/json' ],
					Sites::metering_headers(),
					self::auth_headers_for_token( $token )
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'verdict'        => 'inconclusive',
				'registered_url' => '',
				'detail'         => is_wp_error( $response ) ? $response->get_error_message() : 'no response',
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code ) {
			return [
				'verdict'        => 'revoked',
				'registered_url' => '',
				'detail'         => 'http 401',
			];
		}
		if ( $code < 200 || $code >= 300 ) {
			return [
				'verdict'        => 'inconclusive',
				'registered_url' => '',
				'detail'         => 'http ' . $code,
			];
		}

		$payload        = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$payload        = is_array( $payload ) ? $payload : [];
		$registered_url = isset( $payload['registeredUrl'] ) && is_string( $payload['registeredUrl'] )
			? $payload['registeredUrl']
			: '';

		return [
			'verdict'        => 'connected',
			'registered_url' => $registered_url,
		];
	}

	public static function get( string $path, array $query = [], int $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS ) {
		if ( '' === self::bearer_token() ) {
			return new \WP_Error(
				'site_not_connected',
				'Site is not connected',
				[ 'status' => 503 ]
			);
		}

		$url = RemoteApi::endpoint( $path );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout' => $timeout_seconds,
				'headers' => array_merge(
					[
						'Accept' => 'application/json',
					],
					self::outbound_headers()
				),
			]
		);

		return self::decode_response( $response, $path );
	}

	public static function post( string $path, array $body, int $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS ) {
		if ( '' === self::bearer_token() ) {
			return new \WP_Error(
				'site_not_connected',
				'Site is not connected',
				[ 'status' => 503 ]
			);
		}

		$response = wp_remote_post(
			RemoteApi::endpoint( $path ),
			[
				'method'  => 'POST',
				'timeout' => $timeout_seconds,
				'headers' => array_merge(
					[
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					],
					self::outbound_headers()
				),
				'body'    => wp_json_encode( $body ),
			]
		);

		return self::decode_response( $response, $path );
	}

	public static function put( string $path, array $body, int $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS ) {
		if ( '' === self::bearer_token() ) {
			return new \WP_Error(
				'site_not_connected',
				'Site is not connected',
				[ 'status' => 503 ]
			);
		}

		$response = wp_remote_request(
			RemoteApi::endpoint( $path ),
			[
				'method'  => 'PUT',
				'timeout' => $timeout_seconds,
				'headers' => array_merge(
					[
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					],
					self::outbound_headers()
				),
				'body'    => wp_json_encode( $body ),
			]
		);

		return self::decode_response( $response, $path );
	}

	public static function delete( string $path, int $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS ) {
		if ( '' === self::bearer_token() ) {
			return new \WP_Error(
				'site_not_connected',
				'Site is not connected',
				[ 'status' => 503 ]
			);
		}

		$response = wp_remote_request(
			RemoteApi::endpoint( $path ),
			[
				'method'  => 'DELETE',
				'timeout' => $timeout_seconds,
				'headers' => array_merge(
					[ 'Accept' => 'application/json' ],
					self::outbound_headers()
				),
			]
		);

		return self::decode_response( $response, $path );
	}

	public static function sanitize_job_events_limit( $raw_limit ): int {
		$limit = (int) $raw_limit;
		if ( $limit < 1 ) {
			return 100;
		}

		return min( self::MAX_JOB_EVENTS_LIMIT, $limit );
	}

	public static function sanitize_job_events_before( $raw_before ): string {
		$before = sanitize_text_field( (string) $raw_before );
		if ( '' === $before ) {
			return '';
		}

		$timestamp = strtotime( $before );
		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'c', $timestamp );
	}

	public static function sanitize_job_id( string $raw ): string {
		$job_id = sanitize_text_field( trim( $raw ) );

		if ( 1 !== preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$job_id
		) ) {
			return '';
		}

		return $job_id;
	}

	private static function outbound_headers(): array {
		return array_merge( Sites::metering_headers(), self::auth_headers() );
	}

	private static function decode_response( $response, string $path = '' ) {
		if ( is_wp_error( $response ) ) {
			ActionLog::record( sprintf( 'Service request %s failed: %s', $path, $response->get_error_code() ), 'error' );
			// A transport failure got no answer from the service; the marker lets
			// callers tell it apart from a gateway error that shares the 502.
			return new \WP_Error(
				'remote_error',
				$response->get_error_message(),
				[ 'status' => 502, 'transport' => true ]
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$payload     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$payload     = is_array( $payload ) ? $payload : [];

		if ( 401 === $status_code ) {
			// A single rejected call is only a signal. A background,
			// visitor, or upgrade-time call can draw a 401 for one request
			// without the credentials being revoked, so arm a re-check rather
			// than change state here. The moved/rejected verdict is set only by
			// the dedicated status call in SiteConnection::verify_connection().
			SiteConnection::flag_for_reverify();
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = (string) ( $payload['error'] ?? 'Remote request failed' );
			ActionLog::record( sprintf( 'Service answered %d to %s: %s', $status_code, $path, preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) ( $payload['code'] ?? $payload['error'] ?? '' ) ) ) ), $status_code >= 500 ? 'error' : 'warning' );
			return new \WP_Error(
				'remote_error',
				$message,
				[
					'status'  => $status_code ? $status_code : 502,
					'payload' => $payload,
				]
			);
		}

		return $payload;
	}
}
