<?php

declare(strict_types=1);

use TranslateWP\Functionality\Endpoints\Admin;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.9.0' );
}

// Minimal WP_Query: returns the posts in $GLOBALS['wp_test_query_posts'].
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $posts = [];
		public int $found_posts = 0;
		public int $max_num_pages = 1;

		public function __construct( array $args = [] ) {
			$this->posts       = $GLOBALS['wp_test_query_posts'] ?? [];
			$this->found_posts = count( $this->posts );
		}
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy = '', $args = [] ) {
		return [];
	}
}

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

/**
 * Lists the lexicon terms for the given stored post titles.
 *
 * $texturized maps a post id to what get_the_title() returns after the
 * the_title filters ran (wptexturize turns ' into &#8217;).
 *
 * @return string[]
 */
function list_terms( array $stored_titles, array $texturized = [] ): array {
	wp_test_reset( [] );
	$GLOBALS['wp_test_query_posts'] = [];
	$GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] = [];
	foreach ( $stored_titles as $id => $title ) {
		$GLOBALS['wp_test_query_posts'][] = (object) [
			'ID'           => $id,
			'post_title'   => $title,
			'post_content' => '',
		];
		$GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ][] = [
			'ID'         => $id,
			'post_title' => $texturized[ $id ] ?? $title,
		];
	}

	$admin    = new Admin( 'translatewp', TRANSLATEWP_VERSION );
	$response = $admin->get_lexicon_terms( new WP_REST_Request( 'GET', '/translatewp/v1/admin/lexicon', [], [], '' ) );
	$data     = $response instanceof WP_REST_Response ? $response->get_data() : $response;

	return array_column( $data['items'] ?? [], 'term' );
}

// A term with an apostrophe is listed without HTML entities.
$terms = list_terms( [ 7 => "Planck's constant" ], [ 7 => 'Planck&#8217;s constant' ] );
assert_true(
	in_array( $terms[0] ?? '', [ "Planck's constant", 'Planck’s constant' ], true ),
	'a term with an apostrophe must be listed without HTML entities, got ' . wp_json_encode( $terms )
);

// A term already stored entity-encoded is decoded for display.
$terms = list_terms( [ 8 => 'Planck&#8217;s constant' ] );
assert_true( 'Planck’s constant' === ( $terms[0] ?? '' ), 'a stored encoded term must be listed decoded, got ' . wp_json_encode( $terms ) );

// Decoding happens exactly once: an escaped entity stays one level escaped.
$terms = list_terms( [ 9 => 'Show &amp;#8217; literally', 10 => 'AT&amp;T' ] );
assert_true( 'Show &#8217; literally' === ( $terms[0] ?? '' ), 'terms must be decoded once, not twice, got ' . wp_json_encode( $terms ) );
assert_true( 'AT&T' === ( $terms[1] ?? '' ), 'an ampersand must be listed as a character, got ' . wp_json_encode( $terms ) );

// The scan samples page titles decoded, so the model never sees (or proposes)
// an entity-encoded term. Stored titles are left untouched.
wp_test_reset( [] );
$GLOBALS['wp_test_post_types'] = [ 'page' ];
$GLOBALS[ wp_test_blog_store( 'wp_test_posts' ) ]          = [ 'page' => [ 21 ] ];
$GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] = [
	[
		'ID'           => 21,
		'post_type'    => 'page',
		'post_title'   => 'Planck&#8217;s constant &amp; you',
		'post_content' => '<p>Energy &lt;script&gt; quanta</p>',
	],
];
$scanning = new TranslateWP\Functionality\Endpoints\Scanning( 'translatewp', TRANSLATEWP_VERSION );
$collect  = new ReflectionMethod( $scanning, 'collect_samples' );
$collect->setAccessible( true );
$samples = $collect->invoke( $scanning );
$sample  = (string) ( $samples[0] ?? '' );
assert_true( str_contains( $sample, 'Planck’s constant & you' ), 'the scan sample must carry the decoded title, got ' . wp_json_encode( $samples ) );
assert_true( ! str_contains( $sample, '<script>' ), 'decoding the title must not turn encoded content into markup, got ' . wp_json_encode( $samples ) );
assert_true( 'Planck&#8217;s constant &amp; you' === $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ][0]['post_title'], 'the stored title must stay as it is' );

if ( $failures > 0 ) {
	fwrite( STDERR, "LexiconEntitiesTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "LexiconEntitiesTest: all assertions passed\n";
