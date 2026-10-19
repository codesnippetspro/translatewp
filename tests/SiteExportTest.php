<?php

declare(strict_types=1);

// The export an administrator runs from wp-admin: the plugin asks the service
// for the site's export file with the site's own credentials, checks that a
// whole file arrived, and hands it over under the name the service gave it.
// Every plan may export; nothing here asks about Pro.

use TranslateWP\Components\SiteExport;

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

// The fixture of the export contract: the envelope with empty contents.
const FIXTURE = '{"site":{"id":"00000000-0000-4000-8000-000000000001","url":"https://example.com"},"generated_at":"2026-10-07T09:00:00.000Z","format":"translatewp-export","version":"1.0","contents":{}}';
const FIXTURE_DISPOSITION = 'attachment; filename="translatewp-export-example.com-2026-10-07.json"';

function seed( bool $connected = true ): void {
	wp_test_reset( [ 'site' => $connected ? [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ] : [] ] );
}

function answering( int $status, string $body, string $disposition = '', ?array &$seen = null, string $type = 'application/json; charset=utf-8' ): callable {
	return static function ( string $url, array $headers, string $file ) use ( $status, $body, $disposition, &$seen, $type ) {
		$seen = [ 'url' => $url, 'headers' => $headers ];
		file_put_contents( $file, $body );
		return [ 'status' => $status, 'disposition' => $disposition, 'type' => $type ];
	};
}

// The fixture of the export contract, section 7, byte for byte: a byte order
// mark, CRLF line ends, a quoted comma, a value protected from being read as
// a formula, a row with no page and doubled quotes.
const CSV = "\xEF\xBB\xBF"
	. "url,language,source,translation\r\n"
	. "https://example.com/,de,\"Hello, world\",\"Hallo, Welt\"\r\n"
	. "https://example.com/about/,de,Prices from \$12,'-12 % Rabatt\r\n"
	. ",fr,\"He said \"\"hi\"\"\",\"Il a dit \"\"salut\"\"\"\r\n";

// --- the contract's route is called with the site's credentials, and its file is handed over whole ---
seed();
$seen   = null;
$result = SiteExport::fetch( answering( 200, FIXTURE, FIXTURE_DISPOSITION, $seen ) );
assert_true( is_array( $result ), 'a good answer must give a file, got ' . ( is_wp_error( $result ) ? $result->get_error_code() : '' ) );
assert_true( '/v1/site/export' === parse_url( (string) $seen['url'], PHP_URL_PATH ) && null === parse_url( (string) $seen['url'], PHP_URL_QUERY ), 'the export route is called with no parameters, got ' . $seen['url'] );
assert_true( 'Bearer site-token' === ( $seen['headers']['Authorization'] ?? '' ) && isset( $seen['headers']['X-TranslateWP-Version'] ), 'the request carries the site token and the plugin version' );
assert_true( 'translatewp-export-example.com-2026-10-07.json' === ( $result['filename'] ?? '' ), 'the file keeps the name the service gave it, got ' . ( $result['filename'] ?? '' ) );
assert_true( FIXTURE === file_get_contents( $result['file'] ), 'the file holds exactly what the service sent' );
unlink( $result['file'] );

// --- the file is the service's to shape: a CSV is handed over exactly as it came ---
seed();
$unused = null;
$result = SiteExport::fetch( answering( 200, CSV, 'attachment; filename="translatewp-export-example.com-2026-10-09.csv"', $unused, 'text/csv; charset=utf-8' ) );
assert_true( is_array( $result ) && CSV === file_get_contents( $result['file'] ), 'the contract fixture is handed over with identical bytes, got ' . ( is_wp_error( $result ) ? $result->get_error_code() : 'a different file' ) );
assert_true( is_array( $result ) && hash( 'sha256', CSV ) === hash_file( 'sha256', $result['file'] ) && strlen( CSV ) === filesize( $result['file'] ), 'not one byte of the file is added, dropped or re-encoded' );
assert_true( 'translatewp-export-example.com-2026-10-09.csv' === ( $result['filename'] ?? '' ) && 'text/csv; charset=utf-8' === ( $result['type'] ?? '' ), 'the CSV keeps its name and its type, got ' . wp_json_encode( [ $result['filename'] ?? '', $result['type'] ?? '' ] ) );
unlink( $result['file'] );

seed();
$result = SiteExport::fetch( answering( 200, CSV, '', $unused, 'text/csv; charset=utf-8' ) );
assert_true( 1 === preg_match( '/^translatewp-export-example\.com-\d{4}-\d{2}-\d{2}\.csv$/', (string) ( $result['filename'] ?? '' ) ), 'a CSV without a name gets the contract\'s name for this site, got ' . ( $result['filename'] ?? '' ) );
unlink( $result['file'] );

seed();
$result = SiteExport::fetch( answering( 200, '<html>login</html>', 'attachment; filename="export.html"', $unused, 'text/html' ) );
assert_true( is_wp_error( $result ) && 'export_failed' === $result->get_error_code(), 'a page that is not an export file is not handed over' );

