<?php

namespace TranslateWP\Functionality\Admin;

/**
 * Another plugin's Tailwind build may reset every --tw-* variable on every
 * element, unlayered; that outranks the layered utilities of the admin app,
 * so blur, grayscale, shadows and transforms silently stop. Those sheets are
 * dropped on TranslateWP screens only; the other plugin's own screens keep them.
 */
class StyleConflicts {

	private const CONFLICTING_STYLES = [ 'yoast-seo-tailwind' ];

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		add_action( 'admin_enqueue_scripts', [ self::class, 'dequeue_conflicting_styles' ], 100 );
	}

	public static function dequeue_conflicting_styles(): void {
		if ( ! self::is_translatewp_screen() ) {
			return;
		}

		// A sheet still prints when another queued sheet depends on it, so the
		// dependency is cut first (Yoast's admin-global sheet depends on its
		// Tailwind sheet on every admin page).
		$styles = function_exists( 'wp_styles' ) ? wp_styles() : null;
		foreach ( self::CONFLICTING_STYLES as $handle ) {
			if ( $styles && isset( $styles->registered ) && is_array( $styles->registered ) ) {
				foreach ( $styles->registered as $dependency ) {
					if ( is_object( $dependency ) && isset( $dependency->deps ) && is_array( $dependency->deps ) ) {
						$dependency->deps = array_values( array_diff( $dependency->deps, [ $handle ] ) );
					}
				}
			}
			wp_dequeue_style( $handle );
		}
	}

	private static function is_translatewp_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : '';

		return 'toplevel_page_translatewp' === $id
			|| 0 === strpos( $id, 'translatewp_page_' )
			|| 0 === strpos( $id, 'toplevel_page_translatewp' );
	}
}
