<?php

namespace TranslateWP\Components;

class Sites {
    private const REGISTER_TIMEOUT_SECONDS = 15;
    private const STATUS_PROBE_PATH = '/v1/site/job-events';

    public static function registered_id(): string {
        $id = Options::get( 'site.id', '' );

        return is_string( $id ) ? $id : '';
    }

	public static function registered_id_for_blog( int $blog_id ): string {
		if ( $blog_id < 1 ) {
			return '';
		}

		if ( ! is_multisite() || get_current_blog_id() === $blog_id ) {
			return self::registered_id();
		}

		if ( null === get_site( $blog_id ) ) {
			return '';
		}

		switch_to_blog( $blog_id );
		try {
			return self::registered_id();
		} finally {
			restore_current_blog();
		}
	}

    /**
     * Register this site with the TranslateWP API.
     *
     * @return string|\WP_Error API-issued Site UUID, or error when registration fails.
     */
    public static function register( int $timeout_seconds = self::REGISTER_TIMEOUT_SECONDS ) {
        $home_url = home_url();
        $host     = (string) wp_parse_url( $home_url, PHP_URL_HOST );

        if ( '' === $host || '' === $home_url ) {
            return new \WP_Error(
                'site_register_failed',
                'Site registration unavailable',
                [ 'status' => 503 ]
            );
        }

        $response = wp_remote_post(
            RemoteApi::endpoint( '/v1/sites/register' ),
            [
                'method'  => 'POST',
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body'    => wp_json_encode(
                    [
						'host'    => $host,
						'homeUrl' => $home_url,
					]
                ),
                'timeout' => $timeout_seconds,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error(
                'site_register_failed',
                'Site registration unavailable',
                [ 'status' => 503 ]
            );
        }

        $status  = (int) wp_remote_retrieve_response_code( $response );
        $payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $id      = is_array( $payload ) ? (string) ( $payload['id'] ?? '' ) : '';

        if ( 201 !== $status || '' === $id ) {
            return new \WP_Error(
                'site_register_failed',
                'Site registration unavailable',
                [ 'status' => 503 ]
            );
        }

        return $id;
    }

    public static function bootstrap_existing_install(): void {
        if ( self::registered_id() !== '' ) {
            return;
        }

        if ( ! Options::get( 'setup.ended', false ) ) {
            return;
        }

        if ( SiteConnection::is_connected() ) {
            return;
        }
    }

    /**
     * Move detector. A changed address is only a signal: it asks the
     * status call to run and records nothing by itself, so a scheme flip, a www
     * toggle, or a trailing-slash difference never changes the connection
     * state, and a transient address never persists a rejection. The canonical
     * comparison keeps a siteurl and home scheme disagreement from flapping it.
     */
    public static function detect_home_url_drift(): void {
        if ( ! self::address_has_changed() ) {
            return;
        }

        SiteConnection::flag_for_reverify();
    }

    public static function registered_address(): string {
        $stored = Options::get( 'site.home_url', '' );

        return is_string( $stored ) ? $stored : '';
    }

    public static function address_has_changed(): bool {
        if ( '' === self::registered_id() ) {
            return false;
        }

        $stored = self::registered_address();
        if ( '' === $stored ) {
            return false;
        }

        return self::canonical_identity( $stored ) !== self::canonical_identity( home_url() );
    }

    public static function address_change_state(): ?array {
        if ( ! self::address_has_changed() ) {
            return null;
        }

        return [
            'from' => self::registered_address(),
            'to'   => home_url(),
        ];
    }

    /**
     * One normalised identity for comparing the registered address with the
     * current one: lowercased host without a leading www, plus the path without
     * a trailing slash. Scheme and trailing slash are ignored so the verdict
     * cannot flap when siteurl and home disagree on scheme.
     */
    public static function canonical_identity( string $url ): string {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return strtolower( trim( $url ) );
        }

        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        if ( 0 === strpos( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }

        $path = untrailingslashit( (string) ( $parts['path'] ?? '' ) );

        return $host . $path;
    }

