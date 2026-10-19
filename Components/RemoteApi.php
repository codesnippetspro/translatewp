<?php

namespace TranslateWP\Components;

class RemoteApi {

	private const DEFAULT_BASE_URL     = 'https://api.translatewp.ai';
	private const DEFAULT_APP_BASE_URL = 'https://app.translatewp.ai';

	private static bool $request_filter_registered = false;

	public static function portal_base_url(): string {
		$constant_base = self::constant_value( 'TRANSLATEWP_APP_BASE_URL' );
		$env_base      = self::environment_value( 'TRANSLATEWP_APP_BASE_URL' );
		$base          = '' !== $constant_base ? $constant_base : ( '' !== $env_base ? $env_base : self::DEFAULT_APP_BASE_URL );
		$filtered      = apply_filters( 'translatewp_app_base_url', $base );

		return rtrim( (string) $filtered, '/' );
	}

	public static function post_json( string $path, array $body, int $timeout_seconds = 15 ) {
		self::register_request_filter();

		$response = wp_remote_post(
			self::endpoint( $path ),
			[
				'method'  => 'POST',
				'timeout' => $timeout_seconds,
				'headers' => array_merge(
					[
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					],
					self::request_headers()
				),
				'body'    => wp_json_encode( $body ),
			]
		);

		return self::decode_json_response( $response );
	}

	private static function decode_json_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'remote_error',
				'Remote request failed',
				[ 'status' => 502 ]
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$payload     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$payload     = is_array( $payload ) ? $payload : [];
		$error_code  = self::connect_exchange_error_code( $status_code, $payload );

		if ( $status_code >= 200 && $status_code < 300 ) {
			return $payload;
		}

		if ( in_array( $error_code, self::connect_exchange_error_codes(), true ) ) {
			return new \WP_Error(
				$error_code,
				'Connect exchange failed',
				[ 'status' => $status_code ? $status_code : 502 ]
			);
		}

		return new \WP_Error(
			'remote_error',
			'Remote request failed',
			[ 'status' => $status_code ? $status_code : 502 ]
		);
	}

	private static function connect_exchange_error_codes(): array {
		return [
			'validation_error',
			'invalid_connect_code',
			'site_conflict',
			'address_taken',
			'rate_limited',
			'connect_unavailable',
		];
	}

	private static function connect_exchange_error_code( int $status_code, array $payload ): string {
		$payload_code = isset( $payload['code'] ) ? sanitize_key( (string) $payload['code'] ) : '';
		if ( in_array( $payload_code, self::connect_exchange_error_codes(), true ) ) {
			return $payload_code;
		}

		switch ( $status_code ) {
			case 400:
				return 'validation_error';
			case 401:
				return 'invalid_connect_code';
			case 409:
				return 'site_conflict';
			case 429:
				return 'rate_limited';
			case 503:
				return 'connect_unavailable';
			default:
				return 'remote_error';
		}
	}

	public static function base_url(): string {
		$constant_base = self::constant_value( 'TRANSLATEWP_API_BASE_URL' );
		$env_base      = self::environment_value( 'TRANSLATEWP_API_BASE_URL' );
		$base          = '' !== $constant_base ? $constant_base : ( '' !== $env_base ? $env_base : self::DEFAULT_BASE_URL );
		$filtered      = apply_filters( 'translatewp_api_base_url', $base );

		return rtrim( (string) $filtered, '/' );
	}

	public static function endpoint( string $path ): string {
		self::register_request_filter();

		return self::base_url() . '/' . ltrim( $path, '/' );
	}

	public static function add_request_headers( array $args, string $url ): array {
		$base_url = self::base_url();
		if ( $url !== $base_url && 0 !== strpos( $url, $base_url . '/' ) ) {
			return $args;
		}

		$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : [];
		$args['headers'] = array_merge( $headers, self::request_headers() );

		return $args;
	}

	private static function register_request_filter(): void {
		if ( self::$request_filter_registered ) {
			return;
		}

		add_filter( 'http_request_args', [ self::class, 'add_request_headers' ], 10, 2 );
		self::$request_filter_registered = true;
	}

	private static function request_headers(): array {
		$headers = [];
		if ( defined( 'TRANSLATEWP_API_REQUEST_HEADERS' ) ) {
			$encoded = constant( 'TRANSLATEWP_API_REQUEST_HEADERS' );
			if ( ! is_string( $encoded ) ) {
				throw new \RuntimeException( 'TRANSLATEWP_API_REQUEST_HEADERS must be a JSON object.' );
			}

			if ( '' !== trim( $encoded ) ) {
				$decoded = json_decode( $encoded, true );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
					throw new \RuntimeException( 'TRANSLATEWP_API_REQUEST_HEADERS must be a JSON object.' );
				}
				$headers = $decoded;
			}
		}

		$headers = apply_filters( 'translatewp_api_request_headers', $headers );
		if ( ! is_array( $headers ) ) {
			throw new \RuntimeException( 'API request headers must be an array.' );
		}

		$validated = [];
		foreach ( $headers as $name => $value ) {
			if (
				! is_string( $name )
				|| 1 !== preg_match( '/^[A-Za-z0-9!#$%&*+.^_`|~-]+$/', $name )
				|| ! is_string( $value )
				|| false !== strpos( $value, "\r" )
				|| false !== strpos( $value, "\n" )
			) {
				throw new \RuntimeException( 'API request headers contain an invalid name or value.' );
			}

			$validated[ $name ] = $value;
		}

		return $validated;
	}

	private static function constant_value( string $name ): string {
		return defined( $name ) ? trim( (string) constant( $name ) ) : '';
	}

	private static function environment_value( string $name ): string {
		$value = getenv( $name );

		return false !== $value ? trim( (string) $value ) : '';
	}
}
