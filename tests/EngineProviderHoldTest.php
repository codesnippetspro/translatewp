<?php

namespace TranslateWP\Components {
	function time(): int { return $GLOBALS['fixture_time']; }
}
namespace TranslateWP\Functionality {
	function time(): int { return $GLOBALS['fixture_time']; }
}
namespace {
	use TranslateWP\Components\BackgroundEngine;
	use TranslateWP\Components\IndexQueue;
	use TranslateWP\Components\ProviderHold;
	use TranslateWP\Functionality\Indexer;
	use TranslateWP\Functionality\LexiconChanges;

	require dirname( __DIR__ ) . '/vendor/autoload.php';
	require __DIR__ . '/wp-test-stubs.php';

	function expect_hold( bool $ok, string $message ): void {
		if ( ! $ok ) { throw new \RuntimeException( $message ); }
	}
	function service_answer( array $body, int $code = 200 ): array {
		return [ 'response' => [ 'code' => $code ], 'body' => wp_json_encode( $body ) ];
	}
	function requests_to( string $suffix ): array {
		return array_values( array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $request ): bool => str_ends_with( $request['path'], $suffix ) ) );
	}
	function fixture(): array {
		$GLOBALS['fixture_time'] = time();
		wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'fixture' ], 'languages' => [ 'list' => [
			'en' => [ 'locale' => 'en', 'default' => true, 'active' => true ],
			'it' => [ 'locale' => 'it', 'active' => true ],
		] ] ] );
		update_option( Indexer::SPINE_CHECKED_OPTION, true );
		update_option( LexiconChanges::SEEDED_OPTION, true );
		$queue = new IndexQueue();
		$items = [
			[ 'groupId' => 'one', 'markerPayload' => '⟪item_0001⟫A dance class' ],
			[ 'groupId' => 'two', 'markerPayload' => '⟪item_0001⟫An evening performance' ],
		];
		$ids = [];
		foreach ( [ 101, 102 ] as $id ) {
			$ids[] = $queue->enqueue_push( [ 'object_type' => 'miss', 'object_id' => $id, 'url' => "https://example.com/page-$id/", 'language' => 'it', 'priority' => 1, 'payload' => wp_json_encode( $items ) ] );
		}
		$queue->track_refill( $ids[1] );
		$byok = static fn( array $body ): array => $body + [ 'byok' => [ [ 'provider' => 'openai', 'apiKey' => 'fixture', 'modelId' => 'fixture' ] ] ];
		wp_test_set_filter( 'translatewp_translation_job_request_body', $byok );
		wp_test_set_filter( 'translatewp_translation_batch_request_body', $byok );
		return $ids;
	}

	$ids = fixture();
	$failure = service_answer( [ 'state' => 'done', 'byok' => true, 'items' => [ 'total' => 2, 'succeeded' => 0, 'failed' => 2 ], 'failure' => [ 'code' => 'byok_invalid_key', 'retryAfterSeconds' => 840 ] ] );
	$GLOBALS['wp_test_remote_get_response'] = $failure;
	BackgroundEngine::step();
	BackgroundEngine::step();
	$hold = ProviderHold::state();
	expect_hold( 'invalid_key' === $hold['reason'] && 900 === $hold['due'] - $GLOBALS['fixture_time'], 'The first rejected key starts a fifteen-minute site hold' );
	expect_hold( 1 === count( requests_to( '/translation/jobs' ) ), 'Only one job opens before the first rejection' );
	for ( $i = 0; $i < 4; ++$i ) {
		++$GLOBALS['fixture_time'];
		$response = BackgroundEngine::step();
		expect_hold( $hold['due'] === $response['nextDue'], 'Every held step reports the same due time' );
	}
	( new Indexer( '', '', null, null, false ) )->drain_rows( $ids );
	expect_hold( 1 === count( requests_to( '/translation/jobs' ) ), 'Neither visitor nor worker opens a job during the hold' );
	foreach ( $ids as $id ) {
		expect_hold( 'pending' === get_post_meta( $id, '_twp_status', true ) && ! get_post_meta( $id, '_twp_attempts', true ) && ! get_post_meta( $id, '_twp_translated_at', true ), 'The row remains pending, uncharged and untranslated' );
	}
	$GLOBALS['fixture_time'] = $hold['due'];
	BackgroundEngine::step();
	BackgroundEngine::step();
	expect_hold( 2 === count( requests_to( '/translation/jobs' ) ) && 1800 === ProviderHold::state()['delay'], 'One failed probe doubles the hold' );
	$batches = requests_to( '/batches' );
	expect_hold( 1 === count( $batches[1]['body']['items'] ) && ! empty( $batches[1]['body']['byok'] ), 'The probe sends one item with the site provider' );
	$GLOBALS['fixture_time'] = ProviderHold::state()['due'];
	foreach ( $batches[0]['body']['items'] as $item ) {
		$GLOBALS['wp_test_cache_lookup_items'][ $item['cache']['hash'] ] = '⟪item_0001⟫Traduzione';
	}
	$GLOBALS['wp_test_remote_get_response'] = service_answer( [ 'state' => 'done', 'byok' => true, 'items' => [ 'total' => 1, 'succeeded' => 1, 'failed' => 0 ] ] );
	BackgroundEngine::step();
	BackgroundEngine::step();
	expect_hold( ! ProviderHold::state() && count( requests_to( '/translation/jobs' ) ) > 3, 'The working key clears the hold and resumes the queue within two steps' );

	foreach ( $ids as $id ) {
		expect_hold( (int) get_post_meta( $id, '_twp_translated_at', true ) > 0, 'Both the visitor row and refill row recover in the two steps' );
	}

	$ids = fixture();
	$GLOBALS['wp_test_translation_job_responses'] = [ service_answer( [ 'code' => 'byok_model_unavailable', 'retryAfterSeconds' => 1200 ], 409 ) ];
	BackgroundEngine::step();
	expect_hold( 'model_unavailable' === ProviderHold::state()['reason'] && 1200 === ProviderHold::state()['delay'] && ! requests_to( '/batches' ), 'A typed create refusal honours the service deadline and sends no batch' );
	$ids = fixture();
	$GLOBALS['wp_test_translation_batch_responses'] = [ service_answer( [ 'code' => 'byok_invalid_key', 'retryAfterSeconds' => 300 ], 409 ) ];
	BackgroundEngine::step();
	expect_hold( 900 === ProviderHold::state()['delay'] && 'pending' === get_post_meta( $ids[0], '_twp_status', true ), 'The first rejected batch releases its row without charging it' );
	$_GET['page'] = 'translatewp';
	$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
	ob_start();
	( new \TranslateWP\Functionality\CronFallback( '', '' ) )->render_cron_late_notice();
	$notice = ob_get_clean();
	expect_hold( str_contains( $notice, 'Your AI provider did not accept the key. Translation is waiting.' ) && str_contains( $notice, 'translatewp-settings#ai-model' ), 'The Dashboard names the cause and links to AI models' );
	unset( $_GET['page'] );

	$ids = fixture();
	$indexer = new Indexer( '', '', null, null, false );
	$queue = new IndexQueue();
	$queue->remember_job( $ids[0], 'old-job' );
	$GLOBALS['wp_test_remote_get_sequence'] = [
		service_answer( [ 'state' => 'done', 'byok' => true ] ),
		service_answer( [ 'events' => [
			[ 'kind' => 'provider_error', 'reasonCode' => 'invalid_key', 'detail' => [ 'jobId' => 'another-job' ] ],
			[ 'kind' => 'provider_error', 'reasonCode' => 'model_unavailable', 'detail' => [ 'jobId' => 'old-job', 'count' => 2 ] ],
		] ] ),
	];
	$known = [];
	expect_hold( 'failed' === $indexer->job_outcome_for_row( $ids[0], $known ) && 'model_unavailable' === ProviderHold::state()['reason'], 'Old-service events must match the job before they put the site on hold' );
	foreach ( [ 1800, 3600, 7200, 14400, 21600, 21600 ] as $delay ) {
		$GLOBALS['fixture_time'] = ProviderHold::state()['due'];
		ProviderHold::reject( 'model_unavailable' );
		expect_hold( ProviderHold::state()['delay'] === $delay, 'Backoff doubles up to six hours' );
	}

	$ids = fixture();
	$queue->remember_job( $ids[0], 'managed-job' );
	$GLOBALS['wp_test_remote_get_response'] = service_answer( [ 'state' => 'done', 'byok' => false, 'items' => [ 'total' => 3, 'succeeded' => 0, 'failed' => 3 ], 'failure' => [ 'code' => 'timed_out' ] ] );
	$known = [];
	expect_hold( 'failed' === $indexer->job_outcome_for_row( $ids[0], $known ) && ! ProviderHold::state(), 'An all-failed managed job supplies no success proof and does not hold the site provider' );
	echo "EngineProviderHoldTest: passed\n";
}
