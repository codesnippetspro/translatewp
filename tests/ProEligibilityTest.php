<?php

declare(strict_types=1);

use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\SiteConnection;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function assert_no_github_requested(): void {
	foreach ( $GLOBALS['wp_test_remote_get_urls'] as $url ) {
		assert_true( false === stripos( (string) $url, 'github' ), 'No request may target GitHub, got ' . $url );
	}
}

function eligibility_response( array $payload ): array {
	return [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode( $payload ),
	];
}

function pro_payload(): array {
	return [
		'plan'        => 'pro',
		'entitled'    => true,
		'latest'      => [
			'version'     => '1.2.0',
			'released_at' => '2026-09-12T10:00:00Z',
		],
		'package_url' => 'https://api.translatewp.ai/v1/pro/package?token=signed-abc',
	];
}

function free_payload(): array {
	return [
		'plan'     => 'free',
		'entitled' => false,
		'latest'   => [
			'version'     => '1.2.0',
			'released_at' => '2026-09-12T10:00:00Z',
		],
	];
}

$connected = [
	'site' => [
		'id'    => '00000000-0000-4000-8000-000000000001',
		'token' => 'secret-site-token',
	],
];

// --- parse(): pro payload keeps entitlement, latest, and a same-origin package URL ---
$parsed = ProEligibility::parse( pro_payload() );
assert_true( 'pro' === $parsed['plan'], 'parse must keep the plan' );
assert_true( true === $parsed['entitled'], 'parse must mark a pro account entitled' );
assert_true( '1.2.0' === $parsed['latest_version'], 'parse must read latest.version' );
assert_true( '2026-09-12T10:00:00Z' === $parsed['latest_released_at'], 'parse must read latest.released_at' );
assert_true(
	'https://api.translatewp.ai/v1/pro/package?token=signed-abc' === $parsed['package_url'],
	'parse must keep a same-origin package URL'
);

// --- parse(): free payload without an entitled flag defaults to not entitled and no package ---
$parsed_free = ProEligibility::parse( free_payload() );
assert_true( false === $parsed_free['entitled'], 'a free payload must parse as not entitled' );
assert_true( '' === $parsed_free['package_url'], 'a free payload must carry no package URL' );

// --- parse(): a GitHub package URL is rejected (plugin never contacts GitHub) ---
$github = pro_payload();
$github['package_url'] = 'https://github.com/org/translatewp-pro/releases/download/v1.2.0/translatewp-pro-1.2.0.zip';
$parsed_github = ProEligibility::parse( $github );
assert_true( '' === $parsed_github['package_url'], 'a GitHub package URL must be rejected' );

// --- parse(): a non-https package URL is rejected ---
$insecure = pro_payload();
$insecure['package_url'] = 'http://api.translatewp.ai/v1/pro/package?token=signed-abc';
assert_true( '' === ProEligibility::parse( $insecure )['package_url'], 'a non-https package URL must be rejected' );

// --- fetch over the wire: entitlement parsed, installed sent as telemetry, no GitHub host ---
wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
$state = ProEligibility::refresh();
assert_true( true === $state['entitled'], 'refresh must parse entitlement from the API' );
assert_true( '1.2.0' === $state['latest_version'], 'refresh must parse the latest version' );
assert_true( true === ProEligibility::cached()['entitled'], 'refresh must cache the parsed state' );
$eligibility_hit = false;
foreach ( $GLOBALS['wp_test_remote_get_urls'] as $url ) {
	if ( false !== strpos( (string) $url, '/v1/pro/eligibility' ) && false !== strpos( (string) $url, 'installed=none' ) ) {
		$eligibility_hit = true;
	}
}
assert_true( $eligibility_hit, 'refresh must call the eligibility endpoint with installed=none' );
assert_no_github_requested();

// --- the active addon version is reported as telemetry on the next call ---
wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.0.0';
} );
add_filter( 'translatewp_pro_addon_active', static function () {
	return true;
} );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
ProEligibility::refresh();
$reported_installed = false;
foreach ( $GLOBALS['wp_test_remote_get_urls'] as $url ) {
	if ( false !== strpos( (string) $url, 'installed=1.0.0' ) ) {
		$reported_installed = true;
	}
}
assert_true( $reported_installed, 'refresh must report the active addon version' );
assert_no_github_requested();

