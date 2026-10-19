<?php

declare(strict_types=1);

use TranslateWP\Components\MultisiteSettings;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function multisite_test_setup(): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_multisite']    = true;
	$GLOBALS['wp_test_current_blog'] = 1;
	$GLOBALS['wp_test_caps']         = [ 'manage_network_options', 'manage_options' ];
	$GLOBALS['wp_test_sites']        = [
		[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
		[ 'blog_id' => 2, 'blogname' => 'Sub A', 'siteurl' => 'https://site2.example.com' ],
		[ 'blog_id' => 3, 'blogname' => 'Sub B', 'siteurl' => 'https://site3.example.com' ],
	];
}

// --- network defaults cascade into subsite reads ---

multisite_test_setup();
$GLOBALS['wp_test_site_options']['translatewp_network_settings_defaults'] = [
	'translatewp_languages' => [
		'list' => [
			'es' => [ 'locale' => 'es', 'active' => true, 'default' => true ],
		],
	],
];
$GLOBALS['wp_test_options_store']['blog_2_languages'] = [
	'list' => [
		'fr' => [ 'locale' => 'fr', 'active' => true, 'default' => true ],
	],
];

switch_to_blog( 2 );
$merged = MultisiteSettings::merge_network_defaults(
	$GLOBALS['wp_test_options_store']['blog_2_languages'],
	'translatewp_languages'
);
restore_current_blog();

assert_true( isset( $merged['list']['es'] ) && isset( $merged['list']['fr'] ), 'Subsite overrides must merge over network defaults' );

if ( $failures > 0 ) {
	fwrite( STDERR, "MultisiteSettingsTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "MultisiteSettingsTest ok\n" );
