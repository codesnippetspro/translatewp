<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TranslateWP\Components\Options;
use TranslateWP\Functionality\Endpoints\Scanning;

final class ScanningContractTest extends TestCase {
	private Scanning $scanner;

	protected function setUp(): void {
		wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'fixture-token' ] ] );
		scan_test_reset_boundaries();
		$GLOBALS['scan_test_schedule_false'] = false;
		$GLOBALS['scan_test_queries'] = [];
		$GLOBALS['scan_test_before_response'] = null;
		$GLOBALS['scan_test_self_checks'] = [];
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
		Options::update( 'translations.context.scan_status', 'done' );
		Options::update( 'translations.context.scan_result', [ 'old' => 'result' ] );
		self::assertSame( [ 'status' => 'pending' ], $this->scanner->scan_context( null ) );
		self::assertSame( 'pending', Options::get( 'translations.context.scan_status' ) );
		self::assertNull( Options::get( 'translations.context.scan_result' ) );
		$progress = Options::get( 'translations.context.scan_progress' );
		self::assertSame( 'collecting', $progress['phase'] );
		self::assertSame( 0, $progress['sampled'] );
		self::assertSame( 0, $progress['total'] );
		self::assertEqualsWithDelta( time(), $progress['started'], 1 );
		self::assertSame( 1, $GLOBALS['wp_test_spawn_cron'] );
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
		self::assertSame( [ 'status' => 422 ], $response->get_error_data() );
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
			self::assertMatchesRegularExpression( '/^Page [0-9]+\\n\\n/', $sample );
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
			[ 500, '', 'context_scan_service_unavailable' ], [ 503, '', 'context_scan_service_unavailable' ], [ 422, 'scan_service_unavailable', 'context_scan_service_unavailable' ],
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
		self::assertEqualsWithDelta( time() + 60, wp_next_scheduled( Scanning::SCAN_HOOK ), 1 );
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
		self::assertSame( [ 'status' => 403 ], $denied->get_error_data() );
		set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
		Options::update( 'languages.list', [ [ 'locale' => 'de' ], 'fr', 'fr', [ 'locale' => '' ], '' ] );
		$response = $this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => '1' ] ) );
		self::assertCount( 2, $response['lexiconSuggestions'] );
		self::assertSame( [ 'added' => 1 ], $this->scanner->apply_scan_lexicon( null ) );
		self::assertSame( [ 'added' => 0 ], $this->scanner->apply_scan_lexicon( null ) );
		$response = $this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => '1' ] ) );
		self::assertSame( [], $response['lexiconSuggestions'] );
	}

	public function test_the_persisted_full_scan_hook_runs_the_scheduled_work(): void {
		$this->respond( $this->scanResult() );
		$this->scanner->scan_context( null );
		self::assertNotFalse( wp_next_scheduled( 'translatewp_context_scan_run' ) );
		do_action( 'translatewp_context_scan_run' );
		self::assertCount( 1, $GLOBALS['wp_test_remote_posts'] );
		self::assertSame( 'done', Options::get( 'translations.context.scan_status' ) );
	}

	public function test_a_manual_scan_on_a_translated_site_only_proposes_context_and_style(): void {
		update_option( \TranslateWP\Components\TranslationStore::URL_OPTION, [ 'existing' => [ 'url' => home_url( '/' ), 'post_id' => 1 ] ] );
		Options::update( 'translations.context.text_context', 'Owner context' );
		Options::update( 'translations.style.tone', 'friendly' );
		$this->respond( $this->scanResult() );
		$this->scanner->run_scan();
		$response = $this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => '1' ] ) );
		self::assertFalse( $response['applied'] );
		self::assertSame( 'Bakery', $response['context'] );
		self::assertSame( 'Owner context', Options::get( 'translations.context.text_context' ) );
		self::assertSame( 'friendly', Options::get( 'translations.style.tone' ) );
	}

	public function test_unreachable_self_check_uses_local_samples_with_a_bounded_loopback_request(): void {
		$GLOBALS['wp_test_remote_get_response'] = new WP_Error( 'transport', 'Unreachable' );
		$this->respond( $this->scanResult() );
		$this->scanner->run_scan();
		self::assertTrue( $GLOBALS['wp_test_remote_posts'][0]['body']['site_private'] );
		self::assertSame( [ [ 'url' => home_url( '/' ), 'args' => [ 'blocking' => true, 'timeout' => 5, 'redirection' => 0 ] ] ], $GLOBALS['scan_test_self_checks'] );
	}

	public function test_all_routes_keep_their_methods_permissions_and_cors_contract(): void {
		$routes = $this->scanner->define_endpoints();
		self::assertSame( [ 'context', 'context/status', 'context/apply-lexicon', 'context/followup' ], array_map( static fn( $route ) => $route->getPath(), $routes ) );
		foreach ( $routes as $index => $route ) {
			self::assertSame( 'translatewp/v1/scanning', $route->getNamespace() );
			self::assertSame( 1 === $index ? 'GET' : 'POST', $route->getMethod() );
			self::assertSame( [ $this->scanner, $index < 2 ? 'admin_permissions_check' : 'admin_write_permissions_check' ], $route->getPermissionCallback() );
			$stack = $route->getMiddlewareStack();
			self::assertCount( 1, $stack );
			$cors = $stack[0];
			foreach ( [ 'allowedOrigins' => [ '*' ], 'allowedMethods' => [ 'GET', 'POST', 'OPTIONS' ], 'allowedHeaders' => [ 'content-type', 'authorization', 'x-requested-with' ] ] as $property => $expected ) {
				self::assertSame( $expected, ( new ReflectionProperty( $cors, $property ) )->getValue( $cors ) );
			}
		}
	}

	public function test_progress_survives_collection_and_retries_spawn_after_unlock(): void {
		Options::update( 'translations.context.scan_progress', [ 'started' => time() - 50 ] );
		$before = [];
		$GLOBALS['scan_test_before_response'] = static function () use ( &$before ) {
			$before = Options::get( 'translations.context.scan_progress' );
			self::assertTrue( (bool) get_transient( 'translatewp_context_scan_lock' ) );
		};
		$this->respond( [] );
		$this->scanner->run_scan();
		self::assertSame( 'analysing', $before['phase'] );
		self::assertSame( 1, $before['sampled'] );
		self::assertSame( 1, $before['total'] );
		self::assertEqualsWithDelta( time() - 50, $before['started'], 1 );
		self::assertSame( [ false ], $GLOBALS['scan_test_spawn_locks'] );
		self::assertSame( 1, $GLOBALS['wp_test_spawn_cron'] );
		self::assertNotFalse( wp_next_scheduled( Scanning::SCAN_HOOK ) );
		$this->scanner->run_scan();
		self::assertSame( 1, $GLOBALS['wp_test_spawn_cron'] );
		$this->respond( $this->scanResult() );
		$this->scanner->run_scan();
		self::assertSame( '', Options::get( 'translations.context.scan_reason' ) );
	}

	public function test_a_throwing_service_releases_the_full_scan_lock(): void {
		$GLOBALS['scan_test_before_response'] = static function () { throw new RuntimeException( 'Fixture service failure' ); };
		try {
			$this->scanner->run_scan();
			self::fail( 'The fixture service must throw' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Fixture service failure', $error->getMessage() );
		}
		self::assertFalse( get_transient( 'translatewp_context_scan_lock' ) );
	}

	public function test_explicit_lexicon_application_preserves_metadata_categories_and_duplicates(): void {
		set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
		Options::update( 'languages.list', [ [ 'locale' => 'de' ], 'de', 'fr', 'fr', [ 'locale' => '' ], '', null ] );
		Options::update( 'translations.context.scan_result', [ 'lexicon' => [
			[ 'term' => 'First', 'description' => 'First description', 'category' => 'Brand' ],
			[ 'term' => ' first ', 'description' => 'Duplicate', 'category' => 'Brand' ],
			[ 'term' => 'Second', 'description' => 'Second description', 'category' => 'Technical' ],
		] ] );
		$GLOBALS['scan_test_term_insert_result'] = [ 'term_id' => 42 ];
		self::assertSame( [ 'added' => 2 ], $this->scanner->apply_scan_lexicon( null ) );
		$posts = array_values( array_filter( $GLOBALS['wp_test_inserted_posts'], static fn( $post ) => 'lexicon' === $post['post_type'] ) );
		self::assertSame( [ 'First', 'Second' ], array_column( $posts, 'post_title' ) );
		self::assertSame( [ 'First description', 'Second description' ], array_column( $posts, 'post_content' ) );
		foreach ( $posts as $post ) {
			self::assertSame( [ 'de', 'fr' ], $post['meta_input'][ \TranslateWP\Entities\CPTs\Lexicon::LANGUAGES_META_KEY ] );
		}
		self::assertSame( [ [ $posts[0]['ID'], [ 42 ], 'lexicon_category', false ], [ $posts[1]['ID'], [ 42 ], 'lexicon_category', false ] ], $GLOBALS['scan_test_term_assignments'] );
		self::assertSame( [ 'added' => 0 ], $this->scanner->apply_scan_lexicon( null ) );
	}

	public function test_only_normal_pending_status_reads_kick_an_overdue_full_scan(): void {
		$this->respond( $this->scanResult() );
		foreach ( [ 'pending', 'done', 'failed' ] as $status ) {
			foreach ( [ false, true ] as $followup ) {
				foreach ( [ false, true ] as $read_only ) {
					Options::update( 'translations.context.scan_status', $status );
					Options::update( 'translations.context.scan_result', $this->scanResult() );
					$GLOBALS['wp_test_remote_posts'] = [];
					$GLOBALS['wp_test_scheduled'][ Scanning::SCAN_HOOK ] = time() - 120;
					delete_transient( 'translatewp_cron_kick_' . md5( Scanning::SCAN_HOOK ) );
					$this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => $read_only ? '1' : '0', 'followup' => $followup ? '1' : '0' ] ) );
					self::assertCount( 'pending' === $status && ! $followup && ! $read_only ? 1 : 0, $GLOBALS['wp_test_remote_posts'] );
				}
			}
		}
	}

	public function test_samples_cover_the_front_newest_oldest_and_enabled_private_pages(): void {
		$GLOBALS['wp_test_inserted_posts'] = [];
		foreach ( [ 2 => 'Newest', 3 => 'Front', 4 => 'Oldest', 5 => 'Private', 6 => 'Excluded' ] as $id => $title ) {
			$GLOBALS['wp_test_inserted_posts'][] = [ 'ID' => $id, 'post_type' => 'page', 'post_title' => $title, 'post_excerpt' => '', 'post_content' => $title . ' body', 'post_status' => 5 === $id ? 'private' : 'publish' ];
		}
		update_post_meta( 6, \TranslateWP\Components\PageExclusion::META_KEY, true );
		update_option( 'page_on_front', 3 );
		$GLOBALS['scan_test_post_query'] = static fn( $args ) => array_map( 'get_post', 'DESC' === $args['order'] ? [ 2, 5, 6 ] : [ 4, 3 ] );
		foreach ( [ false, true ] as $protected ) {
			Options::update( 'translations.settings.translate_protected_pages', $protected );
			$GLOBALS['scan_test_queries'] = [];
			$GLOBALS['scan_test_sample_progress'] = [];
			$this->respond( $this->scanResult() );
			$this->scanner->run_scan();
			self::assertSame( $protected ? [ 3, 2, 5, 4 ] : [ 3, 2, 4 ], Options::get( 'translations.context.scan_result' )['scanned_ids'] );
			$names = $protected ? [ 'Front', 'Newest', 'Private', 'Oldest' ] : [ 'Front', 'Newest', 'Oldest' ];
			self::assertSame( array_map( static fn( $name ) => $name . "\n\n" . $name . ' body', $names ), end( $GLOBALS['wp_test_remote_posts'] )['body']['samples'] );
			self::assertSame( [ 'DESC', 'ASC' ], array_column( $GLOBALS['scan_test_queries'], 'order' ) );
			foreach ( $GLOBALS['scan_test_queries'] as $query ) {
				self::assertSame( $protected ? [ 'publish', 'private' ] : [ 'publish' ], $query['post_status'] );
				self::assertSame( [ 'post', 'page' ], $query['post_type'] );
				self::assertSame( ! $protected, array_key_exists( 'has_password', $query ) );
				if ( ! $protected ) self::assertFalse( $query['has_password'] );
			}
			$progress = $GLOBALS['scan_test_sample_progress'];
			self::assertSame( range( 1, $protected ? 4 : 3 ), array_column( $progress, 'sampled' ) );
			self::assertSame( [ 'collecting' ], array_values( array_unique( array_column( $progress, 'phase' ) ) ) );
			self::assertSame( [ $protected ? 4 : 3 ], array_values( array_unique( array_column( $progress, 'total' ) ) ) );
		}
		foreach ( $GLOBALS['scan_test_post_type_queries'] as $query ) self::assertSame( [ [ 'public' => true ], 'names' ], $query );
	}

	public function test_lexicon_category_lookup_and_insert_errors_never_lose_the_term(): void {
		set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
		foreach ( [ [ (object) [ 'term_id' => '7' ], null, 7 ], [ false, [ 'term_id' => '17' ], 17 ],
			[ new WP_Error( 'lookup_error', 'Fixture error' ), [ 'term_id' => 17 ], 17 ],
			[ false, new WP_Error( 'insert_error', 'Fixture error' ), 0 ], [ false, [], 0 ] ] as $index => [ $existing, $inserted, $expected ] ) {
			$GLOBALS['scan_test_term_result'] = $existing;
			$GLOBALS['scan_test_term_insert_result'] = $inserted;
			$GLOBALS['scan_test_term_assignments'] = [];
			$GLOBALS['scan_test_term_inserts'] = [];
			Options::update( 'translations.context.scan_result', [ 'lexicon' => [ [ 'term' => 'Term' . $index, 'description' => 'Meaning', 'category' => 'Brand' ] ] ] );
			self::assertSame( [ 'added' => 1 ], $this->scanner->apply_scan_lexicon( null ) );
			self::assertCount( $expected ? 1 : 0, $GLOBALS['scan_test_term_assignments'] );
			if ( $expected ) self::assertSame( [ $expected ], $GLOBALS['scan_test_term_assignments'][0][1] );
			self::assertCount( is_object( $existing ) && ! is_wp_error( $existing ) ? 0 : 1, $GLOBALS['scan_test_term_inserts'] );
		}
	}

	public function test_lexicon_insert_failures_are_not_counted_as_added_terms(): void {
		set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
		foreach ( [ false, new WP_Error( 'insert_error', 'Fixture error' ) ] as $error ) {
			$GLOBALS['scan_test_insert_result'] = $error;
			Options::update( 'translations.context.scan_result', [ 'lexicon' => [ [ 'term' => 'Not inserted', 'category' => 'Brand' ] ] ] );
			self::assertSame( [ 'added' => 0 ], $this->scanner->apply_scan_lexicon( null ) );
		}
		self::assertSame( [], $GLOBALS['scan_test_term_assignments'] );
	}

	public function test_pending_progress_defaults_elapsed_and_stale_result_status(): void {
		Options::update( 'translations.context.scan_status', 'pending' );
		Options::update( 'translations.context.scan_result', $this->scanResult() );
		Options::update( 'translations.context.scan_progress', [ 'phase' => 'analysing', 'sampled' => 2, 'total' => 9, 'started' => time() - 20 ] );
		$request = new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => '1' ] );
		$response = $this->scanner->scan_status( $request );
		self::assertSame( 'pending', $response['status'] );
		self::assertSame( 'analysing', $response['phase'] );
		self::assertSame( 2, $response['sampled'] );
		self::assertSame( 9, $response['total'] );
		self::assertEqualsWithDelta( 20, $response['elapsedSeconds'], 1 );
		foreach ( [ [], false, [ 'started' => time() + 20 ] ] as $progress ) {
			Options::update( 'translations.context.scan_progress', $progress );
			self::assertSame( [ 'status' => 'pending', 'phase' => 'collecting', 'sampled' => 0, 'total' => 0, 'elapsedSeconds' => 0 ], $this->scanner->scan_status( $request ) );
		}
		Options::update( 'translations.context.scan_status', 'failed' );
		Options::update( 'translations.context.scan_reason', 'Fixture reason' );
		self::assertSame( [ 'status' => 'failed', 'reason' => 'Fixture reason' ], $this->scanner->scan_status( $request ) );
	}

	public function test_scan_numbers_are_integer_clamped_and_new_progress_has_a_real_start(): void {
		foreach ( [ [ -3, 20, 0, 10 ], [ '2.9', '8.7', 2, 8 ], [ 3.3, 4.9, 3, 4 ] ] as [ $formality, $creativity, $expected_formality, $expected_creativity ] ) {
			Options::update( 'translations.context.scan_progress', false );
			$this->respond( array_replace( $this->scanResult(), [ 'formality' => $formality, 'creativity' => $creativity ] ) );
			$this->scanner->run_scan();
			self::assertSame( $expected_formality, Options::get( 'translations.style.formality' ) );
			self::assertSame( $expected_creativity, Options::get( 'translations.style.creativity' ) );
			self::assertEqualsWithDelta( time(), Options::get( 'translations.context.scan_progress' )['started'], 1 );
		}
	}

	public function test_byte_truncation_keeps_the_start_and_trims_the_cut_boundary(): void {
		$GLOBALS['wp_test_inserted_posts'][0]['post_title'] = 'Start';
		$GLOBALS['wp_test_inserted_posts'][0]['post_excerpt'] = '';
		$GLOBALS['wp_test_inserted_posts'][0]['post_content'] = str_repeat( 'word ', 2000 );
		$this->respond( $this->scanResult() );
		$this->scanner->run_scan();
		$sample = $GLOBALS['wp_test_remote_posts'][0]['body']['samples'][0];
		self::assertStringStartsWith( "Start\n\nword ", $sample );
		self::assertSame( rtrim( $sample ), $sample );
		self::assertLessThanOrEqual( 8192, strlen( $sample ) );
	}

	public function test_missing_lexicon_is_empty_and_duplicate_lookup_reads_all_existing_terms(): void {
		set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
		foreach ( [ null, [], [ 'lexicon' => null ], [ 'lexicon' => 'invalid' ] ] as $stored ) {
			Options::update( 'translations.context.scan_result', $stored );
			self::assertSame( [ 'added' => 0 ], $this->scanner->apply_scan_lexicon( null ) );
		}
		foreach ( $GLOBALS['scan_test_queries'] as $query ) self::assertSame( -1, $query['posts_per_page'] );
	}

}