// --- an installed but inactive addon is reported as none ---
wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.0.0';
} );
add_filter( 'translatewp_pro_addon_active', static function () {
	return false;
} );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
ProEligibility::refresh();
$reported_none = false;
foreach ( $GLOBALS['wp_test_remote_get_urls'] as $url ) {
	if ( false !== strpos( (string) $url, 'installed=none' ) ) {
		$reported_none = true;
	}
}
assert_true( $reported_none, 'an inactive addon must be reported as none' );
assert_true( ProEligibility::is_installed(), 'the local install check still sees the inactive addon' );

// --- prompt visibility: connected x entitled x installed ---
wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
ProEligibility::refresh();
assert_true( true === ProEligibility::should_show_install_prompt(), 'connected + entitled + not installed must show the prompt' );

wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.2.0';
} );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
ProEligibility::refresh();
assert_true( false === ProEligibility::should_show_install_prompt(), 'connected + entitled + installed must hide the prompt' );
assert_true( false === ProEligibility::is_pro_active(), 'connected + entitled + installed but inactive must not read as pro active' );
add_filter( 'translatewp_pro_addon_active', static function () {
	return true;
} );
assert_true( true === ProEligibility::is_pro_active(), 'connected + entitled + installed + active must read as pro active' );

wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( free_payload() );
ProEligibility::refresh();
assert_true( false === ProEligibility::should_show_install_prompt(), 'a not entitled site must hide the prompt' );

wp_test_reset( [] );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
ProEligibility::refresh();
assert_true( false === SiteConnection::is_connected(), 'an unconnected site is not connected' );
assert_true( false === ProEligibility::should_show_install_prompt(), 'an unconnected site must hide the prompt' );

// --- update offer: built only when entitled, installed, and a newer version exists ---
wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.0.0';
} );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
$offer_state = ProEligibility::fetch_fresh();
$offer       = ProEligibility::build_update_offer( $offer_state );
assert_true( null !== $offer, 'a newer version must produce an update offer' );
assert_true( '1.2.0' === $offer['new_version'], 'the offer must carry the latest version' );
assert_true( 'translatewp-pro/translatewp-pro.php' === $offer['plugin'], 'the offer must target the addon plugin file' );
assert_true( 'translatewp-pro' === $offer['slug'], 'the offer must carry the addon slug' );
assert_true(
	'https://api.translatewp.ai/v1/pro/package?token=signed-abc' === $offer['package'],
	'the offer must use the API package URL'
);
assert_true( false === stripos( (string) $offer['package'], 'github' ), 'the offer must never point at GitHub' );

// --- no offer when the installed version already matches the latest ---
wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.2.0';
} );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
assert_true(
	null === ProEligibility::build_update_offer( ProEligibility::fetch_fresh() ),
	'no update offer when the installed version is current'
);

// --- no offer for an unentitled account even with an installed addon ---
wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.0.0';
} );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( free_payload() );
assert_true(
	null === ProEligibility::build_update_offer( ProEligibility::fetch_fresh() ),
	'no update offer for an unentitled account'
);

// --- a 503 package_unavailable answer still carries the plan: entitled, package pending ---
wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 503 ],
	'body'     => wp_json_encode( [ 'plan' => 'pro', 'entitled' => true, 'latest' => null, 'error' => 'package_unavailable' ] ),
];
$pending = ProEligibility::fetch_fresh();
assert_true( is_array( $pending ) && true === $pending['entitled'], 'a package_unavailable answer still marks the account entitled' );
assert_true( is_array( $pending ) && '' === $pending['package_url'], 'no package url while the package is unavailable' );
assert_true( is_array( $pending ) && ProEligibility::package_pending( $pending ), 'entitled without a release is package pending' );
assert_true( ! ProEligibility::package_pending( ProEligibility::parse( pro_payload() ) ), 'a resolved release is not package pending' );
assert_true( 'invalidated' !== SiteConnection::connection_status(), 'a 503 must not invalidate the connection' );

// --- panel state mirrors the pending package for the connection panel ---
$panel = ProEligibility::panel_state();
assert_true( true === $panel['entitled'] && true === $panel['packagePending'] && false === $panel['installed'], 'panel state reports an entitled account with a pending package' );

