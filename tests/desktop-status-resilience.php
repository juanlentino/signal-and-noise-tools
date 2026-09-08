<?php
/** Execute the real desktop widgets/runner under deterministic Node fixtures in CI. */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$pipes = array();
$process = proc_open(
	array( 'node', __DIR__ . '/desktop-status-resilience.cjs' ),
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
$ok = 0 === $code && str_contains( $output, 'PASS: runner and both widgets' );
echo $ok ? "Result: 1 passed, 0 failed.\n" : "Result: 0 passed, 1 failed.\n";
exit( $ok ? 0 : 1 );
