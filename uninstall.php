<?php
/**
 * Runs when TranslateWP is deleted from wp-admin.
 *
 * WordPress includes only this file, so the plugin Loader, its hooks and its
 * remote clients are never booted in the delete request (issue 54). The one
 * remote call is the disconnect of a connected site, bounded to five seconds.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

defined( 'TRANSLATEWP_NAME' ) || define( 'TRANSLATEWP_NAME', 'translatewp' );
defined( 'TRANSLATEWP_PATH' ) || define( 'TRANSLATEWP_PATH', __DIR__ . '/' );

// The main plugin file is not loaded in a delete request, so the version the
// service asks every request to name is read from its header.
if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	$translatewp_header = function_exists( 'get_file_data' ) ? get_file_data( TRANSLATEWP_PATH . 'translatewp.php', [ 'version' => 'Version' ] ) : [];
	define( 'TRANSLATEWP_VERSION', (string) ( $translatewp_header['version'] ?? '' ) );
	unset( $translatewp_header );
}

if ( ! file_exists( TRANSLATEWP_PATH . 'vendor/autoload.php' ) ) {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log(
		'TranslateWP uninstall: ' . TRANSLATEWP_PATH . 'vendor/autoload.php is missing,'
		. ' so the plugin data was not removed and connected sites were not disconnected.'
	);
	return;
}

require_once TRANSLATEWP_PATH . 'vendor/autoload.php';

TranslateWP\Includes\Lyfecycle::uninstall();
