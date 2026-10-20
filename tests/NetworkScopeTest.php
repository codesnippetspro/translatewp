<?php

declare(strict_types=1);

use TranslateWP\Components\NetworkAdmin;
use TranslateWP\Components\NetworkScope;
use TranslateWP\Components\SettingsScope;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.0.0-test' );
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

function network_admin_setup( bool $super = true ): void {
	wp_test_reset( [] );
	$GLOBALS['wp_test_multisite']    = true;
	$GLOBALS['wp_test_current_blog'] = 1;
	$GLOBALS['wp_test_super_admin']  = $super;
	$GLOBALS['wp_test_caps']         = $super ? [ 'manage_network_options', 'manage_options' ] : [ 'manage_options' ];
	$GLOBALS['wp_test_sites']        = [
		[ 'blog_id' => 1, 'blogname' => 'Primary', 'siteurl' => 'https://example.com' ],
		[ 'blog_id' => 2, 'blogname' => 'Sub &amp; A', 'siteurl' => 'https://a.example.com' ],
		[ 'blog_id' => 3, 'blogname' => 'Sub B', 'siteurl' => 'https://b.example.com' ],
	];
	$GLOBALS['wp_test_options_store']['languages']        = [ 'switcher' => [ 'text_color' => '#000001', 'type' => 'dropdown' ] ];
	$GLOBALS['wp_test_options_store']['blog_2_languages'] = [ 'switcher' => [ 'text_color' => '#000002', 'show_flag' => false, 'floating_position' => [ 'anchor' => 'top-left', 'x' => 1, 'y' => 1 ] ] ];
	$GLOBALS['wp_test_options_store']['blog_3_languages'] = [ 'switcher' => [ 'text_color' => '#000003' ] ];
}

function scope_group( array $data, string $group_id ): ?array {
	foreach ( $data['groups'] as $group ) {
		if ( $group_id === $group['id'] ) {
			return $group;
		}
	}

	return null;
}

function field( array $group, string $key ): ?array {
	foreach ( $group['fields'] as $field ) {
		if ( $key === $field['key'] ) {
			return $field;
		}
	}

	return null;
}

// --- the page lists every group the network can set, with its state, exceptions and value fields ---
network_admin_setup();
$data = NetworkScope::page_data();
$ids  = array_column( $data['groups'], 'id' );
foreach ( [ 'account', 'uninstall', 'ai_crawlers', 'switcher_appearance', 'seo_technical', 'translation_behaviour', 'visitor_language', 'style_tone', 'content_areas', 'role_permissions' ] as $group_id ) {
	assert_true( in_array( $group_id, $ids, true ), $group_id . ' is on the network page' );
}
$areas = scope_group( $data, 'content_areas' );
assert_true( [ 'translations.settings.translate_comments', 'translations.settings.translate_menus', 'translations.settings.translate_widgets' ] === array_column( $areas['fields'], 'key' ), 'the page areas group lists the three area toggles' );
$roles_group = scope_group( $data, 'role_permissions' );
assert_true( [ 'access.edit_translations', 'access.retranslate', 'access.manage_settings', 'access.manage_lexicon' ] === array_column( $roles_group['fields'] ?? [], 'key' ), 'who can manage translations lists the four capabilities' );
assert_true( [ 'role_map' ] === array_values( array_unique( array_column( $roles_group['fields'], 'type' ) ) ) && [ false ] === array_values( array_unique( array_column( $roles_group['fields'], 'pro' ) ) ), 'each is a role map, not a Pro field' );
assert_true( isset( $roles_group['fields'][0]['options']['editor'] ) && ! isset( $roles_group['fields'][0]['options']['administrator'] ), 'the site roles are offered, the administrator is not, got ' . wp_json_encode( $roles_group['fields'][0]['options'] ?? null ) );
assert_true( null === scope_group( $data, 'audience' ), 'who sees translations is gone from the network page' );
foreach ( [ 'target_languages', 'content_selection', 'ai_context', 'switcher_placement' ] as $group_id ) {
	assert_true( ! in_array( $group_id, $ids, true ), $group_id . ' has no network setting and is not listed' );
}
$appearance = scope_group( $data, 'switcher_appearance' );
assert_true( 'default' === $appearance['state'] && true === $appearance['switchable'] && 'Switcher appearance' === $appearance['label'], 'a group carries its label, state and whether it can be switched, got ' . wp_json_encode( $appearance ) );
assert_true( '#000001' === ( field( $appearance, 'languages.switcher.text_color' )['value'] ?? null ), 'without a network value the field starts from the main site value' );
assert_true( null === field( $appearance, 'languages.switcher.floating_position' ), 'placement is not a network field' );
assert_true( 'select' === ( field( $appearance, 'languages.switcher.regional_names' )['type'] ?? null ), 'regional names is a choice' );
$uninstall = scope_group( $data, 'uninstall' );
assert_true( 'locked' === $uninstall['state'] && false === $uninstall['switchable'] && 'boolean' === ( field( $uninstall, 'advanced.delete_on_uninstall' )['type'] ?? null ), 'a network-only group is locked with its value editor' );
assert_true( [] === scope_group( $data, 'account' )['fields'], 'the account has no value editor here' );
assert_true( ! in_array( 'translation_switch', $ids, true ), 'the network page lists no translation switch group' );
assert_true( [ [ 'id' => 1, 'name' => 'Primary' ], [ 'id' => 2, 'name' => 'Sub & A' ], [ 'id' => 3, 'name' => 'Sub B' ] ] === $data['subsites'], 'the page lists the subsites to choose from, with the stored entity decoded' );

