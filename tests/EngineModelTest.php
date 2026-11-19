<?php
/**
 * M1 engine model: drives BackgroundEngine::service_step() in a loop against a fake
 * translation service, a fake clock (60 s per step, simulated latency per remote call)
 * and checks invariants after every step.
 *
 * Run (one scenario or all):  php tests/EngineModelTest.php [scenario] [--lat=0.25] [--quiet]
 * Scenarios: fresh15 refill15 refill300 refill2000 legacy visitor15 fault500 faulttimeout faultecho fault409 settings15
 * Only the test file is new; it overrides time()/microtime()/wp_remote_* inside the plugin
 * namespaces (the stub layer is not touched).
 */

namespace TranslateWP\Components {
	function time(): int { return \m1_time(); }
	function microtime( bool $as_float = false ) { return \m1_microtime( $as_float ); }
	function wp_remote_get( string $url, array $args = [] ) { return \m1_http( 'GET', $url, $args ); }
	function wp_remote_post( string $url, array $args = [] ) { return \m1_http( 'POST', $url, $args ); }
	function wp_remote_request( string $url, array $args = [] ) { return \m1_http( strtoupper( (string) ( $args['method'] ?? 'GET' ) ), $url, $args ); }
	function wp_delete_post( $id, $force = false ) { return \m1_delete( (int) $id, (bool) $force ); }
}
namespace TranslateWP\Functionality {
	function time(): int { return \m1_time(); }
	function microtime( bool $as_float = false ) { return \m1_microtime( $as_float ); }
	function wp_remote_get( string $url, array $args = [] ) { return \m1_http( 'GET', $url, $args ); }
	function wp_remote_post( string $url, array $args = [] ) { return \m1_http( 'POST', $url, $args ); }
	function wp_remote_request( string $url, array $args = [] ) { return \m1_http( strtoupper( (string) ( $args['method'] ?? 'GET' ) ), $url, $args ); }
	function wp_delete_post( $id, $force = false ) { return \m1_delete( (int) $id, (bool) $force ); }
}
namespace TranslateWP\Functionality\Endpoints {
	function time(): int { return \m1_time(); }
	function microtime( bool $as_float = false ) { return \m1_microtime( $as_float ); }
}
namespace TranslateWP\Includes {
	function time(): int { return \m1_time(); }
}

