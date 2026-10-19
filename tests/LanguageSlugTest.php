<?php

declare(strict_types=1);

use TranslateWP\Components\LanguageSlugs;
use TranslateWP\Components\Options;
use TranslateWP\Functionality\Endpoints\Admin;
use TranslateWP\Functionality\Endpoints\Management;

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

function slugs_by_locale( $list ): array {
	$slugs = [];
	foreach ( is_array( $list ) ? $list : [] as $language ) {
		$slugs[ (string) $language['locale'] ] = (string) $language['slug'];
	}
	ksort( $slugs );

	return $slugs;
}

// --- A new language takes its base subtag, or its whole locale in lower case when that slug is taken ---
assert_true( 'es' === LanguageSlugs::for_new( 'es-es', [] ), 'es-es alone takes es' );
assert_true( 'pt' === LanguageSlugs::for_new( 'pt-br', [] ), 'pt-br alone takes pt' );
assert_true( 'zh' === LanguageSlugs::for_new( 'zh-hans', [] ), 'zh-hans alone takes zh' );
assert_true( 'de-at' === LanguageSlugs::for_new( 'de-at', [ 'en', 'de' ] ), 'de-at takes de-at when de is taken' );
assert_true( 'de-at' === LanguageSlugs::for_new( 'DE_at', [ 'de' ] ), 'a locale is normalised to lower case with hyphens' );

function onboard( string $default_language, array $languages ) {
	$management = new Management( 'translatewp', TRANSLATEWP_VERSION );

	return $management->save_step(
		new WP_REST_Request(
			'POST',
			'/translatewp/v1/management/save-step',
			[
				'step'             => 'languages',
				'resume_step'      => 'translation',
				'default_language' => $default_language,
				'languages'        => $languages,
			]
		)
	);
}

// --- Onboarding: German then Austrian German keeps de and gives the second de-at ---
wp_test_reset( [] );
onboard( 'en-us', [ 'de', 'de-at' ] );
$saved = slugs_by_locale( Options::get( 'languages.list', [] ) );
assert_true( [ 'de' => 'de', 'de-at' => 'de-at', 'en-us' => 'en' ] === $saved, 'de then de-at gives de and de-at, got ' . wp_json_encode( $saved ) );

// --- Onboarding: a default en-us holds en, so en-gb takes en-gb ---
wp_test_reset( [] );
onboard( 'en-us', [ 'en-gb' ] );
$saved = slugs_by_locale( Options::get( 'languages.list', [] ) );
assert_true( [ 'en-gb' => 'en-gb', 'en-us' => 'en' ] === $saved, 'en-us default then en-gb gives en and en-gb, got ' . wp_json_encode( $saved ) );

// --- Onboarding: Austrian German holds de, so German is given de-de ---
wp_test_reset( [ 'setup' => [ 'step' => 'languages' ] ] );
$result = onboard( 'de-at', [ 'de' ] );
$saved  = slugs_by_locale( Options::get( 'languages.list', [] ) );
assert_true( ! ( $result instanceof WP_Error ), 'onboarding de-at then de is saved, got ' . wp_json_encode( $result ) );
assert_true( [ 'de' => 'de-de', 'de-at' => 'de' ] === $saved, 'onboarding gives German the suggested slug de-de, got ' . wp_json_encode( $saved ) );

// --- Finishing setup applies the same slugs ---
wp_test_reset( [] );
$result = ( new Management( 'translatewp', TRANSLATEWP_VERSION ) )->finish_setup(
	new WP_REST_Request(
		'POST',
		'/translatewp/v1/management/finish-setup',
		[
			'default_language' => 'de-at',
			'languages'        => [ 'de', 'es-es' ],
			'auto_translate'   => true,
			'context'          => '',
		]
	)
);
$saved = slugs_by_locale( Options::get( 'languages.list', [] ) );
assert_true( ! ( $result instanceof WP_Error ), 'finishing setup with de-at then de is saved, got ' . wp_json_encode( $result instanceof WP_Error ? $result->get_error_message() : '' ) );
assert_true( [ 'de' => 'de-de', 'de-at' => 'de', 'es-es' => 'es' ] === $saved, 'finishing setup gives German de-de, got ' . wp_json_encode( $saved ) );

