<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Components\ProEligibility;

/**
 * TranslateWP's row on the Plugins screens, the site's and the network's.
 * While TranslateWP Pro is installed the row says to delete Pro first, and
 * offers no delete link of its own. WordPress 6.5 and later also refuses the
 * deletion itself; before 6.5, bulk delete is not guarded here.
 */
class PluginRow {

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		if ( defined( 'TRANSLATEWP_BASENAME' ) ) {
			add_filter( 'plugin_action_links_' . TRANSLATEWP_BASENAME, [ $this, 'action_links' ] );
			add_filter( 'network_admin_plugin_action_links_' . TRANSLATEWP_BASENAME, [ $this, 'action_links' ] );
		}
	}

	/**
	 * The row's actions while Pro is installed: the delete action, when
	 * WordPress offers one, is replaced; otherwise the line is added.
	 *
	 * @param mixed $actions The row's actions, keyed by name.
	 */
	public function action_links( $actions ) {
		if ( ! is_array( $actions ) || ! ProEligibility::is_installed() ) {
			return $actions;
		}

		unset( $actions['delete'] );
		$actions['translatewp_delete_pro_first'] = '<span class="translatewp-delete-pro-first">' . esc_html__( 'Delete TranslateWP Pro first', 'translate' ) . '</span>';

		return $actions;
	}
}