// --- activation from the panel activates the installed addon and clears the cache ---
wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.0.0';
} );
$GLOBALS['wp_test_activated_plugins'] = [];
$GLOBALS['wp_test_caps']              = [ 'activate_plugins', 'install_plugins' ];
$activation = \TranslateWP\Components\ProInstaller::activate_for_site();
assert_true( true === $activation['ok'] && 'activated' === $activation['code'], 'activate_for_site must report activated' );
assert_true( [ 'translatewp-pro/translatewp-pro.php' ] === $GLOBALS['wp_test_activated_plugins'], 'activate_for_site must activate the addon basename' );
assert_true( false === ( $GLOBALS['wp_test_activated_network']['translatewp-pro/translatewp-pro.php'] ?? null ), 'on a single site the addon activates for that site only' );
assert_true( ! ProEligibility::is_cache_warm(), 'activation must clear the eligibility cache' );

// --- on a network where the free plugin is network-active, a network admin's click activates Pro network-wide ---
if ( ! defined( 'TRANSLATEWP_BASENAME' ) ) {
	define( 'TRANSLATEWP_BASENAME', 'translate/translatewp.php' );
}
wp_test_reset( $connected );
add_filter( 'translatewp_pro_installed_version', static function () {
	return '1.0.0';
} );
$GLOBALS['wp_test_multisite']              = true;
$GLOBALS['wp_test_network_active_plugins'] = [ TRANSLATEWP_BASENAME ];
$GLOBALS['wp_test_activated_plugins']      = [];
$GLOBALS['wp_test_activated_network']      = [];
$GLOBALS['wp_test_caps']                   = [ 'activate_plugins', 'install_plugins', 'manage_network_plugins' ];
$network_activation = \TranslateWP\Components\ProInstaller::activate_for_site();
assert_true( true === $network_activation['ok'], 'activate_for_site must succeed on a network' );
assert_true( true === ( $GLOBALS['wp_test_activated_network']['translatewp-pro/translatewp-pro.php'] ?? null ), 'a network admin\'s click activates the addon network-wide when the free plugin is network-active' );

// --- the same click by a site admin without network rights stays per site ---
$GLOBALS['wp_test_activated_network'] = [];
$GLOBALS['wp_test_caps']              = [ 'activate_plugins', 'install_plugins' ];
\TranslateWP\Components\ProInstaller::activate_for_site();
assert_true( false === ( $GLOBALS['wp_test_activated_network']['translatewp-pro/translatewp-pro.php'] ?? null ), 'without network rights the activation stays with the current site' );

// --- and a network where the free plugin is active per site keeps Pro per site too ---
$GLOBALS['wp_test_network_active_plugins'] = [];
$GLOBALS['wp_test_activated_network']      = [];
$GLOBALS['wp_test_caps']                   = [ 'activate_plugins', 'install_plugins', 'manage_network_plugins' ];
\TranslateWP\Components\ProInstaller::activate_for_site();
assert_true( false === ( $GLOBALS['wp_test_activated_network']['translatewp-pro/translatewp-pro.php'] ?? null ), 'a per-site free plugin keeps the addon per site' );
$GLOBALS['wp_test_multisite'] = false;
wp_test_reset( $connected );
$GLOBALS['wp_test_caps'] = [ 'activate_plugins', 'install_plugins' ];
$not_installed = \TranslateWP\Components\ProInstaller::activate_for_site();
assert_true( false === $not_installed['ok'] && 'not_installed' === $not_installed['code'], 'activation without the addon reports not_installed' );

// --- a cached state written by an older plugin build is treated as cold ---
wp_test_reset( $connected );
set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
assert_true( ! ProEligibility::is_cache_warm(), 'a cache without the current shape marker is cold' );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( array_merge( pro_payload(), [ 'capabilities' => [ 'seo' => true, 'custom_style' => true, 'byok' => false ] ] ) );
$refetched = ProEligibility::maybe_refresh();
assert_true( true === $refetched['capabilities']['seo'], 'maybe_refresh must refetch when the cached shape is stale' );
assert_true( ProEligibility::is_cache_warm(), 'a freshly written cache is warm' );

// --- any other error keeps the previous state ---
wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = [
	'response' => [ 'code' => 500 ],
	'body'     => wp_json_encode( [ 'plan' => 'pro', 'error' => 'boom' ] ),
];
assert_true( null === ProEligibility::fetch_fresh(), 'a non-503 error carries no state' );

// --- upgrade URL points at the portal upgrade offer ---
assert_true( 'https://app.translatewp.ai/upgrade' === ProEligibility::upgrade_url(), 'the upsell must deep-link to the portal upgrade offer' );

