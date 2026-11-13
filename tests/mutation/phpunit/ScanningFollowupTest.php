<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TranslateWP\Components\Options;
use TranslateWP\Functionality\Endpoints\Scanning;

final class ScanningFollowupTest extends TestCase {
	private Scanning $scanner;
	private array $stored;

	protected function setUp(): void {
		wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'fixture-token' ] ] );
		scan_test_reset_boundaries();
		$GLOBALS['scan_test_schedule_false'] = false;
		$GLOBALS['scan_test_queries'] = [];
		$GLOBALS['scan_test_before_response'] = null;
		$GLOBALS['scan_test_self_checks'] = [];
		$GLOBALS['wp_test_inserted_posts'] = [
			[ 'ID' => 1, 'post_type' => 'page', 'post_title' => 'Old page', 'post_excerpt' => '', 'post_content' => 'Existing published page content.', 'post_status' => 'publish' ],
		];
		$GLOBALS['wp_test_posts']['page'] = [ 1 ];
		$this->scanner = new Scanning( 'translatewp', TRANSLATEWP_VERSION );
		$this->scanner->run_scan();
		$this->stored = Options::get( 'translations.context.scan_result' );
		self::assertSame( [ 1 ], $this->stored['scanned_ids'] );
		Options::update( 'setup.ended', true );
		Options::update( 'translations.context.text_context', 'Owner edited context' );
		set_transient( 'translatewp_pro_eligibility', [ 'plan' => 'pro', 'entitled' => true ], 3600 );
		wp_test_set_filter( 'translatewp_pro_installed_version', static fn() => '1.0.3' );
		wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => true );
		$GLOBALS['wp_test_inserted_posts'][] = [ 'ID' => 2, 'post_type' => 'page', 'post_title' => 'New page', 'post_excerpt' => '', 'post_content' => 'NewTerm occurs only on the unseen page.', 'post_status' => 'publish' ];
		$GLOBALS['wp_test_posts']['page'][] = 2;
		$GLOBALS['wp_test_remote_posts'] = [];
		$GLOBALS['wp_test_scheduled'] = [];
		$this->respond( [ 'lexicon' => [ [ 'term' => 'NewTerm', 'description' => 'Specialist term', 'category' => 'Technical' ] ] ] );
	}

	private function respond( array $body, int $status = 200 ): void {
		$GLOBALS['wp_test_scan_response'] = [ 'response' => [ 'code' => $status ], 'body' => json_encode( $body ) ];
	}

	private function state() {
		return Options::get( 'translations.context.pro_followup' );
	}

	public function test_status_reads_and_render_do_not_execute_a_followup(): void {
		$this->scanner->schedule_pro_followup();
		self::assertSame( 'pending', $this->state() );
		$GLOBALS['wp_test_scheduled'][ Scanning::PRO_FOLLOWUP_HOOK ] = time() - 120;
		do_action( 'translatewp_admin_page_load' );
		$this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => '1' ] ) );
		$this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'followup' => '1' ] ) );
		self::assertSame( [], $GLOBALS['wp_test_remote_posts'] );
		self::assertSame( 'pending', $this->state() );
		$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
		$this->scanner->resume_pro_followup();
		self::assertCount( 1, $GLOBALS['wp_test_remote_posts'] );
		self::assertSame( 'done', $this->state() );
	}

	public function test_only_unseen_pages_are_sent_in_lexicon_mode_without_changing_context(): void {
		$this->scanner->schedule_pro_followup();
		$this->scanner->run_pro_followup();
		self::assertCount( 1, $GLOBALS['wp_test_remote_posts'] );
		$body = $GLOBALS['wp_test_remote_posts'][0]['body'];
		self::assertSame( 'lexicon', $body['mode'] );
		self::assertStringContainsString( 'NewTerm', json_encode( $body['samples'] ) );
		self::assertStringNotContainsString( 'Existing published', json_encode( $body['samples'] ) );
		self::assertSame( 'Owner edited context', Options::get( 'translations.context.text_context' ) );
		self::assertSame( [ 1, 2 ], Options::get( 'translations.context.scan_result' )['scanned_ids'] );
		self::assertSame( 'done', $this->state() );
		self::assertFalse( get_transient( 'translatewp_context_scan_lock' ) );
		$this->scanner->run_pro_followup();
		self::assertCount( 1, $GLOBALS['wp_test_remote_posts'] );
	}

	public function test_duplicate_schedule_is_pending_but_a_real_scheduling_failure_ends_it(): void {
		$this->scanner->schedule_pro_followup();
		Options::update( 'translations.context.pro_followup', null );
		$GLOBALS['scan_test_schedule_false'] = true;
		$this->scanner->schedule_pro_followup();
		self::assertSame( 'pending', $this->state() );
		self::assertNotFalse( wp_next_scheduled( Scanning::PRO_FOLLOWUP_HOOK ) );
		Options::update( 'translations.context.pro_followup', null );
		$GLOBALS['wp_test_scheduled'] = [];
		$this->scanner->schedule_pro_followup();
		self::assertSame( 'failed', $this->state() );
	}

	public function test_busy_lock_defers_the_run_and_does_not_consume_a_retry(): void {
		$this->scanner->schedule_pro_followup();
		set_transient( 'translatewp_context_scan_lock', 1, 150 );
		$this->scanner->run_pro_followup();
		self::assertSame( [], $GLOBALS['wp_test_remote_posts'] );
		self::assertSame( 'pending', $this->state() );
		self::assertSame( 0, Options::get( 'translations.context.pro_followup_retries' ) );
		self::assertEqualsWithDelta( time() + 60, wp_next_scheduled( Scanning::PRO_FOLLOWUP_HOOK ), 1 );
		self::assertSame( 1, get_transient( 'translatewp_context_scan_lock' ) );
	}

	public function test_timeout_and_server_errors_retry_three_times_then_fail(): void {
		foreach ( [ 0, 503 ] as $status ) {
			Options::update( 'translations.context.pro_followup', null );
			$this->scanner->schedule_pro_followup();
			if ( 0 === $status ) {
				$GLOBALS['wp_test_scan_response'] = new WP_Error( 'http_request_failed', 'Timed out' );
			} else {
				$this->respond( [], $status );
			}
			foreach ( [ 60, 120, 240 ] as $index => $delay ) {
				$GLOBALS['wp_test_scheduled'] = [];
				$this->scanner->run_pro_followup();
				self::assertSame( 'pending', $this->state() );
				self::assertSame( $index + 1, Options::get( 'translations.context.pro_followup_retries' ) );
				self::assertEqualsWithDelta( time() + $delay, wp_next_scheduled( Scanning::PRO_FOLLOWUP_HOOK ), 1 );
			}
			$GLOBALS['wp_test_scheduled'] = [];
			$this->scanner->run_pro_followup();
			self::assertSame( 'failed', $this->state() );
			self::assertFalse( wp_next_scheduled( Scanning::PRO_FOLLOWUP_HOOK ) );
			self::assertSame( $this->stored, Options::get( 'translations.context.scan_result' ) );
		}
	}

	public function test_refusal_and_invalid_responses_preserve_stored_suggestions(): void {
		foreach ( [ 400, 403, 200 ] as $status ) {
			Options::update( 'translations.context.pro_followup', null );
			$this->scanner->schedule_pro_followup();
			$this->respond( [ 'context' => 'No terms' ], $status );
			$this->scanner->run_pro_followup();
			self::assertSame( 200 === $status ? 'failed' : 'unavailable', $this->state() );
			self::assertSame( $this->stored, Options::get( 'translations.context.scan_result' ) );
			self::assertSame( 0, Options::get( 'translations.context.pro_followup_retries' ) );
		}
	}

	public function test_all_deduplicated_terms_are_retained_without_a_total_cap(): void {
		$stored = $this->stored;
		$stored['lexicon'] = [ [ 'term' => 'NewTerm', 'description' => 'Stored description', 'category' => 'Technical' ] ];
		Options::update( 'translations.context.scan_result', $stored );
		$terms = array_map( static fn( $n ) => [ 'term' => 'Term' . $n, 'description' => 'Term description', 'category' => 'Other' ], range( 1, 12 ) );
		$terms[] = [ 'term' => 'newterm', 'description' => 'New description', 'category' => 'Technical' ];
		$this->respond( [ 'lexicon' => $terms ] );
		$this->scanner->schedule_pro_followup();
		$this->scanner->run_pro_followup();
		$result = Options::get( 'translations.context.scan_result' );
		self::assertCount( 13, $result['lexicon'] );
		self::assertSame( 'Stored description', $result['lexicon'][0]['description'] );
		self::assertFalse( $result['lexicon_applied'] );
	}

	public function test_legacy_scans_without_recorded_ids_skip_automatic_work(): void {
		$stored = $this->stored;
		unset( $stored['scanned_ids'] );
		Options::update( 'translations.context.scan_result', $stored );
		$this->scanner->schedule_pro_followup();
		self::assertNull( $this->state() );
		self::assertSame( [], $GLOBALS['wp_test_remote_posts'] );
		self::assertFalse( wp_next_scheduled( Scanning::PRO_FOLLOWUP_HOOK ) );
	}

	public function test_status_restore_returns_suggestions_without_writing_context_or_scheduling(): void {
		Options::update( 'translations.context.scan_result', array_merge( $this->stored, [ 'lexicon' => [
			[ 'term' => 'Saved term', 'description' => 'Saved suggestion', 'category' => 'Other' ],
		] ] ) );
		$status = $this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => '1', 'followup' => '1' ] ) );
		self::assertSame( 'done', $status['status'] );
		self::assertSame( 'Saved term', $status['lexiconSuggestions'][0]['term'] );
		self::assertSame( 'Owner edited context', Options::get( 'translations.context.text_context' ) );
		self::assertSame( [], $GLOBALS['wp_test_remote_posts'] );
		self::assertFalse( wp_next_scheduled( Scanning::PRO_FOLLOWUP_HOOK ) );
	}

	public function test_followup_uses_published_posts_even_when_private_translation_is_enabled(): void {
		Options::update( 'translations.settings.translate_protected_pages', true );
		$GLOBALS['wp_test_inserted_posts'][] = [ 'ID' => 3, 'post_type' => 'page', 'post_title' => 'Private page',
			'post_content' => 'Private material', 'post_status' => 'private' ];
		$GLOBALS['wp_test_posts']['page'][] = 3;
		update_option( 'page_on_front', 3 );
		$GLOBALS['scan_test_queries'] = [];
		$GLOBALS['scan_test_before_response'] = null;
		$GLOBALS['scan_test_self_checks'] = [];
		$this->scanner->schedule_pro_followup();
		$this->scanner->run_pro_followup();
		$body = $GLOBALS['wp_test_remote_posts'][0]['body'];
		self::assertStringNotContainsString( 'Private material', json_encode( $body ) );
		self::assertSame( [ 1, 2 ], Options::get( 'translations.context.scan_result' )['scanned_ids'] );
		foreach ( $GLOBALS['scan_test_queries'] as $query ) {
			self::assertSame( [ 'publish' ], $query['post_status'] );
			self::assertSame( [ 1 ], $query['post__not_in'] );
			self::assertFalse( $query['suppress_filters'] );
			self::assertSame( 12, $query['numberposts'] );
			self::assertSame( 'date', $query['orderby'] );
			self::assertNotContains( 'attachment', $query['post_type'] );
		}
	}

	public function test_only_one_automatic_followup_can_be_scheduled(): void {
		$this->scanner->schedule_pro_followup();
		$count = count( $GLOBALS['wp_test_scheduled_log'] );
		$this->scanner->schedule_pro_followup();
		self::assertCount( $count, $GLOBALS['wp_test_scheduled_log'] );
		self::assertSame( 'pending', $this->state() );
		foreach ( [ 'done', 'failed', 'skipped', 'unavailable' ] as $terminal ) {
			Options::update( 'translations.context.pro_followup', $terminal );
			$this->scanner->schedule_pro_followup();
			$this->scanner->run_pro_followup();
			self::assertSame( $terminal, $this->state() );
		}
		self::assertSame( [], $GLOBALS['wp_test_remote_posts'] );
	}

	public function test_unfinished_setup_and_noncompleted_or_malformed_scans_never_schedule(): void {
		foreach ( [ false, true ] as $setup_ended ) {
			foreach ( [ 'pending', 'failed', 'done' ] as $status ) {
				foreach ( [ null, [], [ 'scanned_ids' => 'invalid' ], $this->stored ] as $stored ) {
					if ( $setup_ended && 'done' === $status && $stored === $this->stored ) {
						continue;
					}
					Options::update( 'setup.ended', $setup_ended );
					Options::update( 'translations.context.scan_status', $status );
					Options::update( 'translations.context.scan_result', $stored );
					Options::update( 'translations.context.pro_followup', null );
					$this->scanner->schedule_pro_followup();
					self::assertNull( $this->state() );
					self::assertFalse( wp_next_scheduled( Scanning::PRO_FOLLOWUP_HOOK ) );
				}
			}
		}
	}

	public function test_nonpro_or_missing_work_never_consumes_a_model_call(): void {
		$this->scanner->schedule_pro_followup();
		wp_test_set_filter( 'translatewp_pro_addon_active', static fn() => false );
		$this->scanner->run_pro_followup();
		self::assertSame( 'skipped', $this->state() );
		self::assertSame( [], $GLOBALS['wp_test_remote_posts'] );
	}

	public function test_no_unseen_pages_finishes_without_a_service_request(): void {
		Options::update( 'translations.context.scan_result', array_merge( $this->stored, [ 'scanned_ids' => [ 1, 2 ] ] ) );
		$this->scanner->schedule_pro_followup();
		$this->scanner->run_pro_followup();
		self::assertSame( 'done', $this->state() );
		self::assertSame( [], $GLOBALS['wp_test_remote_posts'] );
	}

	public function test_manual_pro_rescan_is_one_call_and_does_not_reopen_automatic_work(): void {
		$this->respond( $this->stored );
		$this->scanner->run_scan();
		self::assertSame( 'done', $this->state() );
		$this->scanner->schedule_pro_followup();
		self::assertFalse( wp_next_scheduled( Scanning::PRO_FOLLOWUP_HOOK ) );
		self::assertCount( 1, $GLOBALS['wp_test_remote_posts'] );
	}

	public function test_followup_route_requires_admin_write_permission_and_a_nonce(): void {
		$routes = $this->scanner->define_endpoints();
		$followup = array_values( array_filter( $routes, static fn( $route ) => 'context/followup' === $route->getPath() ) )[0];
		self::assertSame( 'POST', $followup->getMethod() );
		self::assertSame( [ $this->scanner, 'resume_pro_followup' ], $followup->getConfig() );
		self::assertSame( [ $this->scanner, 'admin_write_permissions_check' ], $followup->getPermissionCallback() );
		$permission = $followup->getPermissionCallback();
		$GLOBALS['wp_test_caps'] = [];
		self::assertInstanceOf( WP_Error::class, $permission( new WP_REST_Request( 'POST', '/context/followup' ) ) );
		$GLOBALS['wp_test_caps'] = [ 'manage_options' ];
		self::assertInstanceOf( WP_Error::class, $permission( new WP_REST_Request( 'POST', '/context/followup' ) ) );
		self::assertTrue( $permission( new WP_REST_Request( 'POST', '/context/followup', [], [ 'X-WP-Nonce' => 'test-nonce' ] ) ) );
	}

	public function test_activation_schedules_then_the_persisted_cron_hook_runs_one_lexicon_call(): void {
		do_action( 'translatewp_pro_ready' );
		self::assertSame( 'pending', $this->state() );
		self::assertNotFalse( wp_next_scheduled( 'translatewp_pro_lexicon_followup' ) );
		self::assertSame( [], $GLOBALS['wp_test_remote_posts'] );
		do_action( 'translatewp_pro_lexicon_followup' );
		self::assertSame( 'done', $this->state() );
		self::assertCount( 1, $GLOBALS['wp_test_remote_posts'] );
		self::assertSame( 'lexicon', $GLOBALS['wp_test_remote_posts'][0]['body']['mode'] );
	}

	public function test_an_old_followup_cannot_replace_a_new_scan_result_or_pending_scan(): void {
		foreach ( [ 'ids', 'pending', 'missing' ] as $change ) {
			Options::update( 'translations.context.scan_status', 'done' );
			Options::update( 'translations.context.scan_result', $this->stored );
			Options::update( 'translations.context.pro_followup', null );
			$this->scanner->schedule_pro_followup();
			$replacement = 'missing' === $change ? null : array_replace( $this->stored, [ 'context' => 'Newer scan', 'scanned_ids' => 'ids' === $change ? [ 99 ] : $this->stored['scanned_ids'] ] );
			$GLOBALS['scan_test_before_response'] = static function () use ( $replacement, $change ) {
				Options::update( 'translations.context.scan_result', $replacement );
				Options::update( 'translations.context.scan_status', 'pending' === $change ? 'pending' : 'done' );
			};
			$this->scanner->run_pro_followup();
			self::assertSame( $replacement, Options::get( 'translations.context.scan_result' ) );
			self::assertSame( 'pending', $this->state() );
			self::assertFalse( get_transient( 'translatewp_context_scan_lock' ) );
		}
	}

	public function test_status_flags_schedule_only_when_requested_and_never_on_read_only(): void {
		foreach ( [ false, true ] as $followup ) {
			foreach ( [ false, true ] as $read_only ) {
				Options::update( 'translations.context.pro_followup', null );
				$GLOBALS['wp_test_scheduled'] = [];
				$response = $this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => $read_only ? '1' : '0', 'followup' => $followup ? '1' : '0' ] ) );
				self::assertSame( $followup && ! $read_only, $response['followupPending'] );
				self::assertSame( $followup && ! $read_only, false !== wp_next_scheduled( Scanning::PRO_FOLLOWUP_HOOK ) );
				self::assertSame( [], $GLOBALS['wp_test_remote_posts'] );
			}
		}
		self::assertSame( 'done', $this->scanner->scan_status( null )['status'] );
	}

	public function test_followup_schedule_spawns_once_and_reports_overdue_completion(): void {
		$GLOBALS['wp_test_spawn_cron'] = 0;
		$before = time();
		$this->scanner->schedule_pro_followup();
		self::assertSame( 1, $GLOBALS['wp_test_spawn_cron'] );
		self::assertGreaterThanOrEqual( $before, wp_next_scheduled( Scanning::PRO_FOLLOWUP_HOOK ) );
		self::assertLessThanOrEqual( time(), wp_next_scheduled( Scanning::PRO_FOLLOWUP_HOOK ) );
		self::assertSame( [ 'pending' => true ], $this->scanner->resume_pro_followup() );
		$GLOBALS['wp_test_scheduled'][ Scanning::PRO_FOLLOWUP_HOOK ] = time() - 120;
		self::assertSame( [ 'pending' => false ], $this->scanner->resume_pro_followup() );
		self::assertCount( 1, $GLOBALS['wp_test_remote_posts'] );
		self::assertSame( 'done', $this->state() );
		self::assertSame( [ 'pending' => false ], $this->scanner->resume_pro_followup() );
	}

	public function test_a_throwing_service_releases_the_followup_lock(): void {
		$this->scanner->schedule_pro_followup();
		$GLOBALS['scan_test_before_response'] = static function () {
			self::assertTrue( (bool) get_transient( 'translatewp_context_scan_lock' ) );
			throw new RuntimeException( 'Fixture followup failure' );
		};
		try {
			$this->scanner->run_pro_followup();
			self::fail( 'The fixture service must throw' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Fixture followup failure', $error->getMessage() );
		}
		self::assertFalse( get_transient( 'translatewp_context_scan_lock' ) );
	}

	public function test_stored_context_and_suggestions_keep_their_public_fields(): void {
		$stored = $this->stored;
		$stored['context'] = 'Stored context';
		$stored['language'] = 'lv';
		$stored['style'] = 'medical';
		$stored['tone'] = 'casual';
		$stored['formality'] = 2;
		$stored['creativity'] = 9;
		$stored['lexicon'] = [ [ 'term' => 'Medical term', 'description' => 'Definition', 'category' => 'Speciality' ] ];
		Options::update( 'translations.context.scan_result', $stored );
		$response = $this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => '1' ] ) );
		foreach ( [ 'context', 'language', 'style', 'tone', 'formality', 'creativity' ] as $field ) {
			self::assertSame( $stored[$field], $response[$field] );
		}
		self::assertSame( $stored['lexicon'], $response['lexiconSuggestions'] );
		unset( $stored['applied'], $stored['formality'], $stored['creativity'] );
		Options::update( 'translations.context.scan_result', $stored );
		$response = $this->scanner->scan_status( new WP_REST_Request( 'GET', '/context/status', [ 'read_only' => '1' ] ) );
		self::assertTrue( $response['applied'] );
		self::assertSame( 5, $response['formality'] );
		self::assertSame( 5, $response['creativity'] );
	}

}
