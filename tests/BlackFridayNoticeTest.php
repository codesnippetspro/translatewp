<?php

declare(strict_types=1);

// The Black Friday notice on TranslateWP screens: a TranslateWP line for an
// account without Orbital until 7 December, a Code Snippets line from 23
// November until 1 December on a site without Code Snippets Pro, built like
// the alt text notice and printed last among the TranslateWP notices.
// Run three times: without Code Snippets, with Code Snippets (free) and with
// Code Snippets Pro (--code-snippets-free / --code-snippets-pro).

use TranslateWP\Components\Access;
use TranslateWP\Components\AdminNotices;
use TranslateWP\Components\AltTextNotice;
use TranslateWP\Components\BlackFridayNotice;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( in_array( '--code-snippets-free', $argv, true ) ) {
	define( 'CODE_SNIPPETS_PRO', false );
} elseif ( in_array( '--code-snippets-pro', $argv, true ) ) {
	define( 'CODE_SNIPPETS_PRO', true );
}
$code_snippets_pro = defined( 'CODE_SNIPPETS_PRO' ) && CODE_SNIPPETS_PRO;

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$message}\n";
	}
}

const LEAD         = '<strong>Black Friday</strong>';
const TWP_LINE     = 'Lock in Orbital at $59 a year (instead of $119), or Lifetime at $249. Ends 7 December.';
const TWP_BUTTON   = '<a class="button button-secondary" href="https://translatewp.ai/black-friday/" target="_blank" rel="noopener noreferrer">See the TranslateWP offer</a>';
const CS_LINE      = 'Code Snippets Pro: save up to 40% this Black Friday, until 1 December.';
const CS_BUTTON    = '<a class="button button-secondary" href="https://codesnippets.pro/black-friday/" target="_blank" rel="noopener noreferrer">See the Code Snippets offer</a>';
const NOTICE_SHAPE = '#^<div class="notice notice-info is-dismissible" data-translatewp-dismiss="[a-z_]+" data-nonce="[^"]+"><p><strong>[^<]+</strong> [^<]+(?:<br>[^<]+)*</p><p><a class="button button-secondary" [^>]+>[^<]+</a>(?: <a class="button button-secondary" [^>]+>[^<]+</a>)*</p></div>$#';

function seed( bool $orbital ): void {
	wp_test_reset(
		$orbital
			? [ 'site' => [ 'id' => 'site-1', 'token' => 'token-1', 'connection_status' => 'connected' ] ]
			: []
	);
	if ( $orbital ) {
		set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
	}
	$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
	$_GET                    = [ 'page' => 'translatewp' ];
	$reset                   = new ReflectionProperty( AdminNotices::class, 'action_required_shown' );
	$reset->setAccessible( true );
	$reset->setValue( null, false );
}

function notice( string $at ): string {
	ob_start();
	BlackFridayNotice::render( (int) strtotime( $at ) );
	return (string) ob_get_clean();
}

function expected( bool $twp, bool $cs ): string {
	if ( ! $twp && ! $cs ) {
		return '';
	}
	$lines   = array_merge( $twp ? [ TWP_LINE ] : [], $cs ? [ CS_LINE ] : [] );
	$buttons = array_merge( $twp ? [ TWP_BUTTON ] : [], $cs ? [ CS_BUTTON ] : [] );
	return '<div class="notice notice-info is-dismissible" data-translatewp-dismiss="' . BlackFridayNotice::DISMISS_ACTION . '" data-nonce="nonce-' . BlackFridayNotice::DISMISS_ACTION . '">'
		. '<p>' . LEAD . ' ' . implode( '<br>', $lines ) . '</p><p>' . implode( ' ', $buttons ) . '</p></div>';
}

