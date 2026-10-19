<?php

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

use TranslateWP\Components\ActionLog;
use TranslateWP\Components\DebugInfo;
use TranslateWP\Functionality\Endpoints\Transparency;

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.23' );
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string {
		return 'en_US';
	}
}
if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme() {
		return new class() {
			public function get( string $key ): string {
				return 'Name' === $key ? 'Test Theme' : '1.0';
			}
			public function parent() {
				return null;
			}
		};
	}
}
if ( ! function_exists( 'get_plugins' ) ) {
	function get_plugins(): array {
		return [ 'translate/translatewp.php' => [ 'Name' => 'TranslateWP', 'Version' => TRANSLATEWP_VERSION ] ];
	}
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string(): string {
		return 'UTC';
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return trim( (string) $email );
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return false !== strpos( (string) $email, '@' ) ? $email : false;
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return (object) [ 'user_email' => 'owner@example.com' ];
	}
}

$failures = 0;
function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

class Support_Ticket_Request {
	public function __construct( private array $params ) {}
	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}
}

// --- the action log rotates and keeps the newest ---
wp_test_reset( [] );
for ( $i = 1; $i <= ActionLog::LIMIT + 5; $i++ ) {
	ActionLog::record( 'event ' . $i, 0 === $i % 2 ? 'warning' : 'info' );
}
$entries = ActionLog::entries();
assert_true( ActionLog::LIMIT === count( $entries ), 'the log keeps at most its limit' );
assert_true( 'event ' . ( ActionLog::LIMIT + 5 ) === $entries[ ActionLog::LIMIT - 1 ]['message'], 'the newest entry is last' );
assert_true( 'event 6' === $entries[0]['message'], 'the oldest entries are dropped' );
assert_true( false !== strpos( ActionLog::text(), '[WARNING] event 6' ), 'the text form carries the level' );
ActionLog::record( 'bad level', 'debug' );
$latest = ActionLog::entries();
assert_true( 'info' === end( $latest )['level'], 'an unknown level is recorded as info' );

// --- the report names the site and the account, and never carries the token ---
wp_test_reset(
	[
		'site'      => [ 'id' => '0eb1bfb6-e72f-4a3a-860c-1b0a04861d34', 'token' => 'secret-site-token', 'account_label' => 'owner@example.com', 'connection_status' => 'connected' ],
		'languages' => [ 'list' => [ [ 'locale' => 'en-us', 'default' => true, 'active' => true ], [ 'locale' => 'lv', 'active' => true ] ] ],
	]
);
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
ActionLog::record( 'Site connected to the account' );
$text = DebugInfo::text();
assert_true( false === strpos( $text, 'secret-site-token' ), 'the token never appears in the report' );
assert_true( false !== strpos( $text, 'Account: owner@example.com' ), 'the account the site is connected to is named' );
assert_true( false !== strpos( $text, 'Site id: 0eb1bfb6-e72f-4a3a-860c-1b0a04861d34' ), 'the full site id is named' );
assert_true( strpos( $text, '-- Plugin events' ) < strpos( $text, '-- Plugin --' ) && strpos( $text, '-- Plugin --' ) < strpos( $text, '-- Server --' ), 'the events lead the report and the site facts close it' );
assert_true( false !== strpos( $text, 'Languages: en-us (default), lv' ), 'the languages are listed' );
assert_true( false !== strpos( $text, 'Site connected to the account' ), 'the plugin events are appended' );
assert_true( false !== strpos( $text, 'Version: ' . TRANSLATEWP_VERSION ), 'the plugin version is named' );

// --- a ticket is Pro-only, goes by email with the report attached, and reports when the mailer refused it ---
$endpoint = new Transparency( 'translatewp', TRANSLATEWP_VERSION );
$GLOBALS['wp_test_filters'] = [];
$denied = $endpoint->open_support_ticket( new Support_Ticket_Request( [ 'name' => 'Ann', 'message' => 'Help' ] ) );
assert_true( $denied instanceof WP_Error && 'pro_required' === $denied->get_error_code(), 'a Terrestrial site cannot open a ticket' );

