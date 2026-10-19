<?php

namespace TranslateWP\Functionality\Endpoints;

use PluboRoutes\Endpoint\GetEndpoint;
use PluboRoutes\Middleware\Cors;
use TranslateWP\Components\TranslationProxySettings;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Components\TranslationUrl;

/**
 * Admin read of the server-side translation store for one URL and language,
 * for the Pro addon and for debugging. Read-only; the back-fill keeps the
 * store fresh.
 */
class TranslationPageMap extends BaseApiEndpoints {

	public function __construct( $plugin_name, $plugin_version ) {
		parent::__construct( $plugin_name, $plugin_version, 'translatewp/v1/translation' );
	}

	public function define_endpoints() {
		$cors = new Cors( '*', [ 'GET', 'OPTIONS' ], [ 'Content-Type', 'Authorization', 'X-Requested-With', 'X-WP-Nonce' ] );

		$page_map = new GetEndpoint(
			$this->namespace,
			'page-map',
			[ $this, 'page_map' ],
			[ $this, 'admin_permissions_check' ]
		);
		$page_map->useMiddleware( $cors );

		return [ $page_map ];
	}

	public function page_map( $request ) {
		$url = TranslationUrl::canonicalize( esc_url_raw( (string) $request->get_param( 'url' ) ) );
		if ( '' === $url ) {
			return new \WP_Error( 'invalid_parameter', 'url is required', [ 'status' => 400 ] );
		}

		$lang = sanitize_text_field( (string) $request->get_param( 'lang' ) );
		if ( '' === $lang || ! TranslationProxySettings::is_configured_language( $lang ) ) {
			return new \WP_Error( 'invalid_parameter', 'lang is not a configured language', [ 'status' => 400 ] );
		}

		$post_id = TranslationStore::resolve_post_id( $url );
		$title   = $post_id > 0 ? \TranslateWP\Components\PlainText::title( $post_id ) : '';

		return [
			'url'          => $url,
			'lang'         => $lang,
			'map'          => TranslationStore::for_url( $url, $lang ),
			'pendingCount' => TranslationStore::pending_count( $url, $lang ),
			'storedAt'     => TranslationStore::stored_at( $url, $lang ),
			'titleState'   => TranslationStore::title_state( $url, $title, $lang, $post_id ),
		];
	}
}
