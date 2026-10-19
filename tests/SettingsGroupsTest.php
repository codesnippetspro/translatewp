<?php

declare(strict_types=1);

use TranslateWP\Components\CacheVersions;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\Options;
use TranslateWP\Components\TranslationFingerprint;
use TranslateWP\Components\TranslationProxySettings;
use TranslateWP\Functionality\Endpoints\Admin;
use TranslateWP\Functionality\KeepWarm;

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

function settings_request( string $option, array $data ): WP_REST_Request {
	return new WP_REST_Request( 'POST', '/translatewp/v1/admin/set-data', [
		'option' => $option,
		'data'   => $data,
	], [], '' );
}

function fired_actions(): array {
	return array_column( $GLOBALS['wp_test_actions_fired'] ?? [], 'tag' );
}

function connected_options(): array {
	return [
		'site'         => [
			'id'    => '00000000-0000-4000-8000-000000000001',
			'token' => 'secret-site-token',
		],
		'languages'    => [
			'list' => [
				'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
				'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
				'it' => [ 'locale' => 'it', 'slug' => 'it', 'active' => true, 'default' => false ],
			],
		],
		'translations' => [
			'settings' => [
				'preserve_branded_terms'      => true,
				'localized_date_time_formats' => true,
				'enable_units_conversion'     => true,
			],
			'context'  => [ 'text_context' => 'A shop for climbing gear.' ],
			'style'    => [ 'type' => 'neutral', 'tone' => 'friendly', 'formality' => 5, 'creativity' => 5 ],
		],
	];
}

function hash_for( string $language ): string {
	return TranslationFingerprint::compute_hash( '⟪item_0001⟫Shop the collection', 'en', $language, 'text-flow', TranslationProxySettings::ai_settings( $language ) );
}

// ---------------------------------------------------------------------------
// The fingerprint keys on the model profile only: lexicon terms and the
// localizer toggles leave it unchanged, a style or context change moves it.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$admin                   = new Admin( 'translatewp', TRANSLATEWP_VERSION );

$base = hash_for( 'de' );

$GLOBALS['wp_test_posts'][] = [
	'ID'          => 901,
	'post_type'   => 'twp_lexicon',
	'post_status' => 'publish',
	'post_title'  => 'Karabiner',
	'post_content' => 'Never translate the product name.',
];
$with_terms = TranslationFingerprint::compute_hash(
	'⟪item_0001⟫Shop the collection',
	'en',
	'de',
	'text-flow',
	array_merge( TranslationProxySettings::ai_settings( 'de' ), [ 'lexicon_terms' => [ [ 'term' => 'Karabiner', 'description' => 'keep' ] ] ] )
);
assert_true( $base === $with_terms, 'Lexicon terms must not change the fingerprint' );

$admin->update_data( settings_request( 'translations.settings', [ 'preserve_branded_terms' => true, 'localized_date_time_formats' => false, 'enable_units_conversion' => false ] ) );
assert_true( $base === hash_for( 'de' ), 'Localizer toggles must not change the fingerprint' );
assert_true( in_array( 'translatewp_localizer_settings_saved', fired_actions(), true ), 'A localizer toggle must fire the localizer action' );
assert_true( ! in_array( 'translatewp_translation_affecting_settings_saved', fired_actions(), true ), 'A localizer toggle must not mark every translation stale' );

$GLOBALS['wp_test_actions_fired'] = [];
$admin->update_data( settings_request( 'translations.settings', [ 'preserve_branded_terms' => false, 'localized_date_time_formats' => false, 'enable_units_conversion' => false ] ) );
assert_true( $base !== hash_for( 'de' ), 'The branded-terms instruction is a model setting and must move the fingerprint' );
assert_true( in_array( 'translatewp_translation_affecting_settings_saved', fired_actions(), true ), 'A model key in the content settings must mark every translation stale' );
assert_true( ! in_array( 'translatewp_localizer_settings_saved', fired_actions(), true ), 'An unchanged localizer toggle must not fire the localizer action' );

