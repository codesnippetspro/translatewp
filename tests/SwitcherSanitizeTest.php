<?php

declare(strict_types=1);

use TranslateWP\Components\Options;
use TranslateWP\Functionality\Endpoints\Admin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

$failures = 0;

function assert_same_value( $actual, $expected, string $message ): void {
	global $failures;
	if ( $actual !== $expected ) {
		fwrite( STDERR, $message . ' expected ' . wp_json_encode( $expected ) . ' got ' . wp_json_encode( $actual ) . "\n" );
		$failures++;
	}
}

function switcher_update_request( array $data ) {
	return new class( $data ) {
		private array $data;

		public function __construct( array $data ) {
			$this->data = $data;
		}

		public function get_param( string $key ) {
			if ( 'option' === $key ) {
				return 'languages.switcher';
			}
			if ( 'data' === $key ) {
				return $this->data;
			}

			return null;
		}
	};
}

$stored = [
	'type'             => 'dropdown',
	'show_flag'        => true,
	'icon_style'       => 'circle',
	'text_color'       => '#393535',
	'background_color' => '#FFFFFF',
	'highlight_text'   => '#FFFFFF',
	'highlight_bg'     => '#2031CA',
	'offset'           => 32,
	'icon_size'        => 24,
	'padding'          => 12,
];

// --- A colour that is not a hex colour leaves the stored colour as it was ---
wp_test_reset( [ 'languages' => [ 'switcher' => $stored ] ] );
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'background_color' => 'ZZZZZZ', 'text_color' => '#0E3ECD' ] ) ) );
$saved = Options::get( 'languages.switcher', [] );
assert_same_value( $saved['background_color'], '#FFFFFF', 'A value that is not a hex colour must not replace the stored background colour' );
assert_same_value( $saved['text_color'], '#0E3ECD', 'A hex colour must be saved' );

// --- A colour with an alpha channel round-trips, and a legacy opaque colour stays valid ---
wp_test_reset( [ 'languages' => [ 'switcher' => $stored ] ] );
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'background_color' => '#0E3ECD80', 'text_color' => '#1a2b3c' ] ) ) );
$saved = Options::get( 'languages.switcher', [] );
assert_same_value( $saved['background_color'], '#0E3ECD80', 'A hex colour with an alpha channel must be saved unchanged' );
assert_same_value( $saved['text_color'], '#1a2b3c', 'A legacy opaque hex colour stays valid' );

// --- The border colour is sanitised like its siblings: alpha kept, a bad value leaves the stored one ---
wp_test_reset( [ 'languages' => [ 'switcher' => array_merge( $stored, [ 'border_color' => '#E2E2E4' ] ) ] ] );
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'border_color' => '#0e3ecdAA' ] ) ) );
assert_same_value( Options::get( 'languages.switcher' )['border_color'] ?? null, '#0e3ecdAA', 'A border colour with alpha is stored' );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'border_color' => 'not-a-colour' ] ) ) );
assert_same_value( Options::get( 'languages.switcher' )['border_color'] ?? null, '#0e3ecdAA', 'A border colour that is not hex leaves the stored one' );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'border_color' => '#abc' ] ) ) );
assert_same_value( Options::get( 'languages.switcher' )['border_color'] ?? null, '#abc', 'A three digit border colour is stored' );

// --- A type or icon style outside the known set is dropped ---
wp_test_reset( [ 'languages' => [ 'switcher' => $stored ] ] );
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'type' => 'marquee', 'icon_style' => 'hexagon', 'show_flag' => 0 ] ) ) );
$saved = Options::get( 'languages.switcher', [] );
assert_same_value( $saved['type'], 'dropdown', 'An unknown switcher type must not be saved' );
assert_same_value( $saved['icon_style'], 'circle', 'An unknown icon style must not be saved' );
assert_same_value( $saved['show_flag'], false, 'The flag toggle is stored as a boolean' );

// --- The native-names toggle is part of the patch: turning it off is stored ---
wp_test_reset( [ 'languages' => [ 'switcher' => $stored ] ] );
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'native_names' => false ] ) ) );
$saved = Options::get( 'languages.switcher' );
assert_same_value( $saved['native_names'], false, 'Turning native names off is stored' );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'native_names' => 1 ] ) ) );
$saved = Options::get( 'languages.switcher' );
assert_same_value( $saved['native_names'], true, 'Turning native names back on is stored as a boolean' );

// --- Regional language names are stored as full or short; any other value leaves the stored choice ---
wp_test_reset( [ 'languages' => [ 'switcher' => $stored ] ] );
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
foreach ( [ 'transparent', 'glass', 'solid' ] as $style ) {
	$admin->update_data( switcher_update_request( array_merge( $stored, [ 'background_style' => $style ] ) ) );
	assert_same_value( Options::get( 'languages.switcher' )['background_style'] ?? null, $style, "The {$style} background is stored" );
}
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'background_style' => 'glass' ] ) ) );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'background_style' => 'neon' ] ) ) );
assert_same_value( Options::get( 'languages.switcher' )['background_style'] ?? null, 'glass', 'An unknown background leaves the stored one' );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'regional_names' => 'short' ] ) ) );
assert_same_value( Options::get( 'languages.switcher' )['regional_names'] ?? null, 'short', 'Short regional names are stored' );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'regional_names' => 'medium' ] ) ) );
assert_same_value( Options::get( 'languages.switcher' )['regional_names'] ?? null, 'short', 'An unknown regional names value leaves the stored choice' );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'regional_names' => 'full' ] ) ) );
assert_same_value( Options::get( 'languages.switcher' )['regional_names'] ?? null, 'full', 'Full regional names are stored' );

