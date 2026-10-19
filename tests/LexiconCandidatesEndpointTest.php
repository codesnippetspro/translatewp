<?php

declare(strict_types=1);

use TranslateWP\Functionality\Endpoints\LexiconCandidates;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

if ( ! defined( 'TRANSLATEWP_VERSION' ) ) {
	define( 'TRANSLATEWP_VERSION', '0.10.0' );
}

$failures = 0;

class Lexicon_Test_Request {
	private array $params;

	public function __construct( array $params ) {
		$this->params = $params;
	}

	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}
}

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

wp_test_reset(
	[
		'site'                 => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ],
		'languages'            => [
			'list' => [
				'it' => [ 'locale' => 'it', 'active' => true, 'default' => true ],
				'de' => [ 'locale' => 'de', 'active' => true, 'default' => false ],
				'fr' => [ 'locale' => 'fr', 'active' => false, 'default' => false ],
			],
		],
		'translations'         => [ 'context' => [ 'text_context' => 'A dance studio in Riga.' ] ],
	]
);

// --- The scan request carries the source locale, the active targets, the context and no inactive language ---
$request = LexiconCandidates::scan_request();
assert_true( 'it' === $request['sourceLocale'], 'the default language is the source locale, got ' . $request['sourceLocale'] );
assert_true( [ 'de' ] === $request['targetLocales'], 'only active non-default languages are targets, got ' . wp_json_encode( $request['targetLocales'] ) );
assert_true( 'A dance studio in Riga.' === $request['context'], 'the owner context travels with the scan' );
assert_true( is_array( $request['lexiconTerms'] ), 'the existing lexicon terms travel as a list' );

// --- Starting a scan posts that request to the service scan route ---
$endpoints = new LexiconCandidates( 'translatewp', TRANSLATEWP_VERSION );
$GLOBALS['wp_test_remote_response'] = [ 'response' => [ 'code' => 202 ], 'body' => wp_json_encode( [ 'scan' => [ 'id' => 'scan-1', 'state' => 'queued' ], 'started' => true ] ) ];
$result = $endpoints->start_scan( null );
$posts  = array_values( array_filter( $GLOBALS['wp_test_remote_posts'] ?? [], fn( $post ) => '/v1/site/lexicon/scans' === ( $post['path'] ?? '' ) ) );
assert_true( ! is_wp_error( $result ), 'a 202 from the service is returned as data: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
assert_true( 1 === count( $posts ), 'exactly one scan start reaches the service, got ' . count( $posts ) );
if ( 1 === count( $posts ) ) {
	$body = is_array( $posts[0]['body'] ?? null ) ? $posts[0]['body'] : json_decode( (string) ( $posts[0]['body'] ?? '' ), true );
	assert_true( 'it' === ( $body['sourceLocale'] ?? '' ) && [ 'de' ] === ( $body['targetLocales'] ?? [] ), 'the posted body is the built request' );
}

// --- Without a default language or targets the scan is refused locally, nothing reaches the service ---
wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ], 'languages' => [ 'list' => [] ] ] );
$refused = $endpoints->start_scan( null );
assert_true( is_wp_error( $refused ) && 'languages_missing' === $refused->get_error_code(), 'a site without languages is told to choose them first' );
assert_true( empty( $GLOBALS['wp_test_remote_posts'] ), 'nothing is posted for a site without languages' );

// --- A candidate action validates the id and action before the service is called, then posts the action to the candidate's route ---
$GLOBALS['wp_test_remote_posts'] = [];
$acted = $endpoints->act_on_candidate( new Lexicon_Test_Request( [ 'id' => '00000000-0000-4000-8000-00000000cccc', 'action' => 'restore', 'language' => 'de' ] ) );
assert_true( ! is_wp_error( $acted ), 'a valid action reaches the service' );
assert_true( '/v1/site/lexicon/candidates/00000000-0000-4000-8000-00000000cccc/action' === ( $GLOBALS['wp_test_remote_posts'][0]['path'] ?? '' ), 'the action posts to the candidate route' );
assert_true( [ 'action' => 'restore', 'language' => 'de' ] === ( $GLOBALS['wp_test_remote_posts'][0]['body'] ?? [] ), 'the action body carries the action and the language' );

$bad = $endpoints->act_on_candidate( new Lexicon_Test_Request( [ 'id' => 'nope', 'action' => 'add' ] ) );
assert_true( is_wp_error( $bad ) && 'validation_error' === $bad->get_error_code(), 'a malformed candidate id is refused' );
$bad_action = $endpoints->act_on_candidate( new Lexicon_Test_Request( [ 'id' => '00000000-0000-4000-8000-00000000cccc', 'action' => 'delete' ] ) );
assert_true( is_wp_error( $bad_action ) && 'validation_error' === $bad_action->get_error_code(), 'an unknown action is refused' );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "LexiconCandidatesEndpointTest: all assertions passed\n";
