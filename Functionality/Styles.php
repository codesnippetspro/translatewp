<?php

namespace TranslateWP\Functionality;

use TranslateWP\Entities\Style;

class Styles {

    protected $plugin_name;
    protected $plugin_version;
    protected $styles_count = 0;

    public function __construct( $plugin_name, $plugin_version ) {
        $this->plugin_name = $plugin_name;
        $this->plugin_version = $plugin_version;

        $this->load_styles();
    }

    protected function load_styles() {
        $styles_dir = TRANSLATEWP_PATH . 'Entities/Styles/';

        if ( ! is_dir( $styles_dir ) ) {
            return;
        }

        $style_files = glob( $styles_dir . '*.php' );

        if ( empty( $style_files ) ) {
            return;
        }

        foreach ( $style_files as $filename ) {
            require_once $filename;

            $class_name = '\\TranslateWP\\Entities\\Styles\\' . basename( $filename, '.php' );

            if ( class_exists( $class_name ) && is_subclass_of( $class_name, Style::class ) ) {
                try {
                    $style_instance = new $class_name( $this->plugin_name, $this->plugin_version );
                    $style_instance->enqueue();
                    ++$this->styles_count;
                } catch ( \Throwable $e ) {
                    // Log error if translatewp_log_info exists, otherwise continue silently.
                    if ( function_exists( 'translatewp_log_info' ) ) {
                        translatewp_log_info( $e );
                    }
                    continue;
                }
            }
        }
    }

    public function get_styles_count() {
        return $this->styles_count;
    }
}
