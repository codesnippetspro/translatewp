<?php

namespace TranslateWP\Functionality;

use PluboRoutes\Route\ActionRoute;
use TranslateWP\Components\SiteConnection;

class Connect {

	protected $plugin_name;
	protected $plugin_version;

	public function __construct( $plugin_name, $plugin_version ) {
		$this->plugin_name    = $plugin_name;
		$this->plugin_version = $plugin_version;

		add_filter( 'plubo/routes', [ $this, 'register_routes' ] );
		add_action( 'admin_notices', [ SiteConnection::class, 'render_admin_notices' ] );
		add_filter( 'removable_query_args', [ $this, 'removable_query_args' ] );
	}

	public function removable_query_args( $args ) {
		$args   = is_array( $args ) ? $args : [];
		$args[] = SiteConnection::NOTICE_QUERY_ARG;

		return $args;
	}

	public function register_routes( $routes ) {
		if ( ! is_array( $routes ) ) {
			$routes = [];
		}

		$routes[] = new ActionRoute(
			SiteConnection::RETURN_ROUTE_PATH,
			[ SiteConnection::class, 'handle_return' ],
			[
				'name'         => 'translatewp-connect-return',
				'guest'        => false,
				'logged_in'    => true,
				'allowed_caps' => [ 'manage_options' ],
			]
		);

		$routes[] = new ActionRoute(
			SiteConnection::NETWORK_RETURN_ROUTE_PATH,
			[ SiteConnection::class, 'handle_network_return' ],
			[
				'name'         => 'translatewp-connect-network-return',
				'guest'        => false,
				'logged_in'    => true,
				'allowed_caps' => [ 'manage_network_options' ],
			]
		);

		return $routes;
	}
}
