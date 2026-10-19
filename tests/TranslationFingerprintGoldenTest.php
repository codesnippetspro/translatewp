<?php

declare(strict_types=1);

use TranslateWP\Components\TranslationFingerprint;
use TranslateWP\Components\TranslationProxySettings;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$fixture_path = dirname( __DIR__ ) . '/tests/fixtures/translation-fingerprint-golden.json';
$golden       = json_decode( (string) file_get_contents( $fixture_path ), true );

if ( ! is_array( $golden ) || ! isset( $golden['vectors'] ) || ! is_array( $golden['vectors'] ) ) {
	fwrite( STDERR, "Golden fixture is invalid\n" );
	exit( 1 );
}

foreach ( $golden['vectors'] as $vector ) {
	if ( ! is_array( $vector ) ) {
		fwrite( STDERR, "Golden vector must be an object\n" );
		exit( 1 );
	}

	$id = (string) ( $vector['id'] ?? '' );
	$canonical = TranslationFingerprint::canonical_string(
		(string) ( $vector['markerPayload'] ?? '' ),
		(string) ( $vector['sourceLang'] ?? '' ),
		(string) ( $vector['targetLang'] ?? '' ),
		(string) ( $vector['contextKind'] ?? '' ),
		is_array( $vector['settings'] ?? null ) ? $vector['settings'] : []
	);

	if ( (string) ( $vector['canonical'] ?? '' ) !== $canonical ) {
		fwrite( STDERR, "Canonical mismatch for {$id}\n" );
		exit( 1 );
	}

	$hash = TranslationFingerprint::compute_hash(
		(string) ( $vector['markerPayload'] ?? '' ),
		(string) ( $vector['sourceLang'] ?? '' ),
		(string) ( $vector['targetLang'] ?? '' ),
		(string) ( $vector['contextKind'] ?? '' ),
		is_array( $vector['settings'] ?? null ) ? $vector['settings'] : []
	);

	if ( (string) ( $vector['hash'] ?? '' ) !== $hash ) {
		fwrite( STDERR, "Hash mismatch for {$id}\n" );
		exit( 1 );
	}
}

$reordered = null;
foreach ( $golden['vectors'] as $vector ) {
	if ( is_array( $vector ) && 'reordered-settings-keys' === ( $vector['id'] ?? '' ) ) {
		$reordered = $vector;
		break;
	}
}

if ( ! is_array( $reordered ) ) {
	fwrite( STDERR, "Missing reordered-settings-keys vector\n" );
	exit( 1 );
}

$sorted_settings = [
	'preserve_branded_terms'      => true,
	'localized_date_time_formats' => false,
	'enable_units_conversion'     => true,
	'context'                     => 'website',
	'writing_style'               => 'neutral',
	'tone'                        => 'neutral',
	'formality'                   => 0,
	'creativity'                  => 0,
	'lexicon_terms'               => [],
];

$sorted_canonical = TranslationFingerprint::canonical_string(
	'⟪item_0001⟫Hello',
	'en',
	'es',
	'text-flow',
	$sorted_settings
);

if ( (string) ( $reordered['canonical'] ?? '' ) !== $sorted_canonical ) {
	fwrite( STDERR, "Reordered settings keys must canonicalize identically\n" );
	exit( 1 );
}

$wp_test_options = [
	'site'         => [
		'id'    => '00000000-0000-4000-8000-000000000001',
		'token' => 'secret-site-token',
	],
	'languages'    => [
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
		],
	],
	'translations' => [
		'settings' => [
			'preserve_branded_terms'      => true,
			'localized_date_time_formats' => true,
			'enable_units_conversion'     => true,
		],
		'context'  => [
			'text_context' => '',
		],
		'style'    => [
			'type'       => 'neutral',
			'tone'       => 'friendly',
			'formality'  => 5,
			'creativity' => 5,
		],
	],
];
wp_test_reset( $wp_test_options );

$production_hash = TranslationFingerprint::compute_hash(
	'⟪item_0001⟫Hello',
	'en',
	'fr',
	'text-flow',
	TranslationProxySettings::ai_settings()
);

$basic_proxy_hash = '';
foreach ( $golden['vectors'] as $vector ) {
	if ( is_array( $vector ) && 'basic-proxy-fixture' === ( $vector['id'] ?? '' ) ) {
		$basic_proxy_hash = (string) ( $vector['hash'] ?? '' );
	}
}

if ( '' === $basic_proxy_hash || $basic_proxy_hash !== $production_hash ) {
	fwrite( STDERR, "Production localized settings hash must match basic-proxy-fixture\n" );
	exit( 1 );
}

fwrite( STDOUT, "TranslationFingerprintGoldenTest ok\n" );