// --- Settings: a list where two languages share a slug is refused and nothing is saved ---
$stored = [
	[ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ],
	[ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
];
wp_test_reset( [ 'languages' => [ 'list' => $stored ] ] );
$admin  = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$result = $admin->update_data(
	new WP_REST_Request(
		'POST',
		'/translatewp/v1/admin/set-data',
		[
			'option' => 'languages.list',
			'data'   => array_merge( $stored, [ [ 'locale' => 'de-at', 'slug' => 'de', 'active' => true, 'default' => false ] ] ),
		]
	)
);
assert_true( $result instanceof WP_Error && 'duplicate_language_slug' === $result->get_error_code(), 'a list with a shared slug is refused, got ' . wp_json_encode( $result ) );
assert_true( $result instanceof WP_Error && 400 === (int) ( $result->get_error_data()['status'] ?? 0 ), 'the refusal is a 400' );
assert_true( $result instanceof WP_Error && false !== strpos( $result->get_error_message(), '"de"' ), 'the refusal names the shared slug, got ' . ( $result instanceof WP_Error ? $result->get_error_message() : '' ) );
assert_true( $stored === Options::get( 'languages.list', [] ), 'a refused list leaves the saved languages as they were' );

// --- A clash suggests a slug the admin can accept ---
assert_true( 'de-at' === LanguageSlugs::suggest( 'de-at', [ 'en', 'de' ] ), 'a regional variant is offered its whole locale' );
assert_true( 'de-de' === LanguageSlugs::suggest( 'de', [ 'de' ] ), 'plain German is offered de-de' );
assert_true( 'en-us' === LanguageSlugs::suggest( 'en', [ 'en' ] ), 'plain English is offered en-us' );
assert_true( 'es-es' === LanguageSlugs::suggest( 'es', [ 'es' ] ), 'plain Spanish is offered es-es' );
assert_true( 'pt-pt' === LanguageSlugs::suggest( 'pt', [ 'pt' ] ), 'plain Portuguese is offered pt-pt' );
assert_true( 'de-2' === LanguageSlugs::suggest( 'de', [ 'de', 'de-de' ] ), 'when de-de is taken too, plain German is offered de-2' );
assert_true( 'de-3' === LanguageSlugs::suggest( 'de', [ 'de', 'de-de', 'de-2' ] ), 'the number counts up past taken suffixes' );
assert_true( 'lv-2' === LanguageSlugs::suggest( 'lv', [ 'lv' ] ), 'a base language without a usual region is offered a numbered slug' );

// --- The refusal carries the clashing language and the suggestion ---
$stored = [
	[ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ],
	[ 'locale' => 'de-at', 'slug' => 'de', 'active' => true, 'default' => false ],
];
wp_test_reset( [ 'languages' => [ 'list' => $stored ] ] );
$result = ( new Admin( 'translatewp', TRANSLATEWP_VERSION ) )->update_data(
	new WP_REST_Request(
		'POST',
		'/translatewp/v1/admin/set-data',
		[
			'option' => 'languages.list',
			'data'   => [
				[ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ],
				[ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
				[ 'locale' => 'de-at', 'slug' => 'de', 'active' => true, 'default' => false ],
			],
		]
	)
);
$data = $result instanceof WP_Error ? $result->get_error_data() : [];
assert_true( 'de' === ( $data['locale'] ?? '' ), 'the refusal names the new language, not the one already saved, got ' . wp_json_encode( $data ) );
assert_true( 'de-de' === ( $data['suggested_slug'] ?? '' ), 'the refusal suggests de-de for plain German, got ' . wp_json_encode( $data ) );
assert_true( 400 === (int) ( $data['status'] ?? 0 ), 'the refusal stays a 400' );

wp_test_reset( [ 'languages' => [ 'list' => $stored ] ] );
$result = ( new Admin( 'translatewp', TRANSLATEWP_VERSION ) )->update_data(
	new WP_REST_Request(
		'POST',
		'/translatewp/v1/admin/set-data',
		[
			'option' => 'languages.list',
			'data'   => [
				[ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ],
				[ 'locale' => 'de-at', 'slug' => 'en', 'active' => true, 'default' => false ],
			],
		]
	)
);
$data = $result instanceof WP_Error ? $result->get_error_data() : [];
assert_true( 'de-at' === ( $data['locale'] ?? '' ), 'a changed slug is the one refused, got ' . wp_json_encode( $data ) );
assert_true( 'de-at' === ( $data['suggested_slug'] ?? '' ), 'the changed language is offered its whole locale, got ' . wp_json_encode( $data ) );

// --- Stored collisions are repaired: the language whose locale matches keeps the slug, the default is never changed ---
wp_test_reset(
	[
		'languages' => [
			'list' => [
				'en-us' => [ 'locale' => 'en-us', 'slug' => 'en', 'active' => true, 'default' => true ],
				'de'    => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
				'de-at' => [ 'locale' => 'de-at', 'slug' => 'de', 'active' => true, 'default' => false ],
				'en-gb' => [ 'locale' => 'en-gb', 'slug' => 'en', 'active' => true, 'default' => false ],
			],
		],
	]
);
LanguageSlugs::repair_stored();
$repaired = slugs_by_locale( Options::get( 'languages.list', [] ) );
assert_true( [ 'de' => 'de', 'de-at' => 'de-at', 'en-gb' => 'en-gb', 'en-us' => 'en' ] === $repaired, 'shared slugs are repaired from the locale and the default keeps its slug, got ' . wp_json_encode( $repaired ) );
assert_true( '' === LanguageSlugs::duplicate( Options::get( 'languages.list', [] ) ), 'no slug is shared after the repair' );

// --- A list without collisions is left exactly as it is ---
$clean = [
	[ 'locale' => 'pt', 'slug' => 'pt', 'active' => true, 'default' => true ],
	[ 'locale' => 'ja', 'slug' => 'japanese', 'active' => true, 'default' => false ],
];
wp_test_reset( [ 'languages' => [ 'list' => $clean ] ] );
LanguageSlugs::repair_stored();
assert_true( $clean === Options::get( 'languages.list', [] ), 'a list with unique slugs, custom ones included, is not touched' );

// --- The repair runs on every load ---
wp_test_reset( [] );
new \TranslateWP\Functionality\LanguageSlugRepair( 'translatewp', TRANSLATEWP_VERSION );
assert_true( is_callable( $GLOBALS['wp_test_filters']['init'] ?? null ), 'the stored slugs are checked on init' );

if ( $failures > 0 ) {
	fwrite( STDERR, "LanguageSlugTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "LanguageSlugTest: all assertions passed\n";