// --- capabilities: derived from entitlement, each a boolean, defaulting false ---
$capable_payload                 = pro_payload();
$capable_payload['capabilities'] = [
	'seo'          => true,
	'custom_style' => true,
	'byok'         => false,
];
$parsed_capabilities = ProEligibility::parse( $capable_payload )['capabilities'];
assert_true( true === $parsed_capabilities['seo'], 'parse must keep an entitled seo capability' );
assert_true( true === $parsed_capabilities['custom_style'], 'parse must keep an entitled custom_style capability' );
assert_true( false === $parsed_capabilities['byok'], 'byok stays false until its routing exists' );

$no_capabilities = ProEligibility::parse( pro_payload() )['capabilities'];
assert_true(
	[ 'seo' => false, 'custom_style' => false, 'byok' => false ] === $no_capabilities,
	'capabilities missing from the API answer must default to false'
);

$non_boolean_capabilities                 = pro_payload();
$non_boolean_capabilities['capabilities'] = [ 'seo' => 1, 'custom_style' => 0 ];
$coerced_capabilities                     = ProEligibility::parse( $non_boolean_capabilities )['capabilities'];
assert_true(
	true === $coerced_capabilities['seo'] && false === $coerced_capabilities['custom_style'],
	'capabilities must be coerced to booleans'
);

wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( $capable_payload );
ProEligibility::refresh();
$panel_capabilities = ProEligibility::panel_state()['capabilities'];
assert_true(
	true === $panel_capabilities['seo'] && true === $panel_capabilities['custom_style'] && false === $panel_capabilities['byok'],
	'panel_state must expose the capabilities carried by the connect status'
);

$default_capabilities = ProEligibility::default_state()['capabilities'];
assert_true(
	[ 'seo' => false, 'custom_style' => false, 'byok' => false ] === $default_capabilities,
	'default_state must carry all-false capabilities'
);

// --- cached(): a cold transient falls back to a warm last-known record ---
wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response(
	array_merge( pro_payload(), [ 'capabilities' => [ 'seo' => true, 'custom_style' => true, 'byok' => false ] ] )
);
ProEligibility::refresh();
delete_transient( 'translatewp_pro_eligibility' );
assert_true( ! ProEligibility::is_cache_warm(), 'the transient must read as cold once deleted' );
$fallback = ProEligibility::cached();
assert_true( true === $fallback['entitled'], 'a cold transient must fall back to the last-known entitlement' );
assert_true(
	[ 'seo' => true, 'custom_style' => true, 'byok' => false ] === $fallback['capabilities'],
	'a cold transient must fall back to the last-known capabilities'
);
assert_true(
	[ 'seo' => true, 'custom_style' => true, 'byok' => false ] === ProEligibility::last_known_capabilities(),
	'last_known_capabilities must expose the last successful answer'
);

// --- cached(): a cold transient with no last-known record yields the defaults ---
wp_test_reset( $connected );
assert_true(
	[ 'seo' => false, 'custom_style' => false, 'byok' => false ] === ProEligibility::cached()['capabilities'],
	'no transient and no last-known record must default every capability to false'
);
assert_true(
	[ 'seo' => false, 'custom_style' => false, 'byok' => false ] === ProEligibility::last_known_capabilities(),
	'last_known_capabilities with no record must default every capability to false'
);

// --- cached(): a present transient always wins, so a real downgrade takes effect ---
wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response(
	array_merge( pro_payload(), [ 'capabilities' => [ 'seo' => true, 'custom_style' => true, 'byok' => false ] ] )
);
ProEligibility::refresh();
set_transient(
	'translatewp_pro_eligibility',
	array_merge( ProEligibility::default_state(), [ 'shape' => 2, 'capabilities' => [ 'seo' => false, 'custom_style' => false, 'byok' => false ] ] ),
	3600
);
assert_true(
	[ 'seo' => false, 'custom_style' => false, 'byok' => false ] === ProEligibility::cached()['capabilities'],
	'a present transient must win over a warmer last-known record'
);
assert_true(
	[ 'seo' => true, 'custom_style' => true, 'byok' => false ] === ProEligibility::last_known_capabilities(),
	'a transient-only downgrade must leave the last-known record untouched'
);

