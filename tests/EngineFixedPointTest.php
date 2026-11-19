<?php

declare(strict_types=1);

namespace TranslateWP\Components {
	function time(): int { return $GLOBALS['fixed_time']; }
}
namespace TranslateWP\Functionality {
	function time(): int { return $GLOBALS['fixed_time']; }
	function wp_remote_get( string $url, array $args = [] ) {
		if ( ! empty( $GLOBALS['timeout_source_url'] ) && $url === $GLOBALS['timeout_source_url'] ) {
			if ( $args['timeout'] > 5 || $args['timeout'] < 4.9 ) {
				throw new \RuntimeException( 'A self-read must have its full five-second timeout' );
			}
			$GLOBALS['wpdb']->num_queries += 250;
			return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5000 milliseconds' );
		}
		return \wp_remote_get( $url, $args );
	}

}
namespace {
	use TranslateWP\Components\BackgroundEngine;
	use TranslateWP\Components\CacheVersions;
	use TranslateWP\Components\IndexQueue;
	use TranslateWP\Components\Options;
	use TranslateWP\Components\QueueExecution;
	use TranslateWP\Components\TranslationStore;
	use TranslateWP\Functionality\Indexer;
	use TranslateWP\Functionality\KeepWarm;
	use TranslateWP\Functionality\LexiconChanges;

	$GLOBALS['fixed_time'] = time();
	require __DIR__ . '/BackgroundConvergenceTest.php';
	$queue = new IndexQueue();
	new KeepWarm( '', '' );
	$versions = CacheVersions::settings_version();
	$generations = TranslationStore::cache_generations();
	$before = $queue->list_rows();
	$jobs = count( $GLOBALS['wp_test_remote_posts'] );
	for ( $day = 0; $day < 200; ++$day ) {
		$GLOBALS['fixed_time'] += 3600;
		$GLOBALS['wp_test_now'] = $GLOBALS['fixed_time'];
		wp_schedule_single_event( $GLOBALS['fixed_time'], KeepWarm::SETTINGS_DEBOUNCE_HOOK );
		wp_schedule_single_event( $GLOBALS['fixed_time'], KeepWarm::VERSIONED_REFILL_HOOK );
		BackgroundEngine::cron();
		( new Indexer( '', '', null, null, false ) )->report_completion();
		check_convergence( 'done' === BackgroundEngine::step()['state'], 'Idle steps remain settled with replayed cron and completion callbacks' );
		check_convergence( $generations === TranslationStore::cache_generations() && $versions === CacheVersions::settings_version(), 'No generation or settings revision advances at a fixed point' );
	}
	check_convergence( $jobs === count( $GLOBALS['wp_test_remote_posts'] ) && $before === $queue->list_rows(), 'Two hundred steps neither submit jobs nor modify settled rows' );

