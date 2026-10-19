<?php

declare(strict_types=1);

// At the 1.0 update, a Free site that was translating image alt text is told
// once that alt text translation is now part of Pro. A fresh install, a site
// that had it off and a site with Pro are told nothing.

use TranslateWP\Components\AltTextNotice;
use TranslateWP\Components\Migrations;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '1.0.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function seed( string $from, ?bool $alt ): void {
	$settings = null === $alt ? [] : [ 'translate_image_alt_tags' => $alt ];
	wp_test_reset( [ 'translations' => [ 'settings' => $settings ] ] );
	$GLOBALS['wp_test_caps']     = [ 'manage_options' ];
	$GLOBALS['wp_test_is_admin'] = true;
	if ( '' !== $from ) {
		update_option( Migrations::VERSION_OPTION, $from );
	}
}

function notice(): string {
	ob_start();
	AltTextNotice::render();
	return (string) ob_get_clean();
}

// --- an existing Free site that was translating alt text is told at the update ---
foreach ( [ 'on' => true, 'never changed (on by default)' => null ] as $what => $alt ) {
	seed( '0.10.21', $alt );
	Migrations::maybe_run();
	$html = notice();
	assert_true( false !== strpos( $html, 'alt text' ) && false !== strpos( $html, 'Pro' ), "a site with alt text {$what} is told it is now part of Pro, got " . $html );
	assert_true( false !== strpos( $html, 'data-translatewp-dismiss="' . AltTextNotice::DISMISS_ACTION . '"' ), 'the notice can be dismissed' );
	assert_true( false === strpos( $html, '—' ), 'no em dash in product text' );
}

// --- dismissed once, it is gone for everyone and stays gone ---
seed( '0.10.21', true );
Migrations::maybe_run();
AltTextNotice::dismiss();
assert_true( '' === notice(), 'a dismissed notice is not shown again' );
Migrations::maybe_run();
assert_true( '' === notice(), 'a later load does not bring it back' );

// --- sites with nothing to be told ---
seed( '0.10.21', false );
Migrations::maybe_run();
assert_true( '' === notice(), 'a site that had alt text off is told nothing' );

seed( '', true );
Migrations::seed_for_fresh_install();
Migrations::maybe_run();
assert_true( '' === notice(), 'a fresh install is told nothing' );

seed( '1.0.0', true );
Migrations::maybe_run();
assert_true( '' === notice(), 'a site already on 1.0 is told nothing' );

// --- an update to a build below 1.0 marks nothing ---
seed( '0.10.21', true );
AltTextNotice::flag_if_affected( '0.10.22' );
assert_true( '' === notice(), 'the notice belongs to the 1.0 update, not to an earlier build' );
AltTextNotice::flag_if_affected( '1.0.0' );
assert_true( '' !== notice(), 'the 1.0 update marks the site' );

// --- only someone who manages the site sees it ---
seed( '0.10.21', true );
Migrations::maybe_run();
$GLOBALS['wp_test_caps'] = [];
assert_true( '' === notice(), 'a user who cannot manage the site sees nothing' );

if ( $failures > 0 ) {
	fwrite( STDERR, "AltTextUpdateNoticeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "AltTextUpdateNoticeTest: all assertions passed\n";