namespace {

use TranslateWP\Components\BackgroundEngine;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\Options;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\KeepWarm;
use TranslateWP\Functionality\LexiconChanges;
use TranslateWP\Functionality\TranslationStoreSync;

if ( ! defined( 'TRANSLATEWP_ENGINE_TRACE' ) ) { define( 'TRANSLATEWP_ENGINE_TRACE', true ); }
require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

// ---------------------------------------------------------------- clock
// Sim time runs 10 days BEHIND real time: ReadinessReconcile's default clock is the string callable 'time'
// (global \time(), cannot be overridden), so its GRACE (120 s) and dead back-off (600 s) checks see
// "real now" > every sim timestamp, i.e. both waits are 0 in the model. Everything else uses sim time.
$GLOBALS['m1_clock'] = (float) ( \time() - 864000 );
$GLOBALS['m1_real0'] = \time();
function m1_time(): int { return (int) floor( $GLOBALS['m1_clock'] ); }
function m1_microtime( bool $as_float ) { return $as_float ? $GLOBALS['m1_clock'] : sprintf( '%.8F %d', fmod( $GLOBALS['m1_clock'], 1 ), (int) $GLOBALS['m1_clock'] ); }
function m1_advance( float $seconds ): void { $GLOBALS['m1_clock'] += max( 0.0, $seconds ); }

/** The stub transients expire on real time; translate their expiries into sim time. */
function m1_transients(): void {
	$seen = &$GLOBALS['m1_transient_seen'];
	$seen = is_array( $seen ) ? $seen : [];
	foreach ( $GLOBALS['wp_test_transient_expirations'] ?? [] as $key => $real_expiry ) {
		if ( ( $seen[ $key ]['real'] ?? null ) !== $real_expiry ) {
			$seen[ $key ] = [ 'real' => $real_expiry, 'sim' => m1_time() + ( $real_expiry - \time() ) ];
		}
		if ( $seen[ $key ]['sim'] <= m1_time() ) {
			unset( $GLOBALS['wp_test_transients'][ $key ], $GLOBALS['wp_test_transient_expirations'][ $key ], $seen[ $key ] );
		}
	}
}

// ---------------------------------------------------------------- fake service
function m1_service_reset( array $cfg = [] ): void {
	$GLOBALS['m1_svc'] = $cfg + [
		'lat' => 0.25,          // seconds per remote call
		'polls' => 1,           // status polls until a job reads "done"
		'fault' => '',          // '', http500, timeout, no_echo, 409
		'fault_paths' => 'jobs|batches|status|lookup',
		'jobs' => [],
		'store' => [],          // lang => hash => [payload, lexiconVersion, settingsVersion, job]
		'seq' => 0,
	];
}
function m1_count( string $kind ): void {
	$GLOBALS['m1_step_calls'][ $kind ] = ( $GLOBALS['m1_step_calls'][ $kind ] ?? 0 ) + 1;
	$GLOBALS['m1_total_calls'][ $kind ] = ( $GLOBALS['m1_total_calls'][ $kind ] ?? 0 ) + 1;
}
function m1_json( int $code, array $body ): array { return [ 'response' => [ 'code' => $code ], 'body' => (string) json_encode( $body ), 'headers' => [] ]; }
function m1_translate( string $payload ): string {
	return (string) preg_replace( '/(⟪[^⟫]+⟫)\s*/u', '$1ES ', $payload );
}
function m1_fault( string $kind, array $args ) {
	$svc = $GLOBALS['m1_svc'];
	if ( '' === $svc['fault'] || ! preg_match( '/^(?:' . $svc['fault_paths'] . ')$/', $kind ) ) {
		return null;
	}
	if ( 'http500' === $svc['fault'] ) { return m1_json( 500, [ 'error' => 'Internal error' ] ); }
	if ( 'timeout' === $svc['fault'] ) {
		m1_advance( min( 8.0, (float) ( $args['timeout'] ?? 8 ) ) );
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 8000 milliseconds' );
	}
	if ( '409' === $svc['fault'] && in_array( $kind, [ 'jobs', 'batches' ], true ) ) { return m1_json( 409, [ 'error' => 'Conflict', 'code' => 'conflict' ] ); }
	return null;
}
function m1_http( string $method, string $url, array $args ) {
	$path = (string) parse_url( $url, PHP_URL_PATH );
	$body = json_decode( (string) ( $args['body'] ?? '' ), true );
	$body = is_array( $body ) ? $body : [];
	$svc = &$GLOBALS['m1_svc'];
	$kind = null;
	if ( '/v1/translation/jobs' === $path ) { $kind = 'jobs'; }
	elseif ( preg_match( '#^/v1/translation/jobs/([^/]+)/batches$#', $path, $m ) ) { $kind = 'batches'; }
	elseif ( preg_match( '#^/v1/translation/jobs/([^/]+)/status$#', $path, $m ) ) { $kind = 'status'; }
	elseif ( preg_match( '#^/v1/translation/jobs/([^/]+)/cancel$#', $path, $m ) ) { $kind = 'cancel'; }
	elseif ( '/v1/translation/cache/lookup-batch' === $path ) { $kind = 'lookup'; }
	elseif ( '/v1/site/index-complete' === $path ) { $kind = 'index_complete'; }
	elseif ( '/v1/background/wake' === $path ) { $kind = 'wake'; }
	if ( null === $kind && preg_match( '#/page-(\d+)/#', $url, $pm ) ) {
		m1_count( 'page_read' );
		m1_advance( $svc['lat'] );
		$paras = '';
		foreach ( m1_payload( (int) $pm[1] ) as $item ) { $paras .= '<p>' . htmlspecialchars( (string) preg_replace( '/⟪[^⟫]+⟫/u', '', $item['markerPayload'] ) ) . '</p>'; }
		return [ 'response' => [ 'code' => 200 ], 'body' => '<html><head><title>Page</title></head><body>' . $paras . '</body></html>', 'headers' => [ 'content-type' => 'text/html' ] ];
	}
	if ( null === $kind ) {
		m1_count( 'other' );
		m1_advance( $svc['lat'] );
		return 'GET' === $method ? \wp_remote_get( $url, $args ) : \wp_remote_request( $url, $args + [ 'method' => $method ] );
	}
	m1_count( $kind );
	if ( 'wake' === $kind ) { return [ 'response' => [ 'code' => 204 ], 'body' => '' ]; }
	m1_advance( $svc['lat'] );
	$fault = m1_fault( $kind, $args );
	if ( null !== $fault ) { return $fault; }
	switch ( $kind ) {
		case 'jobs':
			$id = sprintf( '00000000-0000-4000-8000-%012d', ++$svc['seq'] );
			$svc['jobs'][ $id ] = [ 'purpose' => $body['purpose'] ?? null, 'lang' => (string) ( $body['targetLang'] ?? '' ), 'polls' => 0, 'items' => 0, 'cancelled' => false, 'step' => $GLOBALS['m1_step'] ?? 0 ];
			$echo = isset( $body['purpose'] ) && 'no_echo' !== $svc['fault'] ? [ 'purpose' => $body['purpose'] ] : [];
			return m1_json( 200, [ 'jobId' => $id, 'sourceLang' => $body['sourceLang'] ?? 'en', 'targetLang' => $body['targetLang'] ?? '' ] + $echo );
		case 'batches':
			$job = $m[1];
			if ( ! isset( $svc['jobs'][ $job ] ) ) { return m1_json( 404, [ 'error' => 'Unknown job' ] ); }
			$lang = $svc['jobs'][ $job ]['lang'];
			foreach ( (array) ( $body['items'] ?? [] ) as $item ) {
				$hash = (string) ( $item['cache']['hash'] ?? '' );
				if ( '' === $hash ) { continue; }
				$svc['store'][ $lang ][ $hash ] = [ m1_translate( (string) ( $item['markerPayload'] ?? '' ) ), (int) ( $item['cache']['lexiconVersion'] ?? 0 ), (int) ( $item['cache']['settingsVersion'] ?? 0 ), $job ];
				$svc['jobs'][ $job ]['items']++;
			}
			return m1_json( 200, [ 'ok' => true, 'batchId' => $body['batchId'] ?? 'b', 'accepted' => count( (array) ( $body['items'] ?? [] ) ) ] );
		case 'status':
			$job = $m[1];
			if ( ! isset( $svc['jobs'][ $job ] ) ) { return m1_json( 404, [ 'error' => 'Unknown job' ] ); }
			$svc['jobs'][ $job ]['polls']++;
			return m1_json( 200, [ 'state' => m1_job_done( $job ) ? 'done' : ( $svc['jobs'][ $job ]['cancelled'] ? 'cancelled' : 'open' ), 'createdAt' => '2027-01-15T00:00:00Z' ] );
		case 'cancel':
			if ( isset( $svc['jobs'][ $m[1] ] ) ) { $svc['jobs'][ $m[1] ]['cancelled'] = true; }
			return m1_json( 200, [ 'ok' => true ] );
		case 'lookup':
			$lang = (string) ( $body['language'] ?? '' );
			$items = [];
			foreach ( (array) ( $body['hashes'] ?? [] ) as $hash ) {
				$entry = $svc['store'][ $lang ][ $hash ] ?? null;
				$fresh = is_array( $entry ) && m1_job_done( $entry[3] ) && $entry[1] >= (int) ( $body['lexiconVersion'] ?? 0 ) && $entry[2] >= (int) ( $body['settingsVersion'] ?? 0 );
				$items[ $hash ] = $fresh ? $entry[0] : null;
			}
			return m1_json( 200, [ 'siteId' => $body['siteId'] ?? '', 'language' => $lang, 'items' => $items ] );
		case 'index_complete':
			return m1_json( 200, [ 'ok' => true ] );
	}
	return m1_json( 404, [ 'error' => 'not found' ] );
}
function m1_job_done( string $job ): bool {
	$j = $GLOBALS['m1_svc']['jobs'][ $job ] ?? null;
	return is_array( $j ) && ! $j['cancelled'] && $j['polls'] >= $GLOBALS['m1_svc']['polls'];
}

// ---------------------------------------------------------------- deletes (only-copy check)
function m1_delete( int $id, bool $force ) {
	$m = m1_meta( $id );
	if ( null !== $m && IndexQueue::POST_TYPE === ( m1_post( $id )['post_type'] ?? '' ) && m1_nonterminal( $m ) ) {
		$twin = false;
		foreach ( m1_rows( true ) as $other_id => $o ) {
			if ( $other_id !== $id && ( $o['_twp_object_type'] ?? '' ) === ( $m['_twp_object_type'] ?? '' ) && (int) ( $o['_twp_object_id'] ?? 0 ) === (int) ( $m['_twp_object_id'] ?? 0 ) && (string) ( $o['_twp_language'] ?? '' ) === (string) ( $m['_twp_language'] ?? '' ) ) {
				$twin = true;
			}
		}
		if ( ! $twin ) {
			m1_violation( 'deleted the only copy of pending work: row ' . $id . ' ' . m1_describe( $m ) );
		}
	}
	return \wp_delete_post( $id, $force );
}

// ---------------------------------------------------------------- row helpers
function m1_post( int $id ): ?array {
	foreach ( $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] ?? [] as $post ) {
		if ( (int) $post['ID'] === $id ) { return $post; }
	}
	return null;
}
function m1_meta( int $id ): ?array { $p = m1_post( $id ); return null === $p ? null : (array) ( $p['meta_input'] ?? [] ); }
function m1_rows( bool $include_read = false ): array {
	$rows = [];
	foreach ( $GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ] ?? [] as $post ) {
		if ( IndexQueue::POST_TYPE !== ( $post['post_type'] ?? '' ) || 'private' !== ( $post['post_status'] ?? '' ) ) { continue; }
		$m = (array) ( $post['meta_input'] ?? [] );
		if ( ! $include_read && '' === (string) ( $m['_twp_language'] ?? '' ) ) { continue; }
		$rows[ (int) $post['ID'] ] = $m;
	}
	return $rows;
}
function m1_flag( array $m, string $key ): bool { return (int) ( $m[ $key ] ?? 0 ) > 0; }
function m1_nonterminal( array $m ): bool {
	$status = (string) ( $m['_twp_status'] ?? '' );
	if ( '' === (string) ( $m['_twp_language'] ?? '' ) ) { return in_array( $status, [ 'pending', 'claimed' ], true ); }
	return in_array( $status, [ 'pending', 'claimed' ], true ) || ( 'done' === $status && ! m1_flag( $m, '_twp_translated_at' ) );
}
function m1_describe( array $m ): string {
	$flags = [];
	foreach ( [ 'retranslate', 'retranslating', 'background_refresh', 'refill_finished', 'translated_at', 'cold_retries', 'cold_exhausted', 'dead_retries', 'reader_not_before' ] as $f ) {
		if ( m1_flag( $m, '_twp_' . $f ) ) { $flags[] = $f; }
	}
	if ( '' !== (string) ( $m['_twp_job_id'] ?? '' ) ) { $flags[] = 'job'; }
	if ( '' !== (string) ( $m['_twp_job_outcome'] ?? '' ) ) { $flags[] = 'outcome=' . $m['_twp_job_outcome']; }
	return sprintf( '%s:%s/%s %s/%s [%s]%s', $m['_twp_object_type'] ?? '?', $m['_twp_object_id'] ?? '?', $m['_twp_language'] ?? '?', $m['_twp_status'] ?? '?', $m['_twp_stage'] ?? '?', implode( ',', $flags ), '' !== (string) ( $m['_twp_fail_reason'] ?? '' ) ? ' reason=' . $m['_twp_fail_reason'] : '' );
}
function m1_visitor_row( array $m ): bool {
	return 'miss' === ( $m['_twp_object_type'] ?? '' ) && ! m1_flag( $m, '_twp_retranslate' ) && ! m1_flag( $m, '_twp_retranslating' ) && ! m1_flag( $m, '_twp_background_refresh' );
}
/** Independent recount of read/push/visitor from raw meta (refill is engine-internal and only compared loosely). */
function m1_recount(): array {
	$c = [ 'read' => 0, 'push' => 0, 'visitor' => 0 ];
	foreach ( m1_rows( true ) as $m ) {
		if ( ! isset( $m['_twp_language'], $m['_twp_status'], $m['_twp_stage'], $m['_twp_object_type'] ) ) { continue; }
		$status = (string) ( $m['_twp_status'] ?? '' );
		$lang = (string) ( $m['_twp_language'] ?? '' );
		$active = in_array( $status, [ 'pending', 'claimed' ], true );
		$confirming = 'done' === $status && ! m1_flag( $m, '_twp_translated_at' ) && '' !== $lang;
		if ( '' === $lang ) { $c['read'] += $active ? 1 : 0; continue; }
		if ( $active ) { $c[ 'extract' === ( $m['_twp_stage'] ?? '' ) ? 'read' : 'push' ]++; }
		$c['push'] += $confirming ? 1 : 0;
		if ( m1_visitor_row( $m ) && ( $active || $confirming ) ) { $c['visitor']++; }
	}
	return $c;
}

