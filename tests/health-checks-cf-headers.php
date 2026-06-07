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
function wp_remote_get( $url, $args = array() ) { return array( 'response' => array( 'code' => 200 ) ); }

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
cf_true( false !== stripos( $check['fix_hint'], 'origin directly' ), 'advisory note mentions hitting the origin directly' );
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
