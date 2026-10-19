<?php

declare(strict_types=1);

use TranslateWP\Components\SiteRequests;
use TranslateWP\Components\TranslationFingerprint;
use TranslateWP\Components\TranslationMarkerPayload;
use TranslateWP\Components\TranslationProRetry;
use TranslateWP\Components\TranslationProxyRateLimit;
use TranslateWP\Components\TranslationProxyStorage;
use TranslateWP\Functionality\Endpoints\TranslationJobs;

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

function assert_wp_error_code( $result, string $code ): void {
	assert_true( is_wp_error( $result ), "Expected WP_Error for {$code}" );
	if ( is_wp_error( $result ) ) {
		assert_true( $code === $result->get_error_code(), "Expected error code {$code}, got " . $result->get_error_code() );
	}
}

function make_jobs_endpoint(): TranslationJobs {
	return new TranslationJobs( 'translatewp', '0.0.0-test' );
}

function make_request( string $method, array $params = [], array $headers = [], string $body = '' ): WP_REST_Request {
	return new WP_REST_Request( $method, '/translatewp/v1/translation/test', $params, $headers, $body );
}

$wp_test_options = [
	'site'      => [
		'id'    => '00000000-0000-4000-8000-000000000001',
		'token' => 'secret-site-token',
	],
	'languages' => [
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

$golden = json_decode(
	(string) file_get_contents( dirname( __DIR__ ) . '/tests/fixtures/translation-fingerprint-golden.json' ),
	true
);
$basic_fixture = null;
foreach ( (array) ( $golden['vectors'] ?? [] ) as $vector ) {
	if ( is_array( $vector ) && 'basic-proxy-fixture' === ( $vector['id'] ?? '' ) ) {
		$basic_fixture = $vector;
		break;
	}
}

if ( ! is_array( $basic_fixture ) ) {
	fwrite( STDERR, "Missing basic-proxy-fixture golden vector\n" );
	exit( 1 );
}

$valid_hash = (string) ( $basic_fixture['hash'] ?? '' );

$jobs = make_jobs_endpoint();

$permission = $jobs->public_permissions_check(
	make_request( 'POST', [], [ 'origin' => 'https://evil.example' ] )
);
assert_wp_error_code( $permission, 'rest_forbidden' );

$permission = $jobs->public_permissions_check(
	make_request( 'POST', [], [ 'origin' => 'https://example.com' ] )
);
assert_true( true === $permission, 'Same-origin requests must pass the permission check' );

$create = $jobs->create_job(
	make_request(
		'POST',
		[
			'sourceLang' => 'en',
			'targetLang' => 'de',
		]
	)
);
assert_wp_error_code( $create, 'language_not_configured' );

wp_test_reset( $wp_test_options );
$GLOBALS['wp_test_bloginfo'] = [ 'name' => 'Demo Workshop' ];
$jobs = make_jobs_endpoint();

$create = $jobs->create_job(
	make_request(
		'POST',
		[
			'sourceLang' => 'en',
			'targetLang' => 'fr',
		]
	)
);
assert_true( ! is_wp_error( $create ), 'Configured target language must create a job' );
assert_true(
	false !== strpos( (string) ( $GLOBALS['wp_test_remote_posts'][0]['body']['context'] ?? '' ), 'Site: Demo Workshop' ),
	'create_job must send the auto context block with the site title'
);
assert_true(
	is_array( $create ) && '00000000-0000-4000-8000-000000000099' === ( $create['jobId'] ?? '' ),
	'create_job must return upstream jobId'
);
assert_true(
	1 === count( $GLOBALS['wp_test_remote_posts'] ),
	'create_job must proxy exactly one upstream POST'
);
assert_true(
	'/v1/translation/jobs' === $GLOBALS['wp_test_remote_posts'][0]['path'],
	'create_job must post to the fixed upstream jobs path'
);
assert_true(
	false === strpos( wp_json_encode( $create ), 'secret-site-token' ),
	'create_job response must not expose the site bearer token'
);

$job_id = '00000000-0000-4000-8000-000000000099';

$traversal = $jobs->job_status(
	make_request(
		'GET',
		[
			'jobId' => '../../../etc/passwd',
		]
	)
);
assert_wp_error_code( $traversal, 'invalid_parameter' );

$push = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => false,
			'items'      => [
				[
					'groupId'       => 'grp_00000001',
					'markerPayload' => '⟪item_0001⟫Hello',
					'contextKey'    => 'main > p',
					'contextKind'   => 'text-flow',
					'cache'         => [
						'siteId' => '00000000-0000-4000-8000-000000000001',
						'hash'   => 'deadbeef',
					],
				],
			],
		]
	)
);
assert_wp_error_code( $push, 'fingerprint_mismatch' );

