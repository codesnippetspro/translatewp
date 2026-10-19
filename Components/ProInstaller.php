<?php

namespace TranslateWP\Components;

class ProInstaller {

	public static function install_for_site(): array {
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			return self::outcome( false, 'permission_denied' );
		}

		$state = ProEligibility::refresh();
		if ( empty( $state['entitled'] ) ) {
			return self::outcome( false, 'not_entitled' );
		}

		$package_url = isset( $state['package_url'] ) ? (string) $state['package_url'] : '';
		if ( '' === $package_url ) {
			return self::outcome( false, 'package_unavailable' );
		}

		$result = self::install_and_activate( $package_url );

		ProEligibility::refresh();

		if ( ! empty( $result['ok'] ) ) {
			ActionLog::record( 'Pro addon installed from the account' );
			return self::outcome( true, 'installed' );
		}

		return self::outcome(
			false,
			isset( $result['error'] ) ? sanitize_key( (string) $result['error'] ) : 'install_failed',
			isset( $result['reason'] ) ? (string) $result['reason'] : ''
		);
	}

	public static function activate_for_site(): array {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return self::outcome( false, 'permission_denied' );
		}

		if ( ! ProEligibility::is_installed() ) {
			return self::outcome( false, 'not_installed' );
		}

		self::load_dependencies();
		if ( ! function_exists( 'activate_plugin' ) ) {
			return self::outcome( false, 'installer_unavailable' );
		}

		$activated = activate_plugin( ProEligibility::addon_basename(), '', self::activates_network_wide() );
		ProEligibility::clear_cache();

		if ( is_wp_error( $activated ) ) {
			return self::outcome( false, 'activate_failed' );
		}

		ActionLog::record( 'Pro addon activated' );
		return self::outcome( true, 'activated' );
	}

	/**
	 * The reason WordPress itself gave for a failed install or activation
	 * (a directory that cannot be created, a package that did not unpack)
	 * follows the plain message, so the site owner knows what to fix.
	 */
	public static function message_for( string $code, string $reason = '' ): string {
		$message = self::base_message_for( $code );
		$reason  = trim( wp_strip_all_tags( $reason ) );
		if ( '' === $reason ) {
			return $message;
		}

		return $message . ' ' . sprintf(
			/* translators: %s: the error WordPress reported. */
			__( 'WordPress reported: %s', 'translate' ),
			rtrim( $reason, '.' ) . '.'
		);
	}

	private static function base_message_for( string $code ): string {
		switch ( $code ) {
			case 'installed':
			case 'activated':
				return __( 'Pro installed. The Pro features are now available in the menu.', 'translate' );
			case 'not_installed':
				return __( 'TranslateWP Pro is not installed on this site yet.', 'translate' );
			case 'not_entitled':
				return __( 'Your account is not on Pro yet. Finish your upgrade, then install Pro.', 'translate' );
			case 'package_unavailable':
				return __( 'Pro could not be prepared right now. Try again in a moment.', 'translate' );
			case 'install_failed':
				return __( 'Pro could not be installed. Try again, or install it from your account.', 'translate' );
			case 'activate_failed':
				return __( 'Pro was downloaded but could not be activated. Activate it from your Plugins screen.', 'translate' );
			case 'installer_unavailable':
				return __( 'Pro could not be installed on this site. Try again from your account.', 'translate' );
			case 'permission_denied':
				return __( 'You do not have permission to install plugins on this site.', 'translate' );
			default:
				return __( 'Pro could not be installed. Try again, or install it from your account.', 'translate' );
		}
	}

	private static function outcome( bool $ok, string $code, string $reason = '' ): array {
		return [
			'ok'      => $ok,
			'code'    => $code,
			'message' => self::message_for( $code, $reason ),
		];
	}

	/**
	 * What the upgrader said when it failed: its own error, the skin's result,
	 * or the last message it printed while trying.
	 */
	private static function upgrader_reason( $installed, $skin ): string {
		if ( is_wp_error( $installed ) ) {
			return (string) $installed->get_error_message();
		}
		if ( isset( $skin->result ) && is_wp_error( $skin->result ) ) {
			return (string) $skin->result->get_error_message();
		}
		if ( method_exists( $skin, 'get_upgrade_messages' ) ) {
			$messages = array_filter( array_map( 'strval', (array) $skin->get_upgrade_messages() ) );
			if ( $messages ) {
				return (string) end( $messages );
			}
		}

		return '';
	}

	/**
	 * On a network where the free plugin is active network-wide, Pro follows
	 * it network-wide when the installing user manages network plugins, so
	 * every subsite gets Pro from the one click. Anywhere else the
	 * activation stays with the current site.
	 */
	public static function activates_network_wide(): bool {
		if ( ! is_multisite() || ! defined( 'TRANSLATEWP_BASENAME' ) ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( TRANSLATEWP_BASENAME ) ) {
			return false;
		}

		return current_user_can( 'manage_network_plugins' );
	}

	public static function install_and_activate( string $package_url ): array {
		$package_url = trim( $package_url );
		if ( '' === $package_url ) {
			return [
				'ok'    => false,
				'error' => 'package_unavailable',
			];
		}

		self::load_dependencies();

		if ( ! class_exists( '\\Plugin_Upgrader' ) || ! class_exists( '\\Automatic_Upgrader_Skin' ) ) {
			return [
				'ok'    => false,
				'error' => 'installer_unavailable',
			];
		}

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );

		ob_start();
		$installed = $upgrader->install( $package_url, [ 'overwrite_package' => true ] );
		ob_end_clean();

		if ( is_wp_error( $installed ) || true !== $installed ) {
			return [
				'ok'     => false,
				'error'  => 'install_failed',
				'reason' => self::upgrader_reason( $installed, $skin ),
			];
		}

		$basename   = ProEligibility::addon_basename();
		$plugin_info = $upgrader->plugin_info();
		if ( is_string( $plugin_info ) && '' !== $plugin_info ) {
			$basename = $plugin_info;
		}

		$activated = activate_plugin( $basename, '', self::activates_network_wide() );
		if ( is_wp_error( $activated ) ) {
			return [
				'ok'     => false,
				'error'  => 'activate_failed',
				'reason' => $activated->get_error_message(),
			];
		}

		return [
			'ok'      => true,
			'version' => ProEligibility::installed_version(),
		];
	}

	public static function load_dependencies(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		$includes = [
			'wp-admin/includes/file.php',
			'wp-admin/includes/misc.php',
			'wp-admin/includes/plugin.php',
			'wp-admin/includes/class-wp-upgrader.php',
		];

		foreach ( $includes as $include ) {
			$path = ABSPATH . $include;
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}
}