// --- the state of a switchable group changes ---
assert_true( 'scope-saved' === NetworkScope::apply_post( [ 'op' => 'state', 'group' => 'switcher_appearance', 'state' => 'locked' ] ), 'a group state saves' );
assert_true( 'locked' === SettingsScope::state( 'switcher_appearance', 2 ), 'the saved state applies to subsites' );
assert_true( 'failed' === NetworkScope::apply_post( [ 'op' => 'state', 'group' => 'uninstall', 'state' => 'local' ] ), 'a network-only group cannot be delegated' );

// --- exceptions for chosen subsites ---
assert_true( 'scope-saved' === NetworkScope::apply_post( [ 'op' => 'exceptions', 'group' => 'switcher_appearance', 'sites' => [ '3' ], 'exception' => 'local' ] ), 'an exception saves' );
assert_true( 'local' === SettingsScope::state( 'switcher_appearance', 3 ) && 'locked' === SettingsScope::state( 'switcher_appearance', 2 ), 'the exception applies to the chosen subsite only' );
assert_true( [ '3' => 'local' ] === scope_group( NetworkScope::page_data(), 'switcher_appearance' )['exceptions'], 'the page shows the exceptions' );
assert_true( 'scope-saved' === NetworkScope::apply_post( [ 'op' => 'exceptions', 'group' => 'switcher_appearance', 'sites' => [ '3' ], 'exception' => 'clear' ] ), 'an exception clears' );
assert_true( 'locked' === SettingsScope::state( 'switcher_appearance', 3 ), 'a cleared exception returns to the group state' );

// --- a value for every subsite becomes the network value and replaces each subsite's own ---
network_admin_setup();
$status = NetworkScope::apply_post(
	[
		'op'       => 'value',
		'group'    => 'switcher_appearance',
		'apply_to' => 'all',
		'values'   => [
			'languages.switcher.text_color'  => '#123456',
			'languages.switcher.show_flag'   => '1',
			'languages.switcher.type'        => 'marquee',
			'languages.switcher.offset'      => '-4',
			'languages.switcher.custom_css'  => '.x{}<script>x</script>',
			'languages.switcher.pending_message' => '  Bitte <b>warten</b>  ',
			'languages.list'                 => 'ignored',
		],
	]
);
assert_true( 'scope-saved' === $status, 'a value for every subsite saves' );
$network = SettingsScope::network_values()['translatewp_languages']['switcher'] ?? [];
assert_true( 'Bitte warten' === ( $network['pending_message'] ?? null ), 'the translating message is a text field of the switcher group, stored trimmed and without tags, got ' . wp_json_encode( $network['pending_message'] ?? null ) );
assert_true( '#123456' === ( $network['text_color'] ?? null ) && true === ( $network['show_flag'] ?? null ), 'the network value is stored, got ' . wp_json_encode( $network ) );
assert_true( ! array_key_exists( 'type', $network ), 'a value outside the allowed choices is dropped' );
assert_true( 0 === ( $network['offset'] ?? null ) && ! array_key_exists( 'custom_css', $network ), 'numbers are bounded and a custom CSS value is not a field of the group' );
assert_true( ! isset( SettingsScope::network_values()['translatewp_languages']['list'] ), 'a key outside the group is never written' );
foreach ( [ 'languages', 'blog_2_languages', 'blog_3_languages' ] as $key ) {
	assert_true( '#123456' === ( $GLOBALS['wp_test_options_store'][ $key ]['switcher']['text_color'] ?? null ), $key . ' takes the value' );
}
assert_true( [ 'anchor' => 'top-left', 'x' => 1, 'y' => 1 ] === ( $GLOBALS['wp_test_options_store']['blog_2_languages']['switcher']['floating_position'] ?? null ), 'a subsite keeps its placement' );

