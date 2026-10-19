<?php
/**
 * Migrations must run on init, not plugins_loaded: wp_get_nav_menu_items() and
 * get_permalink() need $wp_rewrite, which WordPress creates after plugins_loaded.
 */

declare(strict_types=1);

$loader = file_get_contents( __DIR__ . '/../Includes/Loader.php' );
$failures = 0;

if ( 1 !== preg_match( "/add_action\(\s*'init',\s*\[\s*\\\\TranslateWP\\\\Components\\\\Migrations::class,\s*'maybe_run'\s*\]/", $loader ) ) {
	fwrite( STDERR, "Migrations::maybe_run is not hooked on init\n" );
	$failures++;
}
if ( 1 === preg_match( "/add_action\(\s*'plugins_loaded',\s*\[\s*\\\\TranslateWP\\\\Components\\\\Migrations::class/", $loader ) ) {
	fwrite( STDERR, "Migrations::maybe_run still runs on plugins_loaded, before \$wp_rewrite exists\n" );
	$failures++;
}

if ( $failures ) {
	exit( 1 );
}
echo "MigrationsHookTest passed\n";
