<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Functionality\SwitcherSurfaces;

/**
 * Nav-menus meta box (admin-only, loaded by the is_admin loader). Renders
 * the standard add-to-menu form fields so WordPress creates a custom menu
 * item whose URL carries the switcher marker; the frontend
 * wp_nav_menu_objects filter recognizes that URL and renders the switcher
 * placeholder. The URL is the persistent marker because core forces
 * menu-item-object to "custom" for custom items.
 */
class SwitcherMenuMetaBox {

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		add_action( 'admin_init', [ $this, 'register_meta_box' ] );
	}

	public function register_meta_box(): void {
		add_meta_box(
			'twp-languages',
			__( 'TranslateWP', 'translate' ),
			[ $this, 'render_meta_box' ],
			'nav-menus',
			'side',
			'default'
		);
	}

	public function render_meta_box(): void {
		?>
		<div id="twp-languages-box" class="posttypediv">
			<div id="tabs-panel-twp-languages" class="tabs-panel-active">
				<ul id="twp-languages-checklist" class="categorychecklist form-no-clear">
					<li>
						<label class="menu-item-title">
							<input type="checkbox" class="menu-item-checkbox" name="menu-item[-1][menu-item-object-id]" value="-1" />
							<?php esc_html_e( 'Language Switcher', 'translate' ); ?>
						</label>
						<input type="hidden" class="menu-item-type" name="menu-item[-1][menu-item-type]" value="custom" />
						<input type="hidden" class="menu-item-title" name="menu-item[-1][menu-item-title]" value="<?php esc_attr_e( 'Language Switcher', 'translate' ); ?>" />
						<input type="hidden" class="menu-item-url" name="menu-item[-1][menu-item-url]" value="<?php echo esc_attr( SwitcherSurfaces::MENU_ITEM_URL_MARKER ); ?>" />
					</li>
				</ul>
			</div>
			<p class="button-controls wp-clearfix">
				<span class="add-to-menu">
					<input type="submit" class="button submit-add-to-menu right" value="<?php esc_attr_e( 'Add to Menu', 'translate' ); ?>" name="add-twp-languages-menu-item" id="submit-twp-languages" />
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}
}