// --- a later version with fields this plugin does not know is passed through untouched ---
seed();
$later  = '{"site":{"id":"x","url":"https://Shop.Example.com:8443/blog"},"generated_at":"2026-12-31T23:59:59.000Z","format":"translatewp-export","version":"1.1","contents":{"translations":[{"a":"b"}]},"extra":true}';
$result = SiteExport::fetch( answering( 200, $later ) );
assert_true( is_array( $result ) && $later === file_get_contents( $result['file'] ), 'contents and unknown fields are never read or changed' );
assert_true( 1 === preg_match( '/^translatewp-export-example\.com-\d{4}-\d{2}-\d{2}\.json$/', $result['filename'] ), 'without a name from the service the contract\'s name is built for this site, got ' . $result['filename'] );
unlink( $result['file'] );

// --- a name that is not a plain file name is not trusted ---
seed();
$result = SiteExport::fetch( answering( 200, FIXTURE, 'attachment; filename="../../wp-config.php"' ) );
assert_true( 1 === preg_match( '/^translatewp-export-example\.com-\d{4}-\d{2}-\d{2}\.json$/', $result['filename'] ), 'an unsafe name is replaced by the contract\'s name, got ' . $result['filename'] );
unlink( $result['file'] );

// --- a stream that ended early, or anything that is not the export, is a failure and leaves no file ---
foreach ( [ 'JSON cut short' => substr( FIXTURE, 0, 80 ), 'an empty body' => '' ] as $what => $body ) {
	seed();
	$path   = '';
	$result = SiteExport::fetch(
		static function ( string $url, array $headers, string $file ) use ( $body, &$path ) {
			$path = $file;
			file_put_contents( $file, $body );
			return [ 'status' => 200, 'disposition' => '', 'type' => 'application/json' ];
		}
	);
	assert_true( is_wp_error( $result ) && 'export_failed' === $result->get_error_code(), "{$what} must be reported as a failed export" );
	assert_true( '' !== $path && ! file_exists( $path ), "{$what} must not leave a file behind" );
}

// --- the service's refusals are told apart ---
$cases = [
	'export_unavailable' => [ 503, '{"error":"Export unavailable","code":"export_unavailable"}' ],
	'export_not_offered' => [ 404, 'Not Found' ],
	'site_not_found'     => [ 404, '{"error":"Site not found","code":"site_not_found"}' ],
	'not_connected'      => [ 401, '{"error":"Site authentication required","code":"invalid_site_token"}' ],
	'update_required'    => [ 403, '{"error":"TranslateWP plugin update required","code":"upgrade_required","minimumVersion":"1.0.0","currentVersion":"0.9.0"}' ],
	'export_failed'      => [ 500, 'oops' ],
];
foreach ( $cases as $code => [ $status, $body ] ) {
	seed();
	$result = SiteExport::fetch( answering( $status, $body ) );
	assert_true( is_wp_error( $result ) && $code === $result->get_error_code(), "a {$status} answer must be reported as {$code}, got " . ( is_wp_error( $result ) ? $result->get_error_code() : 'a file' ) );
}

// --- a CSV whose stream ends early is not offered: the bytes that arrived are removed ---
seed();
$path   = '';
$result = SiteExport::fetch(
	static function ( string $url, array $headers, string $file ) use ( &$path ) {
		$path = $file;
		file_put_contents( $file, substr( CSV, 0, 70 ) );
		return new WP_Error( 'http_request_failed', 'cURL error 18: transfer closed with outstanding read data remaining' );
	}
);
assert_true( is_wp_error( $result ) && 'export_failed' === $result->get_error_code(), 'an error after the first byte is a failed export' );
assert_true( '' !== $path && ! file_exists( $path ), 'the part of the file that arrived must not be left behind or offered' );

// --- a transport failure is a failed export ---
seed();
$result = SiteExport::fetch( static fn() => new WP_Error( 'http_request_failed', 'timed out' ) );
assert_true( is_wp_error( $result ) && 'export_failed' === $result->get_error_code(), 'a transport failure must be reported as a failed export' );

// --- a site with no credentials asks nothing ---
seed( false );
$called = false;
$result = SiteExport::fetch( static function () use ( &$called ) { $called = true; return [ 'status' => 200, 'disposition' => '' ]; } );
assert_true( is_wp_error( $result ) && 'not_connected' === $result->get_error_code() && ! $called, 'a site that is not connected must not call the service' );

// --- every refusal has a sentence an administrator can act on, and none mentions Pro ---
foreach ( array_merge( array_keys( $cases ), [ 'anything_else' ] ) as $code ) {
	$message = SiteExport::message( $code );
	assert_true( '' !== $message && false === stripos( $message, 'pro' ), "{$code} needs a plain message, got " . $message );
}

// --- the action address is an admin action carrying a nonce ---
seed();
$url = SiteExport::url();
assert_true( false !== strpos( $url, 'admin-post.php' ) && false !== strpos( $url, 'action=' . SiteExport::ACTION ) && false !== strpos( $url, '_wpnonce=' ), 'the export address must be a nonce-protected admin action, got ' . $url );
assert_true( false === strpos( $url, '&amp;' ), 'the address is handed to a script as data, so it must not be HTML-escaped, got ' . $url );

if ( $failures > 0 ) {
	fwrite( STDERR, "SiteExportTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SiteExportTest: all assertions passed\n";