// --- every account state against every moment ---
$moments = [
	'2026-11-22T23:59:59Z' => [ 'twp' => true, 'cs' => false, 'name' => 'before the Code Snippets offer' ],
	'2026-11-23T00:00:00Z' => [ 'twp' => true, 'cs' => true, 'name' => 'as the Code Snippets offer starts' ],
	'2026-11-27T12:00:00Z' => [ 'twp' => true, 'cs' => true, 'name' => 'on Black Friday' ],
	'2026-11-30T23:59:59Z' => [ 'twp' => true, 'cs' => true, 'name' => 'on the last second of the Code Snippets offer' ],
	'2026-12-01T00:00:00Z' => [ 'twp' => true, 'cs' => false, 'name' => 'once the Code Snippets offer ended' ],
	'2026-12-07T22:58:59Z' => [ 'twp' => true, 'cs' => false, 'name' => 'on the last second of the TranslateWP offer' ],
	'2026-12-07T22:59:00Z' => [ 'twp' => false, 'cs' => false, 'name' => 'once the TranslateWP offer ended' ],
	'2027-01-15T12:00:00Z' => [ 'twp' => false, 'cs' => false, 'name' => 'in January' ],
];
$site = $code_snippets_pro ? 'with Code Snippets Pro' : ( defined( 'CODE_SNIPPETS_PRO' ) ? 'with Code Snippets' : 'without Code Snippets' );
foreach ( [ false, true ] as $orbital ) {
	$account = $orbital ? 'an Orbital account' : 'a Free account';
	foreach ( $moments as $at => $moment ) {
		seed( $orbital );
		$want = expected( $moment['twp'] && ! $orbital, $moment['cs'] && ! $code_snippets_pro );
		$got  = notice( $at );
		assert_true( $want === $got, "{$account} {$site} {$moment['name']}: expected " . ( '' === $want ? 'no notice' : $want ) . ', got ' . $got );
	}
}

// --- the offers stay apart: an Orbital account on Black Friday sees only the Code Snippets offer ---
seed( true );
$orbital_inside = notice( '2026-11-27T12:00:00Z' );
if ( ! $code_snippets_pro ) {
	assert_true( str_contains( $orbital_inside, LEAD . ' ' . CS_LINE . '</p><p>' . CS_BUTTON . '</p>' ), 'Orbital on Black Friday sees the lead, the Code Snippets line and its button, got ' . $orbital_inside );
}
assert_true( ! str_contains( $orbital_inside, 'Orbital at' ) && ! str_contains( $orbital_inside, 'translatewp.ai/black-friday' ), 'Orbital never sees the TranslateWP offer' );

// --- an invalidated connection does not count as Orbital ---
seed( true );
$GLOBALS['wp_test_options_store']['site']['connection_status'] = 'invalidated';
assert_true( str_contains( notice( '2026-11-20T12:00:00Z' ), TWP_LINE ), 'a site whose credentials were invalidated is offered Orbital' );

// --- built like the alt text notice ---
seed( false );
$both = notice( '2026-11-27T12:00:00Z' );
$one  = notice( '2026-11-20T12:00:00Z' );
update_option( AltTextNotice::FLAG_OPTION, 1 );
ob_start();
AltTextNotice::render();
$alt_text = (string) ob_get_clean();
assert_true( 1 === preg_match( NOTICE_SHAPE, $alt_text ), 'the shape check describes the alt text notice, got ' . $alt_text );
assert_true( 1 === preg_match( NOTICE_SHAPE, $one ), 'the notice with one offer is built like the alt text notice, got ' . $one );
if ( ! $code_snippets_pro ) {
	assert_true( 1 === preg_match( NOTICE_SHAPE, $both ), 'the notice with both offers is built like the alt text notice, got ' . $both );
}
assert_true( ! str_contains( $both . $one, '—' ), 'no em dash in the notice' );

// --- dismissed once by this user, gone for good for this user ---
seed( false );
update_user_meta( 1, BlackFridayNotice::DISMISSED_META, 1 );
assert_true( '' === notice( '2026-11-27T12:00:00Z' ), 'a user who closed the notice does not see it again' );
$GLOBALS['wp_test_current_user'] = 2;
assert_true( '' !== notice( '2026-11-27T12:00:00Z' ), 'another user still sees it' );
$GLOBALS['wp_test_current_user'] = 1;

// --- it stays out of the way of a TranslateWP notice that needs action, not of an informational one ---
seed( false );
AdminNotices::open( 'notice-info', AltTextNotice::DISMISS_ACTION );
assert_true( '' !== notice( '2026-11-27T12:00:00Z' ), 'it shows together with an informational TranslateWP notice' );
AdminNotices::open( 'notice-error', 'translatewp_example' );
assert_true( '' === notice( '2026-11-27T12:00:00Z' ), 'it stays hidden while an error notice shows' );
seed( false );
AdminNotices::mark_action_required();
assert_true( '' === notice( '2026-11-27T12:00:00Z' ), 'it stays hidden while a notice that needs action shows' );

