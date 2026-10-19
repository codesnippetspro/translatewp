<?php

declare(strict_types=1);

use TranslateWP\Components\Options;
use TranslateWP\Functionality\Endpoints\Admin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

$failures = 0;

function assert_ok( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function revision_request( array $data, $revision = null ) {
	return new class( $data, $revision ) {
		private array $data;
		private $revision;

		public function __construct( array $data, $revision ) {
			$this->data     = $data;
			$this->revision = $revision;
		}

		public function get_param( string $key ) {
			if ( 'option' === $key ) {
				return 'translations.context';
			}
			if ( 'data' === $key ) {
				return $this->data;
			}
			if ( 'revision' === $key ) {
				return $this->revision;
			}

			return null;
		}
	};
}

wp_test_reset( [] );
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );

// --- A fresh install reports revision 0 and a save without a revision still works ---
assert_ok( 0 === $admin->settings_revision( null )['revision'], 'A fresh install reports revision 0' );
$first = $admin->update_data( revision_request( [ 'text_context' => 'A tea shop.' ] ) );
assert_ok( is_array( $first ) && ! empty( $first['success'] ) && 1 === $first['revision'], 'A save without a revision succeeds and reports revision 1' );

// --- A save carrying the current revision succeeds and moves it on ---
$second = $admin->update_data( revision_request( [ 'text_context' => 'A tea and coffee shop.' ], 1 ) );
assert_ok( is_array( $second ) && 2 === $second['revision'], 'A save with the current revision succeeds and reports revision 2' );
assert_ok( 'A tea and coffee shop.' === Options::get( 'translations.context.text_context' ), 'The current-revision save is stored' );

// --- A save carrying a stale revision is refused and stores nothing ---
$stale = $admin->update_data( revision_request( [ 'text_context' => 'Stale tab text.' ], 1 ) );
assert_ok( is_wp_error( $stale ) && 'settings_changed' === $stale->get_error_code(), 'A stale revision is refused with settings_changed' );
assert_ok( 409 === (int) ( $stale->get_error_data()['status'] ?? 0 ) && 2 === (int) ( $stale->get_error_data()['revision'] ?? -1 ), 'The refusal carries status 409 and the current revision' );
assert_ok( 'A tea and coffee shop.' === Options::get( 'translations.context.text_context' ), 'A refused save stores nothing' );
assert_ok( 2 === $admin->settings_revision( null )['revision'], 'A refused save does not move the revision' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SettingsRevisionTest: {$failures} failure(s)\n" );
	exit( 1 );
}

echo "SettingsRevisionTest: ok\n";
