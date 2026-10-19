<?php

namespace TranslateWP\Components;

class SiteConnection {

	public const RETURN_ROUTE_PATH       = 'translatewp-connect/return';
	public const NETWORK_RETURN_ROUTE_PATH = 'translatewp-connect/network-return';
	private const RETURN_URL_PATH        = 'translatewp-connect/return/';
	private const NETWORK_RETURN_URL_PATH = 'translatewp-connect/network-return/';
	private const STATE_PATTERN          = '/^[A-Za-z0-9_-]{43}$/';
	private const STATE_BYTES            = 32;
	private const STATE_TTL_SECONDS      = 900;
	private const STATE_TRANSIENT_PREFIX = 'translatewp_connect_state_';
	public const NOTICE_QUERY_ARG        = 'translatewp_connect_notice';
	private const MAX_TOKEN_LENGTH       = 512;
	private const SITE_OPTION            = 'site';
	private const MAX_NETWORK_SUBSITES   = 25;
	private const MAX_SUBSITES_PARAM_LENGTH = 2000;
	private const NETWORK_CONNECT_OUTCOME_PREFIX = 'translatewp_network_connect_outcome_';
	private const NETWORK_CONNECT_OUTCOME_TTL    = 600;
	private const NETWORK_SETTINGS_PAGE  = 'translatewp-network-settings';

	public static function is_connected(): bool {
		if ( 'invalidated' === self::connection_status() ) {
			// A stored rejection is re-checked against the service
			// (throttled) so a wrongly stored state repairs itself on any read.
			return self::has_credentials() && 'connected' === self::verify_connection();
		}

		return self::has_credentials();
	}

	public static function has_credentials(): bool {
		$site = get_option( 'translatewp_' . self::SITE_OPTION, [] );
		if ( ! is_array( $site ) ) {
			return false;
		}

		$site_id = trim( (string) ( $site['id'] ?? '' ) );
		$token   = trim( (string) ( $site['token'] ?? '' ) );

		return '' !== $site_id && '' !== $token;
	}

	public static function connection_status(): string {
		$status = Options::get( 'site.connection_status', 'disconnected' );

		return in_array( $status, [ 'connected', 'invalidated', 'disconnected' ], true ) ? $status : 'disconnected';
	}

	public static function invalidate_connection(): void {
		if ( 'invalidated' !== self::connection_status() ) {
			ActionLog::record( 'Site credentials were invalidated by the service', 'error' );
		}
		Options::update( 'site.connection_status', 'invalidated' );
		ProEligibility::clear_cache();
		ProEligibility::clear_last_known();
	}

	public static function mark_connected(): void {
		if ( 'connected' !== self::connection_status() ) {
			ActionLog::record( 'Site connected to the account' );
		}
		Options::update( 'site.connection_status', 'connected' );
	}

	public static function mark_disconnected(): void {
		if ( 'disconnected' !== self::connection_status() ) {
			ActionLog::record( 'Site disconnected from the account', 'warning' );
		}
		Options::update( 'site.connection_status', 'disconnected' );
	}

	private const REVERIFY_TRANSIENT          = 'translatewp_connection_reverify';
	private const REVERIFY_TTL_SECONDS        = 900;
	private const VERIFY_THROTTLE_TRANSIENT   = 'translatewp_connection_verify_throttle';
	private const VERIFY_THROTTLE_TTL_SECONDS = 300;
	private const VERIFY_RETRY_TTL_SECONDS    = 60;
	private const LAST_PROBE_OPTION           = 'translatewp_last_connection_probe';

	/**
	 * Record that a recent call drew an unexpected rejection or that the stored
	 * address no longer matches the current one. This only asks the status call
	 * to run; it never changes the stored connection state by itself.
	 */
	public static function flag_for_reverify(): void {
		set_transient( self::REVERIFY_TRANSIENT, 1, self::REVERIFY_TTL_SECONDS );
	}

	public static function needs_reverify(): bool {
		return false !== get_transient( self::REVERIFY_TRANSIENT );
	}

	private static function clear_reverify(): void {
		delete_transient( self::REVERIFY_TRANSIENT );
	}

	/**
	 * The single authority on the moved/rejected verdict. It asks the
	 * service whether the stored credentials are still accepted and maps only
	 * that answer to state: an explicit unknown/revoked answer invalidates, an
	 * acceptance repairs a wrongly stored state and reconciles a changed
	 * address on the same site, and anything inconclusive (transport error,
	 * timeout, maintenance page, any non-auth status) leaves the connection
	 * exactly as it was. A plugin update never reaches this method on its own.
	 */
	/**
	 * The rejected-state notice offers "Reconnect this site" and
	 * "Connect as a new site" as plain links, so the admin always has a way
	 * out even when the admin app is not rendered. Both start the normal
	 * connect flow; only the reconnect carries the previous-site claim.
	 */
	public static function handle_connect_link(): void {
		$intent = isset( $_GET['translatewp_connect'] ) ? sanitize_key( wp_unslash( $_GET['translatewp_connect'] ) ) : '';
		if ( ! in_array( $intent, [ 'reconnect', 'new' ], true ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'translatewp_connect' ) ) {
			return;
		}

		$begin = self::begin_connect( 'reconnect' === $intent );
		if ( empty( $begin['ok'] ) || empty( $begin['redirect_url'] ) ) {
			return;
		}

		wp_redirect( (string) $begin['redirect_url'] );
		exit;
	}

