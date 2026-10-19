<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\ProInstaller;
use TranslateWP\Components\SiteConnection;

class Pro {

	protected $plugin_name;
	protected $plugin_version;

	public function __construct( $plugin_name, $plugin_version ) {
		$this->plugin_name    = $plugin_name;
		$this->plugin_version = $plugin_version;

		add_action( 'admin_menu', [ $this, 'maybe_refresh_eligibility' ], 0 );
		add_action( 'network_admin_menu', [ $this, 'maybe_refresh_eligibility' ], 0 );
		add_action( 'admin_post_' . ProEligibility::START_UPGRADE_ACTION, [ ProEligibility::class, 'handle_start_upgrade' ] );
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_information' ], 10, 3 );
		add_filter( 'upgrader_pre_download', [ $this, 'pre_download' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'after_upgrade' ], 10, 2 );
		add_action( 'activated_plugin', [ $this, 'after_plugin_toggle' ] );
		add_action( 'deactivated_plugin', [ $this, 'after_plugin_toggle' ] );
	}

	public function after_upgrade( $upgrader, $hook_extra ): void {
		unset( $upgrader );
		if ( ! is_array( $hook_extra ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}

		$plugins  = isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ? $hook_extra['plugins'] : [];
		$basename = ProEligibility::addon_basename();
		if ( ! in_array( $basename, $plugins, true ) && ( $hook_extra['plugin'] ?? '' ) !== $basename ) {
			return;
		}

		ProEligibility::clear_cache();
	}

	public function after_plugin_toggle( $plugin ): void {
		if ( ProEligibility::addon_basename() !== (string) $plugin ) {
			return;
		}

		ProEligibility::clear_cache();
	}

	/**
	 * Signed package URLs expire minutes after they are issued, while the
	 * update transient that carries them lives for hours; download from a
	 * freshly issued URL instead of the stored one.
	 */
	public function pre_download( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) || ! ProEligibility::is_package_url( $package ) ) {
			return $reply;
		}

		$state = ProEligibility::refresh();
		$fresh = isset( $state['package_url'] ) ? (string) $state['package_url'] : '';
		if ( empty( $state['entitled'] ) || '' === $fresh ) {
			return new \WP_Error(
				'translatewp_pro_package_unavailable',
				ProInstaller::message_for( empty( $state['entitled'] ) ? 'not_entitled' : 'package_unavailable' )
			);
		}

		ProInstaller::load_dependencies();
		if ( ! function_exists( 'download_url' ) ) {
			return $reply;
		}

		return download_url( $fresh );
	}

	public function maybe_refresh_eligibility(): void {
		if ( ! $this->on_translatewp_page() ) {
			if ( ! ProEligibility::is_cache_warm() && ProEligibility::cached()['entitled'] && SiteConnection::is_connected() ) {
				ProEligibility::refresh();
			}

			return;
		}

		if ( ! SiteConnection::is_connected() ) {
			return;
		}

		if ( $this->connect_just_completed() || $this->purchase_just_returned() ) {
			ProEligibility::refresh();
			return;
		}

		ProEligibility::maybe_refresh();
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) || ! SiteConnection::is_connected() || ! ProEligibility::is_installed() ) {
			return $transient;
		}

		$fresh = ProEligibility::refresh();
		if ( empty( $fresh['entitled'] ) && '' === (string) $fresh['latest_version'] ) {
			return $transient;
		}

		$basename = ProEligibility::addon_basename();
		$offer    = ProEligibility::build_update_offer( $fresh );

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = [];
		}

		if ( null === $offer ) {
			unset( $transient->response[ $basename ] );
			$transient->no_update[ $basename ] = $this->update_item( $basename, ProEligibility::installed_version(), '' );

			return $transient;
		}

		unset( $transient->no_update[ $basename ] );
		$transient->response[ $offer['plugin'] ] = $this->update_item( $offer['plugin'], $offer['new_version'], $offer['package'] );

		return $transient;
	}

	private function update_item( string $basename, string $version, string $package ): object {
		return (object) [
			'id'          => 'translatewp/' . ProEligibility::addon_slug(),
			'slug'        => ProEligibility::addon_slug(),
			'plugin'      => $basename,
			'new_version' => $version,
			'package'     => $package,
			'url'         => ProEligibility::upgrade_url(),
			'icons'       => [],
			'banners'     => [],
			'banners_rtl' => [],
			'tested'      => '',
			'requires'    => '',
			'requires_php' => '',
			'compatibility' => new \stdClass(),
		];
	}

	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$slug = is_object( $args ) && isset( $args->slug ) ? (string) $args->slug : '';
		if ( ProEligibility::addon_slug() !== $slug ) {
			return $result;
		}

		$fresh = ProEligibility::fetch_fresh();
		if ( null === $fresh || empty( $fresh['entitled'] ) || '' === (string) $fresh['package_url'] ) {
			return $result;
		}

		return (object) [
			'name'          => 'TranslateWP Pro',
			'slug'          => ProEligibility::addon_slug(),
			'version'       => (string) $fresh['latest_version'],
			'author'        => 'TranslateWP',
			'homepage'      => ProEligibility::upgrade_url(),
			'download_link' => (string) $fresh['package_url'],
			'sections'      => [
				'description' => esc_html__( 'TranslateWP Pro features for this site.', 'translate' ),
			],
		];
	}

	private function on_translatewp_page(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return '' !== $page && 0 === strpos( $page, 'translatewp' );
	}

	private function connect_just_completed(): bool {
		$notice = isset( $_GET[ SiteConnection::NOTICE_QUERY_ARG ] )
			? sanitize_key( wp_unslash( $_GET[ SiteConnection::NOTICE_QUERY_ARG ] ) )
			: '';

		return in_array( $notice, [ 'connected', 'reconnected' ], true );
	}

	private function purchase_just_returned(): bool {
		return isset( $_GET[ ProEligibility::RETURN_QUERY_ARG ] );
	}
}
