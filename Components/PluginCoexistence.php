<?php

namespace TranslateWP\Components;

/**
 * When another translation plugin is active, TranslateWP shows a
 * dismissible warning on its own admin pages and otherwise changes nothing.
 * The detected list lives here. Each entry is matched by any signal it loads:
 * a defined constant,
 * a declared class or a declared function, so free and pro builds both match.
 */
class PluginCoexistence {

	public const DISMISS_ACTION    = 'translatewp_dismiss_coexistence';
	public const DEACTIVATE_ACTION = 'translatewp_deactivate_other_plugin';
	private const DISMISSED_META = 'translatewp_coexistence_dismissed';

	private const PLUGINS = [
		[
			'name' => 'WPML',
			'folders' => [ 'sitepress-multilingual-cms' ],
			'constant' => 'ICL_SITEPRESS_VERSION',
			'class' => 'SitePress',
		],
		[
			'name' => 'Polylang',
			'folders' => [ 'polylang', 'polylang-pro' ],
			'constant' => 'POLYLANG_VERSION',
			'function' => 'pll_the_languages',
		],
		[
			'name' => 'TranslatePress',
			'folders' => [ 'translatepress-multilingual' ],
			'constant' => 'TRP_PLUGIN_VERSION',
			'class' => 'TRP_Translate_Press',
		],
		[
			'name' => 'Weglot',
			'folders' => [ 'weglot' ],
			'constant' => 'WEGLOT_VERSION',
			'function' => 'weglot_init',
		],
		[
			'name' => 'GTranslate',
			'folders' => [ 'gtranslate' ],
			'class' => 'GTranslate',
		],
		[
			'name' => 'Loco Translate',
			'folders' => [ 'loco-translate' ],
			'constant' => 'LOCO_TRANSLATE_VERSION',
			'class' => 'Loco_Loader',
			'function' => 'loco_plugin_version',
		],
		[
			'name' => 'MultilingualPress',
			'folders' => [ 'multilingualpress' ],
			'class' => 'Inpsyde\\MultilingualPress\\MultilingualPress',
			'function' => 'MultilingualPress\\bootstrap',
		],
	];

	/**
	 * Names of the translation plugins active alongside TranslateWP. The
	 * presence test is injectable so the detection can be exercised per plugin.
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
		if ( ! empty( $plugin['class'] ) && class_exists( $plugin['class'] ) ) {
			return true;
		}
		if ( ! empty( $plugin['function'] ) && function_exists( $plugin['function'] ) ) {
			return true;
		}

		return false;
	}

	public static function guidance_url(): string {
		return (string) apply_filters( 'translatewp_coexistence_guidance_url', admin_url( 'admin.php?page=translatewp-settings#advanced/other-translation-plugins' ) );
	}

	public static function message( array $detected ): string {
		return sprintf(
			/* translators: %s: comma-separated names of the other active translation plugins */
			__( 'TranslateWP found another translation system active on this site: %s. Running two translation systems on one site can produce mixed languages, duplicate language URLs and conflicting language switchers. TranslateWP keeps working and changes nothing on its own.', 'translate' ),
			implode( ', ', $detected )
		);
	}

	public static function render_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! AdminNotices::on_plugin_screen() ) {
			return;
		}

		$detected = self::detected();
		if ( empty( $detected ) || self::is_dismissed( $detected ) ) {
			return;
		}

		$actions = '';
		if ( current_user_can( 'deactivate_plugins' ) ) {
			foreach ( self::deactivatable( $detected ) as $name => $file ) {
				$actions .= sprintf(
					'<a class="button button-secondary" href="%s">%s</a> ',
					esc_url( self::deactivate_url( $file ) ),
					/* translators: %s: name of another translation plugin, such as Polylang */
					esc_html( sprintf( __( 'Deactivate %s', 'translate' ), $name ) )
				);
			}
		}

		printf(
			'%s<p>%s</p><p>%s<a href="%s">%s</a></p></div>',
			AdminNotices::open( 'notice-warning', self::DISMISS_ACTION ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped in AdminNotices::open().
			esc_html( self::message( $detected ) ),
			$actions, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			esc_url( self::guidance_url() ),
			esc_html__( 'Running alongside another translation plugin', 'translate' )
		);
	}

	/**
	 * The active plugin file of each detected plugin, keyed by its name. A
	 * plugin is matched by the folder it installs into; one that is active
	 * for the whole network is left to the network admin and not listed.
	 */
	public static function deactivatable( array $detected ): array {
		$active = array_filter( (array) get_option( 'active_plugins', [] ), 'is_string' );
		$files  = [];

		foreach ( self::PLUGINS as $plugin ) {
			if ( ! in_array( $plugin['name'], $detected, true ) ) {
				continue;
			}
			foreach ( $active as $file ) {
				if ( in_array( strtok( $file, '/' ), $plugin['folders'], true ) ) {
					$files[ $plugin['name'] ] = $file;
					break;
				}
			}
		}

		return $files;
	}

	private static function deactivate_url( string $file ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action' => self::DEACTIVATE_ACTION,
					'plugin' => rawurlencode( $file ),
				],
				admin_url( 'admin-post.php' )
			),
			self::DEACTIVATE_ACTION
		);
	}

	/** Deactivates the requested plugin only when it is one of the detected translation plugins. */
	public static function handle_deactivate(): void {
		check_admin_referer( self::DEACTIVATE_ACTION );
		if ( ! current_user_can( 'deactivate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to deactivate plugins on this site.', 'translate' ), '', [ 'response' => 403 ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer above.
		$requested = isset( $_GET['plugin'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) : '';
		if ( in_array( $requested, self::deactivatable( self::detected() ), true ) ) {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			deactivate_plugins( $requested );
		}

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=translatewp' ) );
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

	private static function is_dismissed( array $detected ): bool {
		return self::fingerprint( $detected ) === (string) get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
	}
}