// --- page areas and roles are applied network-wide like any other value ---
network_admin_setup();
$status = NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'content_areas', 'apply_to' => 'all', 'values' => [ 'translations.settings.translate_comments' => '0', 'translations.settings.translate_menus' => '1' ] ] );
assert_true( 'scope-saved' === $status, 'page areas save for every subsite' );
$network_settings = SettingsScope::network_values()['translatewp_translations']['settings'] ?? [];
assert_true( false === ( $network_settings['translate_comments'] ?? null ) && true === ( $network_settings['translate_menus'] ?? null ), 'the area toggles are stored as booleans, got ' . wp_json_encode( $network_settings ) );
assert_true( false === ( $GLOBALS['wp_test_options_store']['blog_2_translations']['settings']['translate_comments'] ?? null ), 'a subsite takes the area toggle' );
$status = NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'role_permissions', 'apply_to' => 'all', 'values' => [ 'access.manage_settings' => [ 'editor', 'not-a-role', 'administrator' ] ] ] );
assert_true( 'scope-saved' === $status, 'role permissions save without Pro' );
$network_access = SettingsScope::network_values()['translatewp_access'] ?? [];
assert_true( true === ( $network_access['manage_settings']['editor'] ?? null ) && false === ( $network_access['manage_settings']['author'] ?? null ) && ! isset( $network_access['manage_settings']['administrator'] ) && ! isset( $network_access['manage_settings']['not-a-role'] ), 'a full map is stored: ticked true, the other roles false, unknown roles and the administrator dropped, got ' . wp_json_encode( $network_access ) );
$status = NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'role_permissions', 'apply_to' => 'all', 'values' => [ 'access.manage_settings' => '' ] ] );
assert_true( 'scope-saved' === $status && [] === array_filter( SettingsScope::network_values()['translatewp_access']['manage_settings'] ?? [ 'x' => true ] ), 'no role ticked stores every role false' );
$GLOBALS['wp_test_filters'] = [];

// --- the advanced style controls are Pro fields of the style group ---
network_admin_setup();
$style_fields = array_column( scope_group( NetworkScope::page_data(), 'style_tone' )['fields'], 'pro', 'key' );
assert_true( true === ( $style_fields['translations.style.audience'] ?? null ) && true === ( $style_fields['translations.style.length'] ?? null ) && true === ( $style_fields['translations.style.cultural_adaptation'] ?? null ) && false === ( $style_fields['translations.style.formality'] ?? null ), 'audience, length and cultural adaptation are Pro; formality is not' );
add_filter( 'translatewp_pro_addon_active', static fn () => true );
NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'style_tone', 'apply_to' => 'all', 'values' => [ 'translations.style.audience' => ' Hospital staff ', 'translations.style.length' => 'shorter', 'translations.style.cultural_adaptation' => 'sideways' ] ] );
$network_style = SettingsScope::network_values()['translatewp_translations']['style'] ?? [];
assert_true( 'Hospital staff' === ( $network_style['audience'] ?? null ) && 'shorter' === ( $network_style['length'] ?? null ) && ! array_key_exists( 'cultural_adaptation', $network_style ), 'the style controls are stored sanitised and an unknown choice is dropped, got ' . wp_json_encode( $network_style ) );
$GLOBALS['wp_test_filters'] = [];

// --- a value for chosen subsites changes only those ---
network_admin_setup();
NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'switcher_appearance', 'apply_to' => 'chosen', 'sites' => [ '3' ], 'values' => [ 'languages.switcher.text_color' => '#654321' ] ] );
assert_true( '#654321' === ( $GLOBALS['wp_test_options_store']['blog_3_languages']['switcher']['text_color'] ?? null ), 'the chosen subsite takes the value' );
assert_true( '#000002' === ( $GLOBALS['wp_test_options_store']['blog_2_languages']['switcher']['text_color'] ?? null ), 'another subsite keeps its own' );
assert_true( ! isset( SettingsScope::network_values()['translatewp_languages'] ), 'chosen subsites leave the network value alone' );
assert_true( 'failed' === NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'switcher_appearance', 'apply_to' => 'chosen', 'sites' => [], 'values' => [ 'languages.switcher.text_color' => '#654321' ] ] ), 'chosen with no subsites saves nothing' );

