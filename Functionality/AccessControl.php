<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\Access;

/**
 * Tells WordPress who holds the capability to manage translations, so the
 * admin menu and any other code that asks current_user_can() agree with the
 * plugin's own checks, and registers the routes of the grant itself.
 */
class AccessControl {

	public function __construct( $plugin_name, $plugin_version ) {
		add_filter( 'user_has_cap', [ Access::class, 'filter_user_caps' ], 10, 4 );

		// The routes of the grant a site admin makes in Settings.
		new Endpoints\AccessGrants( $plugin_name, $plugin_version );
	}
}