// ---------------------------------------------------------------- violations and step driver
function m1_violation( string $msg ): void {
	$key = preg_replace( '/\d+/', '#', $msg );
	$GLOBALS['m1_violations'][ $key ] = $GLOBALS['m1_violations'][ $key ] ?? [ 'first_step' => $GLOBALS['m1_step'] ?? 0, 'count' => 0, 'msg' => $msg ];
	$GLOBALS['m1_violations'][ $key ]['count']++;
}
function m1_queries(): int {
	return count( $GLOBALS['wp_test_get_posts_calls'] ?? [] ) + count( $GLOBALS['wp_test_engine_selects'] ?? [] ) + (int) ( $GLOBALS['wp_test_engine_aggregate_calls'] ?? 0 );
}
function m1_generations(): array { return (array) get_option( TranslationStore::CACHE_GENERATIONS_OPTION, [] ); }

function m1_step( array $opts = [] ): array {
	$GLOBALS['m1_step'] = ( $GLOBALS['m1_step'] ?? 0 ) + 1;
	$GLOBALS['m1_step_start'] = ( $GLOBALS['m1_step_start'] ?? m1_time() ) + 60;
	$GLOBALS['m1_clock'] = (float) $GLOBALS['m1_step_start'];
	$GLOBALS['wp_test_now'] = m1_time();
	m1_transients();
	$GLOBALS['m1_step_calls'] = [];
	$q0 = m1_queries();
	$gen0 = m1_generations();
	$jobs_before = array_keys( $GLOBALS['m1_svc']['jobs'] );
	try {
		$r = BackgroundEngine::service_step( [ 'ts' => m1_time() ] );
	} catch ( \Throwable $e ) {
		m1_violation( 'exception: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() );
		$r = [ 'state' => 'error', 'pending' => [ 'read' => 0, 'push' => 0, 'refill' => 0, 'visitor' => 0 ] ];
	}
	$calls = array_sum( array_diff_key( $GLOBALS['m1_step_calls'], [ 'wake' => 1 ] ) );
	$queries = m1_queries() - $q0;
	$S = &$GLOBALS['m1_stats'];
	$S['max_calls'] = max( $S['max_calls'] ?? 0, $calls );
	$S['max_queries'] = max( $S['max_queries'] ?? 0, $queries );
	$S['sim_seconds_max'] = max( $S['sim_seconds_max'] ?? 0, $GLOBALS['m1_clock'] - $GLOBALS['m1_step_start'] );
	// well-formed answer
	if ( ! in_array( $r['state'] ?? null, [ 'more', 'done', 'busy', 'paused' ], true ) ) { m1_violation( 'malformed state ' . json_encode( $r['state'] ?? null ) ); }
	foreach ( [ 'read', 'push', 'refill', 'visitor' ] as $k ) {
		if ( ! is_int( $r['pending'][ $k ] ?? null ) || $r['pending'][ $k ] < 0 ) { m1_violation( 'malformed pending.' . $k ); }
	}
	// purpose invariants on jobs created this step
	$rows = m1_rows();
	foreach ( array_diff( array_keys( $GLOBALS['m1_svc']['jobs'] ), $jobs_before ) as $job ) {
		$purpose = $GLOBALS['m1_svc']['jobs'][ $job ]['purpose'];
		foreach ( $rows as $id => $m ) {
			if ( ( $m['_twp_job_id'] ?? '' ) !== $job ) { continue; }
			if ( m1_visitor_row( $m ) && null !== $purpose ) { m1_violation( "visitor row $id pushed in a job with purpose" ); }
			$bg = m1_flag( $m, '_twp_retranslate' ) || m1_flag( $m, '_twp_retranslating' ) || m1_flag( $m, '_twp_background_refresh' );
			if ( $bg && 'background_refresh' !== $purpose ) { m1_violation( "background row $id pushed in a job without purpose " . m1_describe( $m ) ); }
			if ( ! $bg && ! m1_visitor_row( $m ) ) { $GLOBALS['m1_first_pass_jobs'][ $job ] = $purpose; }
		}
	}
	// translated and pending at once
	foreach ( $rows as $id => $m ) {
		if ( m1_flag( $m, '_twp_translated_at' ) && in_array( $m['_twp_status'] ?? '', [ 'pending', 'claimed' ], true ) ) {
			m1_violation( "row $id is pending with a translated_at stamp " . m1_describe( $m ) );
		}
	}
	// counters versus recount (the answer is computed at the end of the step, so it must match)
	if ( 'more' === $r['state'] || 'done' === $r['state'] ) {
		$re = m1_recount();
		foreach ( $re as $k => $v ) {
			$got = (int) ( $r['pending'][ $k ] ?? 0 );
			if ( 'visitor' === $k && '' !== (string) get_option( BackgroundEngine::VISITOR_HINT_OPTION, '' ) ) { $v = max( 1, $v ); }
			if ( 'push' === $k && '' !== (string) get_option( BackgroundEngine::VISITOR_HINT_OPTION, '' ) ) { $v = max( $v, $re['visitor'] ); }
			if ( $got !== $v ) { m1_violation( "pending.$k answered $got, rows say $v" ); }
		}
		if ( 'done' === $r['state'] && array_sum( $re ) > 0 ) { m1_violation( 'answered done with non-terminal rows' ); }
	}
	foreach ( m1_generations() as $lang => $g ) {
		if ( (int) $g !== (int) ( $gen0[ $lang ] ?? 0 ) ) { $GLOBALS['m1_bumps'][] = [ 'step' => $GLOBALS['m1_step'], 'lang' => $lang, 'gen' => (int) $g ]; }
	}
	$tr = is_array( $r['trace'] ?? null ) ? $r['trace'] : [];
	$GLOBALS['m1_phases'][ (string) ( $tr['phase'] ?? '?' ) ] = ( $GLOBALS['m1_phases'][ (string) ( $tr['phase'] ?? '?' ) ] ?? 0 ) + 1;
	$GLOBALS['m1_trace'][] = sprintf( '%-16s rows=%-3s ', $tr['phase'] ?? '-', $tr['rows'] ?? '-' ) . sprintf( '%4d %-5s r%-4d p%-4d f%-4d v%-3d calls=%-3d q=%-4d %s', $GLOBALS['m1_step'], $r['state'], $r['pending']['read'], $r['pending']['push'], $r['pending']['refill'], $r['pending']['visitor'], $calls, $queries, json_encode( array_diff_key( $GLOBALS['m1_step_calls'], [ 'wake' => 1 ] ) ) ) . ( function_exists( 'm1_dbg' ) ? ' ' . m1_dbg() : '' );
	return $r;
}

/**
 * The engine SQL INNER JOINs _twp_language/_twp_status/_twp_stage/_twp_object_type
 * (IndexQueue::engine_source_sql); the stub double scans every row. Hide rows that lack
 * one of those keys from the engine queries so the model matches MySQL.
 */
class M1_wpdb extends WP_Testwpdb {
	private function engine_query(): bool {
		$r = new ReflectionProperty( WP_Testwpdb::class, 'last_query' ); $r->setAccessible( true );
		$q = (string) $r->getValue( $this );
		return false !== strpos( $q, "meta_key = '_twp_status'" );
	}
	private function filtered( callable $run ) {
		if ( ! $this->engine_query() ) { return $run(); }
		$key = wp_test_blog_store( 'wp_test_inserted_posts' );
		$all = $GLOBALS[ $key ];
		$GLOBALS[ $key ] = array_values( array_filter( $all, static fn( array $p ): bool => IndexQueue::POST_TYPE !== ( $p['post_type'] ?? '' ) || ( isset( $p['meta_input']['_twp_language'], $p['meta_input']['_twp_status'], $p['meta_input']['_twp_stage'], $p['meta_input']['_twp_object_type'] ) ) ) );
		try { return $run(); } finally {
			// writes never happen inside get_results/get_col
			$GLOBALS[ $key ] = $all;
		}
	}
	public function get_results( string $query ): array { return $this->filtered( fn() => parent::get_results( $query ) ); }
	public function get_col( string $query ): array { return $this->filtered( fn() => parent::get_col( $query ) ); }
}

// ---------------------------------------------------------------- start states
function m1_site( array $languages ): void {
	$list = [ 'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ] ];
	foreach ( $languages as $l ) { $list[ $l ] = [ 'locale' => $l, 'slug' => $l, 'active' => true ]; }
	wp_test_reset( [ 'site' => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'test-token' ], 'languages' => [ 'list' => $list ] ] );
	$GLOBALS['wpdb'] = new M1_wpdb();
	foreach ( [ 'm1_violations', 'm1_bumps', 'm1_trace', 'm1_total_calls', 'm1_stats', 'm1_phases', 'wp_test_get_posts_calls', 'wp_test_engine_selects', 'm1_transient_seen' ] as $g ) { $GLOBALS[ $g ] = []; }
	$GLOBALS['wp_test_engine_aggregate_calls'] = 0;
	$GLOBALS['m1_step'] = 0;
	$GLOBALS['m1_step_start'] = m1_time();
	$GLOBALS['wp_test_now'] = m1_time();
	update_option( LexiconChanges::SEEDED_OPTION, true, false );
	update_option( Indexer::SPINE_CHECKED_OPTION, true, false );
	update_option( BackgroundEngine::STARTED_OPTION, m1_time(), false );
	new KeepWarm( '', '' );
	new TranslationStoreSync( '', '' );
	if ( getenv( 'M1_BATCH' ) ) { add_filter( 'translatewp_drain_batch_size', static fn() => (int) getenv( 'M1_BATCH' ) ); }
}
function m1_payload( int $page ): array {
	$items = [];
	for ( $g = 1; $g <= 4; ++$g ) {
		$items[] = [ 'groupId' => "p{$page}g{$g}", 'markerPayload' => "⟪item_0001⟫Paragraph $g of page $page ⟪item_0002⟫more text $page.$g" ];
	}
	return $items;
}
/** (i) fresh site after reads: R read rows with payload, fanned out to every language. */
function m1_fresh( int $pages, array $languages ): void {
	$queue = new IndexQueue();
	$rows = [];
	for ( $i = 1; $i <= $pages; ++$i ) {
		$pid = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page ' . $i ] );
		$GLOBALS['m1_page_of'][ $pid ] = $i;
		$rows[] = [ 'object_type' => 'page', 'object_id' => $pid, 'url' => 'http://example.com/page-' . $i . '/', 'page_title' => '', 'priority' => 100 ];
	}
	$queue->enqueue_read( $rows );
	while ( $claimed = $queue->claim_batch( IndexQueue::STAGE_EXTRACT, 50 ) ) {
		foreach ( $claimed as $row ) { $queue->store_payload( (int) $row['id'], (string) wp_json_encode( m1_payload( (int) $GLOBALS['m1_page_of'][ (int) $row['object_id'] ] ) ) ); }
	}
	( new Indexer( '', '', null, null, false ) )->start_translation( $languages );
}
function m1_run( int $cap, ?callable $each = null ): array {
	$steps = 0; $r = [ 'state' => 'more' ];
	$first_done = null;
	while ( $steps < $cap ) {
		if ( $each ) { $each( $steps + 1 ); }
		$r = m1_step(); ++$steps;
		if ( 'done' === $r['state'] ) { $first_done = $steps; break; }
	}
	return [ 'steps' => $steps, 'done' => $first_done, 'last' => $r ];
}
/** Lexicon change for languages, exactly as LexiconChanges::flush_pending() records it. */
function m1_lexicon_change( array $languages ): void {
	$versions = (array) Options::get( 'cache.lexicon_language_versions', [] );
	$times = (array) Options::get( 'cache.lexicon_changed_at', [] );
	foreach ( $languages as $l ) {
		$versions[ $l ] = \TranslateWP\Components\CacheVersions::lexicon_version_for( $l ) + 1;
		$times[ $l ] = m1_time();
	}
	Options::update( 'cache.lexicon_changed_at', $times );
	Options::update( 'cache.lexicon_language_versions', $versions );
	do_action( 'translatewp_lexicon_changed' );
}
function m1_terminal_report(): array {
	$open = [];
	foreach ( m1_rows() as $id => $m ) {
		if ( m1_nonterminal( $m ) ) { $open[] = "$id " . m1_describe( $m ); }
	}
	$dead = count( array_filter( m1_rows(), static fn( array $m ): bool => 'dead' === ( $m['_twp_status'] ?? '' ) ) );
	return [ 'open' => $open, 'dead' => $dead ];
}

// ---------------------------------------------------------------- scenarios
$results = [];
function m1_record( string $name, int $rows, array $run, ?int $bound, string $note = '' ): void {
	$term = m1_terminal_report();
	$verdict = ( null !== $run['done'] && [] === $term['open'] && ( null === $bound || $run['done'] <= $bound ) && ! $GLOBALS['m1_violations'] ) ? 'PASS' : 'FAIL';
	$GLOBALS['m1_results'][] = [
		'scenario' => $name, 'rows' => $rows, 'steps' => null === $run['done'] ? '>' . $run['steps'] : (string) $run['done'], 'bound' => null === $bound ? '-' : (string) $bound,
		'max_calls' => $GLOBALS['m1_stats']['max_calls'] ?? 0, 'max_q' => $GLOBALS['m1_stats']['max_queries'] ?? 0, 'dead' => $term['dead'],
		'open' => count( $term['open'] ), 'bumps' => count( $GLOBALS['m1_bumps'] ?? [] ), 'verdict' => $verdict, 'note' => $note,
		'violations' => array_values( $GLOBALS['m1_violations'] ?? [] ), 'open_rows' => array_slice( $term['open'], 0, 8 ), 'dead_rows' => array_slice( array_map( static fn( $id, $m ) => "$id " . m1_describe( $m ), array_keys( array_filter( m1_rows(), static fn( $m ) => 'dead' === ( $m['_twp_status'] ?? '' ) ) ), array_filter( m1_rows(), static fn( $m ) => 'dead' === ( $m['_twp_status'] ?? '' ) ) ), 0, 5 ), 'calls' => $GLOBALS['m1_total_calls'] ?? [],
		'trace' => $GLOBALS['m1_trace'], 'phases' => $GLOBALS['m1_phases'] ?? [], 'bump_log' => $GLOBALS['m1_bumps'] ?? [],
	];
}

$args = array_slice( $argv, 1 );
$lat = 0.25; $quiet = false; $want = [];
foreach ( $args as $a ) {
	if ( 0 === strpos( $a, '--lat=' ) ) { $lat = (float) substr( $a, 6 ); }
	elseif ( '--quiet' === $a ) { $quiet = true; }
	elseif ( '--trace' === $a ) { $GLOBALS['m1_show_trace'] = true; }
	elseif ( '--debug' === $a ) { require_once '/tmp/m1-model/dbg.php'; }
	else { $want[] = $a; }
}
$all = [ 'fresh15', 'refill15', 'refill300', 'visitor15', 'fault500', 'faulttimeout', 'faultecho', 'fault409', 'legacy', 'settings15' ];
$want = $want ?: $all;
$GLOBALS['m1_results'] = [];

foreach ( $want as $scenario ) {
	m1_service_reset( [ 'lat' => $lat ] );
	if ( 'fresh15' === $scenario ) {
		m1_site( [ 'es' ] ); m1_fresh( 15, [ 'es' ] );
		$run = m1_run( 200 );
		m1_record( $scenario, 15, $run, null );
		continue;
	}
	if ( preg_match( '/^refill(\d+)$/', $scenario, $mm ) || preg_match( '/^(?:visitor|fault\w+|settings)(\d+)?$/', $scenario, $mm ) ) {
		$r = (int) ( $mm[1] ?? 15 ) ?: 15;
		m1_site( [ 'es' ] ); m1_fresh( $r, [ 'es' ] );
		$warm = m1_run( 5000 );
		if ( null === $warm['done'] ) { m1_record( $scenario . ':warmup', $r, $warm, null, 'fresh warm-up did not finish' ); continue; }
		// reset counters: the measured run starts at the change
		foreach ( [ 'm1_violations', 'm1_bumps', 'm1_trace', 'm1_total_calls', 'm1_stats', 'm1_phases' ] as $g ) { $GLOBALS[ $g ] = []; }
		$GLOBALS['m1_step'] = 0;
		$cap = (int) ( getenv( "M1_CAP" ) ?: 4 * ( 10 + (int) ceil( $r / 10 ) + (int) ceil( $r / 5 ) ) + 60 );
		if ( 'settings15' === $scenario ) {
			do_action( 'translatewp_translation_affecting_settings_saved', [] );
		} else {
			m1_lexicon_change( [ 'es' ] );
		}
		$each = null;
		if ( 'visitor15' === $scenario ) {
			$each = static function ( int $step ): void {
				if ( 0 === $step % 3 && $step < 30 ) {
					$q = new IndexQueue();
					$q->enqueue_miss_page( [ 'object_type' => 'miss', 'object_id' => 5000 + $step, 'url' => 'http://example.com/miss-' . $step . '/', 'page_title' => '', 'language' => 'es', 'priority' => 50 ], [ [ 'groupId' => 'm' . $step, 'markerPayload' => '⟪item_0001⟫Visitor text ' . $step ] ] );
				}
			};
		}
		if ( 0 === strpos( $scenario, 'fault' ) ) {
			$GLOBALS['m1_svc']['fault'] = [ 'fault500' => 'http500', 'faulttimeout' => 'timeout', 'faultecho' => 'no_echo', 'fault409' => '409' ][ $scenario ];
			$faulted = m1_run( 40 );
			$GLOBALS['m1_fault_phase'] = [ 'steps' => $faulted['steps'], 'calls' => $GLOBALS['m1_total_calls'], 'max_calls' => $GLOBALS['m1_stats']['max_calls'] ?? 0, 'dead' => m1_terminal_report()['dead'], 'open' => count( m1_terminal_report()['open'] ), 'state' => $faulted['last']['state'] ];
			$GLOBALS['m1_svc']['fault'] = '';
			// a no-echo back-off holds background jobs for REPAIR_RETRY_SECONDS (7200 s = 120 steps)
			$run = m1_run( $cap + 200 );
			m1_record( $scenario, $r, $run, null, 'fault 40 steps: ' . json_encode( $GLOBALS['m1_fault_phase'] ) );
			continue;
		}
		$run = m1_run( $cap, $each );
		m1_record( $scenario, $r, $run, null );
		continue;
	}
	if ( 'prod612' === $scenario ) {
		// Production shape (coordinator): 612 done rows in 12 languages, 2 read rows, 3 dead, 5 pending-extract.
		// Modelled as 2 read pages + 49 visitor misses per language (51 x 12 = 612), then one Lexicon term for all languages.
		$langs = [ 'es', 'it', 'de', 'fr', 'nl', 'pt', 'pl', 'sv', 'da', 'fi', 'lv', 'cs' ];
		m1_site( $langs ); m1_fresh( 2, $langs );
		$q = new IndexQueue();
		foreach ( $langs as $l ) {
			for ( $i = 1; $i <= 49; ++$i ) {
				$q->enqueue_miss_page( [ 'object_type' => 'miss', 'object_id' => 70000 + $i, 'url' => 'http://example.com/shop-' . $i . '/', 'page_title' => '', 'language' => $l, 'priority' => 50 ], [ [ 'groupId' => 'm' . $i, 'markerPayload' => '⟪item_0001⟫Shop text ' . $i . ' ⟪item_0002⟫more ' . $i ] ] );
			}
		}
		$warm = m1_run( 3000 );
		$t = m1_terminal_report();
		echo "prod612 warm-up: steps=" . json_encode( $warm['done'] ) . ' rows=' . count( m1_rows() ) . ' open=' . count( $t['open'] ) . "\n";
		// 3 dead and 5 pending-extract leftovers (page rows without a read row, as on the site)
		$store = &$GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ];
		$k = 0;
		foreach ( $store as &$post ) {
			if ( IndexQueue::POST_TYPE !== ( $post['post_type'] ?? '' ) || 'miss' !== ( $post['meta_input']['_twp_object_type'] ?? '' ) ) { continue; }
			if ( $k < 3 ) { $post['meta_input']['_twp_status'] = 'dead'; $post['meta_input']['_twp_fail_reason'] = 'job_create_failed'; $post['meta_input']['_twp_updated_at'] = m1_time() - 86400; $post['meta_input']['_twp_dead_retries'] = 3; unset( $post['meta_input']['_twp_translated_at'] ); }
			++$k;
		}
		unset( $post, $store );
		for ( $i = 0; $i < 5; ++$i ) {
			wp_insert_post( [ 'post_type' => IndexQueue::POST_TYPE, 'post_status' => 'private', 'post_content' => '', 'meta_input' => [ '_twp_object_type' => 'page', '_twp_object_id' => 80000 + $i, '_twp_url' => 'http://example.com/page-' . ( 90 + $i ) . '/', '_twp_page_title' => '', '_twp_language' => $langs[ $i ], '_twp_stage' => 'extract', '_twp_status' => 'pending', '_twp_priority' => 100, '_twp_attempts' => 0, '_twp_fail_reason' => '', '_twp_retranslate' => 0 ] ] );
		}
		BackgroundEngine::mark_dirty();
		$pre = m1_run( 400 );
		echo "prod612 leftovers settle: steps=" . json_encode( $pre['done'] ) . ' open=' . count( m1_terminal_report()['open'] ) . "\n";
		foreach ( [ 'm1_violations', 'm1_bumps', 'm1_trace', 'm1_total_calls', 'm1_stats', 'm1_phases' ] as $g ) { $GLOBALS[ $g ] = []; }
		$GLOBALS['m1_step'] = 0;
		m1_lexicon_change( $langs );
		$run = m1_run( 2500 );
		m1_record( $scenario, count( m1_rows() ), $run, null, 'leftover settle run: ' . json_encode( $pre['done'] ) );
		continue;
	}
	if ( 'legacy' === $scenario ) {
		// (iii) a released 1.1.1 site: same meta keys minus _twp_background_refresh, _twp_refill_finished,
		// _twp_settings_version, _twp_previous_read_payload (git show v1.1.1:Components/IndexQueue.php).
		m1_site( [ 'es', 'it' ] ); m1_fresh( 12, [ 'es', 'it' ] );
		$warm = m1_run( 400 );
		foreach ( [ 'm1_violations', 'm1_bumps', 'm1_trace', 'm1_total_calls', 'm1_stats', 'm1_phases' ] as $g ) { $GLOBALS[ $g ] = []; }
		$GLOBALS['m1_step'] = 0;
		$store = &$GLOBALS[ wp_test_blog_store( 'wp_test_inserted_posts' ) ];
		$n = 0; $shapes = [];
		foreach ( $store as &$post ) {
			if ( IndexQueue::POST_TYPE !== ( $post['post_type'] ?? '' ) || '' === (string) ( $post['meta_input']['_twp_language'] ?? '' ) ) { continue; }
			$m = &$post['meta_input'];
			unset( $m['_twp_background_refresh'], $m['_twp_refill_finished'], $m['_twp_settings_version'] );
			$old = m1_time() - 3 * 86400;
			switch ( $n++ % 8 ) {
				case 0: $shapes[] = 'translated, no manifest'; unset( $m['_twp_delivery_manifest'] ); break;
				case 1: $shapes[] = 'claimed 3 days ago (push)'; $m['_twp_status'] = 'claimed'; $m['_twp_stage'] = 'push'; $m['_twp_claimed_at'] = $old; unset( $m['_twp_translated_at'] );
					$post['post_content'] = 'b64:' . base64_encode( (string) wp_json_encode( m1_payload( 1 ) ) ); break;
				case 2: $shapes[] = 'dead, no dead_retries'; $m['_twp_status'] = 'dead'; $m['_twp_fail_reason'] = 'job_create_failed'; $m['_twp_updated_at'] = $old; unset( $m['_twp_translated_at'] ); break;
				case 3: $shapes[] = 'mid-refill pending push, old translated_at'; $m['_twp_status'] = 'pending'; $m['_twp_stage'] = 'push'; $m['_twp_retranslating'] = 1;
					$post['post_content'] = 'b64:' . base64_encode( (string) wp_json_encode( m1_payload( 2 ) ) ); break;
				case 4: $shapes[] = 'reader back-off, pending extract, retranslate'; $m['_twp_status'] = 'pending'; $m['_twp_stage'] = 'extract'; $m['_twp_retranslate'] = 1; $m['_twp_retranslating'] = 1; $m['_twp_reader_not_before'] = m1_time() + 3600; break;
				case 5: $shapes[] = 'confirming, job unknown to service'; unset( $m['_twp_translated_at'], $m['_twp_job_outcome'] ); $m['_twp_job_id'] = '00000000-0000-4000-8000-999999999999'; $m['_twp_updated_at'] = $old; break;
				case 6: $shapes[] = 'missing status/stage keys'; unset( $m['_twp_status'], $m['_twp_stage'] ); break;
				case 7: $shapes[] = 'mid-refill confirming, retranslating, job open'; unset( $m['_twp_translated_at'], $m['_twp_job_outcome'] ); $m['_twp_retranslating'] = 1; $m['_twp_job_id'] = '00000000-0000-4000-8000-999999999998'; $m['_twp_updated_at'] = $old; break;
			}
			unset( $m );
		}
		unset( $post, $store );
		// one legacy visitor miss done+translated and one unconfirmed
		BackgroundEngine::mark_dirty();
		$run = m1_run( 300 );
		m1_record( $scenario, 24, $run, null, 'shapes: ' . json_encode( array_count_values( $shapes ) ) );
		continue;
	}
}

