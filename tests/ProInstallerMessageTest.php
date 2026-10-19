<?php

declare(strict_types=1);

use TranslateWP\Components\ProInstaller;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_msg( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

// --- A failed install names the reason WordPress gave ---
$message = ProInstaller::message_for( 'install_failed', 'Could not create directory.' );
assert_msg( false !== strpos( $message, 'Pro could not be installed.' ), 'The plain message stays first, got: ' . $message );
assert_msg( false !== strpos( $message, 'WordPress reported: Could not create directory.' ), 'The reason WordPress gave follows, got: ' . $message );

// --- Markup in a reason is stripped, and a reason never doubles its full stop ---
$message = ProInstaller::message_for( 'activate_failed', '<strong>Plugin file does not exist</strong>' );
assert_msg( false === strpos( $message, '<' ) && false !== strpos( $message, 'WordPress reported: Plugin file does not exist.' ), 'Markup is stripped and one full stop closes the reason, got: ' . $message );

// --- No reason: the message is unchanged ---
$message = ProInstaller::message_for( 'install_failed' );
assert_msg( 'Pro could not be installed. Try again, or install it from your account.' === $message, 'Without a reason the message is the plain one, got: ' . $message );
assert_msg( ProInstaller::message_for( 'installed' ) === ProInstaller::message_for( 'installed', '' ), 'An empty reason changes nothing' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ProInstallerMessageTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ProInstallerMessageTest: all assertions passed\n";