$posts_before_poison = count( $GLOBALS['wp_test_remote_posts'] );
$poison_item = [
	'groupId'       => 'grp_00000013',
	'markerPayload' => '⟪item_0001⟫Hello⟪item_0002⟫   ',
	'contextKey'    => 'main > p',
	'contextKind'   => 'text-flow',
	'cache'         => [
		'siteId' => '00000000-0000-4000-8000-000000000001',
		'hash'   => $valid_hash,
	],
];
$poison = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => false,
			'items'      => [ $poison_item ],
		]
	)
);
assert_wp_error_code( $poison, 'invalid_marker_payload' );
assert_true(
	$posts_before_poison === count( $GLOBALS['wp_test_remote_posts'] ),
	'Poisonous marker payload must be rejected before upstream forwarding'
);

$marker_cases = [
	[ 'prefix text⟪item_0001⟫Hello', 'invalid_marker_payload' ],
	[ 'Hello', 'invalid_marker_payload' ],
	[ '⟪item_0001⟫Hello⟪item_0001⟫World', 'invalid_marker_payload' ],
	[ '⟪item_0001⟫   ⟪item_0002⟫Hello', 'invalid_marker_payload' ],
	[ '⟪item_0001⟫Hello⟪item_0002⟫   ', 'invalid_marker_payload' ],
	[ '⟪item_0001⟫Hello⟪item_0002⟫' . "\xc2\xa0", 'invalid_marker_payload' ],
	[ "⟪item_0001⟫Hello\nworld", true ],
];
foreach ( $marker_cases as $case ) {
	$result = TranslationMarkerPayload::validate( (string) $case[0] );
	if ( true === $case[1] ) {
		assert_true( true === $result, 'Valid marker payload must pass mirror validation' );
		continue;
	}

	assert_wp_error_code( $result, (string) $case[1] );
}

$invalid_close_input = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => 'true',
			'items'      => [],
		]
	)
);
assert_wp_error_code( $invalid_close_input, 'invalid_parameter' );

$unknown_item_field = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => false,
			'items'      => [
				array_merge(
					[
						'groupId'       => 'grp_00000001',
						'markerPayload' => '⟪item_0001⟫Hello',
						'contextKey'    => 'main > p',
						'contextKind'   => 'text-flow',
						'cache'         => [
							'siteId' => '00000000-0000-4000-8000-000000000001',
							'hash'   => $valid_hash,
						],
					],
					[ 'extraField' => 'nope' ]
				),
			],
		]
	)
);
assert_wp_error_code( $unknown_item_field, 'invalid_parameter' );

$disabled_options                     = $wp_test_options;
$disabled_options['languages']['list']['de'] = [
	'locale'  => 'de',
	'slug'    => 'de',
	'active'  => false,
	'default' => false,
];
wp_test_reset( $disabled_options );
$jobs = make_jobs_endpoint();
$disabled_language = $jobs->create_job(
	make_request(
		'POST',
		[
			'sourceLang' => 'en',
			'targetLang' => 'de',
		]
	)
);
assert_wp_error_code( $disabled_language, 'language_not_configured' );
wp_test_reset( $wp_test_options );
$jobs = make_jobs_endpoint();
$GLOBALS['wp_test_remote_posts'] = [];
$jobs->create_job(
	make_request(
		'POST',
		[
			'sourceLang' => 'en',
			'targetLang' => 'fr',
		]
	)
);

