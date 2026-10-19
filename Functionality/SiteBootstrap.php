<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\Sites;

class SiteBootstrap {

    protected $plugin_name;
    protected $plugin_version;

    public function __construct( $plugin_name, $plugin_version ) {
        $this->plugin_name    = $plugin_name;
        $this->plugin_version = $plugin_version;

        add_action( 'init', [ Sites::class, 'bootstrap_existing_install' ] );
        add_action( 'init', [ Sites::class, 'detect_home_url_drift' ] );

        // Re-validate before the admin renders any notice, so a wrongly stored
        // rejection is repaired on the next page load with no user action and a
        // genuine revoke is confirmed against the service, never a local guess.
        add_action( 'admin_init', [ SiteConnection::class, 'verify_connection' ] );

        add_action( 'admin_init', [ SiteConnection::class, 'handle_connect_link' ] );
        \TranslateWP\Components\UrlScheme::register();
    }
}
