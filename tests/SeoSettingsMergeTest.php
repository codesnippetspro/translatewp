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

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

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

function seo_update_request( array $data ) {
	return new class( $data ) {
		private array $data;

		public function __construct( array $data ) {
			$this->data = $data;
		}

		public function get_param( string $key ) {
			if ( 'option' === $key ) {
				return 'languages.seo';
			}
			if ( 'data' === $key ) {
				return $this->data;
			}

			return null;
		}
	};
}

$configured_languages = [
	'en-us' => [
		'locale'  => 'en-us',
		'slug'    => 'en-us',
		'active'  => true,
		'default' => true,
	],
	'es-es' => [
		'locale'  => 'es-es',
		'slug'    => 'es-es',
		'active'  => true,
		'default' => false,
	],
];

// --- A valid languages.seo save persists the sanitised ai_crawlers data ---
wp_test_reset( [ 'languages' => [ 'list' => $configured_languages ] ] );

$admin  = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$result = $admin->update_data(
	seo_update_request(
		[
			'ai_crawlers'     => [
				'preset_answer_engines' => true,
				'bots'                  => [
					'GPTBot' => [
						'default'   => 'deny',
						'languages' => [
							'en-us' => 'allow',
							'es-es' => 'inherit',
						],
					],
				],
			],
		]
	)
);

assert_true( is_array( $result ) && ! empty( $result['success'] ), 'A valid languages.seo save must succeed' );

$stored = Options::get( 'languages.seo', [] );
assert_true(
	true === ( $stored['ai_crawlers']['preset_answer_engines'] ?? null ),
	'preset_answer_engines must persist as submitted'
);
assert_deep_equal(
	$stored['ai_crawlers']['bots']['GPTBot'] ?? null,
	[
		'default'   => 'deny',
		'languages' => [
			'en-us' => 'allow',
			'es-es' => 'inherit',
		],
	],
	'GPTBot settings must persist exactly as submitted'
);

// --- Unknown bots, invalid decisions and unconfigured locales are stripped ---
wp_test_reset( [ 'languages' => [ 'list' => $configured_languages ] ] );

$admin = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$admin->update_data(
	seo_update_request(
		[
			'ai_crawlers' => [
				'bots' => [
					'GPTBot'        => [
						'default'   => 'bogus-decision',
						'languages' => [
							'en-us' => 'deny',
							'fr-fr' => 'allow',
							'es-es' => 'not-a-real-decision',
						],
					],
					'CustomBot123'        => [
						'default'   => 'deny',
						'languages' => [],
					],
					"Bad\nName"           => [
						'default'   => 'deny',
						'languages' => [],
					],
				],
			],
		]
	)
);

$stored_bots = Options::get( 'languages.seo.ai_crawlers.bots', [] );
assert_true( isset( $stored_bots['CustomBot123'] ), 'A valid site-supplied crawler name must be kept as a custom crawler' );
assert_true( ! isset( $stored_bots["Bad\nName"] ), 'A crawler name with a line break must be stripped' );
assert_true( isset( $stored_bots['GPTBot'] ), 'Allowed bot names must be kept' );
assert_true(
	'allow' === ( $stored_bots['GPTBot']['default'] ?? null ),
	'An invalid default decision must fall back to allow'
);
assert_deep_equal(
	$stored_bots['GPTBot']['languages'] ?? null,
	[ 'en-us' => 'deny' ],
	'Only configured-locale entries with a valid decision may survive sanitisation'
);

// --- Deep merge: a partial ai_crawlers-only save must not clobber the existing seo fields ---
wp_test_reset(
	[
		'languages' => [
			'list' => $configured_languages,
			'seo'  => [
				'url_structure'              => 'query_string',
				'force_lowercase'            => false,
				'remember_last_choice'       => false,
				'cookie_ttl'                 => 90,
				'redirect_after_switch'      => true,
				'output_hreflang'            => false,
				'include_x_default'          => false,
				'sitemaps'                   => false,
				'exclude_disabled_languages' => false,
				'redirect_changed_slugs'     => '302',
				'ai_crawlers'                => [
					'preset_answer_engines' => false,
					'bots'                  => [],
				],
			],
		],
	]
);