// --- The closed switcher trigger is stored as name_flag, flag or icon; any other value leaves the stored choice ---
wp_test_reset( [ 'languages' => [ 'switcher' => $stored ] ] );
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
foreach ( [ 'flag', 'icon', 'name_flag' ] as $trigger ) {
	$admin->update_data( switcher_update_request( array_merge( $stored, [ 'trigger' => $trigger ] ) ) );
	assert_same_value( Options::get( 'languages.switcher' )['trigger'] ?? null, $trigger, "The {$trigger} trigger is stored" );
}
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'trigger' => 'banner' ] ) ) );
assert_same_value( Options::get( 'languages.switcher' )['trigger'] ?? null, 'name_flag', 'An unknown trigger leaves the stored choice' );

// --- Pixel values are whole numbers within bounds; a custom CSS value is never stored ---
wp_test_reset( [ 'languages' => [ 'switcher' => $stored ] ] );
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'offset' => '-8', 'icon_size' => '9999', 'padding' => 'abc', 'custom_css' => '.x{color:red}<script>alert(1)</script>' ] ) ) );
$saved = Options::get( 'languages.switcher', [] );
assert_same_value( $saved['offset'], 0, 'A negative offset is clamped to zero' );
assert_same_value( $saved['icon_size'], 500, 'An oversized icon size is clamped to the ceiling' );
assert_same_value( $saved['padding'], 12, 'A padding value that is not a number leaves the stored value' );
assert_same_value( array_key_exists( 'custom_css', $saved ), false, 'A custom CSS value sent with the switcher settings is not stored' );

foreach ( [ 'top-left', 'top-right', 'bottom-left', 'bottom-right' ] as $anchor ) {
	$admin->update_data( switcher_update_request( [ 'floating_position' => [ 'anchor' => $anchor, 'x' => '12.9', 'y' => 0, 'untrusted' => 'discard' ] ] ) );
	assert_same_value( Options::get( 'languages.switcher.floating_position' ), [ 'anchor' => $anchor, 'x' => 12, 'y' => 0 ], 'Every corner is sanitized and unknown position keys are discarded' );
}
$position = [ 'anchor' => 'top-left', 'x' => 0, 'y' => 100000 ];
$admin->update_data( switcher_update_request( [ 'floating_position' => [ 'anchor' => 'top-left', 'x' => -8, 'y' => 999999 ] ] ) );
assert_same_value( Options::get( 'languages.switcher.floating_position' ), $position, 'Position pixels are bounded independently of appearance metrics' );
foreach ( [ [], true, [ 'anchor' => 'unknown', 'x' => 1, 'y' => 1 ], [ 'anchor' => 'top-left', 'x' => 'abc', 'y' => 1 ], [ 'anchor' => 'top-left', 'x' => NAN, 'y' => 1 ], [ 'anchor' => 'top-left', 'x' => 1 ] ] as $invalid ) {
	$admin->update_data( switcher_update_request( [ 'floating_position' => $invalid ] ) );
	assert_same_value( Options::get( 'languages.switcher.floating_position' ), $position, 'Invalid position patches preserve the stored position' );
}
$admin->update_data( switcher_update_request( [ 'offset' => 40 ] ) );
assert_same_value( Options::get( 'languages.switcher.floating_position' ), $position, 'An appearance save preserves the dragged position' );

// --- Saving the switcher purges page caches: the switcher config is printed into every page ---
function switcher_purges_fired(): array {
	return array_values( array_filter( $GLOBALS['wp_test_actions_fired'], static fn( array $action ): bool => 'translatewp_page_cache_purged' === $action['tag'] ) );
}
wp_test_reset( [ 'languages' => [ 'switcher' => $stored ] ] );
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'type' => 'native_select' ] ) ) );
$purges = switcher_purges_fired();
assert_same_value( count( $purges ), 1, 'Saving languages.switcher purges page caches once' );
assert_same_value( $purges[0]['args'][0] ?? null, 'switcher_saved', 'The purge names the switcher save as its reason' );

$GLOBALS['wp_test_actions_fired'] = [];
update_option( Admin::SETTINGS_REVISION_OPTION, 7, false );
$stale = new class( $stored ) {
	private array $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function get_param( string $key ) {
		return [ 'option' => 'languages.switcher', 'data' => $this->data, 'revision' => 3 ][ $key ] ?? null;
	}
};
$admin->update_data( $stale );
assert_same_value( count( switcher_purges_fired() ), 0, 'A refused switcher save does not purge page caches' );

