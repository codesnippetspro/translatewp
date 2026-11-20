<?php

declare(strict_types=1);
use TranslateWP\Components\MutationLock;
require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';
function check_lock( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
wp_test_reset( [] ); $GLOBALS['wp_test_named_lock_result'] = null;
$ran = 0;
check_lock( MutationLock::run( 'fallback-test', static function () use ( &$ran ): void {
	++$ran;
	check_lock( MutationLock::run( 'fallback-test', static function () use ( &$ran ): void { ++$ran; } ), 'An owned option lock can be nested in the same request' );
} ), 'A database without named locks still executes the worker' );
check_lock( 2 === $ran, 'Both protected callbacks execute once' );
$name = 'translatewp_lock_' . md5( 'fallback-test' );
check_lock( false === get_option( $name ), 'The owner releases its option lease' );
update_option( $name, ( time() + 60 ) . ':another-owner', false );
check_lock( ! MutationLock::run( 'fallback-test', static function (): void { check_lock( false, 'A foreign active lease must not run work' ); }, 0 ), 'A concurrent option lease returns busy' );
update_option( $name, '1:expired-owner', false );
check_lock( MutationLock::run( 'fallback-test', static function () use ( &$ran ): void { ++$ran; } ), 'An expired option lease can be recovered atomically' );
$replacement = ( time() + 60 ) . ':replacement-owner';
MutationLock::run( 'fallback-test', static function () use ( $name, $replacement ): void { update_option( $name, $replacement, false ); } );
check_lock( $replacement === get_option( $name ), 'An old owner never deletes a replacement lease' );
delete_option( $name );
$GLOBALS['wp_test_option_insert_fail'] = true;
check_lock( ! MutationLock::run( 'fallback-test', static function (): void { check_lock( false, 'Unavailable storage cannot execute work' ); } ), 'Database write failure remains fail-closed' );
check_lock( true === get_transient( MutationLock::FAILURE_TRANSIENT ), 'A real lock storage failure remains visible' );
unset( $GLOBALS['wp_test_option_insert_fail'], $GLOBALS['wp_test_named_lock_result'] );
echo "BackgroundLockTest: all assertions passed\n";
