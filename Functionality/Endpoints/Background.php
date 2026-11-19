<?php

namespace TranslateWP\Functionality\Endpoints;

use PluboRoutes\Endpoint\PostEndpoint;
use TranslateWP\Components\BackgroundEngine;
use TranslateWP\Components\BackgroundSignature;

class Background extends BaseApiEndpoints {

	private bool $nonce_busy = false;

	public function __construct( $plugin_name = '', $plugin_version = '' ) {
		parent::__construct( $plugin_name, $plugin_version, 'translatewp/v1/background' );
	}

	public function define_endpoints() {
		return [
			new PostEndpoint( $this->namespace, 'step', [ $this, 'step' ], [ $this, 'step_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'admin-step', [ $this, 'admin_step' ], [ $this, 'admin_write_permissions_check' ] ),
		];
	}

	public function step_permissions_check( $request ) {
		$verified = BackgroundSignature::verify( $request );
		$this->nonce_busy = null === $verified;
		if ( true === $verified || $this->nonce_busy ) {
			return true;
		}
		if ( preg_match( '/^v2=[a-f0-9]{64}$/D', (string) $request->get_header( 'X-TranslateWP-Signature' ) ) ) {
			BackgroundSignature::refresh_after_failure();
		}
		return new \WP_Error( 'background_unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
	}

	public function step( $request ): array {
		if ( $this->nonce_busy ) {
			return BackgroundEngine::snapshot( 'busy' );
		}
		$body = json_decode( $request->get_body(), true );
		$pages = [];
		if ( BackgroundEngine::service_reads() && is_array( $body['pages'] ?? null ) ) {
			foreach ( array_slice( $body['pages'], 0, BackgroundEngine::MAX_READ_PAGES ) as $page ) {
				if ( ! is_array( $page ) || ! is_string( $page['url'] ?? null ) || ! is_string( $page['html'] ?? null )
					|| strlen( $page['html'] ) > BackgroundEngine::MAX_PAGE_BYTES ) {
					continue;
				}
				$pages[] = [
					'url' => esc_url_raw( $page['url'] ),
					'status' => absint( $page['status'] ?? 0 ),
					'html' => wp_check_invalid_utf8( $page['html'] ),
				];
			}
		}
		return BackgroundEngine::service_step(
            [
				'pause' => rest_sanitize_boolean( $body['pause'] ?? false ),
				'pages' => $pages,
			]
        );
	}

	public function admin_step( $request ): array {
		unset( $request );
		return BackgroundEngine::step();
	}
}
