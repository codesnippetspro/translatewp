<?php

namespace TranslateWP\Functionality\Endpoints;

use PluboRoutes\Endpoint\GetEndpoint;
use PluboRoutes\Middleware\Cors;
use TranslateWP\Components\SiteUsage;

class Quota extends BaseApiEndpoints {

	public function __construct( $plugin_name, $plugin_version ) {
		parent::__construct( $plugin_name, $plugin_version, 'translatewp/v1/admin' );
	}

	public function define_endpoints() {
		$cors = new Cors( '*', [ 'GET', 'OPTIONS' ], [ 'Content-Type', 'Authorization', 'X-Requested-With', 'X-WP-Nonce' ] );

		$quota = new GetEndpoint(
			$this->namespace,
			'quota',
			[ $this, 'quota' ],
			[ $this, 'admin_permissions_check' ]
		);
		$quota->useMiddleware( $cors );

		return [ $quota ];
	}

	public function quota( $request ) {
		unset( $request );

		return SiteUsage::quota();
	}
}
