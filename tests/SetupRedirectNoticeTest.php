<?php

declare(strict_types=1);

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function run_setup_redirect_fixture( string $page ): array {
	$result_path = tempnam( sys_get_temp_dir(), 'twp-setup-redirect-' );
	$command     = sprintf(
		'%s %s %s %s 2>/dev/null',
		escapeshellarg( PHP_BINARY ),
		escapeshellarg( __DIR__ . '/fixtures/setup-redirect-runner.php' ),
		escapeshellarg( $result_path ),
		escapeshellarg( $page )
	);
	exec( $command, $output, $exit_code );
	unset( $output, $exit_code );
	$result = json_decode( (string) file_get_contents( $result_path ), true );
	unlink( $result_path );
	return is_array( $result ) ? $result : [];
}

// --- A plugin page requested during the wizard redirects to the setup with the notice ---
$redirect = (string) ( run_setup_redirect_fixture( 'translatewp-settings' )['redirects'][0] ?? '' );
assert_true( false !== strpos( $redirect, 'page=translatewp' ), 'A plugin page during the wizard must redirect to the setup, got ' . $redirect );
assert_true( false !== strpos( $redirect, 'translatewp_notice=finish-setup' ), 'The setup redirect must carry the finish-setup notice, got ' . $redirect );

// --- Any other admin page is left alone ---
$other = run_setup_redirect_fixture( 'options-general' )['redirects'] ?? [];
assert_true( [] === $other, 'A page outside the plugin must not be redirected' );

// --- The setup screen renders the notice when the marker is present ---
$menus_source = (string) file_get_contents( dirname( __DIR__ ) . '/Functionality/Admin/Menus.php' );
assert_true( false !== strpos( $menus_source, 'Finish the setup first.' ), 'The setup screen must carry the finish-setup notice copy' );
assert_true( false !== strpos( $menus_source, "add_action( 'admin_menu', [ \$this, 'redirect_to_setup' ], 1 )" ), 'The setup redirect must run on admin_menu before the page check' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SetupRedirectNoticeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SetupRedirectNoticeTest: all assertions passed\n";
