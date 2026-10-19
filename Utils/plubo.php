<?php

if ( ! function_exists( 'translatewp_log_info' ) ) {
    function translatewp_log_info( $log ) {
        $backtrace = debug_backtrace();
        $backtrace = $backtrace[1] ?? [];
        $class = $backtrace['class'] ?? '';
        $function = $backtrace['function'] ?? '';
        error_log( "\nCLASS: " . $class . "\nFUNCTION:" . $function . "\nLOG:" . print_r( $log, true ) . "\n" );
    }
}

if ( ! function_exists( 'translatewp_asset' ) ) {
    function translatewp_asset( $asset_name ) {
        $manifest = file_get_contents( TRANSLATEWP_ASSETS_PATH . 'manifest.json' );
        $manifest = json_decode( $manifest, true );
        if ( ! isset( $manifest[ $asset_name ] ) ) {
			return $asset_name;
        }
        return TRANSLATEWP_ASSETS_URL . $manifest[ $asset_name ];
    }
}