$push = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => true,
			'items'      => [
				[
					'groupId'       => 'grp_00000001',
					'markerPayload' => '⟪item_0001⟫Hello',
					'contextKey'    => 'main > p',
					'contextKind'   => 'text-flow',
					'cache'         => [
						'siteId' => '00000000-0000-4000-8000-000000000001',
						'hash'   => $valid_hash,
					],
				],
				[
					'groupId'       => 'grp_00000002',
					'markerPayload' => '',
				],
			],
		]
	)
);
assert_wp_error_code( $push, 'invalid_parameter' );
assert_true(
	1 === count( $GLOBALS['wp_test_remote_posts'] ),
	'Invalid mixed batch must not forward closeInput or partial batches upstream'
);

$valid_item = [
	'groupId'       => 'grp_00000001',
	'markerPayload' => '⟪item_0001⟫Hello',
	'contextKey'    => 'main > p',
	'contextKind'   => 'text-flow',
	'cache'         => [
		'siteId' => '00000000-0000-4000-8000-000000000001',
		'hash'   => $valid_hash,
	],
];

$missing_kind = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => false,
			'items'      => [
				[
					'groupId'       => 'grp_00000001',
					'markerPayload' => '⟪item_0001⟫Hello',
					'contextKey'    => 'main > p',
					'cache'         => [
						'siteId' => '00000000-0000-4000-8000-000000000001',
						'hash'   => $valid_hash,
					],
				],
			],
		]
	)
);
assert_wp_error_code( $missing_kind, 'invalid_parameter' );

$wrong_kind = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => false,
			'items'      => [
				[
					'groupId'       => 'grp_00000001',
					'markerPayload' => '⟪item_0001⟫Hello',
					'contextKey'    => 'main > p',
					'contextKind'   => 'alt',
					'cache'         => [
						'siteId' => '00000000-0000-4000-8000-000000000001',
						'hash'   => $valid_hash,
					],
				],
			],
		]
	)
);
assert_wp_error_code( $wrong_kind, 'fingerprint_mismatch' );

$non_string_group = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => false,
			'items'      => [
				[
					'groupId'       => 123,
					'markerPayload' => '⟪item_0001⟫Hello',
					'contextKey'    => 'main > p',
					'contextKind'   => 'text-flow',
					'cache'         => [
						'siteId' => '00000000-0000-4000-8000-000000000001',
						'hash'   => $valid_hash,
					],
				],
			],
		]
	)
);
assert_wp_error_code( $non_string_group, 'invalid_parameter' );

$non_string_cache_hash = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => false,
			'items'      => [
				[
					'groupId'       => 'grp_00000001',
					'markerPayload' => '⟪item_0001⟫Hello',
					'contextKey'    => 'main > p',
					'contextKind'   => 'text-flow',
					'cache'         => [
						'siteId' => '00000000-0000-4000-8000-000000000001',
						'hash'   => 123,
					],
				],
			],
		]
	)
);
assert_wp_error_code( $non_string_cache_hash, 'invalid_parameter' );

$unknown_cache_field = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => false,
			'items'      => [
				[
					'groupId'       => 'grp_00000001',
					'markerPayload' => '⟪item_0001⟫Hello',
					'contextKey'    => 'main > p',
					'contextKind'   => 'text-flow',
					'cache'         => [
						'siteId' => '00000000-0000-4000-8000-000000000001',
						'hash'   => $valid_hash,
						'extra'  => 'nope',
					],
				],
			],
		]
	)
);
assert_wp_error_code( $unknown_cache_field, 'invalid_parameter' );