// --- the border colour inherits and overrides like its sibling colours, alpha kept ---
network_admin_setup();
$appearance = scope_group( NetworkScope::page_data(), 'switcher_appearance' );
assert_true( '#E2E2E4' === ( field( $appearance, 'languages.switcher.border_color' )['value'] ?? null ), 'the border colour field starts from its default when nothing is stored' );
NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'switcher_appearance', 'apply_to' => 'all', 'values' => [ 'languages.switcher.border_color' => '#0e3ecd80', 'languages.switcher.text_color' => 'not-a-colour' ] ] );
assert_true( '#0e3ecd80' === ( SettingsScope::network_values()['translatewp_languages']['switcher']['border_color'] ?? null ), 'a border colour with alpha becomes the network value' );
foreach ( [ 'languages', 'blog_2_languages', 'blog_3_languages' ] as $key ) {
	assert_true( '#0e3ecd80' === ( $GLOBALS['wp_test_options_store'][ $key ]['switcher']['border_color'] ?? null ), $key . ' takes the border colour' );
}
assert_true( ! array_key_exists( 'text_color', SettingsScope::network_values()['translatewp_languages']['switcher'] ?? [] ), 'a colour that is not hex is dropped' );
NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'switcher_appearance', 'apply_to' => 'chosen', 'sites' => [ '3' ], 'values' => [ 'languages.switcher.border_color' => '#abc' ] ] );
assert_true( '#abc' === ( $GLOBALS['wp_test_options_store']['blog_3_languages']['switcher']['border_color'] ?? null ), 'a chosen subsite overrides the border colour' );
assert_true( '#0e3ecd80' === ( $GLOBALS['wp_test_options_store']['blog_2_languages']['switcher']['border_color'] ?? null ), 'another subsite keeps the inherited border colour' );

// --- network-only values and groups that span two options ---
network_admin_setup();
NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'uninstall', 'apply_to' => 'all', 'values' => [ 'advanced.delete_on_uninstall' => '1' ] ] );
assert_true( true === ( SettingsScope::network_values()['translatewp_advanced']['delete_on_uninstall'] ?? null ), 'the uninstall rule is stored for the network' );
add_filter( 'translatewp_pro_addon_active', static fn () => true );
NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'visitor_language', 'apply_to' => 'all', 'values' => [ 'languages.seo.cookie_ttl' => '12', 'translations.settings.auto_translate' => '0' ] ] );
$GLOBALS['wp_test_filters'] = [];
assert_true( 12 === ( SettingsScope::network_values()['translatewp_languages']['seo']['cookie_ttl'] ?? null ), 'a group spanning two options stores both, cookie length' );
assert_true( false === ( SettingsScope::network_values()['translatewp_translations']['settings']['auto_translate'] ?? null ), 'a group spanning two options stores both, auto-detect' );
assert_true( 'failed' === NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'target_languages', 'apply_to' => 'all', 'values' => [ 'languages.list' => '[]' ] ] ), 'a per-subsite group has no network value' );

// --- only a super admin changes the network scope ---
network_admin_setup( false );
assert_true( 'denied' === NetworkScope::apply_post( [ 'op' => 'state', 'group' => 'switcher_appearance', 'state' => 'locked' ] ), 'a subsite admin cannot change a group state' );
assert_true( 'denied' === NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'switcher_appearance', 'apply_to' => 'all', 'values' => [ 'languages.switcher.text_color' => '#123456' ] ] ), 'a subsite admin cannot set a network value' );
assert_true( 'default' === SettingsScope::state( 'switcher_appearance' ) && [] === SettingsScope::network_values(), 'a refused change writes nothing' );

// --- a stored state and its exceptions round-trip into the page, whatever the main site's exception ---
network_admin_setup();
$GLOBALS['wp_test_site_options']['translatewp_network_settings_scope'] = [
	'groups' => [
		'switcher_appearance' => [ 'state' => 'locked', 'exceptions' => [] ],
		'style_tone'          => [ 'state' => 'locked', 'exceptions' => [ 1 => 'local', 3 => 'local' ] ],
	],
];
$appearance = scope_group( NetworkScope::page_data(), 'switcher_appearance' );
assert_true( 'locked' === $appearance['state'] && [] === $appearance['exceptions'], 'a stored lock reaches the page, got ' . wp_json_encode( $appearance ) );
assert_true( SettingsScope::state( 'switcher_appearance', 2 ) === $appearance['state'], 'the page and enforcement read the same state' );
$style = scope_group( NetworkScope::page_data(), 'style_tone' );
assert_true( 'locked' === $style['state'], 'an exception for the main site does not change the group state shown, got ' . $style['state'] );
assert_true( [ '1' => 'local', '3' => 'local' ] === $style['exceptions'], 'the exceptions reach the page, got ' . wp_json_encode( $style['exceptions'] ) );
assert_true( 'locked' === SettingsScope::group_state( 'style_tone' ) && 'local' === SettingsScope::state( 'style_tone', 1 ) && 'locked' === SettingsScope::state( 'style_tone', 2 ), 'the group state and the per-subsite state are read by one reader' );

