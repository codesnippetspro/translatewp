<?php

declare(strict_types=1);

use TranslateWP\Entities\Scripts\Admin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$connected_options = [
	'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'secret-site-token' ],
	'languages' => [ 'list' => [ 'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ] ] ],
];

wp_test_reset( $connected_options );
update_option( 'translatewp_setup', [ 'ended' => true ] );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];

$script = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$data   = $script->context_data();

// The admin screen has no frontend localizer, so the inline script must merge
// into window.TranslateWP rather than replace it and drop the host bridge.
assert_true(
	false !== strpos( $data, 'window.TranslateWP = Object.assign( window.TranslateWP || {}' ),
	'Admin context localizer must merge into window.TranslateWP, got ' . $data
);

foreach ( [ 'home_url', 'site_id', 'nonce', 'remote_api_base', 'translation_base_url', 'export_url' ] as $context_key ) {
	assert_true(
		false !== strpos( $data, '"' . $context_key . '"' ),
		'Admin context must localize ' . $context_key . ', got ' . $data
	);
}

assert_true(
	false !== strpos( $data, 'action=' . \TranslateWP\Components\SiteExport::ACTION ),
	'Admin context must carry the address of the export action, got ' . $data
);

assert_true(
	false !== strpos( $data, '"site_id":"00000000-0000-4000-8000-000000000001"' ),
	'Admin context site_id must carry the registered site id, got ' . $data
);

// Lexicon terms feed the translation fingerprint and are not in the settings
// store, so the admin script must localize them for warm-up parity.
assert_true(
	false !== strpos( $data, '"lexicon_terms"' ),
	'Admin data must localize lexicon_terms for fingerprint parity, got ' . $data
);

$combined = $script->admin_data();
assert_true(
	false !== strpos( $combined, 'window.translatewpPro = ' ),
	'admin_data must still localize window.translatewpPro, got ' . $combined
);
assert_true(
	false !== strpos( $combined, 'window.TranslateWP = Object.assign' ),
	'admin_data must also localize the extraction context, got ' . $combined
);

// The setup wizard offers the same Pro locks, so it carries the same page
// state: an account that includes Pro is never first shown the upgrade.
update_option( 'translatewp_setup', [ 'ended' => false ] );
$setup = new \TranslateWP\Entities\Scripts\Setup( 'translatewp', TRANSLATEWP_VERSION );
$setup_inline = ( new ReflectionProperty( $setup, 'inline_content' ) )->getValue( $setup );
$setup_data   = is_callable( $setup_inline ) ? (string) call_user_func( $setup_inline ) : '';
assert_true(
	false !== strpos( $setup_data, 'window.translatewpPro = ' ) && false !== strpos( $setup_data, '"entitled"' ),
	'the setup script must localize window.translatewpPro, got ' . $setup_data
);

if ( $failures > 0 ) {
	fwrite( STDERR, "AdminScriptContextTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "AdminScriptContextTest: all assertions passed\n";