$GLOBALS['wp_test_bloginfo'] = [ 'name' => 'Demo Workshop' ];
$push = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'batchId'    => 'batch_1',
			'closeInput' => true,
			'items'      => [ $valid_item ],
			'aiSettings' => [
				'tone'       => 'hacked',
				'creativity' => 10,
			],
		]
	)
);
assert_true( ! is_wp_error( $push ), 'Valid batch item must be accepted' );
$last_post = $GLOBALS['wp_test_remote_posts'][ count( $GLOBALS['wp_test_remote_posts'] ) - 1 ];
assert_true(
	'/v1/translation/jobs/' . rawurlencode( $job_id ) . '/batches' === $last_post['path'],
	'push_batch must post to the fixed upstream batches path'
);
assert_true(
	isset( $last_post['body']['aiSettings']['tone'] ) && 'friendly' === $last_post['body']['aiSettings']['tone'],
	'push_batch must drop caller aiSettings and forward server settings'
);
assert_true(
	! isset( $last_post['body']['aiSettings']['creativity'] ) || 5 === (int) $last_post['body']['aiSettings']['creativity'],
	'Caller aiSettings overrides must not reach upstream'
);
assert_true(
	false !== strpos( (string) ( $last_post['body']['context'] ?? '' ), 'Site: Demo Workshop' ),
	'push_batch must send the auto context block with the site title'
);

$duplicate = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => false,
			'items'      => [ $valid_item ],
		]
	)
);
assert_wp_error_code( $duplicate, 'duplicate_segment' );

$job_id_two = '00000000-0000-4000-8000-000000000002';
TranslationProxyStorage::remember_job_languages( $job_id_two, 'en', 'fr' );
$other_job_duplicate = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id_two,
			'closeInput' => false,
			'items'      => [ $valid_item ],
		]
	)
);
assert_wp_error_code( $other_job_duplicate, 'duplicate_segment' );

wp_test_reset( $wp_test_options );
$jobs = make_jobs_endpoint();
$jobs->create_job(
	make_request(
		'POST',
		[
			'sourceLang' => 'en',
			'targetLang' => 'fr',
		]
	)
);
$GLOBALS['wp_test_batch_post_failures'] = 1;
$failed_forward = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => true,
			'items'      => [ $valid_item ],
		]
	)
);
assert_true( is_wp_error( $failed_forward ), 'Failed upstream forward must return WP_Error' );
$GLOBALS['wp_test_batch_post_failures'] = 0;
$retry_forward = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => true,
			'items'      => [ $valid_item ],
		]
	)
);
assert_true( ! is_wp_error( $retry_forward ), 'Segment claim must be released after failed upstream forward' );

$oversized_body = str_repeat( 'a', 524289 );
$too_large = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId' => $job_id,
			'items' => [],
		],
		[],
		$oversized_body
	)
);
assert_wp_error_code( $too_large, 'payload_too_large' );

wp_test_reset( $wp_test_options );
$jobs = make_jobs_endpoint();
$jobs->create_job(
	make_request(
		'POST',
		[
			'sourceLang' => 'en',
			'targetLang' => 'fr',
		]
	)
);

$close_only = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => true,
			'items'      => [],
		]
	)
);
assert_true( ! is_wp_error( $close_only ), 'closeInput with no items must succeed' );
$close_post = $GLOBALS['wp_test_remote_posts'][ count( $GLOBALS['wp_test_remote_posts'] ) - 1 ];
assert_true(
	true === ( $close_post['body']['closeInput'] ?? false ) && [] === ( $close_post['body']['items'] ?? null ),
	'closeInput-only requests must forward an empty items array'
);

wp_test_reset( $wp_test_options );
$jobs = make_jobs_endpoint();
$site_id = '00000000-0000-4000-8000-000000000001';
$client_ip = '203.0.113.10';
$status_bucket = TranslationProxyRateLimit::BUCKET_STATUS;
$status_limit  = TranslationProxyRateLimit::limit_for_bucket( $status_bucket );

for ( $index = 0; $index < $status_limit; $index++ ) {
	$result = TranslationProxyRateLimit::check( $status_bucket, $site_id, $client_ip );
	assert_true( true === $result, 'Status bucket requests within limit must pass' );
}
$limited = TranslationProxyRateLimit::check( $status_bucket, $site_id, $client_ip );
assert_wp_error_code( $limited, 'rate_limited' );

