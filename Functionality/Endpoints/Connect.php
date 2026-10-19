<?php

namespace TranslateWP\Functionality\Endpoints;

use PluboRoutes\Endpoint\GetEndpoint;
use PluboRoutes\Endpoint\PostEndpoint;
use PluboRoutes\Middleware\Cors;
use TranslateWP\Components\ProInstaller;
use TranslateWP\Components\SettingsScope;
use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\Sites;
use TranslateWP\Components\SiteRequests;

class Connect extends BaseApiEndpoints {

	public function __construct( $plugin_name, $plugin_version ) {
		parent::__construct( $plugin_name, $plugin_version, 'translatewp/v1/connect' );
	}

	public function define_endpoints() {
		$cors = new Cors( '*', [ 'GET', 'POST', 'OPTIONS' ], [ 'Content-Type', 'Authorization', 'X-Requested-With' ] );

		$status = new GetEndpoint(
			$this->namespace,
			'status',
			[ $this, 'status' ],
			[ $this, 'admin_permissions_check' ]
		);
		$status->useMiddleware( $cors );

		$begin = new PostEndpoint(
			$this->namespace,
			'begin',
			[ $this, 'begin' ],
			[ $this, 'admin_write_permissions_check' ]
		);
		$begin->useMiddleware( $cors );

		$disconnect = new PostEndpoint(
			$this->namespace,
			'disconnect',
			[ $this, 'disconnect' ],
			[ $this, 'admin_write_permissions_check' ]
		);
		$disconnect->useMiddleware( $cors );

		$pro_install = new PostEndpoint(
			$this->namespace,
			'pro-install',
			[ $this, 'pro_install' ],
			[ $this, 'admin_write_permissions_check' ]
		);
		$pro_install->useMiddleware( $cors );

		$pro_activate = new PostEndpoint(
			$this->namespace,
			'pro-activate',
			[ $this, 'pro_activate' ],
			[ $this, 'admin_write_permissions_check' ]
		);
		$pro_activate->useMiddleware( $cors );

		// The service asks the address named in a reconnect claim to
		// prove it runs the site; the answer is signed with the credentials the
		// plugin holds.
		$challenge = new GetEndpoint(
			$this->namespace,
			'challenge',
			[ $this, 'challenge' ],
			[ $this, 'challenge_permissions_check' ]
		);
		$challenge->useMiddleware( $cors );

		return [
			$status,
			$begin,
			$disconnect,
			$pro_install,
			$pro_activate,
			$challenge,
		];
	}

	/** The challenge is fetched by the service without a WordPress session. */
	public function challenge_permissions_check(): bool {
		return true;
	}

	/**
	 * Answer a reconnect challenge for this site: the nonce and the site id
	 * signed with the site's credential key. Unknown or unconnected sites
	 * answer 404.
	 */
	public function challenge( $request ) {
		$nonce   = (string) $request->get_param( 'nonce' );
		$site_id = sanitize_text_field( (string) $request->get_param( 'site' ) );

		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $nonce ) || '' === $site_id ) {
			return new \WP_Error( 'invalid_parameter', 'A nonce and a site id are required', [ 'status' => 400 ] );
		}

		$answer = Sites::challenge_answer( $site_id, $nonce );
		if ( null === $answer ) {
			return new \WP_Error( 'unknown_site', 'No such site here', [ 'status' => 404 ] );
		}

		return [
			'siteId' => $site_id,
			'answer' => $answer,
		];
	}

	public function pro_install( $request ) {
		unset( $request );

		return ProInstaller::install_for_site();
	}

	public function pro_activate( $request ) {
		unset( $request );

		return ProInstaller::activate_for_site();
	}

	public function status( $request ) {
		// A Pro prompt asks for the account's current plan, not the cached one.
		if ( rest_sanitize_boolean( $request->get_param( 'refresh' ) ) && SiteConnection::is_connected() ) {
			ProEligibility::refresh();
		}

		return SiteConnection::status_payload();
	}

	public function begin( $request ) {
		$locked = SettingsScope::refusal_for_group( 'account' );
		if ( null !== $locked ) {
			return $locked;
		}

		$with_claim = rest_sanitize_boolean( $request->get_param( 'reconnect' ) );
		$result     = SiteConnection::begin_connect( $with_claim );
		if ( empty( $result['ok'] ) ) {
			return new \WP_Error(
				sanitize_key( (string) ( $result['error'] ?? 'connect_unavailable' ) ),
				'Connection could not be started',
				[ 'status' => 503 ]
			);
		}

		return [
			'redirectUrl' => (string) $result['redirect_url'],
		];
	}

	public function disconnect( $request ) {
		unset( $request );
		$locked = SettingsScope::refusal_for_group( 'account' );
		if ( null !== $locked ) {
			return $locked;
		}
		$remote = SiteRequests::post( '/v1/site/disconnect', [] );

		if ( is_wp_error( $remote ) ) {
			$status = (int) ( $remote->get_error_data()['status'] ?? 0 );
			if ( 401 !== $status ) {
				return new \WP_Error(
					'disconnect_failed',
					__( 'Disconnect failed. Try again or disconnect from your account portal.', 'translate' ),
					[ 'status' => 502 ]
				);
			}
		}

		SiteConnection::disconnect();

		return [ 'success' => true ];
	}
}
