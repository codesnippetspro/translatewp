<?php

declare(strict_types=1);

// Who may manage a site's translations. Administrators always may. A site
// admin can grant it to chosen users who can edit pages, and withdraw it.
// The grant belongs to one site of a network.

use TranslateWP\Components\Access;

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

const ADMIN  = 1;
const EDITOR = 7;
const AUTHOR = 8;
const OTHER  = 9;

function seed(): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_user_caps'] = [
		ADMIN  => [ 'manage_options', 'edit_pages', 'edit_posts' ],
		EDITOR => [ 'edit_pages', 'edit_posts' ],
		AUTHOR => [ 'edit_posts' ],
		OTHER  => [ 'edit_pages', 'edit_posts' ],
	];
	$GLOBALS['wp_test_users'] = [
		(object) [ 'ID' => ADMIN, 'display_name' => 'Ada Admin', 'user_email' => 'ada@example.com', 'roles' => [ 'administrator' ], 'blog_ids' => [ 1 ] ],
		(object) [ 'ID' => EDITOR, 'display_name' => 'Ed Editor', 'user_email' => 'ed@example.com', 'roles' => [ 'editor' ], 'blog_ids' => [ 1 ] ],
		(object) [ 'ID' => AUTHOR, 'display_name' => 'Aud Author', 'user_email' => 'aud@example.com', 'roles' => [ 'author' ], 'blog_ids' => [ 1 ] ],
		(object) [ 'ID' => OTHER, 'display_name' => 'Olu Other', 'user_email' => 'olu@example.com', 'roles' => [ 'editor' ], 'blog_ids' => [ 1 ] ],
	];
}

function acting_as( int $user_id ): void {
	$GLOBALS['wp_test_current_user'] = $user_id;
	$GLOBALS['wp_test_caps']         = $GLOBALS['wp_test_user_caps'][ $user_id ];
}

// --- administrators hold it without any grant ---
seed();
acting_as( ADMIN );
assert_true( Access::can_manage_translations() && Access::can_administer(), 'an administrator manages translations and administers the plugin' );
assert_true( ! Access::is_delegate(), 'an administrator is not held to the grant' );

// --- an editor does not, until granted; the grant never makes an administrator ---
acting_as( EDITOR );
assert_true( ! Access::can_manage_translations() && ! Access::can_administer(), 'an editor without the grant manages nothing' );
Access::set_granted( [ EDITOR ] );
assert_true( Access::can( Access::SETTINGS ) && Access::can( Access::LEXICON ) && ! Access::can( Access::EDIT ) && ! Access::can( Access::RETRANSLATE ), 'a granted editor manages the settings and the Lexicon, and neither edits translations nor retranslates' );
assert_true( ! Access::can_manage_translations(), 'a granted editor is not the full manager older addon bundles expect' );
assert_true( ! Access::can_administer(), 'a granted editor does not administer the plugin' );
assert_true( Access::is_delegate(), 'a granted editor is held to what the grant covers' );
acting_as( OTHER );
assert_true( ! Access::can_manage_translations(), 'the grant is for the chosen user only' );

// --- withdrawn, it is gone ---
Access::set_granted( [] );
acting_as( EDITOR );
assert_true( ! Access::can_manage_translations(), 'a withdrawn grant no longer works' );

// --- only users who can edit pages, and are not administrators, can be granted ---
seed();
$kept = Access::set_granted( [ EDITOR, AUTHOR, ADMIN, 4242, EDITOR ] );
assert_true( [ EDITOR ] === $kept && [ EDITOR ] === Access::granted_ids(), 'only eligible users are kept, each once, got ' . wp_json_encode( $kept ) );
acting_as( AUTHOR );
assert_true( ! Access::can_manage_translations(), 'a user who cannot edit pages cannot hold the grant' );

// --- a granted user who later loses the right to edit pages loses this too ---
Access::set_granted( [ EDITOR ] );
$GLOBALS['wp_test_user_caps'][ EDITOR ] = [ 'read' ];
acting_as( EDITOR );
assert_true( ! Access::can_manage_translations(), 'the grant depends on still being able to edit pages' );

// --- the list a site admin chooses from ---
seed();
Access::set_granted( [ OTHER ] );
$list = Access::eligible_users();
assert_true( [ EDITOR, OTHER ] === array_column( $list, 'id' ), 'the list holds the users who can edit pages and are not administrators, got ' . wp_json_encode( array_column( $list, 'id' ) ) );
assert_true( [ false, true ] === array_column( $list, 'granted' ) && 'Ed Editor' === $list[0]['name'], 'each entry names the user and says whether the grant is held' );

// --- WordPress itself sees the capability, for menus and for other code ---
seed();
Access::set_granted( [ EDITOR ] );
$caps = Access::filter_user_caps( [ 'edit_pages' => true ], [ Access::SETTINGS ], [ Access::SETTINGS, EDITOR ], (object) [ 'ID' => EDITOR, 'roles' => [ 'editor' ] ] );
assert_true( true === ( $caps[ Access::SETTINGS ] ?? false ) && true === ( $caps[ Access::LEXICON ] ?? false ) && empty( $caps[ Access::CAPABILITY ] ), 'a granted editor holds the settings and Lexicon capabilities in WordPress, not the full manager one' );
$caps = Access::filter_user_caps( [ 'edit_pages' => true ], [ Access::SETTINGS ], [ Access::SETTINGS, OTHER ], (object) [ 'ID' => OTHER, 'roles' => [ 'editor' ] ] );
assert_true( empty( $caps[ Access::CAPABILITY ] ), 'an editor without the grant does not' );
$caps = Access::filter_user_caps( [ 'manage_options' => true ], [ Access::CAPABILITY ], [ Access::CAPABILITY, ADMIN ], (object) [ 'ID' => ADMIN ] );
assert_true( true === ( $caps[ Access::CAPABILITY ] ?? false ), 'an administrator holds it by default' );
$caps = Access::filter_user_caps( [ 'edit_pages' => true ], [ 'manage_options' ], [ 'manage_options', EDITOR ], (object) [ 'ID' => EDITOR ] );
assert_true( empty( $caps['manage_options'] ), 'the grant never adds an administrator capability' );

// --- a network: the grant is made on one site and does not travel ---
seed();
Access::set_granted( [ EDITOR ] );
$GLOBALS['wp_test_current_blog'] = 2;
acting_as( EDITOR );
assert_true( [] === Access::granted_ids() && ! Access::can( Access::SETTINGS ), 'a grant on one site of a network is not a grant on another' );
$GLOBALS['wp_test_current_blog'] = 1;
assert_true( Access::can( Access::SETTINGS ), 'and it still holds on its own site' );

// --- what a granted user may read and change ---
foreach ( [ 'translations.settings', 'translations.context', 'translations.style', 'languages.switcher' ] as $path ) {
	assert_true( Access::is_delegated_setting( $path ), "{$path} is part of what the grant covers" );
}
foreach ( [ 'languages.list', 'languages.seo', 'advanced', 'setup', 'site', 'ai.model' ] as $path ) {
	assert_true( ! Access::is_delegated_setting( $path ), "{$path} stays with administrators" );
}
assert_true( Access::is_readable_setting( 'languages.list' ) && ! Access::is_readable_setting( 'advanced' ), 'a granted user can read the language list the settings are shown for, and nothing administrative' );

if ( $failures > 0 ) {
	fwrite( STDERR, "AccessTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "AccessTest: all assertions passed\n";