$mutation_bucket = TranslationProxyRateLimit::BUCKET_MUTATION;
$mutation_limit  = TranslationProxyRateLimit::limit_for_bucket( $mutation_bucket );
for ( $index = 0; $index < $mutation_limit; $index++ ) {
	$result = TranslationProxyRateLimit::check( $mutation_bucket, $site_id, '198.51.100.4' );
	assert_true( true === $result, 'Mutation bucket requests within limit must pass' );
}
$mutation_limited = TranslationProxyRateLimit::check( $mutation_bucket, $site_id, '198.51.100.4' );
assert_wp_error_code( $mutation_limited, 'rate_limited' );

// --- job filters: translatewp_translation_ai_settings runs before translatewp_translation_job_request_body sees aiSettings ---
wp_test_reset( $wp_test_options );
$jobs = make_jobs_endpoint();

$job_body_filter_calls = [];
wp_test_set_filter( 'translatewp_translation_job_request_body', static function ( array $body ) use ( &$job_body_filter_calls ) {
	$job_body_filter_calls[] = $body;
	$body['filtered_job'] = true;
	return $body;
} );
wp_test_set_filter( 'translatewp_translation_ai_settings', static function ( array $ai_settings ) {
	$ai_settings['custom_style'] = 'pirate';
	return $ai_settings;
} );

$jobs->create_job( make_request( 'POST', [ 'sourceLang' => 'en', 'targetLang' => 'fr' ] ) );

$job_post = $GLOBALS['wp_test_remote_posts'][ count( $GLOBALS['wp_test_remote_posts'] ) - 1 ];
assert_true( 1 === count( $job_body_filter_calls ), 'translatewp_translation_job_request_body must run exactly once per job POST' );
assert_true(
	isset( $job_body_filter_calls[0]['aiSettings']['custom_style'] ),
	'translatewp_translation_ai_settings must run before translatewp_translation_job_request_body sees aiSettings'
);
assert_true( true === ( $job_post['body']['filtered_job'] ?? false ), 'translatewp_translation_job_request_body must run before the job POST' );
assert_true(
	'pirate' === ( $job_post['body']['aiSettings']['custom_style'] ?? null ),
	'The filtered aiSettings must reach the job POST body'
);

// --- batch filters: translatewp_translation_batch_request_body receives (body, jobId), after ai_settings is assembled ---
wp_test_reset( $wp_test_options );
$jobs = make_jobs_endpoint();
$jobs->create_job( make_request( 'POST', [ 'sourceLang' => 'en', 'targetLang' => 'fr' ] ) );

$batch_filter_calls = [];
wp_test_set_filter( 'translatewp_translation_batch_request_body', static function ( array $body, string $received_job_id ) use ( &$batch_filter_calls ) {
	$batch_filter_calls[] = [
		'body'  => $body,
		'jobId' => $received_job_id,
	];
	$body['filtered_batch'] = true;
	return $body;
} );
wp_test_set_filter( 'translatewp_translation_ai_settings', static function ( array $ai_settings ) {
	$ai_settings['custom_style'] = 'pirate';
	return $ai_settings;
} );

$jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => false,
			'items'      => [ $valid_item ],
		]
	)
);

$batch_post = $GLOBALS['wp_test_remote_posts'][ count( $GLOBALS['wp_test_remote_posts'] ) - 1 ];
assert_true( 1 === count( $batch_filter_calls ), 'translatewp_translation_batch_request_body must run exactly once per batches POST' );
assert_true( $job_id === $batch_filter_calls[0]['jobId'], 'translatewp_translation_batch_request_body must receive the jobId argument' );
assert_true(
	isset( $batch_filter_calls[0]['body']['aiSettings']['custom_style'] ),
	'translatewp_translation_ai_settings must run before translatewp_translation_batch_request_body sees aiSettings'
);
assert_true( true === ( $batch_post['body']['filtered_batch'] ?? false ), 'translatewp_translation_batch_request_body must run before each batches POST' );

// --- pro_required retry: a rejected job POST is retried once without custom_style/custom_tone, and a notice is recorded ---
$pro_required_response = [
	'response' => [ 'code' => 403 ],
	'body'     => wp_json_encode( [ 'error' => 'pro_required' ] ),
];

