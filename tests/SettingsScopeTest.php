<?php

declare(strict_types=1);

use TranslateWP\Components\MultisiteSettings;
use TranslateWP\Components\SettingsScope;

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

function network_setup( bool $multisite = true ): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_multisite']    = $multisite;
	$GLOBALS['wp_test_current_blog'] = $multisite ? 2 : 1;
	$GLOBALS['wp_test_sites']        = [
		[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
		[ 'blog_id' => 2, 'blogname' => 'Sub A', 'siteurl' => 'https://a.example.com' ],
		[ 'blog_id' => 3, 'blogname' => 'Sub B', 'siteurl' => 'https://b.example.com' ],
	];
}

function fill_blanks( array $defaults, string $option_name, $value ) {
	if ( ! isset( $defaults[ $option_name ] ) || ! is_array( $defaults[ $option_name ] ) || ! is_array( $value ) ) {
		return $value;
	}

	return array_replace_recursive( $defaults[ $option_name ], $value );
}

$languages_network = [
	'list'     => [
		'es' => [ 'locale' => 'es', 'slug' => 'es', 'active' => true, 'default' => true ],
		'it' => [ 'locale' => 'it', 'slug' => 'it', 'active' => true, 'default' => false ],
	],
	'switcher' => [
		'type'              => 'dropdown',
		'show_flag'         => true,
		'native_names'      => true,
		'regional_names'    => 'short',
		'text_color'        => '#111111',
		'floating_position' => [ 'anchor' => 'top-left', 'x' => 10, 'y' => 20 ],
	],
	'seo'      => [
		'url_structure'        => 'clean',
		'output_hreflang'      => true,
		'remember_last_choice' => true,
		'cookie_ttl'           => 30,
		'ai_crawlers'          => [ 'preset_answer_engines' => true, 'bots' => [ 'GPTBot' => [ 'default' => 'deny' ] ] ],
	],
];
$translations_network = [
	'settings' => [
		'translate_all_content'    => true,
		'post_types'               => [ 'post', 'page' ],
		'auto_translate'           => true,
		'preserve_html_attributes' => true,
	],
	'context'  => [ 'text_context' => 'A network context.' ],
	'style'    => [ 'type' => 'marketing', 'tone' => 'friendly', 'formality' => 5 ],
];

$blog_fixtures = [
	'translatewp_languages'    => [
		[],
		[ 'list' => [ 'fr' => [ 'locale' => 'fr', 'slug' => 'fr', 'active' => true, 'default' => true ] ] ],
		[
			'switcher' => [ 'text_color' => '#222222', 'show_flag' => false, 'floating_position' => [ 'anchor' => 'bottom-right', 'x' => 1, 'y' => 2 ] ],
			'seo'      => [ 'cookie_ttl' => 7, 'ai_crawlers' => [ 'bots' => [ 'CCBot' => [ 'default' => 'allow' ] ] ] ],
		],
		[ 'switcher' => [ 'type' => 'native_select', 'custom_key' => 'kept' ], 'extra' => [ 1, 2, 3 ] ],
		[ 'list' => [], 'switcher' => [], 'seo' => [] ],
	],
	'translatewp_translations' => [
		[],
		[ 'settings' => [ 'post_types' => [ 'product' ], 'auto_translate' => false ] ],
		[ 'context' => [ 'text_context' => 'Own context.' ], 'style' => [ 'tone' => 'formal' ] ],
		[ 'settings' => [ 'categories' => [ '4', '9' ] ], 'unknown' => 'value' ],
	],
];

// --- the scope map: one entry per group, each with its option parts, tier and whether the network may switch it ---
$tiers = [ 'network_only', 'network_delegable', 'network_default', 'subsite' ];
foreach ( SettingsScope::groups() as $group_id => $group ) {
	assert_true( in_array( $group['tier'] ?? '', $tiers, true ), $group_id . ' has a known tier' );
	assert_true( is_bool( $group['switchable'] ?? null ), $group_id . ' says whether the network may switch it' );
	assert_true( ! empty( $group['parts'] ) && is_array( $group['parts'] ), $group_id . ' names the option parts it covers' );
	if ( in_array( $group['tier'], [ 'network_only', 'subsite' ], true ) ) {
		assert_true( false === $group['switchable'], $group_id . ' in tier ' . $group['tier'] . ' is not switchable' );
	} else {
		assert_true( true === $group['switchable'], $group_id . ' in tier ' . $group['tier'] . ' is switchable' );
	}
}

