<?php
/**
 * Standalone fixture tests for the Cloudflare security-header drift check
 * in inc/health-checks.php (v4.9.0, Task 1).
 *
 * Covers sn_health_check_cf_security_headers():
 *   - all 5 delegated headers present → 0 findings
 *   - a missing header → a finding row with that header label
 *   - 2 missing → 2 findings
 *   - cache hit short-circuits wp_remote_head (call counter)
 *   - WP_Error probe → 0 findings AND transient NOT written (self-heals)
 *
 * The wp_remote_retrieve_headers stub returns the SAME lower-cased assoc
 * shape the impl normalizes to (falsification: a trivially-passing stub
 * that returns already-normalized data would NOT exercise the
 * CaseInsensitiveDictionary cast — so one fixture returns a
 * mixed-case CaseInsensitiveDictionary-like object too).
 *
 * Run: php tests/health-checks-cf-headers.php
 *
 * @since plugin v4.9.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'SNT_VERSION', '4.9.0' );

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) { return 'https://juanlentino.com' . $path; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( $path ) { return basename( $path ); }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p ) { return 'https://juanlentino.com/wp-admin/' . $p; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) { return "https://juanlentino.com/?p=$id"; }
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v ) { return json_encode( $v ); }
}

// Transient stubs.
$GLOBALS['__test_transients'] = array();
function get_transient( $key ) {
	return isset( $GLOBALS['__test_transients'][ $key ] ) ? $GLOBALS['__test_transients'][ $key ] : false;
}
function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['__test_transients'][ $key ] = $value;
	return true;
}

// wp_remote_head — fixture + call counter.
$GLOBALS['__test_head_response'] = null;
$GLOBALS['__test_head_calls']    = 0;
function wp_remote_head( $url, $args = array() ) {
	$GLOBALS['__test_head_calls']++;
	return $GLOBALS['__test_head_response'];
}
// wp_remote_get — the WAF probe's transport. Default fixture: an edge-less 200
// (no cf-ray), which the probe reads as 'unknown' — so the header tests above
// are not disturbed by the probe riding along.
$GLOBALS['__test_get_response'] = array( 'response' => array( 'code' => 200 ), 'headers' => array() );
$GLOBALS['__test_get_calls']    = 0;
$GLOBALS['__test_get_headers']  = array();
$GLOBALS['__test_get_urls']     = array();
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__test_get_calls']++;
	$GLOBALS['__test_get_urls'][]  = (string) $url;
	$GLOBALS['__test_get_headers'] = isset( $args['headers'] ) ? (array) $args['headers'] : array();
	return $GLOBALS['__test_get_response'];
}
function wp_remote_retrieve_body( $resp ) {
	return ( is_array( $resp ) && isset( $resp['body'] ) ) ? (string) $resp['body'] : '';
}
// The Better Stack token: the WAF witness reads sn_uptime_status_configured(),
// which reads this option. Unset by default so the header tests (1-9) see the
// witness as unconfigured and stay about headers.
$GLOBALS['__test_bs_token'] = '';
function get_option( $key, $default = false ) {
	return 'sn_betterstack_api_token' === $key ? $GLOBALS['__test_bs_token'] : $default;
}
function wp_remote_retrieve_response_code( $resp ) {
	return ( is_array( $resp ) && isset( $resp['response']['code'] ) ) ? (int) $resp['response']['code'] : 0;
}

// wp_remote_retrieve_headers — returns whatever the fixture response carries
// under ['headers'] (an assoc OR a CaseInsensitiveDictionary-like object).
function wp_remote_retrieve_headers( $resp ) {
	if ( is_array( $resp ) && isset( $resp['headers'] ) ) {
		return $resp['headers'];
	}
	return array();
}

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

// A faithful CaseInsensitiveDictionary stand-in matching the REAL
// WpOrg\Requests shape: $data is PROTECTED (so a (array) cast mangles the key
// to "\0*\0data" and never unwraps), keys are lower-cased on set, and the
// public getAll() returns the already-lower-cased data. This makes the test
// FALSIFYING: the old (array)-cast impl reports all-missing against it; only
// the getAll()-based impl reads the headers correctly.
class SN_Test_CI_Dictionary implements ArrayAccess, IteratorAggregate {
	protected $data = array();
	public function __construct( $arr ) {
		foreach ( $arr as $k => $v ) { $this->offsetSet( $k, $v ); }
	}
	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ) { return isset( $this->data[ strtolower( $offset ) ] ); }
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) { return $this->data[ strtolower( $offset ) ] ?? null; }
	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) { $this->data[ strtolower( (string) $offset ) ] = $value; }
	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ) { unset( $this->data[ strtolower( $offset ) ] ); }
	#[\ReturnTypeWillChange]
	public function getIterator() { return new ArrayIterator( $this->data ); }
	public function getAll() { return $this->data; }
}

// Minimal $wpdb stub — the CF check does no DB work, but loading
// health-checks.php must not fatal.
if ( ! defined( 'OBJECT' ) )  { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class {
		public $posts = 'wp_posts';
		public $rows  = array();
		public function get_results( $sql, $output_mode = 'OBJECT' ) { return $this->rows; }
	};
}

require_once __DIR__ . '/../inc/health-checks.php';
// The REAL Better Stack client, so the witness is read through the same
// wp_remote_get + JSON:API path production uses — a hand stub of
// sn_uptime_status_api_get() would let the two drift.
require_once __DIR__ . '/../inc/uptime-status.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function cf_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function cf_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

$all5 = array(
	'content-security-policy'   => "default-src 'self'",
	'strict-transport-security' => 'max-age=31536000',
	'x-content-type-options'    => 'nosniff',
	'x-frame-options'           => 'SAMEORIGIN',
	'referrer-policy'           => 'strict-origin-when-cross-origin',
);

function cf_reset() {
	$GLOBALS['__test_transients']    = array();
	$GLOBALS['__test_head_calls']    = 0;
	$GLOBALS['__test_head_response'] = null;
}

// ─── Test 1: all 5 present → 0 findings ──────────────────────────────
echo "\nTest 1: all 5 delegated headers present → 0 findings\n";
cf_reset();
$GLOBALS['__test_head_response'] = array( 'response' => array( 'code' => 200 ), 'headers' => $all5 );
$check = sn_health_check_cf_security_headers();
cf_eq( 0, $check['count'], 'all present → count 0' );
cf_eq( 1, $GLOBALS['__test_head_calls'], 'probe fired once' );
cf_true( is_array( $check['findings'] ), 'findings is an array' );

// ─── Test 2: drop x-frame-options → 1 finding with that label ────────
echo "\nTest 2: missing x-frame-options → 1 finding\n";
cf_reset();
$missing = $all5; unset( $missing['x-frame-options'] );
$GLOBALS['__test_head_response'] = array( 'response' => array( 'code' => 200 ), 'headers' => $missing );
$check = sn_health_check_cf_security_headers();
cf_eq( 1, $check['count'], 'one missing → 1 finding' );
$f = $check['findings'][0];
cf_eq( 'security_header', $f['subject_type'], 'subject_type = security_header' );
cf_eq( 'x-frame-options', $f['subject_label'], 'subject_label is the header name' );
cf_eq( 'https://juanlentino.com/', $f['subject_url'], 'subject_url is home_url' );
cf_true( false !== strpos( $f['note'], 'Cloudflare' ), 'note references the Cloudflare edge' );

// ─── Test 3: drop CSP + HSTS → 2 findings ────────────────────────────
echo "\nTest 3: missing CSP + HSTS → 2 findings\n";
cf_reset();
$missing2 = $all5;
unset( $missing2['content-security-policy'], $missing2['strict-transport-security'] );
$GLOBALS['__test_head_response'] = array( 'response' => array( 'code' => 200 ), 'headers' => $missing2 );
$check = sn_health_check_cf_security_headers();
cf_eq( 2, $check['count'], 'two missing → 2 findings' );
$labels = array_column( $check['findings'], 'subject_label' );
cf_true( in_array( 'content-security-policy', $labels, true ), 'CSP flagged' );
cf_true( in_array( 'strict-transport-security', $labels, true ), 'HSTS flagged' );

// ─── Test 4: CaseInsensitiveDictionary + mixed-case keys normalize ───
echo "\nTest 4: CaseInsensitiveDictionary mixed-case headers normalize\n";
cf_reset();
$mixed = new SN_Test_CI_Dictionary( array(
	'Content-Security-Policy'   => "default-src 'self'",
	'Strict-Transport-Security' => 'max-age=31536000',
	'X-Content-Type-Options'    => 'nosniff',
	'Referrer-Policy'           => 'strict-origin-when-cross-origin',
	// X-Frame-Options intentionally ABSENT.
) );
$GLOBALS['__test_head_response'] = array( 'response' => array( 'code' => 200 ), 'headers' => $mixed );
$check = sn_health_check_cf_security_headers();
cf_eq( 1, $check['count'], 'mixed-case dict: only x-frame-options missing → 1 finding' );
cf_eq( 'x-frame-options', $check['findings'][0]['subject_label'], 'normalized lookup found the present 4 despite mixed case' );
// FALSIFICATION: assert the present-set actually contains a real header name —
// the old (array)-cast impl mangled the protected $data to "\0*\0data" and saw
// ZERO real headers, so it would flag CSP (and all 5) as missing here.
$mixed_labels = array_column( $check['findings'], 'subject_label' );
cf_true( ! in_array( 'content-security-policy', $mixed_labels, true ), 'CSP recognized as PRESENT from the protected-data dict (would FAIL against the (array)-cast impl)' );
cf_true( ! in_array( 'referrer-policy', $mixed_labels, true ), 'referrer-policy recognized as present from the protected-data dict' );

// ─── Test 5: cache hit short-circuits wp_remote_head ─────────────────
echo "\nTest 5: cache hit → wp_remote_head NOT called\n";
cf_reset();
// Prime the transient with a known missing-array.
$GLOBALS['__test_transients']['sn_health_cf_headers_probe'] = array( 'x-frame-options' );
$check = sn_health_check_cf_security_headers();
cf_eq( 0, $GLOBALS['__test_head_calls'], 'cached: wp_remote_head NOT called' );
cf_eq( 1, $check['count'], 'cached missing-array yields 1 finding' );
cf_eq( 'x-frame-options', $check['findings'][0]['subject_label'], 'cached label surfaces' );

// ─── Test 6: WP_Error → 0 findings AND transient NOT written ─────────
echo "\nTest 6: WP_Error probe → 0 findings, transient NOT cached (self-heals)\n";
cf_reset();
$GLOBALS['__test_head_response'] = new WP_Error( 'http_request_failed', 'connection refused' );
$check = sn_health_check_cf_security_headers();
cf_eq( 0, $check['count'], 'WP_Error → 0 findings' );
cf_eq( 1, $GLOBALS['__test_head_calls'], 'probe attempted once' );
cf_true( ! isset( $GLOBALS['__test_transients']['sn_health_cf_headers_probe'] ), 'transient NOT written on WP_Error (self-heals next scan)' );

// ─── Test 7: edge bypass (none present, no cf-ray/server) → advisory ──
echo "\nTest 7: probe hit origin directly (no headers, no cf-ray) → 0 findings + advisory, NOT cached\n";
cf_reset();
$GLOBALS['__test_head_response'] = array(
	'response' => array( 'code' => 200 ),
	'headers'  => array( 'content-type' => 'text/html', 'x-powered-by' => 'PHP' ), // none of the 5, no cf-ray, no server:cloudflare
);
$check = sn_health_check_cf_security_headers();
cf_eq( 0, $check['count'], 'edge bypass → 0 findings (no false positives)' );
cf_true( false !== stripos( (string) $check['skipped'], 'origin directly' ), 'advisory note mentions hitting the origin directly' );
cf_true( is_string( $check['skipped'] ) && '' !== $check['skipped'], 'and it reports as SKIPPED — a probe that could not confirm is not a pass' );
cf_true( ! isset( $GLOBALS['__test_transients']['sn_health_cf_headers_probe'] ), 'degenerate result NOT cached (re-attempts next scan)' );

// ─── Test 8: confirmed edge (cf-ray present, 4/5) → edge path still works
echo "\nTest 8: confirmed edge (cf-ray present) with 1 missing → 1 finding (edge path intact)\n";
cf_reset();
$edge4 = $all5; unset( $edge4['x-frame-options'] );
$edge4['cf-ray'] = '8a1b2c3d4e5f-EWR';
$GLOBALS['__test_head_response'] = array( 'response' => array( 'code' => 200 ), 'headers' => $edge4 );
$check = sn_health_check_cf_security_headers();
cf_eq( 1, $check['count'], 'confirmed edge, 1 missing → 1 finding' );
cf_eq( 'x-frame-options', $check['findings'][0]['subject_label'], 'edge finding label correct' );
cf_true( isset( $GLOBALS['__test_transients']['sn_health_cf_headers_probe'] ), 'edge result IS cached' );

// ─── Test 9: server:cloudflare (no cf-ray) but all 5 absent → edge confirmed, 5 findings
echo "\nTest 9: server:cloudflare edge, all 5 absent → 5 findings (NOT suppressed)\n";
cf_reset();
$GLOBALS['__test_head_response'] = array(
	'response' => array( 'code' => 200 ),
	'headers'  => array( 'server' => 'cloudflare', 'content-type' => 'text/html' ),
);
$check = sn_health_check_cf_security_headers();
cf_eq( 5, $check['count'], 'edge confirmed via server:cloudflare → all 5 genuinely-missing flagged' );

// ─── Tests 10-16: the WAF witness (enforcement audit 2026-09-11, Phase 1) ──
// The rule "Block Basic-auth on abilities API" is the edge layer in front of
// the abilities run route. It lives in the dashboard. Since 2026-09-12 the
// plugin no longer probes it from the origin (the origin's own requests are
// not judged by it — measured from an external host that day); it reads a
// Better Stack monitor that sends the Authorization header from OUTSIDE and
// expects the edge's 403. These fixtures are that monitor list.
$GLOBALS['__all5_edge'] = $all5 + array( 'cf-ray' => '8a1b2c3d4e5f-EWR' );
function waf_reset() {
	cf_reset();
	$GLOBALS['__test_get_calls']   = 0;
	$GLOBALS['__test_get_headers'] = array();
	$GLOBALS['__test_get_urls']    = array();
	$GLOBALS['__test_bs_token']    = 'bs-test-token';
	// Edge confirmed for the header half, all 5 present, so any finding below is the witness's.
	$GLOBALS['__test_head_response'] = array( 'response' => array( 'code' => 200 ), 'headers' => $GLOBALS['__all5_edge'] );
}
// A Better Stack v2/monitors payload. Each entry: [url, status, overrides].
function bs_monitors( array $rows ) {
	$data = array();
	foreach ( $rows as $i => $row ) {
		list( $url, $status ) = $row;
		$attrs = array_merge( array(
			'url'                   => $url,
			'pronounceable_name'    => 'monitor ' . $i,
			'status'                => $status,
			'monitor_type'          => 'expected_status_code',
			'expected_status_codes' => array( 403 ),
			'request_headers'       => array( array( 'id' => '1', 'name' => 'Authorization', 'value' => 'Basic eA==' ) ),
		), $row[2] ?? array() );
		$data[] = array( 'id' => (string) ( 100 + $i ), 'type' => 'monitor', 'attributes' => $attrs );
	}
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => json_encode( array( 'data' => $data ) ) );
}
$abilities_url  = 'https://juanlentino.com/wp-json/wp-abilities/v1/abilities';
$rest_route_url = 'https://juanlentino.com/?rest_route=/wp-abilities/v1/abilities';
$site_monitor   = array( 'https://juanlentino.com/', 'up', array( 'monitor_type' => 'status', 'expected_status_codes' => array(), 'request_headers' => array() ) );

echo "\nTest 10: a witness monitor is up → the rule refused the outside request, 0 findings, cached\n";
waf_reset();
$GLOBALS['__test_get_response'] = bs_monitors( array( $site_monitor, array( $abilities_url, 'up' ) ) );
$check = sn_health_check_cf_security_headers();
cf_eq( 0, $check['count'], 'witness up → the rule is in force, no finding' );
cf_eq( null, $check['skipped'], 'and the check RAN (not skipped)' );
cf_eq( 1, $GLOBALS['__test_get_calls'], 'one GET: the Better Stack monitor list' );
cf_true( 0 === strpos( $GLOBALS['__test_get_urls'][0], 'https://uptime.betterstack.com/api/v2/monitors' ), 'the GET went to Better Stack, not to the abilities route' );
cf_true( 0 === strpos( (string) ( $GLOBALS['__test_get_headers']['Authorization'] ?? '' ), 'Bearer ' ), 'authenticated with the Better Stack bearer token' );
cf_eq( 'blocked', $GLOBALS['__test_transients']['sn_health_cf_waf_abilities_probe'] ?? null, 'verdict cached as blocked' );

echo "\nTest 11: the witness is down → the edge did NOT refuse an outside Authorization-bearing request → 1 finding\n";
waf_reset();
$GLOBALS['__test_get_response'] = bs_monitors( array( $site_monitor, array( $abilities_url, 'down' ) ) );
$check = sn_health_check_cf_security_headers();
cf_eq( 1, $check['count'], 'witness down → the WAF rule is not in force → 1 finding' );
cf_eq( 'waf: Block Basic-auth on abilities API', $check['findings'][0]['subject_label'], 'finding names the rule' );
cf_eq( 'https://juanlentino.com/wp-json/wp-abilities/v1/abilities', $check['findings'][0]['subject_url'], 'finding points at the abilities route' );
cf_true( false !== strpos( $check['findings'][0]['note'], 'sn_mcp_rw_guard_run_route' ), 'note says the in-plugin guard still holds' );
cf_true( false !== strpos( $check['findings'][0]['note'], 'Better Stack' ), 'note names the vantage that measured it' );
cf_eq( 'open', $GLOBALS['__test_transients']['sn_health_cf_waf_abilities_probe'] ?? null, 'verdict cached as open' );

echo "\nTest 12: a monitor on the URL that could not fail the rule is NOT a witness (negative control)\n";
// A plain status monitor with no Authorization header reads up whether or
// not the rule exists — WordPress answers 401, the rule never engages. The
// check must refuse that green rather than cache it.
waf_reset();
$GLOBALS['__test_get_response'] = bs_monitors( array( $site_monitor, array( $abilities_url, 'up', array( 'request_headers' => array() ) ) ) );
$check = sn_health_check_cf_security_headers();
cf_eq( 0, $check['count'], 'no Authorization header on the monitor → nothing measured, no finding' );
cf_true( false !== stripos( (string) $check['skipped'], 'not configured' ), 'skipped reason says the monitor is not configured as a witness' );
cf_true( ! isset( $GLOBALS['__test_transients']['sn_health_cf_waf_abilities_probe'] ), 'and the non-verdict is NOT cached' );
waf_reset();
$GLOBALS['__test_get_response'] = bs_monitors( array( $site_monitor, array( $abilities_url, 'up', array( 'expected_status_codes' => array( 401 ) ) ) ) );
$check = sn_health_check_cf_security_headers();
cf_true( is_string( $check['skipped'] ) && ! isset( $GLOBALS['__test_transients']['sn_health_cf_waf_abilities_probe'] ), 'expecting 401 instead of 403 is not a witness either: skipped, not cached' );
waf_reset();
$GLOBALS['__test_get_response'] = bs_monitors( array( $site_monitor, array( $abilities_url, 'up', array( 'monitor_type' => 'status' ) ) ) );
$check = sn_health_check_cf_security_headers();
cf_true( is_string( $check['skipped'] ) && ! isset( $GLOBALS['__test_transients']['sn_health_cf_waf_abilities_probe'] ), 'a plain status monitor (403 = down) is not a witness: skipped, not cached' );

echo "\nTest 13: nothing to read → unknown: 0 findings, reported as SKIPPED, never cached\n";
waf_reset();
$GLOBALS['__test_bs_token'] = '';
$GLOBALS['__test_get_response'] = bs_monitors( array( $site_monitor, array( $abilities_url, 'up' ) ) );
$check = sn_health_check_cf_security_headers();
cf_eq( 0, $check['count'], 'no Better Stack token → 0 findings' );
cf_eq( 0, $GLOBALS['__test_get_calls'], 'and no request at all' );
cf_true( false !== stripos( (string) $check['skipped'], 'token' ), 'skipped reason: no token' );
cf_true( ! isset( $GLOBALS['__test_transients']['sn_health_cf_waf_abilities_probe'] ), 'unknown is NOT cached' );
waf_reset();
$GLOBALS['__test_get_response'] = new WP_Error( 'http_request_failed', 'timeout' );
$check = sn_health_check_cf_security_headers();
cf_true( is_string( $check['skipped'] ) && false !== stripos( $check['skipped'], 'Better Stack' ), 'API unreachable → skipped, reason names Better Stack' );
cf_true( ! isset( $GLOBALS['__test_transients']['sn_health_cf_waf_abilities_probe'] ), 'and NOT cached' );
waf_reset();
$GLOBALS['__test_get_response'] = bs_monitors( array( $site_monitor ) );
$check = sn_health_check_cf_security_headers();
cf_eq( 0, $check['count'], 'no monitor on the abilities URL → 0 findings' );
cf_true( false !== strpos( (string) $check['skipped'], '/wp-abilities/v1/abilities' ), 'skipped reason tells the operator which URL to monitor' );
cf_true( false !== strpos( (string) $check['skipped'], '403' ), 'and what it must expect' );
cf_true( ! isset( $GLOBALS['__test_transients']['sn_health_cf_waf_abilities_probe'] ), 'NOT cached' );
waf_reset();
$GLOBALS['__test_get_response'] = bs_monitors( array( $site_monitor, array( $abilities_url, 'pending' ) ) );
$check = sn_health_check_cf_security_headers();
cf_true( is_string( $check['skipped'] ) && false !== strpos( $check['skipped'], 'pending' ), 'a pending witness has not measured yet → skipped, names the status' );
cf_true( ! isset( $GLOBALS['__test_transients']['sn_health_cf_waf_abilities_probe'] ), 'NOT cached' );

echo "\nTest 14: a cached verdict short-circuits the read\n";
waf_reset();
$GLOBALS['__test_transients']['sn_health_cf_waf_abilities_probe'] = 'open';
$GLOBALS['__test_get_response'] = bs_monitors( array( $site_monitor, array( $abilities_url, 'up' ) ) );
$check = sn_health_check_cf_security_headers();
cf_eq( 0, $GLOBALS['__test_get_calls'], 'cached: Better Stack NOT called' );
cf_eq( 1, $check['count'], 'the cached open verdict still surfaces as a finding' );

echo "\nTest 15: the site itself is down → a down witness is uninformative (a timeout also reads down)\n";
waf_reset();
$GLOBALS['__test_get_response'] = bs_monitors( array( array( 'https://juanlentino.com/', 'down', array( 'monitor_type' => 'status', 'expected_status_codes' => array(), 'request_headers' => array() ) ), array( $abilities_url, 'down' ) ) );
$check = sn_health_check_cf_security_headers();
cf_eq( 0, $check['count'], 'site down + witness down → NOT read as the rule being gone' );
cf_true( is_string( $check['skipped'] ) && false !== stripos( $check['skipped'], 'down' ), 'skipped reason says the site is down' );
cf_true( ! isset( $GLOBALS['__test_transients']['sn_health_cf_waf_abilities_probe'] ), 'NOT cached' );

echo "\nTest 16: two witnesses (both URL spellings) — one down outvotes one up; a foreign host is ignored\n";
waf_reset();
$GLOBALS['__test_get_response'] = bs_monitors( array(
	$site_monitor,
	array( $abilities_url, 'up' ),
	array( $rest_route_url, 'down' ),
	array( 'https://other.example/wp-json/wp-abilities/v1/abilities', 'down' ), // another site's witness: not ours
) );
$check = sn_health_check_cf_security_headers();
cf_eq( 1, $check['count'], 'the ?rest_route= witness down → open (a rule on uri.path would produce exactly this)' );
waf_reset();
$GLOBALS['__test_get_response'] = bs_monitors( array( $site_monitor, array( $abilities_url, 'up' ), array( 'https://other.example/wp-json/wp-abilities/v1/abilities', 'down' ) ) );
$check = sn_health_check_cf_security_headers();
cf_eq( 0, $check['count'], 'a foreign host\'s witness does not count against this site' );
cf_eq( 'blocked', $GLOBALS['__test_transients']['sn_health_cf_waf_abilities_probe'] ?? null, 'our witness up → blocked' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