add_filter( 'translatewp_pro_addon_active', static fn () => true );
set_transient( 'translatewp_pro_eligibility', [ 'entitled' => true, 'plan' => 'pro', 'shape' => 2 ], 3600 );
$GLOBALS['wp_test_mails']       = [];
$GLOBALS['wp_test_mail_result'] = true;
$result = $endpoint->open_support_ticket( new Support_Ticket_Request( [ 'name' => 'Ann', 'message' => 'The switcher is missing on /shop/' ] ) );
if ( $result instanceof WP_Error ) {
	fwrite( STDERR, 'ticket refused: ' . $result->get_error_code() . "\n" );
}
assert_true( is_array( $result ) && true === $result['sent'] && Transparency::SUPPORT_ADDRESS === $result['address'], 'with Pro the ticket is sent' );
$mail = end( $GLOBALS['wp_test_mails'] );
assert_true( Transparency::SUPPORT_ADDRESS === $mail['to'], 'the ticket goes to the support address' );
assert_true( false !== strpos( $mail['message'], 'The switcher is missing on /shop/' ) && false !== strpos( $mail['message'], '== TranslateWP support report ==' ), 'the message carries the request and the report' );
assert_true( false === strpos( $mail['message'], 'secret-site-token' ), 'the mailed report carries no token' );
assert_true( in_array( 'Reply-To: Ann <owner@example.com>', $mail['headers'], true ), 'the reply goes to the WordPress user' );

// --- the reply goes where the user says ---
$result = $endpoint->open_support_ticket( new Support_Ticket_Request( [ 'name' => 'Ann', 'message' => 'Help', 'email' => 'ann@studio.test' ] ) );
$mail   = end( $GLOBALS['wp_test_mails'] );
assert_true( is_array( $result ) && in_array( 'Reply-To: Ann <ann@studio.test>', $mail['headers'], true ) && false !== strpos( $mail['message'], 'Reply to: ann@studio.test' ), 'a reply address the user gives is used' );
$wrong = $endpoint->open_support_ticket( new Support_Ticket_Request( [ 'name' => 'Ann', 'message' => 'Help', 'email' => 'not-an-email' ] ) );
assert_true( $wrong instanceof WP_Error && 'invalid_reply_address' === $wrong->get_error_code() && 400 === ( $wrong->get_error_data()['status'] ?? 0 ), 'an address that is not an email is refused with its own code' );
$long = $endpoint->open_support_ticket( new Support_Ticket_Request( [ 'name' => 'Ann', 'message' => str_repeat( 'a', 10001 ), 'email' => 'ann@studio.test' ] ) );
assert_true( $long instanceof WP_Error && 'invalid_parameter' === $long->get_error_code(), 'a message that is too long is refused with the general code, not the address one' );
$count  = count( $GLOBALS['wp_test_mails'] );
$result = $endpoint->open_support_ticket( new Support_Ticket_Request( [ 'name' => 'Ann', 'message' => 'Help', 'email' => '' ] ) );
$mail   = end( $GLOBALS['wp_test_mails'] );
assert_true( $count + 1 === count( $GLOBALS['wp_test_mails'] ) && in_array( 'Reply-To: Ann <owner@example.com>', $mail['headers'], true ), 'with no address given, the account email is used' );

$GLOBALS['wp_test_mail_result'] = false;
$result = $endpoint->open_support_ticket( new Support_Ticket_Request( [ 'name' => 'Ann', 'message' => 'Help' ] ) );
assert_true( is_array( $result ) && false === $result['sent'], 'a refused mail is reported so the client can fall back to a mail link' );

$missing = $endpoint->open_support_ticket( new Support_Ticket_Request( [ 'name' => '', 'message' => 'Help' ] ) );
assert_true( $missing instanceof WP_Error && 'missing_parameter' === $missing->get_error_code(), 'a ticket needs a name' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SupportReportTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SupportReportTest: all assertions passed\n";
