<?php

declare(strict_types=1);

use TranslateWP\Components\Options;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\SiteLanguageSync;
use TranslateWP\Components\TranslationProxySettings;
use TranslateWP\Functionality\Endpoints\Admin;
use TranslateWP\Functionality\Endpoints\Management;
use TranslateWP\Includes\Loader;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}
if ( ! defined( 'TRANSLATEWP_NAME' ) ) {
	define( 'TRANSLATEWP_NAME', 'translatewp' );
}
if ( ! defined( 'TRANSLATEWP_PATH' ) ) {
	define( 'TRANSLATEWP_PATH', dirname( __DIR__ ) . '/' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

$wp_test_options = [
	'site'      => [
		'id'       => '00000000-0000-4000-8000-000000000001',
		'token'    => 'site-token',
		'home_url' => 'https://example.com',
	],
	'languages' => [
		'list' => [
			'en' => [
				'locale'  => 'en',
				'slug'    => 'en',
				'active'  => true,
				'default' => true,
			],
			'fr' => [
				'locale'  => 'fr',
				'slug'    => 'fr',
				'active'  => true,
				'default' => false,
			],
			'de' => [
				'locale'  => 'de',
				'slug'    => 'de',
				'active'  => false,
				'default' => false,
			],
			'pt-br' => [
				'locale'  => 'pt-BR',
				'slug'    => 'pt',
				'active'  => true,
				'default' => false,
			],
		],
	],
];

wp_test_reset( $wp_test_options );
$GLOBALS['wp_test_remote_puts'] = [];

assert_deep_equal(
	TranslationProxySettings::active_lookup_languages(),
	[ 'en', 'fr', 'pt-BR' ],
	'Active lookup helper must preserve exact locale values'
);
assert_true(
	! TranslationProxySettings::is_configured_language( 'pt' ),
	'Proxy enforcement must distinguish pt slug from pt-BR locale'
);
assert_true(
	TranslationProxySettings::is_configured_language( 'pt-BR' ),
	'Proxy enforcement must accept the configured locale byte-exact'
);
assert_true(
	! TranslationProxySettings::is_configured_language( 'pt-br' ),
	'Proxy enforcement must not accept lowercased locale drift'
);
assert_deep_equal(
	SiteLanguageSync::active_languages_for_sync(),
	TranslationProxySettings::active_lookup_languages(),
	'Registrar sync must share the proxy lookup helper'
);

$synced = SiteLanguageSync::sync_active_languages();
assert_true( true === $synced, 'Connected site language sync must succeed' );
assert_true( 1 === count( $GLOBALS['wp_test_remote_puts'] ), 'Sync must issue one PUT request' );
$put = $GLOBALS['wp_test_remote_puts'][0];
assert_true(
	'/v1/site/languages' === $put['path'] && 'PUT' === $put['method'],
	'Sync must PUT /v1/site/languages'
);
assert_deep_equal(
	$put['body'],
	[ 'languages' => [ 'en', 'fr', 'pt-BR' ] ],
	'Sync must send active locale values only'
);
$lookup_language = 'pt-BR';
assert_true(
	in_array( $lookup_language, $put['body']['languages'], true ),
	'Registrar payload must include the lookup locale byte-exact'
);
assert_true(
	$lookup_language === $put['body']['languages'][ array_search( 'pt-BR', $put['body']['languages'], true ) ],
	'Registrar payload language must equal lookup body language for pt-BR'
);
assert_true(
	false === strpos( wp_json_encode( $put ), 'site-token' ),
	'Sync request capture must not include bearer tokens'
);

wp_test_reset( $wp_test_options );
$GLOBALS['wp_test_remote_puts'] = [];
$GLOBALS['wp_test_options_store']['languages']['list'] = [
	'en' => [
		'locale'  => 'en',
		'slug'    => 'en',
		'active'  => false,
		'default' => true,
	],
	'fr' => [
		'locale'  => 'fr',
		'slug'    => 'fr',
		'active'  => false,
		'default' => false,
	],
	'pt-br' => [
		'locale'  => 'pt-BR',
		'slug'    => 'pt',
		'active'  => false,
		'default' => false,
	],
];
$empty_sync = SiteLanguageSync::sync_active_languages();
assert_true( true === $empty_sync, 'All-inactive sync must still attempt PUT' );
assert_true( 1 === count( $GLOBALS['wp_test_remote_puts'] ), 'All-inactive sync must issue one PUT request' );
assert_deep_equal(
	$GLOBALS['wp_test_remote_puts'][0]['body'],
	[ 'languages' => [] ],
	'All-inactive sync must send an empty replace-set so stale rows die'
);

wp_test_reset( $wp_test_options );
$GLOBALS['wp_test_remote_puts']           = [];
$GLOBALS['wp_test_site_language_sync_fail'] = true;
$failed = SiteLanguageSync::sync_active_languages();
assert_true( false === $failed, 'Failed sync must return false without throwing' );

// --- P4.1: a failed PUT schedules exactly one retry; the retry enqueues warm-up on success ---
assert_true( false !== wp_next_scheduled( SiteLanguageSync::RETRY_HOOK ), 'Failed sync must schedule one retry' );
$scheduled_before = count( $GLOBALS['wp_test_scheduled_log'] );
SiteLanguageSync::schedule_retry();
assert_true( count( $GLOBALS['wp_test_scheduled_log'] ) === $scheduled_before, 'Retry scheduling must not stack' );

// --- Wiring: the Loader must register the retry hook callback ---
$GLOBALS['wp_test_filters'] = [];
new Loader();
assert_true(
	has_action( SiteLanguageSync::RETRY_HOOK, [ SiteLanguageSync::class, 'retry_sync' ] ),
	'Loader must wire the retry hook to SiteLanguageSync::retry_sync'
);

$GLOBALS['wp_test_site_language_sync_fail'] = false;
$GLOBALS['wp_test_remote_puts']             = [];
$GLOBALS['wp_test_actions_fired']           = [];
SiteLanguageSync::retry_sync();
assert_true( 1 === count( $GLOBALS['wp_test_remote_puts'] ), 'Retry must issue exactly one PUT' );
$synced_actions = array_values( array_filter( $GLOBALS['wp_test_actions_fired'], static fn( array $action ): bool => 'translatewp_languages_synced' === $action['tag'] ) );
assert_true( 1 === count( $synced_actions ), 'Successful retry must fire the languages-synced action (warm-up enqueue)' );

// --- A languages save starts translation; a bare credential sync does not ---
wp_test_reset( $wp_test_options );
$GLOBALS['wp_test_actions_fired'] = [];
SiteLanguageSync::sync_active_languages();
$saved_actions = array_values( array_filter( $GLOBALS['wp_test_actions_fired'], static fn( array $action ): bool => 'translatewp_languages_saved' === $action['tag'] ) );
assert_true( 0 === count( $saved_actions ), 'A sync without a save (connect, reconnect) must not fire the languages-saved action' );
$GLOBALS['wp_test_actions_fired'] = [];
SiteLanguageSync::sync_active_languages( true );
$saved_actions = array_values( array_filter( $GLOBALS['wp_test_actions_fired'], static fn( array $action ): bool => 'translatewp_languages_saved' === $action['tag'] ) );
assert_true( 1 === count( $saved_actions ), 'A languages save must fire the languages-saved action once' );

// --- Completing a connection (connect, reconnect) syncs the languages but starts no translation ---
wp_test_reset( $wp_test_options );
$GLOBALS['wp_test_actions_fired'] = [];
SiteConnection::complete_connection( '00000000-0000-4000-8000-0000000000cc', str_repeat( 'a', 43 ), 'owner@example.com' );
$synced_actions = array_values( array_filter( $GLOBALS['wp_test_actions_fired'], static fn( array $action ): bool => 'translatewp_languages_synced' === $action['tag'] ) );
$saved_actions  = array_values( array_filter( $GLOBALS['wp_test_actions_fired'], static fn( array $action ): bool => 'translatewp_languages_saved' === $action['tag'] ) );
assert_true( 1 === count( $synced_actions ) && 0 === count( $saved_actions ), 'A completed connection syncs the languages and fires no languages-saved action' );

// --- The indexer starts translation from a languages save only ---
$GLOBALS['wp_test_filters'] = [];
$indexer = new \TranslateWP\Functionality\Indexer( 'translatewp', '0.0.0-test' );
assert_true( has_action( 'translatewp_languages_saved', [ $indexer, 'on_languages_saved' ] ), 'The indexer listens to the languages-saved action' );
assert_true( ! has_action( 'translatewp_languages_synced' ), 'The indexer does not listen to the bare sync action' );

// --- A save whose sync failed starts translation on the successful retry, once ---
wp_test_reset( $wp_test_options );
$GLOBALS['wp_test_site_language_sync_fail'] = true;
SiteLanguageSync::sync_active_languages( true );
$GLOBALS['wp_test_site_language_sync_fail'] = false;
$GLOBALS['wp_test_actions_fired']           = [];
SiteLanguageSync::retry_sync();
$saved_actions = array_values( array_filter( $GLOBALS['wp_test_actions_fired'], static fn( array $action ): bool => 'translatewp_languages_saved' === $action['tag'] ) );
assert_true( 1 === count( $saved_actions ), 'The retry after a failed save must fire the languages-saved action' );
$GLOBALS['wp_test_actions_fired'] = [];
SiteLanguageSync::retry_sync();
$saved_actions = array_values( array_filter( $GLOBALS['wp_test_actions_fired'], static fn( array $action ): bool => 'translatewp_languages_saved' === $action['tag'] ) );
assert_true( 0 === count( $saved_actions ), 'A later retry without a pending save fires no languages-saved action' );

// --- Permanent failure: the retry fails and never re-schedules ---
wp_test_reset( $wp_test_options );
$GLOBALS['wp_test_site_language_sync_fail'] = true;
SiteLanguageSync::sync_active_languages();
assert_true( false !== wp_next_scheduled( SiteLanguageSync::RETRY_HOOK ), 'First failure must schedule the retry' );
$GLOBALS['wp_test_scheduled'] = [];
SiteLanguageSync::retry_sync();
assert_true( false === wp_next_scheduled( SiteLanguageSync::RETRY_HOOK ), 'A failed retry must not re-schedule' );
$GLOBALS['wp_test_site_language_sync_fail'] = false;

$admin = new Admin( 'translatewp', '0.0.0-test' );
$GLOBALS['wp_test_remote_puts'] = [];
$updated_languages = [
	'en' => [
		'locale'  => 'en',
		'slug'    => 'en',
		'active'  => true,
		'default' => true,
	],
];
$admin_request = new class( $updated_languages ) {
	private array $updated_languages;

	public function __construct( array $updated_languages ) {
		$this->updated_languages = $updated_languages;
	}

	public function get_param( string $key ) {
		if ( 'option' === $key ) {
			return 'languages.list';
		}
		if ( 'data' === $key ) {
			return $this->updated_languages;
		}

		return null;
	}
};
$result = $admin->update_data( $admin_request );
assert_true(
	is_array( $result ) && ! empty( $result['success'] ),
	'Languages settings save must succeed when sync fails'
);
assert_deep_equal(
	Options::get( 'languages.list', [] ),
	$updated_languages,
	'Languages settings must persist when sync fails'
);
assert_true( 1 === count( $GLOBALS['wp_test_remote_puts'] ), 'Languages settings save must trigger sync while failure flag is enabled' );
assert_deep_equal(
	$GLOBALS['wp_test_remote_puts'][0]['body'],
	[ 'languages' => [ 'en' ] ],
	'Updated languages list must remove inactive locales from sync payload'
);
$GLOBALS['wp_test_site_language_sync_fail'] = false;

$advanced_request = new class() {
	public function get_param( string $key ) {
		if ( 'option' === $key ) {
			return 'advanced';
		}
		if ( 'data' === $key ) {
			return [ 'delete_on_uninstall' => true ];
		}

		return null;
	}
};
$result = $admin->update_data( $advanced_request );
assert_true(
	is_array( $result ) && ! empty( $result['success'] ),
	'Advanced settings save must succeed'
);
assert_true(
	true === Options::get( 'advanced.delete_on_uninstall', false ),
	'Advanced uninstall setting must persist through the admin save endpoint'
);

$management = new Management( 'translatewp', '0.0.0-test' );
$GLOBALS['wp_test_remote_puts'] = [];
$setup_request = new class() {
	public function get_param( string $key ) {
		switch ( $key ) {
			case 'default_language':
				return 'en';
			case 'languages':
				return [ 'fr' ];
			case 'auto_translate':
				return true;
			case 'context':
				return 'website';
			default:
				return null;
		}
	}
};
$setup = $management->finish_setup( $setup_request );
assert_true(
	is_array( $setup ) && ! empty( $setup['success'] ),
	'Finish setup must succeed'
);
assert_true( 1 === count( $GLOBALS['wp_test_remote_puts'] ), 'Finish setup must trigger language sync' );
assert_deep_equal(
	$GLOBALS['wp_test_remote_puts'][0]['body'],
	[ 'languages' => [ 'en', 'fr' ] ],
	'Finish setup must sync the newly saved active language set'
);

$GLOBALS['wp_test_remote_puts'] = [];
$manual = SiteConnection::store_manual_credentials(
	'00000000-0000-4000-8000-000000000001',
	'site-token'
);
assert_true( ! empty( $manual['ok'] ), 'Manual credential store must succeed' );
assert_true( 1 === count( $GLOBALS['wp_test_remote_puts'] ), 'Manual credential store must trigger language sync' );

wp_test_reset( $wp_test_options );
$GLOBALS['wp_test_remote_puts']             = [];
$GLOBALS['wp_test_site_language_sync_fail'] = true;
SiteConnection::complete_connection(
	'00000000-0000-4000-8000-000000000001',
	'site-token'
);
assert_true( 1 === count( $GLOBALS['wp_test_remote_puts'] ), 'Pairing return completion must trigger language sync while failure flag is enabled' );
assert_deep_equal(
	Options::get( 'site.id' ),
	'00000000-0000-4000-8000-000000000001',
	'Pairing return completion must persist credentials when sync fails'
);
assert_deep_equal(
	Options::get( 'site.token' ),
	'site-token',
	'Pairing return completion must persist token when sync fails'
);
$GLOBALS['wp_test_site_language_sync_fail'] = false;

if ( $failures > 0 ) {
	fwrite( STDERR, "SiteLanguageSyncTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "SiteLanguageSyncTest ok\n" );

function assert_deep_equal( $actual, $expected, string $message ): void {
	global $failures;
	if ( $actual !== $expected ) {
		fwrite(
			STDERR,
			$message . ' expected ' . wp_json_encode( $expected ) . ' got ' . wp_json_encode( $actual ) . "\n"
		);
		$failures++;
	}
}
