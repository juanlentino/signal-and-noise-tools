<?php
/**
 * Sweep wrapper for the /verify decision-core Node harness.
 *
 * The pure verifier logic lives in assets/js/prov-verify-core.js and its
 * executable fixtures in tests/js/prov-verify-core.test.mjs (fixtures under
 * tests/js/fixtures/, NO network at runtime). The standalone sweep and CI
 * both glob tests/*.php only, so this wrapper is the harness's seat at that
 * table: it runs node via proc_open (argv array — no shell string, nothing
 * to inject), relays every per-case line, and emits its own standard
 * "N passed, M failed" summary mirroring the harness totals.
 *
 * Failure is always LOUD, never a silent skip:
 *   - node missing / not runnable   -> "0 passed, 1 failed" + a message
 *   - harness crashed / no summary  -> "0 passed, 1 failed" + the output
 *   - summary present but exit code disagrees -> forced failure
 *
 * Run: php tests/provenance-verify-core.php
 *
 * @package SignalNoiseTools
 * @since 9.79.2
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

/**
 * Run an argv-array command via proc_open (never a shell string), returning
 * array{output:string, code:int}. code -1 means the process could not start
 * (the "node is not installed" path).
 *
 * @param array $argv Command + arguments.
 * @return array{output:string, code:int}
 */
function pvc_run( array $argv ) {
	$spec = array(
		0 => array( 'file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$proc = @proc_open( $argv, $spec, $pipes );
	if ( ! is_resource( $proc ) ) {
		return array(
			'output' => '',
			'code'   => -1,
		);
	}
	$stdout = (string) stream_get_contents( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $proc );
	return array(
		'output' => $stdout . ( '' !== $stderr ? "\n" . $stderr : '' ),
		'code'   => $code,
	);
}

echo "Provenance verify-core suite (Node harness relay)\n\n";

$harness = __DIR__ . '/js/prov-verify-core.test.mjs';
if ( ! file_exists( $harness ) ) {
	echo "  FAIL: harness file tests/js/prov-verify-core.test.mjs is missing\n";
	echo "\nResult: 0 passed, 1 failed.\n";
	exit( 1 );
}

$probe = pvc_run( array( 'node', '--version' ) );
if ( -1 === $probe['code'] || 0 !== $probe['code'] ) {
	echo "  FAIL: node is not runnable on this machine — the /verify decision core has an executable\n";
	echo "        fixture harness (tests/js/prov-verify-core.test.mjs) that MUST run in every sweep.\n";
	echo "        Install Node.js; this suite never skips silently (a skipped trust-surface test is\n";
	echo "        a false green).\n";
	echo "\nResult: 0 passed, 1 failed.\n";
	exit( 1 );
}

$run    = pvc_run( array( 'node', $harness ) );
$output = $run['output'];

// Relay the harness's per-case lines indented under this suite's banner.
foreach ( explode( "\n", rtrim( $output, "\n" ) ) as $line ) {
	echo '  ' . $line . "\n";
}

// Gate on the harness's own literal summary line — a crash mid-run leaves no
// summary, and its absence is a failure (never trust the exit code alone, and
// never trust output alone either: both must agree).
if ( ! preg_match( '/Result: (\d+) passed, (\d+) failed\./', $output, $m ) ) {
	echo "\n  FAIL: the Node harness emitted no summary line (crash or truncation), exit code {$run['code']}\n";
	echo "\nResult: 0 passed, 1 failed.\n";
	exit( 1 );
}

$node_pass = (int) $m[1];
$node_fail = (int) $m[2];
if ( 0 === $node_fail && 0 !== $run['code'] ) {
	echo "\n  FAIL: harness summary says 0 failed but its exit code is {$run['code']} — refusing the green\n";
	echo "\nResult: {$node_pass} passed, 1 failed.\n";
	exit( 1 );
}
if ( 0 === $node_pass ) {
	echo "\n  FAIL: the harness asserted nothing (0 passed) — an empty run is not a green run\n";
	echo "\nResult: 0 passed, 1 failed.\n";
	exit( 1 );
}

echo "\nResult: {$node_pass} passed, {$node_fail} failed.\n";
exit( $node_fail > 0 ? 1 : 0 );