    public static function status_probe_path(): string {
        $path = apply_filters( 'translatewp_site_status_path', self::STATUS_PROBE_PATH );

        return is_string( $path ) && '' !== $path ? $path : self::STATUS_PROBE_PATH;
    }

    /**
     * Previous-site claim. When the site already holds credentials, the
     * reconnect redirect carries the previous site id and a signature over the
     * previous site id, the current canonical address and a timestamp, made
     * with the held token. The service verifies it against that site's secret
     * and, on owner approval, re-references the same site row; a site it does
     * not recognise for the account falls through to a plain new-site connect.
     * The parameter names live in one place so the contract can be adjusted
     * without touching the connect flow.
     *
     * @return array<string,string> Claim parameters, or an empty array when
     *                               there is no previous site to claim.
     */
    public static function reconnect_claim( string $new_url = '' ): array {
        $site_id = self::registered_id();
        $token   = Options::get( 'site.token', '' );
        $token   = is_string( $token ) ? trim( $token ) : '';
        if ( '' === $site_id || '' === $token ) {
            return [];
        }

        $new_url        = '' !== $new_url ? $new_url : home_url();
        $timestamp      = (string) time();
        $signing_key    = hash( 'sha256', $token );
        $message        = implode( "\n", [ $site_id, self::canonical_claim_url( $new_url ), $timestamp ] );
        $signature      = self::base64url( hash_hmac( 'sha256', $message, $signing_key, true ) );

        $names = self::reconnect_claim_param_names();

        return [
            $names['site_id']   => $site_id,
            $names['url']       => $new_url,
            $names['timestamp'] => $timestamp,
            $names['signature'] => $signature,
        ];
    }

    /**
     * Canonical address the service signs, per the connect contract: the host
     * with a leading www removed and the port kept, then the path and query
     * with trailing slashes stripped and the scheme dropped. The plugin sends
     * the full new home URL in the claim; the service canonicalises it the same
     * way to check the signature.
     */
    public static function canonical_claim_url( string $url ): string {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return strtolower( trim( $url ) );
        }

        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        if ( 0 === strpos( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }
        if ( isset( $parts['port'] ) && '' !== (string) $parts['port'] ) {
            $host .= ':' . (int) $parts['port'];
        }

        $path  = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
        $query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

        return $host . $path . $query;
    }

    private static function base64url( string $binary ): string {
        return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' );
    }

    /**
     * The answer to a reconnect challenge from the service. The
     * nonce and this site's id are signed with the same key the reconnect
     * claim uses. Null when the site is not connected or the id is not
     * this site's.
     */
    public static function challenge_answer( string $site_id, string $nonce ): ?string {
        $own_id = self::registered_id();
        $token  = (string) Options::get( 'site.token', '' );
        if ( '' === $own_id || '' === $token || ! hash_equals( $own_id, $site_id ) ) {
            return null;
        }

        $signing_key = hash( 'sha256', $token );
        $message     = implode( "\n", [ 'challenge', $site_id, $nonce ] );

        return self::base64url( hash_hmac( 'sha256', $message, $signing_key, true ) );
    }

    public static function reconnect_claim_param_names(): array {
        $defaults = [
            'site_id'   => 'reconnect_site',
            'url'       => 'reconnect_url',
            'timestamp' => 'reconnect_ts',
            'signature' => 'reconnect_sig',
        ];

        $names = apply_filters( 'translatewp_reconnect_claim_params', $defaults );
        if ( ! is_array( $names ) ) {
            return $defaults;
        }

        foreach ( $defaults as $key => $default ) {
            if ( ! isset( $names[ $key ] ) || ! is_string( $names[ $key ] ) || '' === $names[ $key ] ) {
                $names[ $key ] = $default;
            }
        }

        return $names;
    }

    public static function metering_headers(): array {
        $home_url = home_url();
        $headers  = [
            'X-TranslateWP-Host'     => (string) wp_parse_url( $home_url, PHP_URL_HOST ),
            'X-TranslateWP-Home-Url' => $home_url,
        ];

        $site_id = self::registered_id();
        if ( '' !== $site_id ) {
            $headers['X-TranslateWP-Site-Id'] = $site_id;
        }

        return $headers;
    }
}
