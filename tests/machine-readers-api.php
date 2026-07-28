<?php
/**
 * Tests: Machine Readers sensor read + normalization (Session 3 lane 1).
 * SCAFFOLD-RED: written against the shells on purpose; lane 1 turns it green.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

// Minimal WP stubs — lane 1 extends these to whatever the impl actually calls
// (sn_setting, ssrf guard, wp_remote_*). Recorder pattern per house fixtures.
$GLOBALS['__settings'] = array(
	'machine_readers.worker_url' => 'https://juanlentino.com/_sn/rights-signals/machine-readers',
	'machine_readers.read_token' => 'test-token',
);
function sn_setting( $key, $default = null ) { return $GLOBALS['__settings'][ $key ] ?? $default; }
$GLOBALS['__requests'] = array();
$GLOBALS['__response'] = array( 'code' => 200, 'body' => '' );
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__requests'][] = array( 'url' => $url, 'args' => $args );
	return array( 'response' => array( 'code' => $GLOBALS['__response']['code'] ), 'body' => $GLOBALS['__response']['body'] );
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function is_wp_error( $x ) { return false; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $ttl = 0 ) { return true; }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function wp_http_validate_url( $url ) { return false !== filter_var( (string) $url, FILTER_VALIDATE_URL ) ? $url : false; }
// Deterministic SSRF-guard seam (no DNS in tests): records every host consulted,
// blocks only the empty host, mirroring the real guard's fail-closed edge.
$GLOBALS['__ssrf_hosts'] = array();
function sn_ssrf_host_blocked( $host ) { $GLOBALS['__ssrf_hosts'][] = (string) $host; return '' === (string) $host; }

require __DIR__ . '/../inc/machine-readers-api.php';

echo "Group: enums (mirror of the worker's src/machine-readers.mjs)\n";
$fams = snt_mr_valid_families();
ok( 18 === count( $fams ) && in_array( 'anthropic', $fams, true ) && in_array( 'other-bot', $fams, true ), '18 families incl anthropic + other-bot' );
$surf = snt_mr_valid_surfaces();
ok( 10 === count( $surf ) && in_array( 'rights', $surf, true ) && in_array( 'html', $surf, true ), '10 surfaces incl rights + html' );

echo "\nGroup: snt_mr_normalize_rows — worker values are untrusted\n";
$rows = snt_mr_normalize_rows( array(
	array( 'family' => 'openai', 'surface' => 'llms', 'day' => '2026-07-28', 'hits' => '12' ),
	array( 'family' => '<script>alert(1)</script>', 'surface' => 'nonsense', 'day' => '2026-07-28', 'hits' => 3 ),
	array( 'family' => 'anthropic', 'surface' => 'rights', 'day' => '2026-07-27', 'hits' => -5 ),
	'not-a-row',
) );
ok( 3 === count( $rows ), 'non-array rows are dropped' );
ok( ( $rows[0]['family'] ?? '' ) === 'openai' && ( $rows[0]['hits'] ?? 0 ) === 12, 'valid row passes; hits cast to int' );
ok( ( $rows[1]['family'] ?? '' ) === 'other-bot', 'unknown family coerces to other-bot (never the raw string)' );
ok( ( $rows[1]['surface'] ?? '' ) === 'html', 'unknown surface coerces to html' );
ok( ( $rows[2]['hits'] ?? -1 ) === 0, 'negative hits floor at 0' );
$flat = json_encode( $rows );
ok( false === strpos( $flat, '<script>' ), 'no raw attacker string survives normalization' );

echo "\nGroup: snt_mr_fetch — token, clamp, fail-closed\n";
$GLOBALS['__settings']['machine_readers.read_token'] = '';
$r = snt_mr_fetch( 7 );
ok( false === $r['ok'] && 'not_configured' === ( $r['error'] ?? '' ), 'no token → ok=false error=not_configured (loud, never silent)' );
$GLOBALS['__settings']['machine_readers.read_token'] = 'test-token';
$GLOBALS['__response'] = array( 'code' => 200, 'body' => json_encode( array( 'worker' => 'sn-rights-signals', 'days' => 7, 'data' => array( array( 'family' => 'openai', 'surface' => 'llms', 'day' => '2026-07-28', 'hits' => 4 ) ) ) ) );
$r = snt_mr_fetch( 999 );
ok( true === $r['ok'] && 1 === count( $r['rows'] ), 'happy path: ok=true with normalized rows' );
$last = end( $GLOBALS['__requests'] );
ok( $last && false !== strpos( (string) $last['url'], 'days=90' ), 'days clamps to 90 in the request' );
$auth = $last['args']['headers']['Authorization'] ?? ( $last['args']['headers']['authorization'] ?? '' );
ok( 'Bearer test-token' === $auth, 'Bearer token rides the request header' );
ok( in_array( 'juanlentino.com', $GLOBALS['__ssrf_hosts'], true ), 'outbound host was consulted through the SSRF guard' );
$GLOBALS['__response'] = array( 'code' => 200, 'body' => '{"data":"not-an-array"}' );
$r = snt_mr_fetch( 7 );
ok( false === $r['ok'], 'schema mismatch fails closed (ok=false)' );

echo "\nGroup: snt_mr_sensor_info — deployed-version read, fail-quiet\n";
$GLOBALS['__response'] = array( 'code' => 200, 'body' => json_encode( array( 'worker' => 'sn-rights-signals', 'version' => '1.4.0', 'deployed_at' => '2026-07-28T17:12:22.596257Z' ) ) );
$info = snt_mr_sensor_info();
ok( is_array( $info ) && '1.4.0' === ( $info['version'] ?? '' ), 'happy path returns the deployed version' );
ok( '' !== (string) ( $info['deployed_at'] ?? '' ), 'deployed_at rides along' );
$GLOBALS['__response'] = array( 'code' => 200, 'body' => json_encode( array( 'version' => '<b>evil</b>', 'deployed_at' => 'x' ) ) );
ok( null === snt_mr_sensor_info(), 'hostile version string fails the allowlist and returns null' );
$GLOBALS['__response'] = array( 'code' => 500, 'body' => '' );
ok( null === snt_mr_sensor_info(), 'non-200 returns null (quiet dash, never fatal)' );
$GLOBALS['__response'] = array( 'code' => 200, 'body' => 'not json' );
ok( null === snt_mr_sensor_info(), 'garbage body returns null' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
