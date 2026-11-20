<?php

declare(strict_types=1);

use TranslateWP\Components\BackgroundEngine;
use TranslateWP\Components\QueueExecution;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/wp-test-stubs.php';

wp_test_reset( [] );
if ( isset( BackgroundEngine::step()['trace'] ) ) {
	throw new RuntimeException( 'Normal responses must not contain a trace' );
}
define( 'TRANSLATEWP_ENGINE_TRACE', true );
$trace = BackgroundEngine::step()['trace'];
if ( ['phase', 'rows', 'inputRows', 'queries', 'ms', 'remote'] !== array_keys( $trace ) || 'idle' !== $trace['phase'] || 0 !== $trace['rows'] || 0 !== $trace['remote'] ) {
	throw new RuntimeException( 'Opt-in idle trace has the bounded measurement shape' );
}
QueueExecution::bound_http_args( [] );
if ( 1 !== QueueExecution::trace_remote() ) {
	throw new RuntimeException( 'Trace counts HTTP attempts' );
}
echo "BackgroundTraceTest: all assertions passed\n";