// --- TranslateWP screens and people who manage TranslateWP only ---
seed( false );
$_GET = [ 'page' => 'woocommerce' ];
assert_true( '' === notice( '2026-11-27T12:00:00Z' ), 'it is not shown on another plugin screen' );
$_GET = [];
assert_true( '' === notice( '2026-11-27T12:00:00Z' ), 'it is not shown on the WordPress dashboard' );
seed( false );
$_GET = [ 'page' => 'translatewp-support' ];
assert_true( '' !== notice( '2026-11-27T12:00:00Z' ), 'it is shown on every TranslateWP screen' );
$GLOBALS['wp_test_caps'] = [];
assert_true( '' === notice( '2026-11-27T12:00:00Z' ), 'a user who cannot manage TranslateWP does not see it' );

// --- a sales offer: only for those who can buy, not for delegated TranslateWP users ---
function dismiss(): array {
	$GLOBALS['wp_test_json_sent'] = [];
	BlackFridayNotice::handle_ajax_dismiss();
	return $GLOBALS['wp_test_json_sent'][0] ?? [];
}

seed( false );
Access::set_role_map( [ 'edit_translations' => [ 'editor' => true ] ] );
$GLOBALS['wp_test_caps']               = [ 'edit_pages' ];
$GLOBALS['wp_test_current_user_roles'] = [ 'editor' ];
assert_true( Access::can( Access::EDIT ), 'the editor holds the translation-editing grant' );
assert_true( '' === notice( '2026-11-27T12:00:00Z' ), 'an editor with a TranslateWP grant but without manage_options does not see the offer' );
$GLOBALS['wp_test_options_store']['site'] = [ 'id' => 'site-1', 'token' => 'token-1', 'connection_status' => 'invalidated' ];
assert_true( '' === notice( '2026-11-20T12:00:00Z' ), 'nor on a site whose connection was invalidated, where no connection notice is shown to them' );
$denied = dismiss();
assert_true( 'error' === ( $denied['type'] ?? '' ) && 403 === ( $denied['status'] ?? 0 ), 'an editor with a TranslateWP grant cannot dismiss the offer' );
assert_true( '' === get_user_meta( 1, BlackFridayNotice::DISMISSED_META, true ), 'the refused dismissal is not stored' );
$GLOBALS['wp_test_current_user_roles'] = [];

seed( false );
assert_true( '' !== notice( '2026-11-27T12:00:00Z' ), 'an administrator still sees the offer' );
$allowed = dismiss();
assert_true( 'success' === ( $allowed['type'] ?? '' ) && 1 === get_user_meta( 1, BlackFridayNotice::DISMISSED_META, true ), 'an administrator can dismiss the offer' );

seed( false );
$_GET                            = [ 'page' => 'translatewp-network-settings' ];
$GLOBALS['wp_test_network_admin'] = true;
$GLOBALS['wp_test_caps']          = [ 'manage_options' ];
assert_true( '' === notice( '2026-11-27T12:00:00Z' ), 'on a network admin screen manage_options alone is not enough' );
$GLOBALS['wp_test_caps'] = [ 'manage_network_options' ];
assert_true( '' !== notice( '2026-11-27T12:00:00Z' ), 'a network administrator sees the offer on the network screen' );
$GLOBALS['wp_test_network_admin'] = false;

// --- printed last among the TranslateWP notices, at their priority ---
wp_test_reset( [] );
$GLOBALS['wp_test_action_registrations'] = [];
foreach ( glob( dirname( __DIR__ ) . '/Functionality/Admin/*.php' ) as $file ) {
	$class = '\\TranslateWP\\Functionality\\Admin\\' . basename( $file, '.php' );
	new $class( 'translatewp', '0.0.0' );
}
foreach ( $GLOBALS['wp_test_action_registrations'] as $registration ) {
	if ( 'translatewp_loaded' === $registration['tag'] ) {
		$registration['callback']();
	}
}
$notices = array_values(
	array_filter(
		$GLOBALS['wp_test_action_registrations'],
		static fn ( array $registration ): bool => 'admin_notices' === $registration['tag']
	)
);
$last    = end( $notices );
assert_true( count( $notices ) > 1 && [ BlackFridayNotice::class, 'render' ] === $last['callback'], 'the Black Friday notice is hooked after every other TranslateWP notice' );
assert_true( [ 10 ] === array_values( array_unique( array_column( $notices, 'priority' ) ) ), 'it shares the priority of the other TranslateWP notices, so no other plugin notice lands between them' );

if ( $failures > 0 ) {
	fwrite( STDERR, "BlackFridayNoticeTest ({$site}): {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "BlackFridayNoticeTest ({$site}): all assertions passed\n";
