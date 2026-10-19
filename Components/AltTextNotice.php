<?php

namespace TranslateWP\Components;

/**
 * The one notice of the 1.0 update: a Free site that was translating image
 * alt text is told that alt text translation is now part of TranslateWP Pro.
 * The update marks the site; the notice shows to those who manage the site
 * until one of them closes it, and never on a site with Pro.
 */
class AltTextNotice {

	public const DISMISS_ACTION = 'translatewp_dismiss_alt_text_notice';
	public const FLAG_OPTION    = 'translatewp_alt_text_notice';

	/**
	 * Marks a site that was translating alt text before the update. Run by the 1.0 migration.
	 *
	 * @param string|null $version The plugin version being updated to; the running one by default.
	 */
	public static function flag_if_affected( ?string $version = null ): void {
		// A pre-release build below 1.0 still translates nothing differently
		// from the site's point of view: the notice belongs to the 1.0 update.
		$version = $version ?? ( defined( 'TRANSLATEWP_VERSION' ) ? (string) TRANSLATEWP_VERSION : '' );
		if ( version_compare( $version, '1.0.0', '<' ) ) {
			return;
		}

		$settings = Options::get( 'translations.settings', [] );
		// The setting was on unless the site turned it off.
		if ( ! is_array( $settings ) || false !== ( $settings['translate_image_alt_tags'] ?? true ) ) {
			update_option( self::FLAG_OPTION, 1, false );
		}
	}

	/** Whether the current user has the notice still to see. */
	public static function is_pending(): bool {
		return (bool) get_option( self::FLAG_OPTION ) && current_user_can( 'manage_options' ) && ! ProEligibility::unlocks_pro_features();
	}

	public static function render(): void {
		if ( ! self::is_pending() ) {
			return;
		}

		printf(
			'%s<p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a> <a class="button button-secondary" href="%s">%s</a></p></div>',
			AdminNotices::open( 'notice-info', self::DISMISS_ACTION ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped in AdminNotices::open().
			esc_html__( 'Image alt text translation is now part of TranslateWP Pro.', 'translate' ),
			esc_html__( 'From this version, image alt text on this site stays in its original language. Everything else keeps translating as before.', 'translate' ),
			esc_url( ProEligibility::upgrade_url_with_return() ),
			esc_html__( 'See TranslateWP Pro', 'translate' ),
			esc_url( admin_url( 'admin.php?page=translatewp-settings#translation' ) ),
			esc_html__( 'Open translation settings', 'translate' )
		);
	}

	public static function dismiss(): void {
		delete_option( self::FLAG_OPTION );
	}

	/** The notice X, posted by Functionality\Admin\NoticeDismiss. */
	public static function handle_ajax_dismiss(): void {
		check_ajax_referer( self::DISMISS_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
			return;
		}

		self::dismiss();
		wp_send_json_success();
	}
}