// ---------------------------------------------------------------- report
printf( "%-14s %6s %8s %6s %9s %6s %5s %5s %6s %s\n", 'scenario', 'rows', 'steps', 'bound', 'maxcalls', 'maxq', 'dead', 'open', 'bumps', 'verdict' );
foreach ( $GLOBALS['m1_results'] as $res ) {
	printf( "%-14s %6d %8s %6s %9d %6d %5d %5d %6d %s\n", $res['scenario'], $res['rows'], $res['steps'], $res['bound'], $res['max_calls'], $res['max_q'], $res['dead'], $res['open'], $res['bumps'], $res['verdict'] );
}
if ( ! $quiet ) {
	foreach ( $GLOBALS['m1_results'] as $res ) {
		echo "\n== {$res['scenario']}  phases=" . json_encode( $res['phases'] ) . "\n   bumps=" . json_encode( array_map( static fn( $b ) => $b['lang'] . '@' . $b['step'], $res['bump_log'] ) ) . "\n   calls=" . json_encode( $res['calls'] ) . ( $res['note'] ? "\n   note: {$res['note']}" : '' ) . "\n";
		foreach ( $res['violations'] as $v ) { echo "   VIOLATION x{$v['count']} first@step {$v['first_step']}: {$v['msg']}\n"; }
		foreach ( $res['open_rows'] as $o ) { echo "   OPEN $o\n"; }
		foreach ( $res['dead_rows'] ?? [] as $o ) { echo "   DEAD $o\n"; }
		if ( ! empty( $GLOBALS['m1_show_trace'] ) ) { echo '   ' . implode( "\n   ", $res['trace'] ) . "\n"; }
	}
}
exit( in_array( 'FAIL', array_column( $GLOBALS['m1_results'], 'verdict' ), true ) ? 1 : 0 );
}