wp_test_reset( $wp_test_options );
$jobs = make_jobs_endpoint();
$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
wp_test_set_filter( 'translatewp_translation_ai_settings', static function ( array $ai_settings ) {
	$ai_settings['custom_style'] = 'pirate';
	$ai_settings['custom_tone']  = 'salty';
	return $ai_settings;
} );
$GLOBALS['wp_test_translation_job_responses'] = [ $pro_required_response ];

$create = $jobs->create_job( make_request( 'POST', [ 'sourceLang' => 'en', 'targetLang' => 'fr' ] ) );
assert_true( ! is_wp_error( $create ), 'A single pro_required rejection must be retried once and then succeed' );
assert_true( 2 === count( $GLOBALS['wp_test_remote_posts'] ), 'pro_required must cause exactly one retry (two upstream job posts)' );
$retry_post = $GLOBALS['wp_test_remote_posts'][1];
assert_true(
	! isset( $retry_post['body']['aiSettings']['custom_style'] ) && ! isset( $retry_post['body']['aiSettings']['custom_tone'] ),
	'The retried job request must drop custom_style and custom_tone'
);

$_GET['page'] = 'translatewp';
ob_start();
TranslationProRetry::render_notice();
$notice_html = ob_get_clean();
unset( $_GET['page'] );
assert_true(
	false !== strpos( $notice_html, 'A Pro setting was not applied because this account is not on Pro.' ),
	'A pro_required retry must record an admin notice on the TranslateWP pages'
);
assert_true( false === get_transient( TranslationProRetry::NOTICE_TRANSIENT ), 'The notice must be shown (consumed) only once' );

// --- pro_required retry never loops: a second rejection is returned as-is ---
wp_test_reset( $wp_test_options );
$jobs = make_jobs_endpoint();
wp_test_set_filter( 'translatewp_translation_ai_settings', static function ( array $ai_settings ) {
	$ai_settings['custom_style'] = 'pirate';
	return $ai_settings;
} );
$GLOBALS['wp_test_translation_job_responses'] = [ $pro_required_response, $pro_required_response ];

$create = $jobs->create_job( make_request( 'POST', [ 'sourceLang' => 'en', 'targetLang' => 'fr' ] ) );
assert_wp_error_code( $create, 'remote_error' );
assert_true( 2 === count( $GLOBALS['wp_test_remote_posts'] ), 'A persistent pro_required must not be retried more than once' );

// --- pro_required retry applies to a batches POST the same way ---
wp_test_reset( $wp_test_options );
$jobs = make_jobs_endpoint();
$jobs->create_job( make_request( 'POST', [ 'sourceLang' => 'en', 'targetLang' => 'fr' ] ) );
wp_test_set_filter( 'translatewp_translation_ai_settings', static function ( array $ai_settings ) {
	$ai_settings['custom_tone'] = 'salty';
	return $ai_settings;
} );
$GLOBALS['wp_test_translation_batch_responses'] = [ $pro_required_response ];

$push = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => false,
			'items'      => [ $valid_item ],
		]
	)
);
assert_true( ! is_wp_error( $push ), 'A single pro_required rejection on a batch POST must be retried once and then succeed' );
$batch_posts_after = array_values(
	array_filter(
		$GLOBALS['wp_test_remote_posts'],
		static fn( array $post ): bool => (bool) preg_match( '#/batches$#', $post['path'] )
	)
);
assert_true( 2 === count( $batch_posts_after ), 'pro_required must cause exactly one batch retry' );
assert_true(
	! isset( $batch_posts_after[1]['body']['aiSettings']['custom_tone'] ),
	'The retried batch request must drop custom_tone'
);

// --- push_batch tags every forwarded item with the caller's canonical page url ---
wp_test_reset( $wp_test_options );
$jobs = make_jobs_endpoint();
$jobs->create_job( make_request( 'POST', [ 'sourceLang' => 'en', 'targetLang' => 'fr' ] ) );

