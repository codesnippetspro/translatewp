<?php

declare(strict_types=1);

use TranslateWP\Components\Options;
use TranslateWP\Components\SiteConnection;

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

SiteConnection::complete_connection(
	'00000000-0000-4000-8000-000000000001',
	'site-token',
	'owner@example.com'
);
assert_true(
	'owner@example.com' === Options::get( 'site.account_label', '' ),
	'Exchange with accountLabel must store the label in the site option'
);

wp_test_reset( [] );
SiteConnection::complete_connection(
	'00000000-0000-4000-8000-000000000001',
	'site-token'
);
assert_true(
	'' === Options::get( 'site.account_label', '' ),
	'Exchange without accountLabel must not store a label'
);

wp_test_reset( [] );
SiteConnection::complete_connection(
	'00000000-0000-4000-8000-000000000001',
	'site-token',
	'<script>alert(1)</script>'
);
$stored_label = Options::get( 'site.account_label', '' );
assert_true(
	'alert(1)' === $stored_label,
	'Label with HTML tags must have tags stripped at the persisted boundary'
);

wp_test_reset( [] );
SiteConnection::complete_connection(
	'00000000-0000-4000-8000-000000000001',
	'site-token',
	'  <b>owner@example.com</b>  '
);
assert_true(
	'owner@example.com' === Options::get( 'site.account_label', '' ),
	'Label with HTML and whitespace must be sanitized to plain text at the persisted boundary'
);

$payload = SiteConnection::status_payload();
assert_true(
	is_array( $payload ) && array_key_exists( 'accountLabel', $payload ),
	'status_payload must include accountLabel field'
);
assert_true(
	'owner@example.com' === $payload['accountLabel'],
	'status_payload must return the sanitized stored label'
);
assert_true(
	'https://app.translatewp.ai/sites/00000000-0000-4000-8000-000000000001' === ( $payload['portalSiteUrl'] ?? '' ),
	'Connected status must provide the portal URL for this specific site'
);

wp_test_reset( [] );
SiteConnection::complete_connection(
	'00000000-0000-4000-8000-000000000001',
	'site-token',
	'owner@example.com'
);
SiteConnection::disconnect();
assert_true(
	'' === Options::get( 'site.account_label', '' ),
	'Disconnect must clear account_label atomically with the tuple'
);

if ( $failures > 0 ) {
	fwrite( STDERR, "SiteAccountLabelTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "SiteAccountLabelTest ok\n" );
