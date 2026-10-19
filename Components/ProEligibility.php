<?php

namespace TranslateWP\Components;

class ProEligibility {

	public const INSTALLED_NONE    = 'none';
	public const RETURN_QUERY_ARG  = 'translatewp_pro_return';
	public const START_UPGRADE_ACTION = 'translatewp_start_upgrade';

	private const ELIGIBILITY_PATH      = '/v1/pro/eligibility';
	private const TRANSIENT_KEY         = 'translatewp_pro_eligibility';
	private const LAST_KNOWN_OPTION     = 'translatewp_pro_capabilities_last_known';
	private const TRANSIENT_TTL_SECONDS     = 3600;
	private const PENDING_TTL_SECONDS       = 300;
	private const UPGRADE_TTL_SECONDS       = 15;
	private const UPGRADE_STAGES            = [ 'checkout_started', 'checkout_completed', 'payment_received', 'confirmed', 'declined' ];
	private const UPGRADE_STARTED_TRANSIENT = 'translatewp_upgrade_started';
	private const UPGRADE_STARTED_SECONDS   = 1800;
	private const CACHE_SHAPE               = 2;
	private const DEFAULT_ADDON_SLUG    = 'translatewp-pro';
	private const UPGRADE_PATH          = '/upgrade';
	private const ACCOUNT_PATH          = '/account';

	public static function upgrade_url(): string {
		return RemoteApi::portal_base_url() . self::UPGRADE_PATH;
	}

	public static function account_url(): string {
		return RemoteApi::portal_base_url() . self::ACCOUNT_PATH;
	}