$url_push = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => false,
			'items'      => [ $valid_item ],
			'url'        => 'https://example.com/pricing/',
		]
	)
);
assert_true( ! is_wp_error( $url_push ), 'A batch with a known page url must still be accepted' );
$url_post = $GLOBALS['wp_test_remote_posts'][ count( $GLOBALS['wp_test_remote_posts'] ) - 1 ];
assert_true(
	'https://example.com/pricing' === ( $url_post['body']['items'][0]['pageUrl'] ?? '' ),
	'push_batch must forward every item with the canonical page url, got ' . wp_json_encode( $url_post['body']['items'][0] ?? null )
);

// --- push_batch drops a cross-host page url instead of failing the batch ---
wp_test_reset( $wp_test_options );
$jobs = make_jobs_endpoint();
$jobs->create_job( make_request( 'POST', [ 'sourceLang' => 'en', 'targetLang' => 'fr' ] ) );

$hostile_url_push = $jobs->push_batch(
	make_request(
		'POST',
		[
			'jobId'      => $job_id,
			'closeInput' => false,
			'items'      => [ $valid_item ],
			'url'        => 'https://evil.example/pricing',
		]
	)
);
assert_true( ! is_wp_error( $hostile_url_push ), 'An invalid page url must not fail the batch' );
$hostile_url_post = $GLOBALS['wp_test_remote_posts'][ count( $GLOBALS['wp_test_remote_posts'] ) - 1 ];
assert_true(
	! array_key_exists( 'pageUrl', $hostile_url_post['body']['items'][0] ?? [] ),
	'A cross-host page url must not be forwarded as pageUrl, got ' . wp_json_encode( $hostile_url_post['body']['items'][0] ?? null )
);

// --- The quota handoff on the visitor path: a quota refusal at a batch of an open job is resent once with the own providers, the job is pinned, and the closing batch releases it ---
wp_test_reset( $wp_test_options );
$jobs = make_jobs_endpoint();
\TranslateWP\Components\QuotaHandoff::set_enabled( true );
$own_providers = [ [ 'provider' => 'openai', 'modelId' => 'gpt-5.2', 'apiKey' => 'sk-own' ] ];
wp_test_set_filter( 'translatewp_quota_handoff_providers', static fn ( $providers, $language ) => 'fr' === $language ? $own_providers : $providers );
$GLOBALS['wp_test_remote_posts'] = [];
$opened = $jobs->create_job( make_request( 'POST', [ 'sourceLang' => 'en', 'targetLang' => 'fr' ] ) );
assert_true( ! is_wp_error( $opened ) && ! isset( $GLOBALS['wp_test_remote_posts'][0]['body']['byok'] ), 'Visitor path: a job opens on the TranslateWP model while the quota lasts' );
$GLOBALS['wp_test_remote_posts'] = [];
$GLOBALS['wp_test_translation_batch_responses'] = [
	[ 'response' => [ 'code' => 429 ], 'body' => wp_json_encode( [ 'error' => 'Translation quota reached', 'code' => 'limit_exceeded', 'quota' => [ 'percent_available' => 0, 'window_ends_at' => null ] ] ) ],
	[ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'ok' => true, 'batchId' => 'batch_q', 'accepted' => 1 ] ) ],
];
\TranslateWP\Components\TranslationProxyDedupe::release_segments( '00000000-0000-4000-8000-000000000001', 'fr', [ $valid_hash ] );
$pushed = $jobs->push_batch( make_request( 'POST', [ 'jobId' => $job_id, 'closeInput' => false, 'items' => [ $valid_item ] ] ) );
assert_true( ! is_wp_error( $pushed ) && 1 === (int) ( $pushed['accepted'] ?? 0 ), 'Visitor path: the refused batch goes through on the resend, got ' . wp_json_encode( $pushed ) );
$batch_posts = $GLOBALS['wp_test_remote_posts'];
assert_true( 2 === count( $batch_posts ) && ! isset( $batch_posts[0]['body']['byok'] ) && $own_providers === ( $batch_posts[1]['body']['byok'] ?? null ), 'Visitor path: the batch is resent exactly once, with the own providers' );
assert_true( \TranslateWP\Components\QuotaHandoff::job_uses_own_providers( $job_id ) && \TranslateWP\Components\QuotaHandoff::is_active(), 'Visitor path: the job is pinned and the handoff is active' );