$admin  = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$result = $admin->update_data(
	seo_update_request(
		[
			'ai_crawlers' => [
				'preset_answer_engines' => true,
				'bots'                  => [
					'ClaudeBot' => [
						'default'   => 'deny',
						'languages' => [],
					],
				],
			],
		]
	)
);

assert_true( is_array( $result ) && ! empty( $result['success'] ), 'A partial languages.seo save must succeed' );

$stored = Options::get( 'languages.seo', [] );
assert_true( 'query_string' === ( $stored['url_structure'] ?? null ), 'Partial save must not clobber url_structure' );
assert_true( false === ( $stored['force_lowercase'] ?? null ), 'Partial save must not clobber force_lowercase' );
assert_true(
	false === ( $stored['remember_last_choice'] ?? null ),
	'Partial save must not clobber remember_last_choice'
);
assert_true( 90 === ( $stored['cookie_ttl'] ?? null ), 'Partial save must not clobber cookie_ttl' );
assert_true(
	true === ( $stored['redirect_after_switch'] ?? null ),
	'Partial save must not clobber redirect_after_switch'
);
assert_true( false === ( $stored['output_hreflang'] ?? null ), 'Partial save must not clobber output_hreflang' );
assert_true( false === ( $stored['include_x_default'] ?? null ), 'Partial save must not clobber include_x_default' );
assert_true( false === ( $stored['sitemaps'] ?? null ), 'Partial save must not clobber sitemaps' );
assert_true(
	false === ( $stored['exclude_disabled_languages'] ?? null ),
	'Partial save must not clobber exclude_disabled_languages'
);
assert_true(
	'302' === ( $stored['redirect_changed_slugs'] ?? null ),
	'Partial save must not clobber redirect_changed_slugs'
);
assert_true(
	true === ( $stored['ai_crawlers']['preset_answer_engines'] ?? null ),
	'Submitted ai_crawlers fields must be applied'
);
assert_true( isset( $stored['ai_crawlers']['bots']['ClaudeBot'] ), 'A submitted bot must be applied' );

// --- get_data casts empty ai_crawlers maps to objects so the admin reads {} not [] ---
wp_test_reset(
	[
		'languages' => [
			'list' => $configured_languages,
			'seo'  => [
				'ai_crawlers' => [
					'preset_answer_engines' => true,
					'bots'                  => [],
				],
			],
		],
	]
);

$admin    = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$returned = $admin->get_data( seo_update_request( [] ) );

assert_true( is_array( $returned ), 'get_data must return the languages.seo array' );
assert_true(
	is_object( $returned['ai_crawlers']['bots'] ?? null ),
	'An empty ai_crawlers.bots map must be returned as an object'
);
assert_true(
	false !== strpos( (string) wp_json_encode( $returned['ai_crawlers'] ), '"bots":{}' ),
	'An empty ai_crawlers.bots map must serialise as an object, not []'
);

// --- get_data casts an empty per-bot languages map to an object too ---
wp_test_reset(
	[
		'languages' => [
			'list' => $configured_languages,
			'seo'  => [
				'ai_crawlers' => [
					'bots' => [
						'GPTBot' => [
							'default'   => 'deny',
							'languages' => [],
						],
					],
				],
			],
		],
	]
);

$admin    = new Admin( 'translatewp', TRANSLATEWP_VERSION );
$returned = $admin->get_data( seo_update_request( [] ) );

assert_true(
	is_object( $returned['ai_crawlers']['bots']['GPTBot']['languages'] ?? null ),
	'An empty per-bot languages map must be returned as an object'
);
assert_true(
	false !== strpos( (string) wp_json_encode( $returned['ai_crawlers']['bots']['GPTBot'] ), '"languages":{}' ),
	'An empty per-bot languages map must serialise as an object, not []'
);

if ( $failures > 0 ) {
	fwrite( STDERR, "SeoSettingsMergeTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "SeoSettingsMergeTest: all assertions passed\n";