$expected_groups = [
	[ 'translatewp_languages', 'list', null, 'target_languages' ],
	[ 'translatewp_languages', 'switcher', 'type', 'switcher_appearance' ],
	[ 'translatewp_languages', 'switcher', 'regional_names', 'switcher_appearance' ],
	[ 'translatewp_languages', 'switcher', 'floating_position', 'switcher_placement' ],
	[ 'translatewp_languages', 'switcher', 'inline_placement', 'switcher_placement' ],
	[ 'translatewp_languages', 'seo', 'output_hreflang', 'seo_technical' ],
	[ 'translatewp_languages', 'seo', 'url_structure', 'seo_technical' ],
	[ 'translatewp_languages', 'seo', 'ai_crawlers', 'ai_crawlers' ],
	[ 'translatewp_languages', 'seo', 'remember_last_choice', 'visitor_language' ],
	[ 'translatewp_languages', 'seo', 'cookie_ttl', 'visitor_language' ],
	[ 'translatewp_languages', 'seo', 'redirect_after_switch', 'visitor_language' ],
	[ 'translatewp_translations', 'settings', 'auto_translate', 'visitor_language' ],
	[ 'translatewp_translations', 'settings', 'post_types', 'content_selection' ],
	[ 'translatewp_translations', 'settings', 'categories', 'content_selection' ],
	[ 'translatewp_translations', 'settings', 'translate_all_content', 'content_selection' ],
	[ 'translatewp_translations', 'settings', 'preserve_html_attributes', 'translation_behaviour' ],
	[ 'translatewp_translations', 'settings', 'translate_comments', 'content_areas' ],
	[ 'translatewp_translations', 'settings', 'translate_menus', 'content_areas' ],
	[ 'translatewp_translations', 'settings', 'translate_widgets', 'content_areas' ],
	[ 'translatewp_access', null, null, 'role_permissions' ],
	[ 'translatewp_translations', 'context', null, 'ai_context' ],
	[ 'translatewp_translations', 'style', null, 'style_tone' ],
	[ 'translatewp_advanced', 'delete_on_uninstall', null, 'uninstall' ],
	[ 'translatewp_site', null, null, 'account' ],
];
foreach ( $expected_groups as [ $option, $path, $key, $group_id ] ) {
	$found = SettingsScope::group_for( $option, $path, $key );
	assert_true( $group_id === $found, $option . ' ' . $path . ' ' . $key . ' belongs to ' . $group_id . ', got ' . wp_json_encode( $found ) );
}
$expected_tiers = [
	'account'               => 'network_only',
	'uninstall'             => 'network_only',
	'ai_crawlers'           => 'network_delegable',
	'switcher_appearance'   => 'network_default',
	'seo_technical'         => 'network_default',
	'translation_behaviour' => 'network_default',
	'visitor_language'      => 'network_default',
	'style_tone'            => 'network_default',
	'content_areas'         => 'network_default',
	'role_permissions'      => 'network_default',
	'target_languages'      => 'subsite',
	'content_selection'     => 'subsite',
	'ai_context'            => 'subsite',
	'switcher_placement'    => 'subsite',
];
foreach ( $expected_tiers as $group_id => $tier ) {
	assert_true( $tier === ( SettingsScope::groups()[ $group_id ]['tier'] ?? null ), $group_id . ' is tier ' . $tier );
}

// --- a single site returns its own value, editable, whatever a network record holds ---
network_setup( false );
$GLOBALS['wp_test_site_options']['translatewp_network_settings_defaults'] = [ 'translatewp_languages' => $languages_network ];
$own = [ 'switcher' => [ 'text_color' => '#222222', 'floating_position' => [ 'anchor' => 'top-left', 'x' => 0, 'y' => 0 ] ] ];
$GLOBALS['wp_test_options_store']['languages'] = $own;
assert_true( $own === SettingsScope::resolve( 'translatewp_languages', $own ), 'a single site reads its own value' );
$read = SettingsScope::resolve_group( 'switcher_appearance' );
assert_true( [ 'text_color' => '#222222' ] === $read['value'] && true === $read['editable'] && 'site' === $read['source'], 'a single site reads a group as its own and editable, got ' . wp_json_encode( $read ) );

// --- with an empty network record the output is the blog value, exactly as today ---
foreach ( $blog_fixtures as $option_name => $fixtures ) {
	foreach ( $fixtures as $index => $fixture ) {
		network_setup();
		$today = fill_blanks( [], $option_name, $fixture );
		assert_true( wp_json_encode( $today ) === wp_json_encode( SettingsScope::resolve( $option_name, $fixture ) ), 'empty record: ' . $option_name . ' fixture ' . $index . ' matches today' );
		assert_true( wp_json_encode( $today ) === wp_json_encode( MultisiteSettings::merge_network_defaults( $fixture, $option_name ) ), 'empty record through the option filter: ' . $option_name . ' fixture ' . $index . ' matches today' );
	}
}

// --- with today's flat defaults record the output equals today's fill-blanks merge, byte for byte ---
$flat = [
	'translatewp_languages'    => $languages_network,
	'translatewp_translations' => $translations_network,
];
foreach ( $blog_fixtures as $option_name => $fixtures ) {
	foreach ( $fixtures as $index => $fixture ) {
		network_setup();
		$GLOBALS['wp_test_site_options']['translatewp_network_settings_defaults'] = $flat;
		$today = fill_blanks( $flat, $option_name, $fixture );
		$now   = MultisiteSettings::merge_network_defaults( $fixture, $option_name );
		assert_true( wp_json_encode( $today ) === wp_json_encode( $now ), 'flat record: ' . $option_name . ' fixture ' . $index . ' matches today, got ' . wp_json_encode( $now ) . ' expected ' . wp_json_encode( $today ) );
	}
}

// --- values that are not arrays pass through as today ---
network_setup();
$GLOBALS['wp_test_site_options']['translatewp_network_settings_defaults'] = $flat;
assert_true( false === MultisiteSettings::merge_network_defaults( false, 'translatewp_languages' ), 'a missing option stays false' );
assert_true( 'raw' === MultisiteSettings::merge_network_defaults( 'raw', 'translatewp_translations' ), 'a scalar option passes through' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SettingsScopeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SettingsScopeTest: all assertions passed\n";
