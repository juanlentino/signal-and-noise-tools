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
	if ( ! empty( $GLOBALS['__response']['wp_error'] ) ) {
		return (object) array( 'errors' => array( 'http_request_failed' => array( 'fail' ) ) );
	}
	return array( 'response' => array( 'code' => $GLOBALS['__response']['code'] ), 'body' => $GLOBALS['__response']['body'] );
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function is_wp_error( $x ) { return is_object( $x ) && isset( $x->errors ); }
$GLOBALS['__transients'] = array();
$GLOBALS['__cache_on']   = false;
function get_transient( $k ) {
	if ( empty( $GLOBALS['__cache_on'] ) ) {
		return false;
	}
	return $GLOBALS['__transients'][ $k ] ?? false;
}
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function set_transient( $k, $v, $ttl = 0 ) {
	if ( ! empty( $GLOBALS['__cache_on'] ) ) {
		$GLOBALS['__transients'][ $k ] = $v;
	}
	return true;
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function wp_http_validate_url( $url ) { return false !== filter_var( (string) $url, FILTER_VALIDATE_URL ) ? $url : false; }
// Deterministic SSRF-guard seam (no DNS in tests): records every host consulted,
// blocks only the empty host, mirroring the real guard's fail-closed edge.
$GLOBALS['__ssrf_hosts'] = array();
function sn_ssrf_host_blocked( $host ) { $GLOBALS['__ssrf_hosts'][] = (string) $host; return '' === (string) $host; }

// v10.79.0: api.php calls snt_mr_normalize_taxonomy_fields(), so the real
// dependency is loaded rather than stubbed — these declarations are unguarded,
// and a stub would either fatal on redeclare or model a shape the callee does
// not actually have.
require __DIR__ . '/../inc/machine-readers-taxonomy.php';
require __DIR__ . '/../inc/machine-readers-api.php';

echo "Group: enums (mirror of the worker's src/machine-readers.mjs)\n";
$fams = snt_mr_valid_families();
ok( 19 === count( $fams ) && in_array( 'anthropic', $fams, true ) && in_array( 'other-bot', $fams, true ), '19 families incl anthropic + other-bot' );
// v10.79.0 RULE 1: the 18 frozen values keep their identity AND their order.
// Counting alone would pass if a value were swapped for another.
ok( array_slice( $fams, 0, 18 ) === array(
	'openai', 'anthropic', 'google-ai', 'perplexity', 'commoncrawl',
	'bytedance', 'amazon-ai', 'apple-ai', 'meta-ai', 'mistral', 'cohere',
	'allen-ai', 'diffbot', 'search', 'seo', 'feed', 'uptime', 'other-bot',
), 'the 18 frozen families are byte-identical and in their original order' );
ok( 'unclassified-machine' === $fams[18], 'the additive family is appended last' );
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

echo "\nGroup: v9.85.1 regression — a stored-blank worker_url means the default endpoint\n";
// The settings form says "Blank uses the built-in live endpoint" and the save
// handler stores '' — config must fall back, not read '' as unconfigured.
$GLOBALS['__settings']['machine_readers.worker_url'] = '';
$GLOBALS['__settings']['machine_readers.read_token'] = 'test-token';
$GLOBALS['__response'] = array( 'code' => 200, 'body' => json_encode( array( 'worker' => 'sn-rights-signals', 'days' => 7, 'data' => array() ) ) );
$r = snt_mr_fetch( 7 );
ok( true === $r['ok'], 'blank stored URL + token set: fetch works (the v9.85.0 yellow-banner bug)' );
$last = end( $GLOBALS['__requests'] );
ok( $last && 0 === strpos( (string) $last['url'], SN_MR_DEFAULT_ENDPOINT ), 'request went to the built-in default endpoint' );
$GLOBALS['__settings']['machine_readers.worker_url'] = 'https://juanlentino.com/_sn/rights-signals/machine-readers';

echo "\nGroup: snt_mr_sensor_info — deployed-version read, fail-quiet\n";
$GLOBALS['__response'] = array( 'code' => 200, 'body' => json_encode( array( 'worker' => 'sn-rights-signals', 'version' => '1.4.0', 'deployed_at' => '2026-07-28T17:12:22.596257Z' ) ) );
$info = snt_mr_sensor_info();
ok( is_array( $info ) && '1.4.0' === ( $info['version'] ?? '' ), 'happy path returns the deployed version' );
ok( true === ( $info['reachable'] ?? null ), 'valid version still marks the sensor reachable' );
ok( '' !== (string) ( $info['deployed_at'] ?? '' ), 'deployed_at rides along' );
$hostile = '<script>alert(1)</script>';
$GLOBALS['__response'] = array( 'code' => 200, 'body' => json_encode( array( 'version' => $hostile, 'deployed_at' => 'x' ) ) );
$hostile_info = snt_mr_sensor_info();
ok( is_array( $hostile_info ) && true === ( $hostile_info['reachable'] ?? null ), 'hostile version is still reachable (200, we refuse the string, not the sensor)' );
ok( '' === ( $hostile_info['version'] ?? null ), 'hostile version is exactly the empty string, never a sanitised derivative' );
$hostile_serial = json_encode( $hostile_info );
ok( false === strpos( $hostile_serial, $hostile ) && false === strpos( $hostile_serial, '<script>' ), 'hostile version string appears nowhere in the returned structure' );
$GLOBALS['__response'] = array( 'code' => 500, 'body' => '' );
ok( null === snt_mr_sensor_info(), 'non-200 returns null (quiet dash, never fatal)' );
$GLOBALS['__response'] = array( 'code' => 200, 'body' => '', 'wp_error' => true );
ok( null === snt_mr_sensor_info(), 'WP_Error returns null (transport failure, unchanged)' );
unset( $GLOBALS['__response']['wp_error'] );
$GLOBALS['__response'] = array( 'code' => 200, 'body' => 'not json' );
ok( null === snt_mr_sensor_info(), 'garbage body returns null' );

echo "\nGroup: snt_mr_sensor_info — reachable but version unreported\n";
$live = array(
	'worker'      => 'sn-rights-signals',
	'version'     => null,
	'deployed_at' => '2026-08-13T01:49:33.861394Z',
	'sensor'      => array( 'ae_bound' => true, 'last_write_ok' => null, 'last_write_at' => null ),
);
$GLOBALS['__response'] = array( 'code' => 200, 'body' => json_encode( $live ) );
$info = snt_mr_sensor_info();
ok( is_array( $info ) && true === ( $info['reachable'] ?? null ), '200 + version null → array, reachable true' );
ok( '' === ( $info['version'] ?? null ), '200 + version null → version is exactly empty string' );
ok( 0 === strpos( (string) ( $info['deployed_at'] ?? '' ), '2026-08-13T01:49:33' ), 'deployed_at survives into the reachable-but-unversioned array' );
ok( isset( $info['sensor'] ) && is_array( $info['sensor'] ) && true === $info['sensor']['ae_bound'] && array_key_exists( 'last_write_ok', $info['sensor'] ) && null === $info['sensor']['last_write_ok'], 'sensor block survives into the reachable-but-unversioned array' );
$absent = $live;
unset( $absent['version'] );
$GLOBALS['__response'] = array( 'code' => 200, 'body' => json_encode( $absent ) );
$info = snt_mr_sensor_info();
ok( is_array( $info ) && true === ( $info['reachable'] ?? null ) && '' === ( $info['version'] ?? null ), '200 + version key absent → reachable, version empty' );

$GLOBALS['__cache_on']   = true;
$GLOBALS['__transients'] = array();
$GLOBALS['__response']   = array( 'code' => 200, 'body' => json_encode( $live ) );
$cached = snt_mr_sensor_info();
$GLOBALS['__response'] = array( 'code' => 500, 'body' => '' );
$again = snt_mr_sensor_info();
ok( isset( $cached['version'] ) && '' === $cached['version'] && $again === $cached, 'empty-string version still hits the 15-minute transient (isset is true for \'\')' );
$GLOBALS['__cache_on']   = false;
$GLOBALS['__transients'] = array();

echo "\nGroup: v9.86.0 — crawler-list status flattens last_check into scalars\n";
$GLOBALS['__response'] = array( 'code' => 200, 'body' => json_encode( array( 'worker' => 'sn-rights-signals', 'last_check' => array( 'ok' => true, 'drift' => false, 'checked_at' => '2026-07-27T07:23:00.000Z' ) ) ) );
$st = snt_mr_crawler_list_status();
ok( is_array( $st ) && 'sn-rights-signals' === ( $st['worker'] ?? '' ), 'scalar top-level members survive' );
ok( '1' === (string) ( $st['last_check_ok'] ?? '' ) || 'yes' === ( $st['last_check_ok'] ?? '' ), 'nested last_check.ok flattens to a scalar' );
ok( isset( $st['last_check_drift'] ), 'nested last_check.drift flattens' );
ok( '2026-07-27T07:23:00.000Z' === ( $st['last_check_checked_at'] ?? '' ), 'nested last_check.checked_at flattens' );

echo "\nGroup: v10.2.7 — last-known-good verdict (the SN_WORKER_VERSION_LASTGOOD pattern)\n";
// A real verdict is remembered durably...
$GLOBALS['__response'] = array( 'code' => 200, 'body' => json_encode( array( 'worker' => 'sn-rights-signals', 'last_check' => array( 'ok' => true, 'drift' => false, 'checked_at' => '2026-07-28T22:36:58.025Z' ) ) ) );
$st = snt_mr_crawler_list_status();
ok( isset( $GLOBALS['__options']['sn_mr_crawler_lastgood']['last_check_ok'] ), 'a completed verdict is stored as last-known-good' );
// ...and a later null blip (fresh isolate, purged colo cache, deploy) serves it
// instead of flickering the pill back to "unchecked".
$GLOBALS['__response'] = array( 'code' => 200, 'body' => json_encode( array( 'worker' => 'sn-rights-signals', 'last_check' => null ) ) );
$st = snt_mr_crawler_list_status();
ok( is_array( $st ) && '1' === (string) ( $st['last_check_ok'] ?? '' ), 'a null-verdict response falls back to the stored verdict (no pill flicker)' );
ok( '2026-07-28T22:36:58.025Z' === ( $st['last_check_checked_at'] ?? '' ), 'the fallback carries its own checked_at (honest about WHEN it was judged)' );
// A site that has NEVER seen a verdict still says so honestly.
$GLOBALS['__options'] = array();
$st = snt_mr_crawler_list_status();
ok( is_array( $st ) && ! isset( $st['last_check_ok'] ), 'no stored verdict + null response stays honestly unchecked' );
// A NEWER verdict replaces the stored one (drift flip must not be masked).
$GLOBALS['__response'] = array( 'code' => 200, 'body' => json_encode( array( 'worker' => 'sn-rights-signals', 'last_check' => array( 'ok' => true, 'drift' => true, 'checked_at' => '2026-08-03T07:23:00.000Z' ) ) ) );
$st = snt_mr_crawler_list_status();
ok( '1' === (string) ( $GLOBALS['__options']['sn_mr_crawler_lastgood']['last_check_drift'] ?? '' ), 'a newer verdict (incl. a drift flip) replaces the stored one' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
