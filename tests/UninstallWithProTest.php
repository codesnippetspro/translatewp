<?php
/**
 * Deleting TranslateWP with TranslateWP Pro's constants defined must not fatal, and
 * every remote call made during uninstall must carry a timeout of 5 seconds or less
 * so the "Deleting..." request cannot hang (issue 54).
 *
 * The uninstall runs in a child PHP process so a fatal error is caught as a
 * non-zero exit instead of killing this test.
 */

declare(strict_types=1);

$root = dirname( __DIR__ );

if ( in_array( '--child', $argv, true ) ) {
	require_once __DIR__ . '/uninstall-test-stubs.php';

	// TranslateWP Pro's bootstrap constants (translatewp-pro.php), as if Pro was
	// loaded in the same request.
	define( 'TRANSLATEWP_PRO_NAME', 'translatewp-pro' );
	define( 'TRANSLATEWP_PRO_VERSION', '0.3.38' );
	define( 'TRANSLATEWP_PRO_PATH', '/tmp/wp-content/plugins/translatewp-pro/' );
	define( 'TRANSLATEWP_PRO_URL', 'https://example.com/wp-content/plugins/translatewp-pro/' );
	define( 'TRANSLATEWP_PRO_BASENAME', 'translatewp-pro/translatewp-pro.php' );

	define( 'WP_UNINSTALL_PLUGIN', 'translate/translatewp.php' );

	$GLOBALS['uninstall_test_options'] = [
		'translatewp_advanced' => [ 'delete_on_uninstall' => true ],
		'translatewp_ai'       => [ 'api_key' => 'test-only' ],
		'translatewp_site'     => [ 'site_id' => 'test-only' ],
	];
	$GLOBALS['wpdb']->seed( 'twp_index_item', 600 );
	$GLOBALS['wpdb']->seed( 'translatewp_metric', 20 );
	$GLOBALS['wpdb']->seed( 'lexicon', 5 );

	require $root . '/uninstall.php';

	fwrite(
		STDOUT,
		json_encode(
			[
				'remote' => $GLOBALS['uninstall_test_remote'],
				'rows'   => count( $GLOBALS['wpdb']->post_rows ),
			]
		)
	);
	exit( 0 );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$descriptors = [
	1 => [ 'pipe', 'w' ],
	2 => [ 'pipe', 'w' ],
];
$process     = proc_open( [ PHP_BINARY, '-d', 'display_errors=stderr', __FILE__, '--child' ], $descriptors, $pipes );
$stdout      = stream_get_contents( $pipes[1] );
$stderr      = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
$exit = proc_close( $process );

assert_true( 0 === $exit, "Uninstall with Pro's constants exited with {$exit}: " . trim( $stderr . ' ' . $stdout ) );
assert_true( false === stripos( $stderr . $stdout, 'fatal' ), 'Uninstall with Pro\'s constants raised a fatal: ' . trim( $stderr ) );
assert_true( '' === trim( $stderr ), 'Uninstall with Pro\'s constants wrote errors or warnings: ' . trim( $stderr ) );

$result = json_decode( (string) $stdout, true );
assert_true( is_array( $result ), 'Child did not report a result: ' . trim( (string) $stdout ) );

if ( is_array( $result ) ) {
	assert_true( 0 === (int) $result['rows'], 'Uninstall left ' . (int) $result['rows'] . ' plugin rows behind' );
	foreach ( (array) $result['remote'] as $call ) {
		$timeout = $call['timeout'];
		assert_true(
			is_numeric( $timeout ) && (float) $timeout > 0 && (float) $timeout <= 5,
			sprintf( 'Remote call %s(%s) during uninstall has timeout %s; it must be set and at most 5 seconds', $call['fn'], $call['url'], var_export( $timeout, true ) )
		);
	}
	fwrite( STDOUT, 'Remote calls during uninstall: ' . count( (array) $result['remote'] ) . "\n" );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "UninstallWithProTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "UninstallWithProTest ok\n" );