	public static function upgrade_url_with_return(): string {
		return add_query_arg(
			[
				'action'   => self::START_UPGRADE_ACTION,
				'_wpnonce' => wp_create_nonce( self::START_UPGRADE_ACTION ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	public static function portal_upgrade_url_with_return(): string {
		$return_to = admin_url( 'admin.php?page=translatewp&' . self::RETURN_QUERY_ARG . '=1' );

		return add_query_arg( [ 'return' => $return_to ], self::upgrade_url() );
	}

	public static function start_upgrade(): void {
		set_transient( self::UPGRADE_STARTED_TRANSIENT, time(), self::UPGRADE_STARTED_SECONDS );
		self::clear_cache();
	}

	public static function upgrade_started(): bool {
		return false !== get_transient( self::UPGRADE_STARTED_TRANSIENT );
	}

	public static function handle_start_upgrade(): void {
		check_admin_referer( self::START_UPGRADE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to upgrade this site.', 'translate' ), '', [ 'response' => 403 ] );
		}

		self::start_upgrade();
		// The address comes from the plugin's own portal URL builder, never from the request.
		wp_redirect( self::portal_upgrade_url_with_return() );
		exit;
	}

	public static function addon_slug(): string {
		$slug = apply_filters( 'translatewp_pro_addon_slug', self::DEFAULT_ADDON_SLUG );

		return is_string( $slug ) && '' !== $slug ? $slug : self::DEFAULT_ADDON_SLUG;
	}

	public static function addon_basename(): string {
		$slug    = self::addon_slug();
		$default = $slug . '/' . $slug . '.php';

		self::load_plugin_functions();

		$resolved = '';
		if ( function_exists( 'get_plugins' ) ) {
			foreach ( array_keys( get_plugins() ) as $plugin_file ) {
				$plugin_file = (string) $plugin_file;
				if ( 0 === strpos( $plugin_file, $slug . '/' ) ) {
					$resolved = $plugin_file;
					break;
				}
			}
		}

		$basename = '' !== $resolved ? $resolved : $default;
		$basename = apply_filters( 'translatewp_pro_addon_basename', $basename );

		return is_string( $basename ) && '' !== $basename ? $basename : $default;
	}

	public static function installed_version(): string {
		self::load_plugin_functions();

		$version  = '';
		$basename = self::addon_basename();
		$file     = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/' . $basename : '';
		if ( '' !== $file && function_exists( 'get_plugin_data' ) && is_readable( $file ) ) {
			$data    = get_plugin_data( $file, false, false );
			$version = isset( $data['Version'] ) ? (string) $data['Version'] : '';
		} elseif ( function_exists( 'get_plugins' ) ) {
			$plugins = get_plugins();
			if ( isset( $plugins[ $basename ]['Version'] ) ) {
				$version = (string) $plugins[ $basename ]['Version'];
			}
		}

		$version = apply_filters( 'translatewp_pro_installed_version', $version );
		$version = is_string( $version ) ? trim( $version ) : '';

		return '' !== $version ? $version : self::INSTALLED_NONE;
	}

	public static function is_installed(): bool {
		return self::INSTALLED_NONE !== self::installed_version();
	}

	public static function reported_version(): string {
		return self::is_active() ? self::installed_version() : self::INSTALLED_NONE;
	}

	/**
	 * Whether Pro features are on for this site: the account is entitled and
	 * the addon is active. Either one alone unlocks nothing.
	 */
	public static function unlocks_pro_features(): bool {
		return self::is_active() && ! empty( self::cached()['entitled'] );
	}

	public static function is_active(): bool {
		self::load_plugin_functions();

		$active = function_exists( 'is_plugin_active' ) && is_plugin_active( self::addon_basename() );
		$active = apply_filters( 'translatewp_pro_addon_active', $active );

		return (bool) $active;
	}

	public static function default_state(): array {
		return [
			'plan'               => 'free',
			'entitled'           => false,
			'latest_version'     => '',
			'latest_released_at' => '',
			'package_url'        => '',
			'upgrade'            => null,
			'capabilities'       => self::default_capabilities(),
		];
	}

	public static function parse( array $payload ): array {
		$plan = isset( $payload['plan'] ) && is_string( $payload['plan'] ) ? $payload['plan'] : 'free';

		$entitled = array_key_exists( 'entitled', $payload )
			? (bool) $payload['entitled']
			: ( 'pro' === $plan );

		$latest             = is_array( $payload['latest'] ?? null ) ? $payload['latest'] : [];
		$latest_version     = isset( $latest['version'] ) && is_string( $latest['version'] ) ? trim( $latest['version'] ) : '';
		$latest_released_at = isset( $latest['released_at'] ) && is_string( $latest['released_at'] ) ? $latest['released_at'] : '';

		$package_url = isset( $payload['package_url'] ) && is_string( $payload['package_url'] )
			? self::sanitize_package_url( $payload['package_url'] )
			: '';

		return [
			'plan'               => $plan,
			'entitled'           => $entitled,
			'latest_version'     => $latest_version,
			'latest_released_at' => $latest_released_at,
			'package_url'        => $package_url,
			'upgrade'            => self::parse_upgrade( $payload['upgrade'] ?? null ),
			'capabilities'       => self::parse_capabilities( $payload ),
		];
	}

	private static function parse_upgrade( $upgrade ): ?array {
		if ( ! is_array( $upgrade ) || ! in_array( $upgrade['stage'] ?? null, self::UPGRADE_STAGES, true ) ) {
			return null;
		}

		$parsed = [ 'stage' => $upgrade['stage'] ];
		foreach ( self::UPGRADE_STAGES as $stage ) {
			$parsed[ $stage . '_at' ] = isset( $upgrade[ $stage . '_at' ] ) && is_string( $upgrade[ $stage . '_at' ] ) ? $upgrade[ $stage . '_at' ] : null;
		}

		return $parsed;
	}

	private static function upgrade_state( array $state ): ?array {
		$upgrade = is_array( $state['upgrade'] ?? null ) ? $state['upgrade'] : null;
		if ( null === $upgrade && empty( $state['entitled'] ) && self::upgrade_started() ) {
			$started = get_transient( self::UPGRADE_STARTED_TRANSIENT );
			$upgrade = [
				'stage'               => 'checkout_started',
				'checkout_started_at' => is_numeric( $started ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $started ) : null,
			];
		}
		if ( null === $upgrade ) {
			return null;
		}

		$format = (string) get_option( 'time_format' );
		$format = '' !== $format ? $format : 'H:i';
		$times  = [];
		foreach ( self::UPGRADE_STAGES as $stage ) {
			$at        = $upgrade[ $stage . '_at' ] ?? null;
			$timestamp = is_string( $at ) ? strtotime( $at ) : false;

			$times[ lcfirst( str_replace( '_', '', ucwords( $stage, '_' ) ) ) ] = false !== $timestamp ? wp_date( $format, $timestamp ) : null;
		}

		return [
			'stage' => (string) $upgrade['stage'],
			'times' => $times,
		];
	}

	private static function default_capabilities(): array {
		return [
			'seo'          => false,
			'custom_style' => false,
			'byok'         => false,
		];
	}

	private static function parse_capabilities( array $payload ): array {
		$raw = is_array( $payload['capabilities'] ?? null ) ? $payload['capabilities'] : [];

		$capabilities = [];
		foreach ( self::default_capabilities() as $key => $default ) {
			$capabilities[ $key ] = array_key_exists( $key, $raw ) ? (bool) $raw[ $key ] : $default;
		}

		return $capabilities;
	}

	public static function cached(): array {
		$state = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $state ) ) {
			return array_merge( self::default_state(), $state );
		}

		return array_merge( self::default_state(), self::last_known_record() );
	}

	public static function refresh(): array {
		$fresh = self::fetch_fresh();
		if ( null === $fresh ) {
			return self::cached();
		}

		// A site that is not entitled asks again soon, so a purchase shows up in the
		// admin within minutes instead of hours.
		$ttl = self::package_pending( $fresh ) || empty( $fresh['entitled'] ) ? self::PENDING_TTL_SECONDS : self::TRANSIENT_TTL_SECONDS;
		$stage = is_array( $fresh['upgrade'] ?? null ) ? $fresh['upgrade']['stage'] : '';
		if ( ! empty( $fresh['entitled'] ) || 'declined' === $stage ) {
			delete_transient( self::UPGRADE_STARTED_TRANSIENT );
		}
		if ( ( '' !== $stage && 'declined' !== $stage ) || self::upgrade_started() ) {
			$ttl = self::UPGRADE_TTL_SECONDS;
		}
		$previous = self::last_known_record();
		if ( ( $previous['entitled'] ?? null ) !== ( $fresh['entitled'] ?? null ) || ( $previous['plan'] ?? null ) !== ( $fresh['plan'] ?? null ) ) {
			ActionLog::record( sprintf( 'Account plan is now %s, Pro %s', (string) ( $fresh['plan'] ?? 'free' ), ! empty( $fresh['entitled'] ) ? 'entitled' : 'not entitled' ) );
		}
		$record = array_merge( $fresh, [ 'shape' => self::CACHE_SHAPE ] );
		set_transient( self::TRANSIENT_KEY, $record, $ttl );
		update_option( self::LAST_KNOWN_OPTION, $record, true );

		return $fresh;
	}

	public static function last_known_capabilities(): array {
		$record = self::last_known_record();

		return isset( $record['capabilities'] ) ? self::parse_capabilities( $record ) : self::default_capabilities();
	}

	private static function last_known_record(): array {
		$record = get_option( self::LAST_KNOWN_OPTION, [] );

		return is_array( $record ) && self::CACHE_SHAPE === (int) ( $record['shape'] ?? 0 ) ? $record : [];
	}

	public static function panel_state(): array {
		$state = SiteConnection::is_connected() ? self::maybe_refresh() : self::default_state();

		$update_available = self::has_update( $state );

		return [
			'plan'             => (string) $state['plan'],
			'entitled'         => (bool) $state['entitled'],
			'installed'        => self::is_installed(),
			'active'           => self::is_active(),
			'installedVersion' => self::is_installed() ? self::installed_version() : null,
			'latestVersion'    => '' !== (string) $state['latest_version'] ? (string) $state['latest_version'] : null,
			'packagePending'   => self::package_pending( $state ),
			'upgrade'          => self::upgrade_state( $state ),
			'accountUrl'       => self::account_url(),
			'updateAvailable'  => $update_available,
			'updateUrl'        => $update_available ? self::update_url() : null,
			'capabilities'     => is_array( $state['capabilities'] ?? null ) ? $state['capabilities'] : self::default_capabilities(),
		];
	}

	public static function update_url(): string {
		$basename = self::addon_basename();

		return add_query_arg(
			[
				'action'   => 'upgrade-plugin',
				'plugin'   => rawurlencode( $basename ),
				'_wpnonce' => wp_create_nonce( 'upgrade-plugin_' . $basename ),
			],
			self_admin_url( 'update.php' )
		);
	}

	public static function clear_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	public static function clear_last_known(): void {
		delete_option( self::LAST_KNOWN_OPTION );
	}

	public static function is_cache_warm(): bool {
		$state = get_transient( self::TRANSIENT_KEY );

		return is_array( $state ) && self::CACHE_SHAPE === (int) ( $state['shape'] ?? 0 );
	}

	public static function maybe_refresh(): array {
		if ( self::is_cache_warm() ) {
			return self::cached();
		}

		return self::refresh();
	}

	public static function fetch_fresh(): ?array {
		$response = SiteRequests::get(
			self::ELIGIBILITY_PATH,
			[ 'installed' => self::reported_version() ]
		);

		if ( is_wp_error( $response ) ) {
			return self::state_from_error( $response );
		}

		return self::parse( is_array( $response ) ? $response : [] );
	}

	public static function state_from_error( \WP_Error $error ): ?array {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || 503 !== (int) ( $data['status'] ?? 0 ) ) {
			return null;
		}

		$payload = is_array( $data['payload'] ?? null ) ? $data['payload'] : [];
		if ( ! isset( $payload['plan'] ) ) {
			return null;
		}

		unset( $payload['package_url'] );

		return self::parse( $payload );
	}

	public static function package_pending( array $state ): bool {
		return ! empty( $state['entitled'] ) && '' === (string) ( $state['latest_version'] ?? '' );
	}

	public static function should_show_install_prompt(): bool {
		if ( ! SiteConnection::is_connected() ) {
			return false;
		}

		if ( self::is_installed() ) {
			return false;
		}

		return (bool) self::cached()['entitled'];
	}

	public static function is_pro_active(): bool {
		return (bool) self::cached()['entitled'] && self::is_installed() && self::is_active();
	}

	private const WELCOME_SEEN_OPTION = 'translatewp_pro_welcome_seen';

	/**
	 * The Dashboard welcomes a site to Pro once, after the account
	 * includes Pro and the addon is active, until the owner dismisses it.
	 */
	public static function welcome_pending(): bool {
		return self::is_pro_active() && false === get_option( self::WELCOME_SEEN_OPTION, false );
	}

	public static function dismiss_welcome(): void {
		update_option( self::WELCOME_SEEN_OPTION, time(), false );
	}

	public static function has_update( array $state ): bool {
		if ( empty( $state['entitled'] ) || ! self::is_installed() ) {
			return false;
		}

		$latest = is_string( $state['latest_version'] ?? null ) ? $state['latest_version'] : '';
		if ( '' === $latest || '' === ( $state['package_url'] ?? '' ) ) {
			return false;
		}

		return self::semver_gt( $latest, self::installed_version() );
	}

	public static function build_update_offer( array $state ): ?array {
		if ( ! self::has_update( $state ) ) {
			return null;
		}

		return [
			'slug'        => self::addon_slug(),
			'plugin'      => self::addon_basename(),
			'new_version' => (string) $state['latest_version'],
			'package'     => (string) $state['package_url'],
		];
	}

	public static function semver_gt( string $candidate, string $current ): bool {
		if ( self::INSTALLED_NONE === $current || '' === $current ) {
			return false;
		}

		return version_compare( $candidate, $current, '>' );
	}

	private static function load_plugin_functions(): void {
		if ( function_exists( 'get_plugins' ) || ! defined( 'ABSPATH' ) ) {
			return;
		}

		$path = ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}

	public static function is_package_url( string $url ): bool {
		if ( '' === self::sanitize_package_url( $url ) ) {
			return false;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '/v1/pro/package' === rtrim( $path, '/' );
	}

	private static function sanitize_package_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
			return '';
		}

		if ( 'https' !== strtolower( (string) $parsed['scheme'] ) ) {
			return '';
		}

		$base = wp_parse_url( RemoteApi::base_url() );
		if ( ! is_array( $base ) || empty( $base['host'] ) ) {
			return '';
		}

		if ( strtolower( (string) $parsed['host'] ) !== strtolower( (string) $base['host'] ) ) {
			return '';
		}

		return $url;
	}
}
