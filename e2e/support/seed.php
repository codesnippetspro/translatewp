<?php
/**
 * Puts the end-to-end test site in a known state: set up, connected (with
 * credentials the local service stub accepts), two languages, the default
 * switcher, and one page to visit.
 * Run: npx wp-env run tests-cli wp eval-file wp-content/translatewp-e2e/seed.php [switcher-json]
 */

use TranslateWP\Components\Options;

update_option(
    'translatewp_site',
    [
		'id' => '00000000-0000-4000-8000-0000000e2e00',
		'token' => 'e2e-site-token',
		'connection_status' => 'connected',
	]
);
update_option(
    'translatewp_setup',
    [
		'ended' => true,
		'restarted' => false,
		'step' => 'done',
	]
);
update_option( 'translatewp_version', defined( 'TRANSLATEWP_VERSION' ) ? TRANSLATEWP_VERSION : '0' );
Options::update(
	'languages.list',
	[
		'en-us' => [
			'locale' => 'en-us',
			'slug' => 'en',
			'active' => true,
			'default' => true,
		],
		'de'    => [
			'locale' => 'de',
			'slug' => 'de',
			'active' => true,
			'default' => false,
		],
	]
);

$switcher = isset( $args[0] ) ? json_decode( (string) $args[0], true ) : null;
Options::update(
	'languages.switcher',
	array_merge(
		[
			'type' => 'dropdown',
			'trigger' => 'name_flag',
			'background_style' => 'solid',
			'offset' => 24,
			'icon_size' => 24,
			'padding' => 12,
		],
		is_array( $switcher ) ? $switcher : []
	)
);

$switcher_page = get_page_by_path( 'e2e-switcher' );
if ( ! $switcher_page ) {
	wp_insert_post(
		[
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Switcher test page',
			'post_name'    => 'e2e-switcher',
			'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( 'A page long enough to scroll. ', 400 ) . '</p><!-- /wp:paragraph -->',
		]
	);
}
flush_rewrite_rules();

echo 'E2E_SEEDED ' . wp_json_encode( Options::get( 'languages.switcher', [] ) ) . "\n";
