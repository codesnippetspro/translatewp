<?php

namespace TranslateWP\Functionality;

/**
 * Every response of the plugin's REST namespace carries per-site, per-user
 * data or mutates state and leaves marked uncacheable: the headers address
 * HTTP caches and the LiteSpeed server, the action the LiteSpeed Cache plugin.
 */
class RestNoCache {

	public const NAMESPACE_PREFIX = '/translatewp/v1';

	public function __construct( $plugin_name = '', $plugin_version = '' ) {
		unset( $plugin_name, $plugin_version );

		add_filter( 'rest_post_dispatch', [ $this, 'mark_uncacheable' ], 10, 3 );
	}

	/**
	 * Stamp a plugin route response with the no-cache headers and signal.
	 *
	 * @param mixed                 $response The response about to be served.
	 * @param mixed                 $server   The REST server.
	 * @param \WP_REST_Request|null $request  The request being answered.
	 * @return mixed The same response, with the no-cache headers on a plugin route.
	 */
	public function mark_uncacheable( $response, $server = null, $request = null ) {
		unset( $server );

		if ( ! self::is_plugin_route( $request ) || ! is_object( $response ) || ! method_exists( $response, 'header' ) ) {
			return $response;
		}

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		do_action( 'litespeed_control_set_nocache', 'translatewp rest' );

		return $response;
	}

	public static function is_plugin_route( $request ): bool {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return false;
		}

		$route = (string) $request->get_route();

		return self::NAMESPACE_PREFIX === $route || 0 === strpos( $route, self::NAMESPACE_PREFIX . '/' );
	}
}