// --- disconnect clears the last-known record along with the transient ---
wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response(
	array_merge( pro_payload(), [ 'capabilities' => [ 'seo' => true, 'custom_style' => true, 'byok' => false ] ] )
);
ProEligibility::refresh();
SiteConnection::disconnect();
assert_true( ! ProEligibility::is_cache_warm(), 'disconnect must clear the eligibility transient' );
assert_true(
	[ 'seo' => false, 'custom_style' => false, 'byok' => false ] === ProEligibility::last_known_capabilities(),
	'disconnect must clear the last-known capabilities record'
);

// --- upgrade started: the admin clicked Upgrade, the next checks come soon until the account is Pro ---
$start_url = ProEligibility::upgrade_url_with_return();
assert_true( false !== strpos( $start_url, 'admin-post.php?' ) && false !== strpos( $start_url, 'action=' . ProEligibility::START_UPGRADE_ACTION ), 'the upgrade link records the click on this site first, got ' . $start_url );
assert_true( false === strpos( $start_url, '&amp;' ), 'the upgrade link is a plain URL, not HTML-escaped, so a script can use it as an href, got ' . $start_url );
parse_str( (string) parse_url( $start_url, PHP_URL_QUERY ), $start_query );
assert_true( ProEligibility::START_UPGRADE_ACTION === ( $start_query['action'] ?? '' ), 'the upgrade link names the start-upgrade action' );
assert_true( wp_verify_nonce( (string) ( $start_query['_wpnonce'] ?? '' ), ProEligibility::START_UPGRADE_ACTION ), 'the upgrade link carries a nonce valid for the start-upgrade action, got ' . wp_json_encode( $start_query ) );
assert_true( 0 === strpos( ProEligibility::portal_upgrade_url_with_return(), 'https://app.translatewp.ai/upgrade?return=' ), 'after recording the click the admin lands on the portal upgrade page with a way back, got ' . ProEligibility::portal_upgrade_url_with_return() );

wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( free_payload() );
ProEligibility::refresh();
assert_true( ProEligibility::is_cache_warm(), 'a not-entitled answer is cached before the click' );
ProEligibility::start_upgrade();
assert_true( ! ProEligibility::is_cache_warm(), 'starting an upgrade drops the cached not-entitled answer' );
ProEligibility::refresh();
$started_ttl = ( $GLOBALS['wp_test_transient_expirations']['translatewp_pro_eligibility'] ?? 0 ) - time();
assert_true( $started_ttl > 0 && $started_ttl <= 15, 'after the click the next check comes within 15 seconds, got ' . $started_ttl );
assert_true( 'checkout_started' === ( ProEligibility::panel_state()['upgrade']['stage'] ?? null ), 'after the click the panel shows the checkout as started' );

$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
ProEligibility::refresh();
$entitled_ttl = ( $GLOBALS['wp_test_transient_expirations']['translatewp_pro_eligibility'] ?? 0 ) - time();
assert_true( $entitled_ttl > 300, 'once the account is Pro the usual long cache returns, got ' . $entitled_ttl );
$panel = ProEligibility::panel_state();
assert_true( array_key_exists( 'upgrade', $panel ) && null === $panel['upgrade'], 'an entitled account with no upgrade from the service shows no timeline' );

wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( free_payload() );
ProEligibility::refresh();
$panel = ProEligibility::panel_state();
assert_true( array_key_exists( 'upgrade', $panel ) && null === $panel['upgrade'], 'without a click or an upgrade from the service there is no timeline' );

wp_test_reset( [] );
new \TranslateWP\Functionality\Pro( 'translatewp', '0.0.0-test' );
assert_true( is_callable( $GLOBALS['wp_test_filters'][ 'admin_post_' . ProEligibility::START_UPGRADE_ACTION ] ?? null ), 'the upgrade click is handled by an admin-post action' );

// --- an entitled answer is trusted for at most one hour ---
wp_test_reset( $connected );
$GLOBALS['wp_test_remote_get_response'] = eligibility_response( pro_payload() );
ProEligibility::refresh();
$entitled_cache = ( $GLOBALS['wp_test_transient_expirations']['translatewp_pro_eligibility'] ?? 0 ) - time();
assert_true( $entitled_cache > 300 && $entitled_cache <= 3600, 'an entitled answer is cached for at most an hour, got ' . $entitled_cache );

if ( $failures > 0 ) {
	fwrite( STDERR, "ProEligibilityTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "ProEligibilityTest: all assertions passed\n";
