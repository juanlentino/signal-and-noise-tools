<?php
/** Run the real prov-verify-diff.js arrival path under a fake /verify page in Node (tests/prov-verify-compare-arrival.cjs). */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$pipes = array();
$process = proc_open(
	array( 'node', __DIR__ . '/prov-verify-compare-arrival.cjs' ),
	array( 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ),
	$pipes
);
if ( ! is_resource( $process ) ) {
	echo "Result: 0 passed, 1 failed. Node is required; never silently skip.\n";
	exit( 1 );
}
$output = stream_get_contents( $pipes[1] );
fclose( $pipes[1] );
$code = proc_close( $process );
echo $output;
// The harness prints its own summary last. A crash prints none, and a nonzero
// exit outranks any count, so either one ends this suite red.
if ( 0 !== $code || ! preg_match( '/Result: [1-9]\d* passed, 0 failed\.\s*$/', $output ) ) {
	echo "Result: 0 passed, 1 failed.\n";
	exit( 1 );
}
exit( 0 );