	public static function verify_connection(): string {
		$current = self::connection_status();

		if ( '' === Sites::registered_id() || '' === self::stored_token() ) {
			self::clear_reverify();
			return $current;
		}

		if ( 'invalidated' !== $current && ! self::needs_reverify() ) {
			return $current;
		}

		if ( false !== get_transient( self::VERIFY_THROTTLE_TRANSIENT ) ) {
			return $current;
		}
		set_transient( self::VERIFY_THROTTLE_TRANSIENT, 1, self::VERIFY_THROTTLE_TTL_SECONDS );

		$probe = SiteRequests::probe_connection();
		$last_probe = [
			'verdict'   => (string) ( $probe['verdict'] ?? '' ),
			'detail'    => (string) ( $probe['detail'] ?? '' ),
			'checkedAt' => time(),
		];
		update_option( self::LAST_PROBE_OPTION, $last_probe, false );

		if ( 'inconclusive' === $probe['verdict'] ) {
			// A transport failure or a maintenance page proves nothing; retry
			// soon instead of holding the stored state for the full window.
			set_transient( self::VERIFY_THROTTLE_TRANSIENT, 1, self::VERIFY_RETRY_TTL_SECONDS );
			return $current;
		}

		self::clear_reverify();

		if ( 'revoked' === $probe['verdict'] ) {
			if ( 'invalidated' !== $current ) {
				self::invalidate_connection();
			}
			return 'invalidated';
		}

		if ( 'connected' !== $current ) {
			self::mark_connected();
		}

		// Keep the registered address the service reports as the single value to
		// compare the current address against, so the address-changed state is
		// judged from the service's record, not a local guess.
		$registered_url = (string) $probe['registered_url'];
		$stored_url     = (string) Options::get( 'site.home_url', '' );
		if ( '' !== $registered_url && $registered_url !== $stored_url ) {
			Options::update( 'site.home_url', $registered_url );
		}

		return 'connected';
	}

	private static function stored_token(): string {
		$token = Options::get( 'site.token', '' );

		return is_string( $token ) ? trim( $token ) : '';
	}

	private const PAIRING_VERIFIED_TRANSIENT = 'translatewp_pairing_verified';
	private const PAIRING_VERIFIED_TTL_SECONDS = 600;

	/**
	 * The onboarding gate derives from the real
	 * pairing state, never the local setup.ended flag alone. A reinstall or
	 * update that lost the flag must not re-force the wizard on a site whose
	 * pairing is still approved server-side.
	 */
	public static function needs_onboarding(): bool {
		// A site with no default language has not been set up, whatever its
		// setup flag says: the wizard runs.
		if ( ! self::has_default_language() ) {
			return true;
		}

		if ( Options::get( 'setup.ended', false ) ) {
			return false;
		}

		// A deliberate restart from the Settings action re-opens
		// the wizard on a still-connected site; the heal branches below must
		// never defeat it.
		if ( Options::get( 'setup.restarted', false ) ) {
			return true;
		}

		if ( ! self::is_connected() ) {
			return true;
		}

		if ( 'invalidated' === self::connection_status() ) {
			return true;
		}

		$verified = get_transient( self::PAIRING_VERIFIED_TRANSIENT );
		if ( false !== $verified ) {
			return self::needs_reading_wizard();
		}

		$response = SiteRequests::get( '/v1/site/job-events', [ 'limit' => 1 ] );
		if ( is_wp_error( $response ) ) {
			$status = (int) ( $response->get_error_data()['status'] ?? 0 );
			if ( 401 === $status ) {
				return true;
			}
			// Inconclusive (API down): fail open so an operator is never
			// locked out of their admin.
			return false;
		}

		set_transient( self::PAIRING_VERIFIED_TRANSIENT, 1, self::PAIRING_VERIFIED_TTL_SECONDS );
		return self::needs_reading_wizard();
	}

