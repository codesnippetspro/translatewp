<?php

namespace TranslateWP\Functionality;

/**
 * Switcher surfaces besides the floating switcher: the [twp-languages]
 * shortcode, the classic widget and the nav-menu item all print the same
 * inert placeholder that the public bundle discovers and portals an inline
 * switcher into. The nav-menus meta box lives in Functionality/Admin.
 */
class SwitcherSurfaces {

	public const PLACEHOLDER = '<span data-twp-switcher class="translatewp-no-translate"></span>';

	public const MENU_ITEM_URL_MARKER = '#translatewp-language-switcher';

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		add_shortcode( 'twp-languages', [ $this, 'render_shortcode' ] );
		add_action( 'widgets_init', [ $this, 'register_widget' ] );
		add_filter( 'wp_nav_menu_objects', [ $this, 'render_menu_item' ], 10, 2 );
	}

	public function render_shortcode( $atts ): string {
		unset( $atts );
		return self::PLACEHOLDER;
	}

	public function register_widget(): void {
		register_widget( SwitcherWidget::class );
	}

	public function render_menu_item( $items, $args ) {
		unset( $args );

		if ( ! is_array( $items ) ) {
			return $items;
		}

		foreach ( $items as $item ) {
			if ( self::MENU_ITEM_URL_MARKER === ( $item->url ?? '' ) ) {
				$item->title = self::PLACEHOLDER;
				$item->url   = '';
			}
		}

		return $items;
	}
}