	foreach ( [ false, true ] as $timeout ) {
		$list = [ 'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ] ];
		foreach ( [ 'es', 'it' ] as $language ) {
			$list[$language] = [ 'locale' => $language, 'active' => true ];
		}
		wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'fixture' ], 'languages' => [ 'list' => $list ] ] );
		update_option( LexiconChanges::SEEDED_OPTION, true );
		update_option( Indexer::SPINE_CHECKED_OPTION, true );
		add_filter( 'translatewp_engine_service_reads', static fn(): bool => true );
		$pages = [];
		for ( $n = 0; $n < 10; ++$n ) {
			$post = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
			$url = home_url( "/studio-$n/" );
			$source = wp_insert_post( [ 'post_type' => IndexQueue::POST_TYPE, 'post_status' => 'private', 'meta_input' => [
				'_twp_language' => '', '_twp_status' => 'pending', '_twp_stage' => 'extract', '_twp_object_type' => 'page',
				'_twp_object_id' => $post, '_twp_url' => $url, '_twp_translate_on_read' => 1,
			] ] );
			$pages[$source] = [ 'url' => $url, 'html' => "<p>Meet our dance instructor for class $n.</p>", 'status' => 200 ];
		}
		$blocked_url = home_url( '/private-class/' );
		$blocked = wp_insert_post( [ 'post_type' => IndexQueue::POST_TYPE, 'post_status' => 'private', 'meta_input' => [
			'_twp_language' => '', '_twp_status' => 'pending', '_twp_stage' => 'extract', '_twp_object_type' => 'page',
			'_twp_object_id' => wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish' ] ), '_twp_url' => $blocked_url,
		] ] );
		for ( $poll = 0; $poll < 13; ++$poll ) {
			$waiting = BackgroundEngine::step();
			check_convergence( 'more' === $waiting['state'] && $waiting['nextDue'] > $GLOBALS['fixed_time'], 'A reader awaiting supplied pages always returns its next poll time' );
			$GLOBALS['fixed_time'] = $waiting['nextDue'];
			$GLOBALS['wp_test_now'] = $waiting['nextDue'];
		}
		$start = TranslationStore::cache_generations();
		$GLOBALS['wp_test_job_states']['00000000-0000-4000-8000-000000000099'] = 'done';
		foreach ( $pages as $source => $page ) {
			BackgroundEngine::step( [ $page ] );
			check_convergence( 'done' === get_post_meta( $source, '_twp_status', true ), 'Each supplied read is actually consumed' );
			foreach ( $GLOBALS['wp_test_remote_posts'] as $request ) {
				foreach ( $request['body']['items'] ?? [] as $item ) {
					$GLOBALS['wp_test_cache_lookup_items'][ $item['cache']['hash'] ] = $item['markerPayload'];
				}
			}
			if ( $source !== array_key_last( $pages ) ) {
				check_convergence( $start === TranslationStore::cache_generations(), 'No completion while another source can produce rows' );
			}
			$GLOBALS['fixed_time'] += 60;
			$GLOBALS['wp_test_now'] = $GLOBALS['fixed_time'];
		}
		if ( $timeout ) {
			wp_test_set_filter( 'translatewp_engine_service_reads', static fn(): bool => false );
			$GLOBALS['wpdb'] = new class extends WP_Testwpdb { public int $num_queries = 0; };
			$GLOBALS['timeout_source_url'] = $blocked_url;
			$reader = new Indexer( '', '', null, null, false );
			check_convergence( 0 === $reader->step_read( new \TranslateWP\Components\ReadinessBudget( 4 ) ) && ! get_post_meta( $blocked, '_twp_attempts', true ), 'A short remaining deadline leaves the unread page uncharged' );
		}
		for ( $n = 0; $n < 15; ++$n ) {
			$result = BackgroundEngine::step( [ [ 'url' => $blocked_url, 'status' => 403, 'html' => '' ] ] );
			if ( 'done' === $result['state'] ) {
				break;
			}
			$GLOBALS['fixed_time'] = max( $GLOBALS['fixed_time'] + 60, $result['nextDue'] ?? 0 );
			$GLOBALS['wp_test_now'] = $GLOBALS['fixed_time'];
		}
		check_convergence( 'done' === $result['state'] && 3 === (int) get_post_meta( $blocked, '_twp_dead_retries', true ), 'A persistent 403 exhausts three late retries and settles within 25 steps including the ten supplied reads: ' . wp_json_encode( $result ) );
		check_convergence( ( $timeout ? 'page_fetch_timeout' : 'page_fetch_403' ) === array_column( $queue->failed_items(), 'reason', 'id' )[ $blocked ], 'The dead source preserves the visible HTTP reason' );
		foreach ( [ 'es', 'it' ] as $language ) {
			check_convergence( 1 + ( $start[$language] ?? 1 ) === TranslationStore::cache_generation_for( $language ), "Ten page deliveries complete $language exactly once" );
		}
		unset( $GLOBALS['timeout_source_url'] );
		check_convergence( 20 === count( array_filter( $queue->list_rows(), static fn( array $row ): bool => $row['translated'] ) ), 'All twenty generated language rows are confirmed' );
	}

	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'fixture' ], 'languages' => [ 'list' => [
		'en' => [ 'locale' => 'en', 'active' => true, 'default' => true ],
		'es' => [ 'locale' => 'es', 'active' => true ],
	] ] ] );
	update_option( LexiconChanges::SEEDED_OPTION, true );
	update_option( Indexer::SPINE_CHECKED_OPTION, true );
	new KeepWarm( '', '' );
	add_filter( 'translatewp_engine_service_reads', static fn(): bool => true );
	$miss_url = home_url( '/visitor-only/' );
	$miss = wp_insert_post( [ 'post_type' => IndexQueue::POST_TYPE, 'post_status' => 'private', 'meta_input' => [
		'_twp_language' => 'es', '_twp_status' => 'done', '_twp_stage' => 'push', '_twp_translated_at' => time(),
		'_twp_object_type' => 'miss', '_twp_object_id' => 123, '_twp_url' => $miss_url,
	] ] );
	TranslationStore::record_pending( $miss_url, 0, 'es', 'en', [ [ 'hash' => 'm1', 'markerPayload' => '⟪item_0001⟫Dance' ] ] );
	TranslationStore::resolve_pending( $miss_url, 0, 'es', static fn(): array => [ 'm1' => '⟪item_0001⟫Baile' ] );
	$reader = new Indexer( '', '', null, null, false );
	for ( $n = 1; $n <= 15; ++$n ) {
		$post = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Class ' . $n ] );
		$id = $queue->enqueue_push( [ 'object_type' => 'page', 'object_id' => $post, 'url' => home_url( "/page-$n/" ), 'language' => 'es', 'priority' => 10,
			'payload' => wp_json_encode( [ [ 'groupId' => 'group-' . $n, 'markerPayload' => '⟪item_0001⟫Dance class ' . $n ] ] ) ] );
		$queue->track_refill( $id );
	}
	Options::update( 'translations.context', [ 'text_context' => 'A dance studio' ] );
	do_action( 'translatewp_translation_affecting_settings_saved' );
	$at = wp_next_scheduled( KeepWarm::SETTINGS_DEBOUNCE_HOOK );
	$r = BackgroundEngine::step();
	check_convergence( 0 === count( $GLOBALS['wp_test_remote_posts'] ) && $at === $r['nextDue'], 'The pending debounce holds all fifteen superseded pushes' );
	$GLOBALS['fixed_time'] = $at;
	$GLOBALS['wp_test_now'] = $at;
	$sent = [];
	$GLOBALS['wp_test_job_states']['00000000-0000-4000-8000-000000000099'] = 'done';
	for ( $turn = 0; $turn < 15; ++$turn ) {
		$pages = array_map( static fn( array $wanted ): array => $wanted + [ 'status' => 200, 'html' => '<p>Dance class for ' . basename( $wanted['url'] ) . '</p>' ], $reader->wanted_pages() );
		$r = BackgroundEngine::step( $pages );
		foreach ( $GLOBALS['wp_test_remote_posts'] as $request ) {
			if ( ! str_ends_with( $request['path'], '/batches' ) ) {
				continue;
			}
			foreach ( $request['body']['items'] as $item ) {
				check_convergence( 1 === $item['cache']['settingsVersion'], 'The only paid wave uses the changed settings' );
				$sent[] = $item['cache']['hash'];
				$GLOBALS['wp_test_cache_lookup_items'][ $item['cache']['hash'] ] = $item['markerPayload'];
			}
		}
		$GLOBALS['wp_test_remote_posts'] = [];
		if ( 'done' === $r['state'] ) {
			break;
		}
		$GLOBALS['fixed_time'] += 60;
		$GLOBALS['wp_test_now'] = $GLOBALS['fixed_time'];
	}
	check_convergence( 'done' === $r['state'] && 15 === count( $sent ) && 15 === count( array_unique( $sent ) ), 'Fifteen refill rows and a settings change produce one paid wave: ' . wp_json_encode( [ count( $sent ), count( array_unique( $sent ) ), $r ] ) );
	check_convergence( 'done' === get_post_meta( $miss, '_twp_status', true ) && 'Baile' === TranslationStore::translated_string( 'Dance', 'Dance', $miss_url, 'es' ), 'A completed visitor miss without replay data keeps its stored translation across the rebuild' );
	echo "EngineFixedPointTest: passed\n";
}