	public static function has_default_language(): bool {
		$languages = Options::get( 'languages.list', [] );
		if ( ! is_array( $languages ) ) {
			return false;
		}
		foreach ( $languages as $language ) {
			if ( is_array( $language ) && ! empty( $language['default'] ) && ! empty( $language['locale'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A connected, pairing-valid site that has not finished setup resumes at
	 * the reading step when its content was never read, and otherwise lands on
	 * the Overview, where a Finish setting up banner leads back to the
	 * remaining steps. A finished site passes straight through. A pairing is
	 * never treated as a finished onboarding for an unread site.
	 */
	private static function needs_reading_wizard(): bool {
		if ( Options::get( 'setup.ended', false ) ) {
			return false;
		}

		if ( ( new IndexQueue() )->read_counts()['pages'] > 0 ) {
			return false;
		}

		Options::update( 'setup.step', 'read-site' );

		return true;
	}

	/**
	 * A connected site that has pages read but has not finished setup: it
	 * lands on the Overview with a Finish setting up banner instead of a silent
	 * screen of zeros. A never-read site resumes the wizard instead and is not
	 * a banner case.
	 */
	public static function setup_unfinished(): bool {
		if ( ! self::is_connected() || 'invalidated' === self::connection_status() ) {
			return false;
		}

		if ( Options::get( 'setup.ended', false ) ) {
			return false;
		}

		return ( new IndexQueue() )->read_counts()['pages'] > 0;
	}

	public static function is_connected_for_blog( int $blog_id ): bool {
		if ( $blog_id < 1 || null === get_site( $blog_id ) ) {
			return false;
		}

		if ( get_current_blog_id() === $blog_id ) {
			return self::is_connected();
		}

		switch_to_blog( $blog_id );
		try {
			return self::is_connected();
		} finally {
			restore_current_blog();
		}
	}

	public static function return_url(): string {
		return home_url( '/' . ltrim( self::RETURN_URL_PATH, '/' ) );
	}

	public static function network_return_url(): string {
		return home_url( '/' . ltrim( self::NETWORK_RETURN_URL_PATH, '/' ) );
	}

	public static function return_request_path_from_url( string $return_url ): string {
		$path = wp_parse_url( $return_url, PHP_URL_PATH );

		return is_string( $path ) ? trim( $path, '/' ) : '';
	}

	public static function advertised_return_path_from_url( string $return_url ): string {
		$path = wp_parse_url( $return_url, PHP_URL_PATH );

		return is_string( $path ) ? self::normalize_route_path( $path ) : '';
	}

	public static function connect_host_from_return_url( string $return_url ): string {
		$parsed = wp_parse_url( $return_url );
		$host   = is_array( $parsed ) ? (string) ( $parsed['host'] ?? '' ) : '';
		if ( '' === $host ) {
			return '';
		}

		if ( ! isset( $parsed['port'] ) ) {
			return $host;
		}

		$port   = (int) $parsed['port'];
		$scheme = (string) ( $parsed['scheme'] ?? 'https' );
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			return $host;
		}

		return $host . ':' . $port;
	}

	/**
	 * The address a subsite is known by in a network connect: its host, plus
	 * its path for a subdirectory network, so every subsite has its own key.
	 */
	public static function subsite_address_from_url( string $home_url ): string {
		$host = self::connect_host_from_return_url( $home_url );
		if ( '' === $host ) {
			return '';
		}

		$path = rtrim( (string) ( wp_parse_url( $home_url, PHP_URL_PATH ) ?? '' ), '/' );

		return $host . $path;
	}

	public static function connect_host(): string {
		return self::connect_host_from_return_url( self::return_url() );
	}

	public static function portal_connect_url( string $state, string $return_url = '' ): string {
		$host = self::connect_host_from_return_url( '' !== $return_url ? $return_url : self::return_url() );
		if ( '' === $host ) {
			return '';
		}

		return add_query_arg(
			[
				'host'   => $host,
				'return' => '' !== $return_url ? $return_url : self::return_url(),
				'state'  => $state,
			],
			RemoteApi::portal_base_url() . '/connect'
		);
	}

	public static function portal_sites_url(): string {
		return RemoteApi::portal_base_url() . '/sites';
	}

	public static function network_subsites(): array {
		if ( ! is_multisite() ) {
			return [];
		}

		$current_blog_id = get_current_blog_id();
		$subsites        = [];
		foreach ( get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		) as $site_id ) {
			$site_id = absint( $site_id );
			if ( $site_id === $current_blog_id ) {
				continue;
			}

			$home_url = self::blog_home_url( $site_id );
			$host     = self::subsite_address_from_url( $home_url );
			if ( '' === $host ) {
				continue;
			}

			$subsites[] = [
				'blog_id' => $site_id,
				'host'    => $host,
				'homeUrl' => $home_url,
			];
		}

		return $subsites;
	}

	/**
	 * One claim per connected subsite for a network reconnect, each
	 * signed with that subsite's own credentials over its address under the
	 * network's scheme (the address the portal rebuilds from the host). A
	 * subsite without credentials has no claim and connects as a new site.
	 *
	 * @return array<int,array{host:string,site:string,ts:int,sig:string}>
	 */
	public static function network_subsite_claims( array $subsites ): array {
		$names  = Sites::reconnect_claim_param_names();
		$scheme = (string) wp_parse_url( self::network_return_url(), PHP_URL_SCHEME );
		$scheme = '' !== $scheme ? $scheme : 'https';
		$claims = [];
		foreach ( $subsites as $subsite ) {
			switch_to_blog( (int) $subsite['blog_id'] );
			try {
				$claim = Sites::reconnect_claim( $scheme . '://' . (string) $subsite['host'] );
			} finally {
				restore_current_blog();
			}
			if ( empty( $claim ) ) {
				continue;
			}

			$claims[] = [
				'host' => (string) $subsite['host'],
				'site' => (string) $claim[ $names['site_id'] ],
				'ts'   => (int) $claim[ $names['timestamp'] ],
				'sig'  => (string) $claim[ $names['signature'] ],
			];
		}

		return $claims;
	}

	public static function network_connect_url( string $state, array $only_hosts = [] ): string {
		$subsites = self::network_subsites();
		if ( ! empty( $only_hosts ) ) {
			$subsites = array_values(
				array_filter(
					$subsites,
					static function ( array $subsite ) use ( $only_hosts ): bool {
						return in_array( $subsite['host'], $only_hosts, true );
					}
				)
			);
		}

		$hosts = array_map(
			static function ( array $subsite ): string {
				return $subsite['host'];
			},
			$subsites
		);

		$url = self::portal_connect_url( $state, self::network_return_url() );
		if ( '' === $url || empty( $hosts ) ) {
			return $url;
		}

		$subsites_param = implode( ',', $hosts );
		if ( count( $hosts ) > self::MAX_NETWORK_SUBSITES || strlen( $subsites_param ) > self::MAX_SUBSITES_PARAM_LENGTH ) {
			return '';
		}

		return add_query_arg( 'subsites', $subsites_param, $url );
	}

	public static function begin_network_connect( array $only_hosts = [], bool $with_claims = false ): array {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
			return [
				'ok'    => false,
				'error' => 'permission_denied',
			];
		}

		$subsites = self::network_subsites();
		if ( ! empty( $only_hosts ) ) {
			$subsites = array_values(
				array_filter(
					$subsites,
					static function ( array $subsite ) use ( $only_hosts ): bool {
						return in_array( $subsite['host'], $only_hosts, true );
					}
				)
			);
		}

		$hosts = array_map(
			static function ( array $subsite ): string {
				return $subsite['host'];
			},
			$subsites
		);

		if ( count( $hosts ) > self::MAX_NETWORK_SUBSITES || strlen( implode( ',', $hosts ) ) > self::MAX_SUBSITES_PARAM_LENGTH ) {
			return [
				'ok'    => false,
				'error' => 'network_too_large',
			];
		}

		$state = self::generate_state(
			empty( $only_hosts ) ? [] : [ 'only_hosts' => array_values( $only_hosts ) ]
		);
		if ( '' === $state ) {
			return [
				'ok'    => false,
				'error' => 'state_unavailable',
			];
		}

		$redirect_url = self::network_connect_url( $state, $only_hosts );
		if ( '' === $redirect_url ) {
			self::forget_state( $state );

			return [
				'ok'    => false,
				'error' => 'host_unavailable',
			];
		}

		// The network reconnect carries the root's claim and one claim
		// per connected subsite so one approval settles them all; a plain
		// network connect never claims previous sites.
		if ( $with_claims ) {
			$root_claim = Sites::reconnect_claim();
			if ( ! empty( $root_claim ) ) {
				$redirect_url = add_query_arg( $root_claim, $redirect_url );
			}

			// The hosts list is already capped (MAX_NETWORK_SUBSITES entries,
			// MAX_SUBSITES_PARAM_LENGTH bytes) and each claim adds a fixed
			// site id, timestamp and signature, so the claim set stays under
			// the portal's 6000-byte bound for every network the plain
			// connect accepts. Appended encoded by hand: the JSON carries
			// quotes and braces that a redirect sanitiser strips when raw.
			$claims = self::network_subsite_claims( $subsites );
			if ( ! empty( $claims ) ) {
				$encoded       = (string) wp_json_encode( $claims );
				$redirect_url .= ( false === strpos( $redirect_url, '?' ) ? '?' : '&' ) . 'subsite_claims=' . rawurlencode( $encoded );
			}
		}

		return [
			'ok'           => true,
			'redirect_url' => $redirect_url,
		];
	}

	public static function handle_network_return(): void {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
			self::redirect_with_notice( 'permission_denied' );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! self::is_valid_state_format( $state ) ) {
			self::redirect_with_notice( 'invalid_request' );
		}

		$state_payload = self::consume_state( $state );
		if ( ! is_array( $state_payload ) ) {
			self::redirect_with_notice( 'invalid_state' );
		}

		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		if ( 'access_denied' === $error ) {
			self::redirect_with_notice( 'access_denied' );
		}

		$raw_codes     = isset( $_GET['codes'] ) ? sanitize_text_field( wp_unslash( $_GET['codes'] ) ) : '';
		$subsite_codes = self::parse_subsite_codes( $raw_codes );
		if ( '' !== $raw_codes && null === $subsite_codes ) {
			self::redirect_with_notice( 'invalid_request' );
		}
		$subsite_codes = is_array( $subsite_codes ) ? $subsite_codes : [];

		$only_hosts = (array) ( $state_payload['only_hosts'] ?? [] );
		$is_retry   = ! empty( $only_hosts );

		$results = [];

		if ( ! $is_retry ) {
			$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
			if ( '' === $code ) {
				self::redirect_with_notice( 'invalid_request' );
			}

			$results[] = [
				'host'        => self::connect_host(),
				'blog_id'     => get_current_blog_id(),
				'ok'          => false,
				'reconnected' => false,
				'error'       => '',
			];

			$primary = self::exchange_code( $code, $state );
			if ( is_wp_error( $primary ) ) {
				$results[0]['error'] = $primary->get_error_code();
			} else {
				$payload = is_array( $primary ) ? $primary : [];
				$site_id = isset( $payload['siteId'] ) ? (string) $payload['siteId'] : '';
				$token   = isset( $payload['token'] ) ? (string) $payload['token'] : '';
				if ( self::is_uuid( $site_id ) && self::is_valid_token( $token ) ) {
					$previous_site_id = Sites::registered_id();
					self::complete_connection( $site_id, $token, (string) ( $payload['accountLabel'] ?? '' ) );
					self::resume_setup_when_unread( $previous_site_id, $site_id );
					$results[0]['ok']          = true;
					$results[0]['reconnected'] = ! empty( $payload['reusedSiteId'] );
				} else {
					$results[0]['error'] = 'connect_unavailable';
				}
			}
		}

		$code_by_host = [];
		foreach ( $subsite_codes as $entry ) {
			$code_by_host[ (string) $entry['host'] ] = (string) $entry['code'];
		}

		foreach ( self::network_subsites() as $subsite ) {
			$host = (string) $subsite['host'];
			if ( $is_retry && ! in_array( $host, $only_hosts, true ) ) {
				continue;
			}

			if ( ! isset( $code_by_host[ $host ] ) ) {
				$results[] = [
					'host'    => $host,
					'blog_id' => $subsite['blog_id'],
					'ok'      => false,
					'error'   => 'no_code',
				];
				continue;
			}

			$result = [
				'host'        => $host,
				'blog_id'     => $subsite['blog_id'],
				'ok'          => false,
				'reconnected' => false,
				'error'       => '',
			];

			$exchanged = self::exchange_code_for_blog( $code_by_host[ $host ], $state, $subsite );
			if ( is_wp_error( $exchanged ) ) {
				$result['error'] = $exchanged->get_error_code();
			} else {
				$payload = is_array( $exchanged ) ? $exchanged : [];
				$site_id = isset( $payload['siteId'] ) ? (string) $payload['siteId'] : '';
				$token   = isset( $payload['token'] ) ? (string) $payload['token'] : '';
				if ( self::is_uuid( $site_id ) && self::is_valid_token( $token ) ) {
					self::complete_connection_for_blog(
						$subsite['blog_id'],
						$site_id,
						$token,
						(string) ( $payload['accountLabel'] ?? '' )
					);
					$result['ok']          = true;
					$result['reconnected'] = ! empty( $payload['reusedSiteId'] );
				} else {
					$result['error'] = 'connect_unavailable';
				}
			}

			$results[] = $result;
		}

		if ( ! $is_retry ) {
			$known_hosts = array_map(
				static function ( array $subsite ): string {
					return (string) $subsite['host'];
				},
				self::network_subsites()
			);
			foreach ( $code_by_host as $host => $unused_code ) {
				unset( $unused_code );
				if ( in_array( $host, $known_hosts, true ) ) {
					continue;
				}

				$results[] = [
					'host'    => $host,
					'blog_id' => 0,
					'ok'      => false,
					'error'   => 'unknown_host',
				];
			}
		}

		self::remember_network_connect_outcome( $results );
		self::redirect_to_network_settings( 'network-connect-results' );
	}

	public static function remember_network_connect_outcome( array $results ): void {
		if ( is_multisite() ) {
			set_site_transient( self::NETWORK_CONNECT_OUTCOME_PREFIX . get_current_user_id(), $results, self::NETWORK_CONNECT_OUTCOME_TTL );
		}
	}

	public static function take_network_connect_outcome(): array {
		if ( ! is_multisite() ) {
			return [];
		}

		$key     = self::NETWORK_CONNECT_OUTCOME_PREFIX . get_current_user_id();
		$outcome = get_site_transient( $key );
		delete_site_transient( $key );

		return is_array( $outcome ) ? $outcome : [];
	}

	private static function parse_subsite_codes( $raw ) {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return [];
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$entries = [];
		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) ) {
				return null;
			}