// --- Only a switcher settings save purges: other options and the drag position do not ---
foreach ( [ 'languages.seo' => [ 'hreflang' => true ], 'translations.context' => wp_json_encode( 'A bakery in Utrecht.' ) ] as $other_option => $other_data ) {
	wp_test_reset( [ 'languages' => [ 'switcher' => $stored ] ] );
	$other = new class( $other_option, $other_data ) {
		private string $option;
		private $data;

		public function __construct( string $option, $data ) {
			$this->option = $option;
			$this->data   = $data;
		}

		public function get_param( string $key ) {
			return [ 'option' => $this->option, 'data' => $this->data ][ $key ] ?? null;
		}
	};
	$result = ( new Admin( 'translatewp', TRANSLATEWP_VERSION ) )->update_data( $other );
	assert_same_value( is_wp_error( $result ) ? $result->get_error_code() : ! empty( $result["success"] ), true, "Saving {$other_option} succeeds" );
	assert_same_value( count( switcher_purges_fired() ), 0, "Saving {$other_option} does not purge page caches" );
}

wp_test_reset( [ 'languages' => [ 'switcher' => $stored ] ] );
$admin  = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$dragged = $admin->save_switcher_position(
	new WP_REST_Request( 'POST', '/translatewp/v1/admin/switcher-position', [ 'position' => [ 'anchor' => 'top-left', 'x' => 4, 'y' => 8 ], 'revision' => Admin::settings_revision_value() ] )
);
assert_same_value( is_array( $dragged ) ? ( $dragged["success"] ?? null ) : null, true, 'The drag endpoint saves the position' );
assert_same_value( Options::get( 'languages.switcher.floating_position' ), [ 'anchor' => 'top-left', 'x' => 4, 'y' => 8 ], 'The drag endpoint stores the position' );
assert_same_value( count( switcher_purges_fired() ), 0, 'Dragging the switcher does not purge page caches' );

$GLOBALS['wp_test_actions_fired'] = [];
$admin->update_data( switcher_update_request( [ 'floating_position' => [ 'anchor' => 'bottom-left', 'x' => 10, 'y' => 12 ] ] ) );
assert_same_value( count( switcher_purges_fired() ), 0, 'A switcher save that only moves the position does not purge page caches' );

$GLOBALS['wp_test_actions_fired'] = [];
$admin->update_data( switcher_update_request( [ 'type' => 'native_select', 'floating_position' => [ 'anchor' => 'bottom-left', 'x' => 1, 'y' => 1 ] ] ) );
assert_same_value( count( switcher_purges_fired() ), 1, 'A switcher settings change purges page caches exactly once' );

// --- Reset position: an explicit null returns the floating switcher to its default corner ---
wp_test_reset( [ 'languages' => [ 'switcher' => array_merge( $stored, [ 'floating_position' => [ 'anchor' => 'bottom-left', 'x' => 10, 'y' => 12 ] ] ) ] ] );
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'floating_position' => null ] ) ) );
$saved = Options::get( 'languages.switcher', [] );
assert_same_value( array_key_exists( 'floating_position', $saved ), false, 'Reset position removes the saved floating position' );
assert_same_value( $saved['offset'] ?? null, $stored['offset'] ?? null, 'Reset position keeps the other switcher settings' );
assert_same_value( count( switcher_purges_fired() ), 1, 'Reset position purges page caches so visitors see the default corner' );

$GLOBALS['wp_test_actions_fired'] = [];
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'offset' => 30 ] ) ) );
assert_same_value( array_key_exists( 'floating_position', Options::get( 'languages.switcher', [] ) ), false, 'A save without a position after a reset keeps the default corner' );

$dragged_null = $admin->save_switcher_position(
	new WP_REST_Request( 'POST', '/translatewp/v1/admin/switcher-position', [ 'position' => null, 'revision' => Admin::settings_revision_value() ] )
);
assert_same_value( is_wp_error( $dragged_null ), true, 'The drag endpoint still refuses a null position' );

// --- The translating message is stored as plain text, trimmed, at most 200 characters; empty clears it ---
wp_test_reset( [ 'languages' => [ 'switcher' => $stored ] ] );
$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'pending_message' => '  Un momento, <b>traduciendo</b>…  ' ] ) ) );
assert_same_value( Options::get( 'languages.switcher' )['pending_message'] ?? null, 'Un momento, traduciendo…', 'The translating message is stored trimmed and without tags' );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'pending_message' => str_repeat( 'a', 260 ) ] ) ) );
assert_same_value( strlen( Options::get( 'languages.switcher' )['pending_message'] ?? '' ), 200, 'The translating message is cut at 200 characters' );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'pending_message' => '' ] ) ) );
assert_same_value( Options::get( 'languages.switcher' )['pending_message'] ?? null, '', 'An empty message is stored as empty so the default line shows' );
$admin->update_data( switcher_update_request( array_merge( $stored, [ 'pending_message' => [ 'not' => 'text' ] ] ) ) );
assert_same_value( Options::get( 'languages.switcher' )['pending_message'] ?? null, '', 'A value that is not text leaves the stored message' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SwitcherSanitizeTest: {$failures} failure(s)\n" );
	exit( 1 );
}

echo "SwitcherSanitizeTest: ok\n";
