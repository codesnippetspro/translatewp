<?php

namespace TranslateWP\Components;

/**
 * The plain-text report a site owner pastes into a support request. Every
 * value is one the team needs to reproduce a problem: the site id and the
 * account the site is connected to are in it, tokens never are, and visitor
 * data is not part of it.
 */
class DebugInfo {

	public static function text(): string {
		$lines = [ '== TranslateWP support report ==', 'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC', '' ];

		// The events come first: they are what support reads; the site facts follow.
		$lines[] = '-- Plugin events (newest last) --';
		$log     = ActionLog::text();
		$lines[] = '' !== $log ? $log : '(none recorded)';
		$lines[] = '';

		foreach ( self::sections() as $title => $rows ) {
			$lines[] = '-- ' . $title . ' --';
			foreach ( $rows as $label => $value ) {
				$lines[] = sprintf( '%s: %s', $label, self::scalar( $value ) );
			}
			$lines[] = '';
		}

		return rtrim( implode( "\n", $lines ) );
	}

	public static function sections(): array {
		global $wpdb;

		$pro        = ProEligibility::panel_state();
		$languages  = Options::get( 'languages.list', [] );
		$switcher   = Options::get( 'languages.switcher', [] );
		$seo        = Options::get( 'languages.seo', [] );
		$settings   = Options::get( 'translations.settings', [] );
		$probe      = get_option( 'translatewp_last_connection_probe', null );
		$theme      = wp_get_theme();
		$list       = is_array( $languages ) ? array_map(
			static fn ( $language ) => is_array( $language ) ? sprintf( '%s%s%s', (string) ( $language['locale'] ?? '' ), ! empty( $language['default'] ) ? ' (default)' : '', empty( $language['active'] ) ? ' (off)' : '' ) : (string) $language,
			$languages
		) : [];

		return [
			'Plugin'      => [
				'Version'          => defined( 'TRANSLATEWP_VERSION' ) ? TRANSLATEWP_VERSION : '',
				'Pro addon'        => sprintf( '%s, %s, %s', $pro['installed'] ? 'installed ' . (string) ( $pro['installedVersion'] ?? '' ) : 'not installed', $pro['active'] ? 'active' : 'inactive', $pro['entitled'] ? 'entitled (' . $pro['plan'] . ')' : 'not entitled' ),
				'Setup ended'      => (bool) Options::get( 'setup.ended', false ),
				'Connection'       => SiteConnection::connection_status(),
				'Site id'          => (string) Options::get( 'site.id', '' ),
				'Account'          => (string) Options::get( 'site.account_label', '' ),
				'Service host'     => wp_parse_url( RemoteApi::base_url(), PHP_URL_HOST ),
				'Last probe'       => is_array( $probe ) ? (string) ( $probe['status'] ?? $probe['verdict'] ?? wp_json_encode( $probe ) ) : '',
				'Languages'        => implode( ', ', $list ),
				'URL structure'    => (string) ( $seo['url_structure'] ?? 'subdirectory' ),
				'Switcher'         => sprintf( '%s, %s', (string) ( $switcher['type'] ?? '' ), isset( $switcher['floating_position'] ) ? 'positioned' : 'default position' ),
				'Auto translate'   => (bool) ( $settings['auto_translate'] ?? false ),
				'Settings scope'   => is_multisite() ? self::scope_summary( SettingsScope::subsite_scope() ) : 'single site',
				'Cache plugins'    => implode( ', ', CachePluginNotice::detected() ),
				'Other translators' => implode( ', ', PluginCoexistence::detected() ),
			],
			'WordPress'   => [
				'Version'          => get_bloginfo( 'version' ),
				'Site URL'         => home_url(),
				'Multisite'        => is_multisite() ? ( is_network_admin() ? 'yes (network admin)' : 'yes, blog ' . get_current_blog_id() ) : 'no',
				'Locale'           => get_locale(),
				'Permalinks'       => '' !== (string) get_option( 'permalink_structure' ) ? 'pretty' : 'plain',
				'Theme'            => PlainText::decode( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $theme->parent() ? ' (child of ' . $theme->parent()->get( 'Name' ) . ')' : '' ) ),
				'Active plugins'   => implode( ', ', self::active_plugins() ),
				'WP_CRON'          => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'disabled' : 'enabled',
				'Object cache'     => wp_using_ext_object_cache() ? 'external' : 'none',
				'WP_DEBUG'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'Timezone'         => wp_timezone_string(),
			],
			'Server'      => [
				'PHP'              => PHP_VERSION,
				'Database'         => isset( $wpdb ) && method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '',
				'Memory limit'     => ini_get( 'memory_limit' ),
				'Max execution'    => ini_get( 'max_execution_time' ) . 's',
				'Software'         => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : '',
				'cURL'             => function_exists( 'curl_version' ) ? (string) ( curl_version()['version'] ?? '' ) : 'missing',
				'Loopback'         => self::loopback_state(),
			],
		];
	}

	private static function active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active  = (array) get_option( 'active_plugins', [] );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) : [];
		$all     = get_plugins();
		$names   = [];
		foreach ( array_unique( array_merge( $active, $network ) ) as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$names[] = $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version'];
			}
		}
		sort( $names );

		return $names;
	}

	private static function loopback_state(): string {
		$response = wp_remote_get(
            home_url( '/' ),
            [
				'timeout' => 5,
				'sslverify' => false,
			]
        );

		return is_wp_error( $response ) ? 'failed: ' . $response->get_error_code() : 'ok (' . (int) wp_remote_retrieve_response_code( $response ) . ')';
	}

	private static function scope_summary( array $scope ): string {
		$locked = array_keys( array_filter( $scope ) );

		return [] === $locked ? 'every group set by this site' : 'locked by the network: ' . implode( ', ', $locked );
	}

	private static function scalar( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		return '' === (string) $value ? '-' : (string) $value;
	}
}