			$host = isset( $entry['host'] ) ? sanitize_text_field( (string) $entry['host'] ) : '';
			$code = isset( $entry['code'] ) ? sanitize_text_field( (string) $entry['code'] ) : '';
			if ( '' === $host || '' === $code ) {
				return null;
			}

			$entries[] = [
				'host' => $host,
				'code' => $code,
			];
		}

		if ( count( $entries ) > self::MAX_NETWORK_SUBSITES ) {
			return null;
		}

		return $entries;
	}

	private static function subsite_by_host( string $host ): ?array {
		foreach ( self::network_subsites() as $subsite ) {
			if ( $subsite['host'] === $host ) {
				return $subsite;
			}
		}

		return null;
	}

	private static function blog_home_url( int $blog_id ): string {
		switch_to_blog( $blog_id );
		try {
			return home_url();
		} finally {
			restore_current_blog();
		}
	}

	private static function exchange_code_for_blog( string $code, string $state, array $subsite ) {
		$host = (string) $subsite['host'];
		if ( '' === $host ) {
			return new \WP_Error( 'host_unavailable', 'Site host unavailable' );
		}

		switch_to_blog( (int) $subsite['blog_id'] );
		try {
			$response = RemoteApi::post_json(
				'/v1/connect/exchange',
				[
					'code'    => $code,
					'state'   => $state,
					'host'    => $host,
					'homeUrl' => (string) $subsite['homeUrl'],
				]
			);
		} finally {
			restore_current_blog();
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return [
			'siteId'       => (string) ( $response['siteId'] ?? '' ),
			'token'        => (string) ( $response['token'] ?? '' ),
			'accountLabel' => (string) ( $response['accountLabel'] ?? '' ),
			'reusedSiteId' => ! empty( $response['reusedSiteId'] ),
		];
	}

	private static function complete_connection_for_blog( int $blog_id, string $site_id, string $token, string $account_label = '' ): void {
		switch_to_blog( $blog_id );
		try {
			$previous_site_id = Sites::registered_id();
			self::complete_connection( $site_id, $token, $account_label );
			self::resume_setup_when_unread( $previous_site_id, $site_id );
		} finally {
			restore_current_blog();
		}
	}

	private static function redirect_to_network_settings( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					self::NOTICE_QUERY_ARG => sanitize_key( $notice ),
				],
				network_admin_url( 'admin.php?page=' . self::NETWORK_SETTINGS_PAGE )
			)
		);
		exit;
	}

	public static function begin_connect( bool $with_claim = false ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [
				'ok'    => false,
				'error' => 'permission_denied',
			];
		}

		$state = self::generate_state();
		if ( '' === $state ) {
			return [
				'ok'    => false,
				'error' => 'state_unavailable',
			];
		}

		$redirect_url = self::portal_connect_url( $state );
		if ( '' === $redirect_url ) {
			self::forget_state( $state );

			return [
				'ok'    => false,
				'error' => 'host_unavailable',
			];
		}

		// Only the explicit "Reconnect this site" action carries the
		// previous-site claim, so the service re-references the same site row
		// instead of issuing a second one. A plain connect (a fresh site or a
		// copy that must have its own identity) never claims a previous site,
		// and a site the service does not recognise falls through to a plain
		// new-site connect with no dead end.
		if ( $with_claim ) {
			$claim = Sites::reconnect_claim();
			if ( ! empty( $claim ) ) {
				$redirect_url = add_query_arg( $claim, $redirect_url );
			}
		}

		return [
			'ok'           => true,
			'redirect_url' => $redirect_url,
		];
	}

	public static function store_manual_credentials( string $site_id, string $token ): array {
		$site_id = sanitize_text_field( trim( $site_id ) );
		$token   = trim( $token );

		if ( ! self::is_uuid( $site_id ) || ! self::is_valid_token( $token ) ) {
			return [
				'ok'    => false,
				'error' => 'validation_error',
			];
		}

		self::complete_connection( $site_id, $token );

		return [ 'ok' => true ];
	}

	public static function disconnect(): void {
		ActionLog::record( 'Site credentials cleared by the administrator', 'warning' );
		self::clear_credentials();
	}

	/**
	 * Reconnect paths. "relink" keeps the stored credentials as
	 * evidence and re-arms the wizard so the operator re-connects the same
	 * account; "clone" clears them for a fresh connect as a new site.
	 */
	public static function relink_after_invalidation(): void {
		Options::update( 'setup.ended', false );
	}

	public static function reconnect_as_new_site(): void {
		ActionLog::record( 'Reconnecting as a new site', 'warning' );
		self::clear_credentials();
		Options::update( 'setup.ended', false );
	}

	public static function status_payload(): array {
		self::verify_connection();

		$site_id = Sites::registered_id();

		return [
			'connected'     => self::is_connected(),
			'connectionStatus' => self::connection_status(),
			'verification'     => get_option( self::LAST_PROBE_OPTION, null ),
			'siteId'        => '' !== $site_id ? $site_id : null,
			'accountLabel'  => (string) Options::get( 'site.account_label', '' ),
			'registeredUrl' => Sites::registered_address(),
			'addressChange' => Sites::address_change_state(),
			'portalSitesUrl' => self::portal_sites_url(),
			'portalSiteUrl' => '' !== $site_id ? RemoteApi::portal_base_url() . '/sites/' . rawurlencode( $site_id ) : '',
			'pro'           => ProEligibility::panel_state(),
			'setupUnfinished' => self::setup_unfinished() && ! self::finish_setup_dismissed(),
			'setupStep'       => (string) Options::get( 'setup.step', '' ),
			'proWelcomePending' => ProEligibility::welcome_pending(),
		];
	}

	private const FINISH_SETUP_DISMISSED_META = 'translatewp_finish_setup_dismissed';
	private const FINISH_SETUP_DISMISS_TTL_SECONDS = 604800;

	public static function finish_setup_dismissed(): bool {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return false;
		}

		$dismissed_at = (int) get_user_meta( $user_id, self::FINISH_SETUP_DISMISSED_META, true );

		return $dismissed_at > 0 && ( time() - $dismissed_at ) < self::FINISH_SETUP_DISMISS_TTL_SECONDS;
	}

	public static function dismiss_finish_setup(): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::FINISH_SETUP_DISMISSED_META, time() );
		}
	}

	public static function handle_return(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::redirect_with_notice( 'permission_denied' );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! self::is_valid_state_format( $state ) ) {
			self::redirect_with_notice( 'invalid_request' );
		}

		if ( ! self::consume_state( $state ) ) {
			self::redirect_with_notice( 'invalid_state' );
		}

		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		if ( 'access_denied' === $error ) {
			self::redirect_with_notice( 'access_denied' );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			self::redirect_with_notice( 'invalid_request' );
		}

		$result = self::exchange_code( $code, $state );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( $result->get_error_code() );
		}

		$payload        = is_array( $result ) ? $result : [];
		$site_id        = isset( $payload['siteId'] ) ? (string) $payload['siteId'] : '';
		$token          = isset( $payload['token'] ) ? (string) $payload['token'] : '';
		$account_label  = isset( $payload['accountLabel'] ) ? (string) $payload['accountLabel'] : '';

		if ( ! self::is_uuid( $site_id ) || ! self::is_valid_token( $token ) ) {
			self::redirect_with_notice( 'connect_unavailable' );
		}

		$previous_site_id = Sites::registered_id();
		self::complete_connection( $site_id, $token, $account_label );
		self::resume_setup_when_unread( $previous_site_id, $site_id );
		self::redirect_with_notice( ! empty( $payload['reusedSiteId'] ) ? 'reconnected' : 'connected' );
	}

	public static function exchange_code( string $code, string $state ) {
		$host = self::connect_host();
		if ( '' === $host ) {
			return new \WP_Error( 'host_unavailable', 'Site host unavailable' );
		}

		$response = RemoteApi::post_json(
			'/v1/connect/exchange',
			[
				'code'    => $code,
				'state'   => $state,
				'host'    => $host,
				'homeUrl' => home_url(),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return [
			'siteId'       => (string) ( $response['siteId'] ?? '' ),
			'token'        => (string) ( $response['token'] ?? '' ),
			'accountLabel' => (string) ( $response['accountLabel'] ?? '' ),
			'reusedSiteId' => ! empty( $response['reusedSiteId'] ),
		];
	}

	public static function notice_message( string $notice ): string {
		switch ( $notice ) {
			case 'connected':
				return __( 'Your site is connected. Translation is ready.', 'translate' );
			case 'reconnected':
				return __( 'Reconnected on the same account. Translation continues without interruption.', 'translate' );
			case 'access_denied':
				return __( 'Connection was not approved. Your site is still disconnected.', 'translate' );
			case 'invalid_state':
				return __( 'The connection took too long. Connect again.', 'translate' );
			case 'invalid_request':
				return __( 'The connection response was incomplete. Start connect again.', 'translate' );
			case 'validation_error':
				return __( 'The connection request was invalid. Start connect again.', 'translate' );
			case 'invalid_connect_code':
				return __( 'The connection code expired or was already used. Start connect again.', 'translate' );
			case 'site_conflict':
				return __( 'This site is linked to another account. Disconnect it there or contact support.', 'translate' );
			case 'address_taken':
				return __( 'Another site in your account already uses this address. Remove that site from your sites list, then reconnect.', 'translate' );
			case 'rate_limited':
				return __( 'Too many connection attempts. Wait a few minutes and try again.', 'translate' );
			case 'connect_unavailable':
				return __( 'Connection is temporarily unavailable. Try again later.', 'translate' );
			case 'remote_error':
				return __( 'Connection could not be completed right now. Try again.', 'translate' );
			case 'permission_denied':
				return __( 'You do not have permission to connect this site.', 'translate' );
			default:
				return '';
		}
	}

	public static function render_admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'translatewp' ) ) {
			return;
		}

		if ( 'invalidated' === self::connection_status() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p><p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p></div>',
				esc_html__( 'The service no longer recognises this site\'s credentials. Reconnect it to keep it on the same account, or connect it as a new site if this is a copy.', 'translate' ),
				esc_url( wp_nonce_url( admin_url( 'admin.php?page=translatewp&translatewp_connect=reconnect' ), 'translatewp_connect' ) ),
				esc_html__( 'Reconnect this site', 'translate' ),
				esc_url( wp_nonce_url( admin_url( 'admin.php?page=translatewp&translatewp_connect=new' ), 'translatewp_connect' ) ),
				esc_html__( 'Connect as a new site', 'translate' )
			);
			return;
		}

		$notice = isset( $_GET[ self::NOTICE_QUERY_ARG ] )
			? sanitize_key( wp_unslash( $_GET[ self::NOTICE_QUERY_ARG ] ) )
			: '';
		$message = self::notice_message( $notice );
		if ( '' === $message ) {
			$address_change = Sites::address_change_state();
			if ( self::is_connected() && null !== $address_change ) {
				printf(
					'<div class="notice notice-warning"><p>%s</p></div>',
					sprintf(
						/* translators: 1: registered site address, 2: current site address */
						esc_html__( 'The site address changed from %1$s to %2$s. Reconnect this site to keep it on the same account, or connect it as a new site. Translation keeps running until you choose.', 'translate' ),
						esc_html( (string) ( $address_change['from'] ?? '' ) ),
						esc_html( (string) ( $address_change['to'] ?? '' ) )
					)
				);
				return;
			}

			if ( ! self::is_connected() && Options::get( 'setup.ended', false ) ) {
				printf(
					'<div class="notice notice-warning"><p>%s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
					esc_html__( 'Connect your site to enable translation.', 'translate' ),
					esc_url( self::fresh_connect_url() ),
					esc_html__( 'Connect this site', 'translate' )
				);
			}
			return;
		}

		$class = in_array( $notice, [ 'connected', 'reconnected' ], true ) ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p>';
		if ( 'invalid_state' === $notice ) {
			printf(
				'<p><a class="button button-primary" href="%s">%s</a></p>',
				esc_url( self::fresh_connect_url() ),
				esc_html__( 'Connect this site', 'translate' )
			);
		}
		if ( 'address_taken' === $notice ) {
			printf(
				'<p><a class="button" href="%s" target="_blank" rel="noreferrer">%s</a></p>',
				esc_url( self::portal_sites_url() ),
				esc_html__( 'Open your sites list', 'translate' )
			);
		}
		echo '</div>';
	}

	private static function fresh_connect_url(): string {
		return wp_nonce_url( admin_url( 'admin.php?page=translatewp&translatewp_connect=new' ), 'translatewp_connect' );
	}

	private static function redirect_with_notice( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					self::NOTICE_QUERY_ARG => sanitize_key( $notice ),
				],
				admin_url( 'admin.php?page=translatewp' )
			)
		);
		exit;
	}

	public static function complete_connection( string $site_id, string $token, string $account_label = '' ): void {
		self::persist_credentials( $site_id, $token, $account_label );
		SiteLanguageSync::sync_active_languages();
	}

	private static function resume_setup_when_unread( string $previous_site_id, string $site_id ): void {
		if (
			Options::get( 'setup.ended', false )
			&& $previous_site_id === $site_id
			&& ( new IndexQueue() )->read_counts()['pages'] > 0
		) {
			return;
		}

		Options::update( 'setup.ended', false );
		Options::update( 'setup.restarted', true );
		Options::update( 'setup.step', 'read-site' );
		delete_transient( self::PAIRING_VERIFIED_TRANSIENT );
	}

	private static function persist_credentials( string $site_id, string $token, string $account_label = '' ): void {
		$account_label = sanitize_text_field( trim( $account_label ) );
		update_option(
			'translatewp_' . self::SITE_OPTION,
			[
				'id'            => $site_id,
				'token'         => $token,
				'home_url'      => home_url(),
				'account_label' => $account_label,
			]
		);
		self::mark_connected();
	}

	private static function clear_credentials(): void {
		update_option(
			'translatewp_' . self::SITE_OPTION,
			[
				'id'            => '',
				'token'         => '',
				'home_url'      => '',
				'account_label' => '',
			]
		);
		self::mark_disconnected();
		ProEligibility::clear_cache();
		ProEligibility::clear_last_known();
	}

	private static function is_valid_token( string $token ): bool {
		if ( '' === $token || strlen( $token ) > self::MAX_TOKEN_LENGTH ) {
			return false;
		}

		return 1 !== preg_match( '/[\x00-\x1F\x7F]/', $token );
	}

	private static function normalize_route_path( string $path ): string {
		$trimmed = trim( $path, '/' );

		return '' === $trimmed ? '' : $trimmed . '/';
	}

	private static function generate_state( array $payload = [] ): string {
		if ( ! function_exists( 'random_bytes' ) ) {
			return '';
		}

		try {
			$state = rtrim( strtr( base64_encode( random_bytes( self::STATE_BYTES ) ), '+/', '-_' ), '=' );
		} catch ( \Exception $exception ) {
			unset( $exception );
			return '';
		}

		if ( ! self::is_valid_state_format( $state ) ) {
			return '';
		}

		set_transient(
			self::state_transient_key( $state ),
			array_merge(
				[
					'user_id' => get_current_user_id(),
				],
				$payload
			),
			self::STATE_TTL_SECONDS
		);

		return $state;
	}

	private static function consume_state( string $state ) {
		if ( ! self::is_valid_state_format( $state ) ) {
			return false;
		}

		$key     = self::state_transient_key( $state );
		$payload = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $payload ) ) {
			return false;
		}

		if ( (int) ( $payload['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return false;
		}

		return $payload;
	}

	private static function forget_state( string $state ): void {
		if ( self::is_valid_state_format( $state ) ) {
			delete_transient( self::state_transient_key( $state ) );
		}
	}

	private static function state_transient_key( string $state ): string {
		return self::STATE_TRANSIENT_PREFIX . hash( 'sha256', $state );
	}

	private static function is_valid_state_format( string $state ): bool {
		return 1 === preg_match( self::STATE_PATTERN, $state );
	}

	private static function is_uuid( string $value ): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		);
	}
}
