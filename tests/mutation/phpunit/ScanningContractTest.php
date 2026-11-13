<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TranslateWP\Components\Options;
use TranslateWP\Functionality\Endpoints\Scanning;

final class ScanningContractTest extends TestCase {
	private Scanning $scanner;

	protected function setUp(): void {
		wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'fixture-token' ] ] );
		$GLOBALS['scan_test_schedule_false'] = false;
		$GLOBALS['scan_test_queries'] = [];
		$GLOBALS['scan_test_http_requests'] = [];
		$GLOBALS['wp_test_inserted_posts'] = [ [ 'ID' => 1, 'post_type' => 'page', 'post_title' => 'Title', 'post_excerpt' => 'Excerpt', 'post_content' => '<p>Page content</p>', 'post_status' => 'publish' ] ];
		$GLOBALS['wp_test_posts']['page'] = [ 1 ];
		$this->scanner = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
	}

	private function scanResult(): array {
		return [ 'context' => 'Bakery', 'language' => 'EN_us', 'style' => 'marketing', 'tone' => 'formal', 'formality' => 20, 'creativity' => -3, 'lexicon' => [] ];
	}

	private function respond( $body, int $status = 200 ): void {
		$GLOBALS['wp_test_scan_response'] = [ 'response' => [ 'code' => $status ], 'body' => json_encode( $body ) ];
	}

	public function test_full_scan_schedules_without_sending_and_applies_sanitized_initial_settings(): void {
		self::assertSame( [ 'status' => 'pending' ], $this->scanner->scan_context( null ) );
		self::assertSame( [], $GLOBALS['wp_test_remote_posts'] );
		self::assertNotFalse( wp_next_scheduled( Scanning::SCAN_HOOK ) );
		self::assertFalse( Options::get( 'translations.context.scan_retried' ) );
		self::assertSame( '', Options::get( 'translations.context.scan_reason' ) );
		$this->respond( $this->scanResult() );
		$this->scanner->run_scan();
		self::assertSame( 'done', Options::get( 'translations.context.scan_status' ) );
		self::assertSame( 'Bakery', Options::get( 'translations.context.text_context' ) );
		self::assertSame( 'marketing', Options::get( 'translations.style.type' ) );
		self::assertSame( 'neutral', Options::get( 'translations.style.tone' ) );
		self::assertSame( 10, Options::get( 'translations.style.formality' ) );
		self::assertSame( 0, Options::get( 'translations.style.creativity' ) );
		self::assertNull( Options::get( 'translations.context.pro_followup' ) );
		self::assertFalse( get_transient( 'translatewp_context_scan_lock' ) );
		self::assertEqualsWithDelta( time(), Options::get( 'translations.context.last_scan_time' ), 1 );
		$result = $this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => '1' ] ) );
		self::assertSame( 'en-us', $result['language'] );
		self::assertTrue( $result['applied'] );
		self::assertSame( [], $result['lexiconSuggestions'] );
		self::assertSame( 10, $result['formality'] );
		self::assertSame( 0, $result['creativity'] );
		$request = $GLOBALS['wp_test_remote_posts'][0];
		self::assertSame( [ "Title\n\nExcerpt\n\nPage content" ], $request['body']['samples'] );
		self::assertSame( 90, $GLOBALS['scan_test_http_requests'][0]['args']['timeout'] );
	}

	public function test_empty_content_neither_schedules_nor_calls_the_model(): void {
		$GLOBALS['wp_test_inserted_posts'] = [];
		$GLOBALS['wp_test_posts']['page'] = [];
		$response = $this->scanner->scan_context( null );
		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'no_scannable_content', $response->get_error_code() );
		self::assertFalse( wp_next_scheduled( Scanning::SCAN_HOOK ) );
		$this->scanner->run_scan();
		self::assertSame( 'failed', Options::get( 'translations.context.scan_status' ) );
		self::assertSame( 'no_scannable_content', Options::get( 'translations.context.scan_reason' ) );
		self::assertSame( [], $GLOBALS['wp_test_remote_posts'] );
	}

	public function test_malformed_full_answers_retry_once_without_changing_owner_settings(): void {
		$invalid = [ null, [], [ 'context' => 42 ] ];
		foreach ( [ 'context' => [], 'style' => 'unknown', 'tone' => 'unknown', 'formality' => 'unknown', 'creativity' => [], 'lexicon' => false ] as $key => $value ) {
			$invalid[] = array_replace( $this->scanResult(), [ $key => $value ] );
		}
		foreach ( $invalid as $body ) {
			Options::update( 'translations.context.scan_retried', false );
			Options::update( 'translations.context.scan_status', 'pending' );
			Options::update( 'translations.context.text_context', 'Owner settings' );
			$this->respond( $body );
			$this->scanner->run_scan();
			self::assertSame( 'pending', Options::get( 'translations.context.scan_status' ) );
			self::assertTrue( Options::get( 'translations.context.scan_retried' ) );
			$this->scanner->run_scan();
			self::assertSame( 'failed', Options::get( 'translations.context.scan_status' ) );
			self::assertSame( 'invalid_scan_response', Options::get( 'translations.context.scan_reason' ) );
			self::assertSame( 'Owner settings', Options::get( 'translations.context.text_context' ) );
		}
	}

	public function test_payload_shares_the_byte_budget_and_never_splits_multibyte_text(): void {
		$GLOBALS['wp_test_inserted_posts'] = [];
		$GLOBALS['wp_test_posts']['page'] = [];
		for ( $id = 1; $id <= 30; $id++ ) {
			$GLOBALS['wp_test_inserted_posts'][] = [ 'ID' => $id, 'post_type' => 'page', 'post_title' => "Page $id", 'post_excerpt' => '', 'post_content' => str_repeat( 'ž', 10000 ), 'post_status' => 'publish' ];
			$GLOBALS['wp_test_posts']['page'][] = $id;
		}
		$this->respond( $this->scanResult() );
		$this->scanner->run_scan();
		$samples = $GLOBALS['wp_test_remote_posts'][0]['body']['samples'];
		self::assertCount( 25, $samples );
		self::assertLessThanOrEqual( 49152, array_sum( array_map( 'strlen', $samples ) ) );
		foreach ( $samples as $sample ) {
			self::assertLessThanOrEqual( 8192, strlen( $sample ) );
			self::assertGreaterThan( 1900, strlen( $sample ) );
			self::assertTrue( mb_check_encoding( $sample, 'UTF-8' ) );
		}
	}

	public function test_self_check_privacy_hint_covers_status_and_redirect_boundaries(): void {
		foreach ( [
			[ 199, '', true ], [ 200, '', false ], [ 399, '', false ], [ 400, '', true ],
			[ 302, 'https://foreign.example/login', true ], [ 302, '/login', false ],
			[ 302, home_url( '/login' ), false ],
		] as [ $status, $location, $private ] ) {
			$GLOBALS['wp_test_remote_get_response'] = [ 'response' => [ 'code' => $status ], 'headers' => [ 'location' => $location ], 'body' => '' ];
			$this->respond( $this->scanResult() );
			$GLOBALS['wp_test_remote_posts'] = [];
			$this->scanner->run_scan();
			self::assertSame( $private, $GLOBALS['wp_test_remote_posts'][0]['body']['site_private'] );
		}
	}

	public function test_language_tags_and_formal_tone_remain_safe(): void {
		foreach ( [ ' EN_us ' => 'en-us', 'zh_Hant_TW' => 'zh-hant-tw', 'abc-12345678' => 'abc-12345678', 'a' => '', 'abcd' => '', 'en-1' => '', 'en-123456789' => '', '<b>de</b>' => '', '' => '' ] as $raw => $expected ) {
			self::assertSame( $expected, Scanning::language_tag( $raw ) );
		}
		self::assertSame( 'neutral', Scanning::offered_tone( 'formal' ) );
		self::assertSame( 'friendly', Scanning::offered_tone( 'friendly' ) );
	}
	public function test_service_errors_keep_the_specific_failure_reason_after_the_one_retry(): void {
		foreach ( [
			[ 0, '', 'context_scan_unreachable' ], [ 504, '', 'context_scan_timeout' ],
			[ 422, 'scan_timeout', 'context_scan_timeout' ], [ 422, 'scan_output_unusable', 'invalid_scan_response' ],
			[ 503, '', 'context_scan_service_unavailable' ], [ 422, 'scan_service_unavailable', 'context_scan_service_unavailable' ],
			[ 400, '', 'invalid_scan_response' ],
		] as [ $status, $code, $reason ] ) {
			Options::update( 'translations.context.scan_retried', true );
			if ( 0 === $status ) {
				$GLOBALS['wp_test_scan_response'] = new WP_Error( 'transport', 'Offline' );
			} else {
				$this->respond( [ 'code' => $code ], $status );
			}
			$this->scanner->run_scan();
			self::assertSame( 'failed', Options::get( 'translations.context.scan_status' ) );
			self::assertSame( $reason, Options::get( 'translations.context.scan_reason' ) );
			$response = $this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => '1' ] ) );
			self::assertSame( [ 'status' => 'failed', 'reason' => $reason ], $response );
			self::assertFalse( get_transient( 'translatewp_context_scan_lock' ) );
		}
	}

	public function test_the_lock_prevents_another_full_scan_and_preserves_its_state(): void {
		Options::update( 'translations.context.scan_status', 'pending' );
		set_transient( 'translatewp_context_scan_lock', 1, 150 );
		$this->scanner->run_scan();
		self::assertSame( [], $GLOBALS['wp_test_remote_posts'] );
		self::assertSame( 'pending', Options::get( 'translations.context.scan_status' ) );
		self::assertSame( 1, get_transient( 'translatewp_context_scan_lock' ) );
	}

	public function test_pro_suggestions_require_entitlement_and_explicit_application(): void {
		$result = $this->scanResult();
		$result['lexicon'] = [ [ 'term' => 'Orbital', 'description' => 'Product name', 'category' => 'Brand' ], [ 'term' => ' orbital ', 'description' => 'Duplicate', 'category' => 'Brand' ] ];
		$this->respond( $result );
		$this->scanner->run_scan();
		$response = $this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => '1' ] ) );
		self::assertSame( [], $response['lexiconSuggestions'] );
		$denied = $this->scanner->apply_scan_lexicon( null );
		self::assertInstanceOf( WP_Error::class, $denied );
		self::assertSame( 'pro_required', $denied->get_error_code() );
		set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
		Options::update( 'languages.list', [ [ 'locale' => 'de' ], 'fr', 'fr', [ 'locale' => '' ], '' ] );
		$response = $this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => '1' ] ) );
		self::assertCount( 2, $response['lexiconSuggestions'] );
		self::assertSame( [ 'added' => 1 ], $this->scanner->apply_scan_lexicon( null ) );
		self::assertSame( [ 'added' => 0 ], $this->scanner->apply_scan_lexicon( null ) );
		$response = $this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => '1' ] ) );
		self::assertSame( [], $response['lexiconSuggestions'] );
	}

}