// A later batch of the same job (another page of the same visit) carries the providers at once; the closing one releases the pin.
$GLOBALS['wp_test_remote_posts'] = [];
$GLOBALS['wp_test_translation_batch_responses'] = [];
\TranslateWP\Components\TranslationProxyDedupe::release_segments( '00000000-0000-4000-8000-000000000001', 'fr', [ $valid_hash ] );
$jobs->push_batch( make_request( 'POST', [ 'jobId' => $job_id, 'closeInput' => false, 'items' => [ $valid_item ] ] ) );
assert_true( $own_providers === ( $GLOBALS['wp_test_remote_posts'][0]['body']['byok'] ?? null ), 'Visitor path: a later batch of the pinned job carries the own providers at once' );
$jobs->push_batch( make_request( 'POST', [ 'jobId' => $job_id, 'closeInput' => true, 'items' => [] ] ) );
assert_true( ! \TranslateWP\Components\QuotaHandoff::job_uses_own_providers( $job_id ), 'Visitor path: the closing batch releases the pin' );

// Two jobs side by side: one pinned, one not; each keeps its own routing.
\TranslateWP\Components\QuotaHandoff::clear();
\TranslateWP\Components\QuotaHandoff::pin_job( $job_id );
TranslationProxyStorage::remember_job_languages( $job_id_two, 'en', 'fr' );
$GLOBALS['wp_test_remote_posts'] = [];
\TranslateWP\Components\TranslationProxyDedupe::release_segments( '00000000-0000-4000-8000-000000000001', 'fr', [ $valid_hash ] );
$pinned_push = $jobs->push_batch( make_request( 'POST', [ 'jobId' => $job_id, 'closeInput' => false, 'items' => [ $valid_item ] ] ) );
\TranslateWP\Components\TranslationProxyDedupe::release_segments( '00000000-0000-4000-8000-000000000001', 'fr', [ $valid_hash ] );
$hosted_push = $jobs->push_batch( make_request( 'POST', [ 'jobId' => $job_id_two, 'closeInput' => false, 'items' => [ $valid_item ] ] ) );
$side_by_side = $GLOBALS['wp_test_remote_posts'];
assert_true( ! is_wp_error( $pinned_push ) && ! is_wp_error( $hosted_push ), 'Visitor path: both side-by-side pushes are accepted, got ' . wp_json_encode( [ is_wp_error( $pinned_push ) ? $pinned_push->get_error_code() : 'ok', is_wp_error( $hosted_push ) ? $hosted_push->get_error_code() : 'ok' ] ) );
assert_true( 2 === count( $side_by_side ) && isset( $side_by_side[0]['body']['byok'] ) && ! isset( $side_by_side[1]['body']['byok'] ), 'Visitor path: two open jobs keep their own routing, got ' . wp_json_encode( array_map( static fn ( $p ) => isset( $p['body']['byok'] ), $side_by_side ) ) );
// A cancel releases the job's pin.
\TranslateWP\Components\QuotaHandoff::pin_job( $job_id_two );
$GLOBALS['wp_test_remote_posts'] = [];
$cancelled = $jobs->cancel_job( make_request( 'POST', [ 'jobId' => $job_id_two ] ) );
assert_true( ! is_wp_error( $cancelled ) && ! \TranslateWP\Components\QuotaHandoff::job_uses_own_providers( $job_id_two ), 'Visitor path: a cancel releases the pin, got ' . ( is_wp_error( $cancelled ) ? $cancelled->get_error_code() : 'ok' ) );
\TranslateWP\Components\QuotaHandoff::set_enabled( false );
\TranslateWP\Components\QuotaHandoff::clear();

if ( $failures > 0 ) {
	fwrite( STDERR, "TranslationJobsProxyTest failed with {$failures} assertion(s)\n" );
	exit( 1 );
}

fwrite( STDOUT, "TranslationJobsProxyTest ok\n" );
