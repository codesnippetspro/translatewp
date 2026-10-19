<?php

declare(strict_types=1);

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

function run_return( array $scenario = [] ): array {
	$result_path   = tempnam( sys_get_temp_dir(), 'twp-onboarding-return-' );
	$scenario_path = tempnam( sys_get_temp_dir(), 'twp-onboarding-scenario-' );
	file_put_contents( $scenario_path, wp_json_encode( $scenario ) );
	$command = sprintf(
		'%s %s %s %s 2>/dev/null',
		escapeshellarg( PHP_BINARY ),
		escapeshellarg( __DIR__ . '/fixtures/onboarding-return-runner.php' ),
		escapeshellarg( $result_path ),
		escapeshellarg( $scenario_path )
	);
	exec( $command, $output, $exit_code );
	unset( $output, $exit_code );
	$result = json_decode( (string) file_get_contents( $result_path ), true );
	unlink( $result_path );
	unlink( $scenario_path );

	return is_array( $result ) ? $result : [];
}

$set_up_languages = [
	'list' => [
		'en-us' => [ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ],
		'de'    => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
	],
];
$returned_site_id = '00000000-0000-4000-8000-' . substr( md5( 'example.com' ), 0, 12 );

// --- A first connection during setup resumes the wizard at the Read site step ---
$result   = run_return();
$redirect = (string) ( $result['redirects'][0] ?? '' );
$html     = (string) ( $result['admin_html'] ?? '' );
assert_true( false !== strpos( $redirect, 'page=translatewp' ), 'Approved connection must return to the TranslateWP admin entry' );
assert_true( false !== strpos( $html, 'id="translatewp-setup"' ), 'Approved onboarding connection must render the setup wizard, not the dashboard' );
assert_true( false !== strpos( $html, '"step":"read-site"' ), 'Approved onboarding connection must resume at the Read site step, got ' . $html );

// --- A returning site left marked as set up, now a different Site with nothing read: wizard at Read site ---
$result = run_return(
	[
		'options_store' => [
			'setup'     => [ 'ended' => true, 'step' => 'done' ],
			'languages' => $set_up_languages,
			'site'      => [ 'id' => '00000000-0000-4000-8000-0000000000aa', 'token' => '', 'home_url' => 'https://example.com' ],
		],
	]
);
$html = (string) ( $result['admin_html'] ?? '' );
assert_true( false !== strpos( $html, 'id="translatewp-setup"' ), 'A new Site on a site left marked as set up must open the setup wizard, got ' . $html );
assert_true( false !== strpos( $html, '"step":"read-site"' ), 'A new Site on a site left marked as set up must resume at the Read site step' );
assert_true( false !== strpos( $html, '"languages":["en-us","de"]' ), 'The resumed wizard must be prefilled with the saved languages, got ' . $html );

// --- The same Site reconnecting with an empty index also reads the site again ---
$result = run_return(
	[
		'options_store' => [
			'setup'     => [ 'ended' => true, 'step' => 'done' ],
			'languages' => $set_up_languages,
			'site'      => [ 'id' => $returned_site_id, 'token' => '', 'home_url' => 'https://example.com' ],
		],
	]
);
assert_true( false !== strpos( (string) ( $result['admin_html'] ?? '' ), '"step":"read-site"' ), 'A reconnect with nothing read must resume at the Read site step' );

// --- An already-read site reconnecting as the same Site keeps its dashboard ---
$result = run_return(
	[
		'options_store' => [
			'setup'     => [ 'ended' => true, 'step' => 'done' ],
			'languages' => $set_up_languages,
			'site'      => [ 'id' => $returned_site_id, 'token' => '', 'home_url' => 'https://example.com' ],
		],
		'read_pages'    => 2,
	]
);
$html = (string) ( $result['admin_html'] ?? '' );
assert_true( false !== strpos( $html, 'id="translatewp-admin"' ), 'An already-read site reconnecting as the same Site must not reopen the wizard, got ' . $html );
assert_true( true === ( $result['options_store']['setup']['ended'] ?? null ), 'An already-read site keeps its finished setup' );

if ( $failures > 0 ) {
	fwrite( STDERR, "OnboardingReturnTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

fwrite( STDOUT, "OnboardingReturnTest ok\n" );
