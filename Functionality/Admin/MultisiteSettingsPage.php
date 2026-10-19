<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Components\SiteConnection;

class MultisiteSettingsPage {

	private const BEGIN_ACTION  = 'translatewp_begin_network_connect';
	private const SCOPE_ACTION   = 'translatewp_save_settings_scope';
	public const PAGE_SLUG      = 'translatewp-network-settings';

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'network_admin_menu', [ $this, 'add_network_page' ] );
		add_action( 'admin_post_' . self::BEGIN_ACTION, [ $this, 'handle_begin' ] );
		add_action( 'admin_post_' . self::SCOPE_ACTION, [ $this, 'handle_scope' ] );
	}

	public function add_network_page(): void {
		add_menu_page(
			esc_html__( 'TranslateWP Network Settings', 'translate' ),
			esc_html__( 'TranslateWP', 'translate' ),
			'manage_network_options',
			self::PAGE_SLUG,
			[ $this, 'render_network_page' ],
			\TranslateWP\Components\BrandIcon::menu_icon(),
			25
		);

		add_submenu_page(
			self::PAGE_SLUG,
			esc_html__( 'TranslateWP Network Settings', 'translate' ),
			esc_html__( 'Settings', 'translate' ),
			'manage_network_options',
			self::PAGE_SLUG,
			[ $this, 'render_network_page' ]
		);

		add_submenu_page(
			self::PAGE_SLUG,
			esc_html__( 'Upgrade', 'translate' ),
			esc_html__( 'Upgrade', 'translate' ),
			'manage_network_options',
			\TranslateWP\Components\ProEligibility::upgrade_url_with_return()
		);
	}

	public function handle_begin(): void {
		check_admin_referer( self::BEGIN_ACTION );
		if ( ! current_user_can( 'manage_network_options' ) ) {
			$this->deny();
		}

		$only_hosts = isset( $_POST['only_hosts'] ) ? sanitize_text_field( wp_unslash( $_POST['only_hosts'] ) ) : '';
		$only_hosts = '' !== $only_hosts ? array_values( array_filter( array_map( 'trim', explode( ',', $only_hosts ) ) ) ) : [];
		$reconnect  = ! empty( $_POST['reconnect'] );

		$result = SiteConnection::begin_network_connect( $only_hosts, $reconnect );
		if ( empty( $result['ok'] ) || empty( $result['redirect_url'] ) ) {
			$this->redirect( 'connect-failed' );
		}

		// The address comes from the plugin's own connect URL builder, never
		// from the request.
		wp_redirect( (string) $result['redirect_url'] );
		exit;
	}

	public function handle_scope(): void {
		check_admin_referer( self::SCOPE_ACTION );
		if ( ! current_user_can( 'manage_network_options' ) ) {
			$this->deny();
		}

		wp_safe_redirect( self::settings_tab_url( \TranslateWP\Components\NetworkScope::apply_post( wp_unslash( $_POST ) ) ) );
		exit;
	}

	public function render_network_page(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			$this->deny();
		}

		echo '<div id="translatewp-network-admin"></div>';
	}

	public static function settings_tab_url( string $status ): string {
		return add_query_arg( 'translatewp_multisite_status', $status, network_admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '#settings';
	}

	private function redirect( string $status ): void {
		$referer = wp_get_referer();
		$url     = $referer ? $referer : network_admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		wp_safe_redirect( add_query_arg( 'translatewp_multisite_status', $status, $url ) );
		exit;
	}

	private function deny(): void {
		wp_die( esc_html__( 'You are not allowed to manage these settings.', 'translate' ), '', [ 'response' => 403 ] );
	}
}
