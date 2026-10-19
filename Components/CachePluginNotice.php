<?php

namespace TranslateWP\Components;

/**
 * When a known page cache plugin is active, TranslateWP shows a dismissible
 * notice on its own admin screens that names the plugin and says where to
 * purge its cache once if a translated page still shows old text. The
 * detected list lives here. Each entry is matched by any signal it loads:
 * a defined constant or a declared function.
 */
class CachePluginNotice {

	public const DISMISS_ACTION = 'translatewp_dismiss_cache_notice';
	private const DISMISSED_META = 'translatewp_cache_notice_dismissed';

	private const PLUGINS = [
		[
			'name' => 'LiteSpeed Cache',
			'menu' => 'LiteSpeed Cache',
			'constant' => 'LSCWP_V',
		],
		[
			'name' => 'WP Rocket',
			'menu' => 'WP Rocket',
			'constant' => 'WP_ROCKET_VERSION',
		],
		[
			'name' => 'W3 Total Cache',
			'menu' => 'Performance',
			'constant' => 'W3TC',
		],
		[
			'name' => 'WP Super Cache',
			'menu' => 'Settings > WP Super Cache',
			'constant' => 'WPCACHEHOME',
		],
		[
			'name' => 'SiteGround Speed Optimizer',
			'menu' => 'Speed Optimizer',
			'function' => 'sg_cachepress_purge_cache',
		],
		[
			'name' => 'WP Fastest Cache',
			'menu' => 'WP Fastest Cache',
			'constant' => 'WPFC_WP_CONTENT_URL',
		],
	];

	/**
	 * Names of the page cache plugins active on this site. The presence test
	 * is injectable so the detection can be exercised per plugin.
	 */
	public static function detected( ?callable $is_present = null ): array {
		$is_present = $is_present ?? [ self::class, 'signal_present' ];
		$found      = [];

		foreach ( self::PLUGINS as $plugin ) {
			if ( $is_present( $plugin ) ) {
				$found[] = $plugin['name'];
			}
		}

		return $found;
	}

	private static function signal_present( array $plugin ): bool {
		if ( ! empty( $plugin['constant'] ) && defined( $plugin['constant'] ) ) {
			return true;
		}
		if ( ! empty( $plugin['function'] ) && function_exists( $plugin['function'] ) ) {
			return true;
		}

		return false;
	}

	public static function message( array $detected ): string {
		$menus = array_column( self::PLUGINS, 'menu', 'name' );
		$sentences = [];
		foreach ( $detected as $name ) {
			$sentences[] = sprintf(
				/* translators: %1$s: name of the active cache plugin, %2$s: the admin menu where that plugin purges its cache */
				__( '%1$s is active. TranslateWP clears its cache when translations change. If a translated page shows old text, purge the cache once from the %2$s menu.', 'translate' ),
				$name,
				$menus[ $name ] ?? $name
			);
		}

		return implode( ' ', $sentences );
	}

	private static function is_translatewp_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : '';

		return 'toplevel_page_translatewp' === $id || 0 === strpos( $id, 'translatewp_page_' );
	}

	public static function render_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_translatewp_screen() ) {
			return;
		}

		$detected = self::detected();
		if ( empty( $detected ) || self::is_dismissed( $detected ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p><form method="post" action="%s"><p><input type="hidden" name="action" value="%s">%s<button type="submit" class="button">%s</button></p></form></div>',
			esc_html( self::message( $detected ) ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::DISMISS_ACTION ),
			wp_nonce_field( self::DISMISS_ACTION, '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built nonce fields.
			esc_html__( 'Dismiss', 'translate' )
		);
	}

	public static function fingerprint( array $detected ): string {
		sort( $detected );

		return hash( 'sha256', implode( '|', $detected ) );
	}

	public static function dismiss(): void {
		$detected = self::detected();
		if ( empty( $detected ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::DISMISSED_META, self::fingerprint( $detected ) );
	}

	public static function handle_dismiss(): void {
		check_admin_referer( self::DISMISS_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to dismiss this notice.', 'translate' ), '', [ 'response' => 403 ] );
		}

		self::dismiss();
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=translatewp' ) );
		exit;
	}

	private static function is_dismissed( array $detected ): bool {
		return self::fingerprint( $detected ) === (string) get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
	}
}
