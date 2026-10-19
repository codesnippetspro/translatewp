<?php

namespace TranslateWP\Components;

/**
 * The "Translate the site for visitors" switch is retired. A site (or,
 * on a network, the network default) that had it stored off translates again
 * after the update, so the migration leaves a marker and the owner is told
 * once, by a notice they dismiss, that translation is on and that
 * deactivating the plugin turns it off. Dismissing deletes the marker.
 */
class TranslationSwitchNotice {

	public const SITE_OPTION    = 'translatewp_translation_switch_carried_over';
	public const NETWORK_OPTION = 'translatewp_network_translation_switch_carried_over';
	public const DISMISS_ACTION = 'translatewp_dismiss_translation_switch';

	public static function mark_site(): void {
		update_option( self::SITE_OPTION, 1 );
	}

	public static function mark_network(): void {
		update_site_option( self::NETWORK_OPTION, 1 );
	}

	public static function message( string $scope ): string {
		if ( 'network' === $scope ) {
			return __( 'Translation for visitors is now on across the network. The network default had it switched off, and that setting was retired: TranslateWP now translates every site where it is active. To stop translating a site, deactivate the plugin there.', 'translate' );
		}

		return __( 'Translation for visitors is now on. This site had it switched off, and that setting was retired: TranslateWP now translates the site whenever it is active. To stop translating, deactivate the plugin.', 'translate' );
	}

	public static function render_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! get_option( self::SITE_OPTION, false ) ) {
			return;
		}

		self::render( 'site' );
	}

	public static function render_network_notice(): void {
		if ( ! is_multisite() || ! is_super_admin() || ! get_site_option( self::NETWORK_OPTION, false ) ) {
			return;
		}

		self::render( 'network' );
	}

	private static function render( string $scope ): void {
		printf(
			'<div class="notice notice-info"><p>%s</p><form method="post" action="%s"><p><input type="hidden" name="action" value="%s"><input type="hidden" name="scope" value="%s">%s<button type="submit" class="button">%s</button></p></form></div>',
			esc_html( self::message( $scope ) ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::DISMISS_ACTION ),
			esc_attr( $scope ),
			wp_nonce_field( self::DISMISS_ACTION, '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built nonce fields.
			esc_html__( 'Dismiss', 'translate' )
		);
	}

	public static function dismiss( string $scope ): void {
		if ( 'network' === $scope ) {
			delete_site_option( self::NETWORK_OPTION );
			return;
		}

		delete_option( self::SITE_OPTION );
	}

	public static function handle_dismiss(): void {
		check_admin_referer( self::DISMISS_ACTION );
		$scope   = isset( $_POST['scope'] ) && 'network' === sanitize_key( wp_unslash( $_POST['scope'] ) ) ? 'network' : 'site';
		$allowed = 'network' === $scope ? is_super_admin() : current_user_can( 'manage_options' );
		if ( ! $allowed ) {
			wp_die( esc_html__( 'You are not allowed to dismiss this notice.', 'translate' ), '', [ 'response' => 403 ] );
		}

		self::dismiss( $scope );
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}
}
