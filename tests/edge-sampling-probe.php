<?php
/**
 * Tests: signal-noise/edge-sampling-probe (17.9.2, #1002).
 *
 * Run: php tests/edge-sampling-probe.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function add_action( $h, $c = null, $p = 10, $a = 1 ) {}
$GLOBALS['__cfg']  = array( 'token' => 't', 'zone' => 'z' );
$GLOBALS['__resp'] = array();
function sn_edge_config() { return $GLOBALS['__cfg']; }
function sn_edge_query( $query, $vars = array(), &$error = null ) {
	$error = '';
	foreach ( $GLOBALS['__resp'] as $needle => $r ) {
		if ( false !== strpos( $query, $needle ) ) {
			if ( is_string( $r ) ) { $error = $r; return null; }
			return $r;
		}
	}
	$error = 'no fixture';
	return null;
}
require __DIR__ . '/../inc/abilities-edge-sampling-probe.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group 1: the summary\n";
$s = snt_edge_sampling_summary( array(
	array( 'count' => 10, 'avg' => array( 'sampleInterval' => 1 ) ),
	array( 'count' => 4, 'avg' => array( 'sampleInterval' => 10 ) ),
) );
ok( 2 === $s['rows'] && 14 === $s['count'] && 50 === $s['count_times_interval'], 'count 14; count x interval 10 + 40 = 50' );
ok( 1.0 === $s['interval_min'] && 10.0 === $s['interval_max'] && 1 === $s['sampled_rows'], 'the interval range and the one row that is sampled' );
$s = snt_edge_sampling_summary( array( array( 'count' => 7, 'avg' => array( 'sampleInterval' => 1 ) ) ) );
ok( 0 === $s['sampled_rows'] && $s['count'] === $s['count_times_interval'], 'at interval 1 the two readings agree: nothing is sampled' );

echo "\nGroup 2: the probe\n";
$GLOBALS['__resp'] = array(
	'a:httpRequestsAdaptiveGroups' => array( 'a' => array( array( 'count' => 3, 'avg' => array( 'sampleInterval' => 1 ), 'dimensions' => array( 'edgeResponseStatus' => 504, 'originResponseStatus' => 0, 'clientRequestPath' => '/x' ) ) ) ),
	'b:httpRequestsAdaptiveGroups' => array( 'b' => array(
		array( 'count' => 2, 'dimensions' => array( 'requestSource' => 'edgeWorkerFetch', 'edgeResponseStatus' => 504 ) ),
		array( 'count' => 1, 'dimensions' => array( 'requestSource' => 'eyeball', 'edgeResponseStatus' => 504 ) ),
	) ),
);
$r = snt_ability_edge_sampling_probe();
ok( 'measured' === $r['state'] && 3 === $r['sampling']['count'] && '/x' === $r['top_groups'][0]['path'], 'reading A: the sampled 5xx groups and their paths' );
ok( array( 'edgeWorkerFetch 504' => 2, 'eyeball 504' => 1 ) === $r['by_request_source'], 'reading B: 5xx split by request source, largest first' );

$GLOBALS['__resp']['b:httpRequestsAdaptiveGroups'] = 'unknown field "requestSource"';
$r = snt_ability_edge_sampling_probe();
ok( null === $r['by_request_source'] && false !== strpos( $r['source_error'], 'requestSource' ) && 3 === $r['sampling']['count'], 'a refused B reports its own error and leaves A intact' );

$GLOBALS['__cfg'] = null;
ok( array( 'state' => 'unconfigured' ) === snt_ability_edge_sampling_probe(), 'unconfigured: says so, spends no call' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