// --- the network page carries the scope section and no longer the copy migration ---
network_admin_setup();
$page = NetworkAdmin::page_data();
assert_true( isset( $page['scope']['groups'] ) && isset( $page['actions']['scope'] ) && isset( $page['actions']['nonces']['scope'] ), 'the network page carries the scope section and its form action' );
assert_true( ! isset( $page['migration'] ), 'the copy migration is gone from the network page' );

// --- a Pro-only value is not written without Pro ---
network_admin_setup();
$GLOBALS['wp_test_filters'] = [];
assert_true( 'failed' === NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'ai_crawlers', 'apply_to' => 'all', 'values' => [ 'languages.seo.ai_crawlers.preset_answer_engines' => '1' ] ] ), 'a Pro-only value alone saves nothing without Pro' );
NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'seo_technical', 'apply_to' => 'all', 'values' => [ 'languages.seo.output_hreflang' => '0', 'languages.seo.sitemaps' => '0' ] ] );
assert_true( false === ( $GLOBALS['wp_test_options_store']['blog_2_languages']['seo']['output_hreflang'] ?? null ), 'the free value in the same group still saves' );
assert_true( ! isset( $GLOBALS['wp_test_options_store']['blog_2_languages']['seo']['sitemaps'] ), 'the Pro value in the same group is dropped' );
add_filter( 'translatewp_pro_addon_active', static fn () => true );
assert_true( 'scope-saved' === NetworkScope::apply_post( [ 'op' => 'value', 'group' => 'ai_crawlers', 'apply_to' => 'all', 'values' => [ 'languages.seo.ai_crawlers.preset_answer_engines' => '1' ] ] ), 'with Pro the value saves' );
assert_true( true === ( $GLOBALS['wp_test_options_store']['blog_2_languages']['seo']['ai_crawlers']['preset_answer_engines'] ?? null ), 'and reaches a subsite' );
$GLOBALS['wp_test_filters'] = [];

// --- the main site's switcher position goes to every subsite, with Pro ---
network_admin_setup();
$GLOBALS['wp_test_options_store']['languages']['switcher']['floating_position'] = [ 'anchor' => 'bottom-right', 'x' => 40, 'y' => 60 ];
assert_true( 'pro-required' === NetworkScope::apply_post( [ 'op' => 'switcher_position' ] ), 'without Pro the position is not applied' );
assert_true( [ 'anchor' => 'top-left', 'x' => 1, 'y' => 1 ] === ( $GLOBALS['wp_test_options_store']['blog_2_languages']['switcher']['floating_position'] ?? null ), 'a subsite keeps its placement without Pro' );
add_filter( 'translatewp_pro_addon_active', static fn () => true );
assert_true( 'pro-required' === NetworkScope::apply_post( [ 'op' => 'switcher_position' ] ), 'the addon without an entitled plan does not apply the position' );
set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
assert_true( 'position-applied' === NetworkScope::apply_post( [ 'op' => 'switcher_position' ] ), 'with Pro the position is applied' );
assert_true( [ 'anchor' => 'bottom-right', 'x' => 40, 'y' => 60 ] === ( $GLOBALS['wp_test_options_store']['blog_2_languages']['switcher']['floating_position'] ?? null ), 'a subsite takes the main site position' );
assert_true( [ 'anchor' => 'bottom-right', 'x' => 40, 'y' => 60 ] === ( $GLOBALS['wp_test_options_store']['blog_3_languages']['switcher']['floating_position'] ?? null ), 'every subsite takes it' );
assert_true( '#000003' === ( $GLOBALS['wp_test_options_store']['blog_3_languages']['switcher']['text_color'] ?? null ), 'the rest of a subsite switcher is untouched' );
unset( $GLOBALS['wp_test_options_store']['languages']['switcher']['floating_position'] );
assert_true( 'no-position' === NetworkScope::apply_post( [ 'op' => 'switcher_position' ] ), 'a main site without a dragged position has nothing to apply' );

if ( $failures > 0 ) {
	fwrite( STDERR, "NetworkScopeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "NetworkScopeTest: all assertions passed\n";
