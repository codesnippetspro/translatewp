<?php

namespace TranslateWP\Includes;

class Loader {

    public function __construct() {
        $this->load_dependencies();

        do_action( 'translatewp_loaded' );

        add_action( 'plugins_loaded', [ $this, 'load_plugin_textdomain' ] );
        // Migrations touch menus and permalinks, which need $wp_rewrite; it does not exist before init.
        add_action( 'init', [ \TranslateWP\Components\Migrations::class, 'maybe_run' ], 5 );
        add_action( 'after_setup_theme', [ $this, 'load_plubo_routes' ] );
        add_action( 'init', [ Lyfecycle::class, 'maybe_flush_rewrite_rules' ], 20 );
        add_action( \TranslateWP\Components\SiteLanguageSync::RETRY_HOOK, [ \TranslateWP\Components\SiteLanguageSync::class, 'retry_sync' ] );
        add_filter( 'rest_request_after_callbacks', [ $this, 'unwrap_error_response' ], 10, 3 );
    }

    private function load_dependencies() {
        // FUNCTIONALITY CLASSES.
        foreach ( glob( TRANSLATEWP_PATH . 'Functionality/*.php' ) as $filename ) {
            $class_name = '\\TranslateWP\Functionality\\' . basename( $filename, '.php' );
            // Widgets are registered on widgets_init by SwitcherSurfaces; constructing
            // one here would translate its title before init.
            if ( class_exists( $class_name ) && ! is_subclass_of( $class_name, '\\WP_Widget' ) ) {
                try {
                    new $class_name( TRANSLATEWP_NAME, TRANSLATEWP_VERSION );
                } catch ( \Throwable $e ) {
                    translatewp_log_info( $e );
                    continue;
                }
            }
        }

        // ADMIN FUNCTIONALITY.
        if ( is_admin() ) {
            foreach ( glob( TRANSLATEWP_PATH . 'Functionality/Admin/*.php' ) as $filename ) {
                $class_name = '\\TranslateWP\Functionality\Admin\\' . basename( $filename, '.php' );
                if ( class_exists( $class_name ) ) {
                    try {
                        new $class_name( TRANSLATEWP_NAME, TRANSLATEWP_VERSION );
                    } catch ( \Throwable $e ) {
                        translatewp_log_info( $e );
                        continue;
                    }
                }
            }
        }
    }

    public function load_plugin_textdomain() {
        load_plugin_textdomain( 'translate', false, dirname( TRANSLATEWP_BASENAME ) . '/languages/' );
    }

    public function load_plubo_routes(): void {
        \PluboRoutes\RoutesProcessor::init();
    }

    public function unwrap_error_response( $response, $server, $request ) {
        if ( ! $request instanceof \WP_REST_Request ) {
            return $response;
        }

        $route = $request->get_route();
        if ( 0 !== strpos( $route, '/translatewp/v1/' ) ) {
            return $response;
        }

        if ( $response instanceof \WP_REST_Response && $response->get_data() instanceof \WP_Error ) {
            return $response->get_data();
        }

        return $response;
    }
}
