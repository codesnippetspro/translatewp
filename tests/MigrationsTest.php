<?php

declare(strict_types=1);

use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\Migrations;
use TranslateWP\Components\Options;
use TranslateWP\Includes\Loader;
use TranslateWP\Includes\Lyfecycle;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.5' );
}
if ( ! defined( 'TRANSLATEWP_NAME' ) ) {
	define( 'TRANSLATEWP_NAME', 'translatewp' );
}
if ( ! defined( 'TRANSLATEWP_PATH' ) ) {
	define( 'TRANSLATEWP_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'TRANSLATEWP_BASENAME' ) ) {
	define( 'TRANSLATEWP_BASENAME', 'translatewp/translatewp.php' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

// --- Fresh install: activation seeds the marker, no migration runs ---
wp_test_reset( [] );
Lyfecycle::activate( false );
assert_true( TRANSLATEWP_VERSION === get_option( 'translatewp_version', '' ), 'Activation must seed the version marker' );

// --- Existing install with the old default: migration rewrites it ---
wp_test_reset( [] );
Options::update( 'languages.switcher', [ 'highlight_bg' => '#2031CA', 'offset' => 24 ] );
Migrations::maybe_run();
$switcher = Options::get( 'languages.switcher', [] );
assert_true( '#0e3ecd' === ( $switcher['highlight_bg'] ?? '' ), 'Old default highlight_bg must migrate to #0e3ecd, got ' . ( $switcher['highlight_bg'] ?? 'none' ) );
assert_true( TRANSLATEWP_VERSION === get_option( 'translatewp_version', '' ), 'Migration must update the version marker' );

// --- User-customized value is never touched ---
wp_test_reset( [] );
Options::update( 'languages.switcher', [ 'highlight_bg' => '#FF00FF', 'offset' => 24 ] );
Migrations::maybe_run();
$switcher = Options::get( 'languages.switcher', [] );
assert_true( '#FF00FF' === ( $switcher['highlight_bg'] ?? '' ), 'User-customized highlight_bg must never be touched' );

// --- Already-migrated install does not re-run ---
wp_test_reset( [] );
Options::update( 'languages.switcher', [ 'highlight_bg' => '#2031CA', 'offset' => 24 ] );
update_option( 'translatewp_version', TRANSLATEWP_VERSION );
Migrations::maybe_run();
$switcher = Options::get( 'languages.switcher', [] );
assert_true( '#2031CA' === ( $switcher['highlight_bg'] ?? '' ), 'Current-version install must not re-run migrations' );

// --- The version marker is in the cleanup inventory ---
assert_true(
	in_array( 'translatewp_version', \TranslateWP\Components\CleanupInventory::INFRASTRUCTURE_OPTIONS, true ),
	'Version marker must be in the cleanup inventory'
);

// --- Lifecycle wiring: the Loader registers maybe_run on init,
// and firing the hook runs the migration. ---
wp_test_reset( [] );
Options::update( 'languages.switcher', [ 'highlight_bg' => '#2031CA', 'offset' => 24 ] );
$GLOBALS['wp_test_filters'] = [];
$GLOBALS['wp_test_action_registrations'] = [];
new Loader();
$migration_hook = null;
foreach ( $GLOBALS['wp_test_action_registrations'] as $registration ) {
	if ( [ Migrations::class, 'maybe_run' ] === $registration['callback'] ) {
		$migration_hook = $registration;
	}
}
assert_true( null !== $migration_hook && 'init' === $migration_hook['tag'], 'Loader must wire init to Migrations::maybe_run' );
assert_true( null !== $migration_hook && $migration_hook['priority'] < 10, 'the migration runs before the default-priority init work' );
// The stubs keep one callback per hook; run the registered one directly.
call_user_func( $migration_hook['callback'] );
$switcher = Options::get( 'languages.switcher', [] );
assert_true( '#0e3ecd' === ( $switcher['highlight_bg'] ?? '' ), 'Running the registered migration must migrate, got ' . ( $switcher['highlight_bg'] ?? 'none' ) );

// --- 0.9.5 migration: source-locale queue rows are dropped, others kept ---
wp_test_reset( [] );
update_option(
	'translatewp_languages',
	[
		'list' => [
			'pl' => [ 'locale' => 'pl', 'slug' => 'pl', 'active' => true, 'default' => true ],
			'pt' => [ 'locale' => 'pt', 'slug' => 'pt', 'active' => true, 'default' => false ],
		],
	]
);
$queue = new IndexQueue();
$queue->enqueue(
	[
		[ 'object_type' => 'page', 'object_id' => 1, 'url' => 'https://example.com/?p=1', 'language' => 'pl', 'priority' => 100 ],
		[ 'object_type' => 'page', 'object_id' => 2, 'url' => 'https://example.com/?p=2', 'language' => 'pl', 'priority' => 100 ],
		[ 'object_type' => 'page', 'object_id' => 3, 'url' => 'https://example.com/?p=3', 'language' => 'pt', 'priority' => 100 ],
	]
);
// The source locale is hidden from counts(); the raw rows show the precondition.
assert_true( 2 === count( array_filter( $queue->list_rows(), static fn ( array $row ): bool => 'pl' === $row['language'] ) ), 'Two pl rows must exist before the migration' );

update_option( 'translatewp_version', '0.9.4' );
Migrations::maybe_run();

$counts = $queue->counts();
assert_true( ! isset( $counts['pl'] ), 'The source locale rows must be gone after the 0.9.5 migration, got ' . wp_json_encode( array_keys( $counts ) ) );
assert_true( 1 === ( $counts['pt']['total'] ?? 0 ), 'Non-source rows must survive the 0.9.5 migration' );
assert_true( '0.9.5' === get_option( 'translatewp_version', '' ), 'The 0.9.5 migration must update the version marker' );

// --- 0.9.5 migration with no default locale configured leaves the queue alone ---
wp_test_reset( [] );
$queue = new IndexQueue();
$queue->enqueue(
	[
		[ 'object_type' => 'page', 'object_id' => 4, 'url' => 'https://example.com/?p=4', 'language' => 'fr', 'priority' => 100 ],
	]
);
update_option( 'translatewp_version', '0.9.4' );
Migrations::maybe_run();
assert_true( 1 === ( $queue->counts()['fr']['total'] ?? 0 ), 'With no default locale the migration must not delete any rows' );

// --- 1.0.0 migration: rows a visitor's browser reported for a file are dropped, page rows kept ---
wp_test_reset( [] );
$queue = new IndexQueue();
$queue->enqueue( [ [ 'object_type' => 'page', 'object_id' => 7, 'url' => 'https://example.com/about', 'language' => 'de', 'priority' => 100 ] ] );
$queue->enqueue_miss_page(
	[ 'object_type' => 'miss', 'object_id' => 991, 'url' => 'https://example.com/classes/undefined/locales/fr/translation.json', 'page_title' => '', 'language' => 'de', 'priority' => 100 ],
	[ [ 'groupId' => 'g', 'markerPayload' => '⟪item_0001⟫Hello' ] ]
);
$queue->enqueue_miss_page(
	[ 'object_type' => 'miss', 'object_id' => 992, 'url' => 'https://example.com/app.webmanifest', 'page_title' => '', 'language' => 'de', 'priority' => 100 ],
	[ [ 'groupId' => 'g', 'markerPayload' => '⟪item_0001⟫My app' ] ]
);
assert_true( 3 === count( $queue->list_rows() ), 'All three rows must exist before the 1.0.0 migration, got ' . count( $queue->list_rows() ) );
// The test pins TRANSLATEWP_VERSION below 1.0.0, so the 1.0.0 step is called on its own.
$migrate_1_0_0 = new ReflectionMethod( Migrations::class, 'migrate_1_0_0' );
$migrate_1_0_0->setAccessible( true );
$migrate_1_0_0->invoke( null );
$urls = array_column( $queue->list_rows(), 'url' );
assert_true( [ 'https://example.com/about' ] === $urls, 'The 1.0.0 migration must drop the file rows and keep the page row, got ' . wp_json_encode( $urls ) );

// --- 0.9.20 migration: the stored honest_indexing key is dropped, other seo keys kept ---
wp_test_reset( [] );
update_option( 'translatewp_version', '0.9.4' );
Options::update(
	'languages.seo',
	[
		'sitemaps'        => true,
		'output_hreflang' => false,
		'honest_indexing' => true,
	]
);
Migrations::maybe_run();
$seo = Options::get( 'languages.seo', [] );
assert_true( ! array_key_exists( 'honest_indexing', $seo ), 'The 0.9.20 migration must drop the stored honest_indexing key' );
assert_true( true === ( $seo['sitemaps'] ?? null ), 'The 0.9.18 migration must leave other seo keys untouched' );
assert_true( false === ( $seo['output_hreflang'] ?? null ), 'The 0.9.18 migration must leave other seo keys untouched' );

// --- An update keeps the switcher menu items a site stored ---
wp_test_reset( [] );
$GLOBALS['wp_test_nav_menus'] = [ 'main' ];
$switcher_item_id = wp_update_nav_menu_item( 1, 0, [
	'menu-item-type'  => 'custom',
	'menu-item-title' => 'Language Switcher',
	'menu-item-url'   => '#translatewp-language-switcher',
] );
$object_item_id = wp_update_nav_menu_item( 1, 0, [
	'menu-item-type'   => 'custom',
	'menu-item-object' => 'translatewp-language-switcher',
	'menu-item-title'  => 'Language Switcher',
	'menu-item-url'    => '',
] );
wp_update_nav_menu_item( 1, 0, [
	'menu-item-type'  => 'custom',
	'menu-item-title' => 'About',
	'menu-item-url'   => 'https://example.com/about',
] );
assert_true( 3 === count( (array) wp_get_nav_menu_items( 'main' ) ), 'Three menu items must exist before the migration' );
update_option( 'translatewp_version', '0.9.4' );
Migrations::maybe_run();
$remaining = array_map( static fn( $item ) => (string) $item->title, (array) wp_get_nav_menu_items( 'main' ) );
assert_true( 3 === count( $remaining ), 'The migrations must delete no menu item, got ' . implode( ', ', $remaining ) );

// --- 0.10.21 migration: an existing switcher option keeps the icon trigger ---
wp_test_reset( [] );
update_option( 'translatewp_version', '0.9.4' );
Options::update( 'languages.switcher', [ 'type' => 'dropdown', 'show_flag' => true, 'offset' => 24 ] );
Migrations::maybe_run();
$switcher = Options::get( 'languages.switcher', [] );
assert_true( 'icon' === ( $switcher['trigger'] ?? null ), 'an existing switcher option keeps the icon trigger, got ' . ( $switcher['trigger'] ?? 'none' ) );
assert_true( true === ( $switcher['show_flag'] ?? null ) && 24 === ( $switcher['offset'] ?? null ), 'The trigger migration must leave the other switcher keys untouched' );

// --- 0.10.21 migration: a site with no switcher option gets no trigger written ---
wp_test_reset( [] );
update_option( 'translatewp_version', '0.9.4' );
Migrations::maybe_run();
$switcher = Options::get( 'languages.switcher', null );
assert_true( ! is_array( $switcher ) || ! array_key_exists( 'trigger', $switcher ), 'A site without a switcher option must get the new default, not a stored icon trigger' );

// --- 0.10.21 migration: a stored trigger choice is never overwritten ---
wp_test_reset( [] );
update_option( 'translatewp_version', '0.9.4' );
Options::update( 'languages.switcher', [ 'trigger' => 'flag' ] );
Migrations::maybe_run();
assert_true( 'flag' === ( Options::get( 'languages.switcher', [] )['trigger'] ?? null ), 'A stored trigger choice must never be overwritten' );

if ( $failures > 0 ) {
	fwrite( STDERR, "MigrationsTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "MigrationsTest: all assertions passed\n";
