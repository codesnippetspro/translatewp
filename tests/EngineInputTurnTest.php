<?php

declare(strict_types=1);

namespace TranslateWP\Components {
	function microtime( bool $as_float = false ) {
		return $GLOBALS['input_clock'];
	}
}

namespace {
	use TranslateWP\Components\BackgroundEngine;
	use TranslateWP\Components\CacheVersions;
	use TranslateWP\Components\IndexQueue;
	use TranslateWP\Functionality\Indexer;
	use TranslateWP\Functionality\KeepWarm;
	use TranslateWP\Functionality\LexiconChanges;

	require dirname( __DIR__ ) . '/vendor/autoload.php';
	require __DIR__ . '/wp-test-stubs.php';

	function check_input( bool $ok, string $message ): void {
		if ( ! $ok ) { throw new RuntimeException( $message ); }
	}

	function input_step( array $pages = [] ): array {
		$started = $GLOBALS['input_clock'];
		$response = BackgroundEngine::step( $pages );
		check_input( $GLOBALS['input_clock'] - $started < 8, 'A whole input step respects the shared deadline' );
		return $response;
	}

	class InputTurnDatabase extends WP_Testwpdb {
		public int $num_queries = 0;
		public function prepare( string $query, ...$args ): string {
			++$this->num_queries;
			if ( str_contains( $query, 'AND ID >' ) ) {
				$GLOBALS['input_clock'] += 0.06;
			}
			return parent::prepare( $query, ...$args );
		}
	}

	foreach ( [ 388, 10000 ] as $size ) {
		wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'fixture' ], 'languages' => [ 'list' => [
			'en' => [ 'default' => true, 'active' => true, 'locale' => 'en' ],
			'it' => [ 'active' => true, 'locale' => 'it' ],
		] ] ] );
		$GLOBALS['input_clock'] = 1000.0;
		$GLOBALS['wpdb'] = new InputTurnDatabase();
		update_option( LexiconChanges::SEEDED_OPTION, true );
		update_option( Indexer::SPINE_CHECKED_OPTION, true );
		add_filter( 'translatewp_engine_service_reads', static fn(): bool => true );
		new KeepWarm( '', '' );
		$meta = [ '_twp_language' => 'it', '_twp_status' => 'done', '_twp_stage' => 'push', '_twp_object_type' => 'page', '_twp_translated_at' => time() ];
		$source = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		for ( $n = 1; $n <= $size; ++$n ) {
			$id = wp_insert_post( [ 'post_type' => IndexQueue::POST_TYPE, 'post_status' => 'private',
				'meta_input' => $meta + [ '_twp_object_id' => $source, '_twp_url' => home_url( "/class-$n/" ) ],
			] );
			if ( 1 === $n ) { $first = $id; }
		}
		$last = $id;
		update_option( KeepWarm::PENDING_LANGUAGES_OPTION, [ 'it' ], false );
		wp_schedule_single_event( time() - 1, KeepWarm::SETTINGS_DEBOUNCE_HOOK );
		$page = [ 'url' => home_url( '/class-1/' ), 'status' => 200, 'html' => '<p>Dance school timetable</p>' ];
		input_step( [ $page ] );
		check_input( 1 === CacheVersions::settings_version(), 'The due input advances the version before submitting work' );
		check_input( 'extract' !== get_post_meta( $first, '_twp_stage', true ), 'The supplied page is consumed on the input turn' );
		$turn = 1;
		if ( 10000 === $size ) {
			check_input( false !== get_option( KeepWarm::REBUILD_CURSOR ), 'The large input respects the partial-step deadline' );
			$visitor = ( new IndexQueue() )->enqueue_push( [ 'object_type' => 'miss', 'object_id' => 98000, 'url' => home_url( '/visitor/' ), 'language' => 'it', 'priority' => 1,
				'payload' => wp_json_encode( [ [ 'groupId' => 'visitor', 'markerPayload' => '⟪item_0001⟫Today we dance' ] ] ),
			] );
			$GLOBALS['wp_test_remote_posts'] = [];
			input_step();
			++$turn;
			$batches = array_values( array_filter( $GLOBALS['wp_test_remote_posts'], static fn( array $request ): bool => str_ends_with( $request['path'], '/batches' ) ) );
			check_input( 1 === ( $batches[0]['body']['items'][0]['cache']['settingsVersion'] ?? -1 ) && str_contains( $batches[0]['body']['items'][0]['markerPayload'], 'Today we dance' ), 'The next step pushes the visitor first at the new version' );
			check_input( false !== get_option( KeepWarm::REBUILD_CURSOR ), 'Visitor delivery precedes the end of the large rebuild' );
			while ( $turn < 3 && get_option( KeepWarm::REBUILD_CURSOR ) ) {
				input_step();
				++$turn;
			}
			check_input( ! get_post_meta( $visitor, '_twp_retranslate', true ) && 'done' === get_post_meta( $visitor, '_twp_status', true ), 'The cursor leaves the new visitor delivery alone' );
		}
		check_input( false === get_option( KeepWarm::REBUILD_CURSOR ) && get_post_meta( $last, '_twp_retranslate', true ) && 1 === CacheVersions::settings_version(), 'All affected rows are marked in one version wave' );
		echo "$size rows marked in $turn input steps\n";
	}
}