$GLOBALS['wp_test_actions_fired'] = [];
$admin->update_data( settings_request( 'translations.style', [ 'type' => 'formal', 'tone' => 'serious', 'formality' => 9, 'creativity' => 2 ] ) );
assert_true( in_array( 'translatewp_translation_affecting_settings_saved', fired_actions(), true ), 'A style save must mark every translation stale' );
assert_true( ! in_array( 'translatewp_localizer_settings_saved', fired_actions(), true ), 'A style save must not touch the localizer version' );

// ---------------------------------------------------------------------------
// Finished rows remember the lexicon and localizer versions they were made
// under; a bump reports them as waiting and the apply action requeues only
// those rows, as forced retranslations.
// ---------------------------------------------------------------------------
wp_test_reset( connected_options() );
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
$queue    = new IndexQueue();
$keepwarm = new KeepWarm( 'translatewp', TRANSLATEWP_VERSION );

$row_de = $queue->enqueue_push( [ 'object_type' => 'post', 'object_id' => 12, 'url' => 'https://example.com/shop/', 'language' => 'de', 'priority' => 0, 'payload' => '[]' ] );
$row_it = $queue->enqueue_push( [ 'object_type' => 'post', 'object_id' => 12, 'url' => 'https://example.com/shop/', 'language' => 'it', 'priority' => 0, 'payload' => '[]' ] );
foreach ( $queue->claim_rows( [ (int) $row_de, (int) $row_it ] ) as $claimed ) {
	$queue->complete( (int) $claimed['id'] );
}

$counts = $queue->stale_counts();
assert_true( [] === $counts['lexicon'] && [] === $counts['localizer'], 'Fresh rows must not read as waiting, got ' . wp_json_encode( $counts ) );

$keepwarm->mark_lexicon_stale();
$counts = $queue->stale_counts();
assert_true( [ 'de' => 1, 'it' => 1 ] === $counts['lexicon'], 'A lexicon bump must report every finished page per language, got ' . wp_json_encode( $counts ) );
assert_true( [] === $counts['localizer'], 'A lexicon bump must leave the localizer group alone' );

$requeued = KeepWarm::apply_lexicon_now();
assert_true( 2 === $requeued, 'Applying the lexicon must requeue the two waiting rows, got ' . $requeued );
$pending = $queue->counts();
assert_true( 1 === ( $pending['de']['pending_extract'] ?? 0 ) && 1 === ( $pending['it']['pending_extract'] ?? 0 ), 'Requeued rows return to the extract stage, got ' . wp_json_encode( $pending ) );
assert_true( 0 === KeepWarm::apply_lexicon_now(), 'A second apply with nothing waiting queues nothing' );

// A row finished after the bump is up to date; only the older one waits.
foreach ( $queue->claim_rows( [ (int) $row_de ] ) as $claimed ) {
	$queue->complete( (int) $claimed['id'] );
}
$keepwarm->mark_localizer_stale();
$row_fr = $queue->enqueue_push( [ 'object_type' => 'post', 'object_id' => 12, 'url' => 'https://example.com/shop/', 'language' => 'fr', 'priority' => 0, 'payload' => '[]' ] );
foreach ( $queue->claim_rows( [ (int) $row_fr ] ) as $claimed ) {
	$queue->complete( (int) $claimed['id'] );
}
$counts = $queue->stale_counts();
assert_true( [ 'de' => 1 ] === $counts['localizer'], 'Only the page finished before the localizer bump waits, got ' . wp_json_encode( $counts ) );
assert_true( 1 === KeepWarm::apply_localizer_now(), 'Applying the localizer must requeue the one waiting row' );

if ( $failures > 0 ) {
	fwrite( STDERR, "SettingsGroupsTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SettingsGroupsTest: all assertions passed\n";
