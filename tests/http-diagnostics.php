<?php
/**
 * Admin-request HTTP-call diagnosis — fixture tests.
 *
 * Covers inc/http-diagnostics.php:
 *   - sn_httpdiag_sanitize_url(): scheme+host+path ONLY — query/fragment/
 *     userinfo are UNSTORABLE (a hostile URL carrying a secret in its query
 *     string must never reach the returned string).
 *   - sn_httpdiag_capture(): duration math off the http_request_args
 *     stamp, unstamped calls skipped outright.
 *   - sn_httpdiag_record(): ring-buffer cap at 50 entries (oldest dropped),
 *     per-entry http-list cap at 20 calls, option written autoload=false.
 *   - sn_httpdiag_screen_label(): $pagenow + a WHITELISTED query-key set —
 *     a hostile key/value never survives into the stored label.
 *   - sn_httpdiag_shutdown(): the wall-clock/HTTP-buffer threshold gate.
 *   - sn_httpdiag_debug_information(): the Site Health `snt_httpdiag`
 *     panel — slowest-first ordering, the empty state, the 10-entry cap.
 *   - sn_httpdiag_register_hooks(): http_request_args/http_api_debug wire
 *     up ONLY when is_admin() is true at registration time; shutdown +
 *     debug_information always wire up.
 *
 * Run: php tests/http-diagnostics.php
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module — direct HTTP GET to this path would leak internal structure.
// Allow only CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

// ── WP stubs (identity-ish; enough for the wiring + Site Health paths) ──
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function __( $s, $d = '' ) { return $s; }

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function sanitize_key( $k ) { return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }

class WP_Error_Stub {
	public $code; public $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error_Stub; }
function wp_remote_retrieve_response_code( $resp ) {
	return is_array( $resp ) ? (int) ( $resp['response']['code'] ?? 0 ) : 0;
}

$GLOBALS['__test_options'] = array();
$GLOBALS['__test_option_autoload'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__test_options'][ $key ]          = $value;
	$GLOBALS['__test_option_autoload'][ $key ] = $autoload;
	return true;
}

$GLOBALS['__test_is_admin']  = true;
$GLOBALS['__test_doing_ajax'] = false;
$GLOBALS['__test_doing_cron'] = false;
function is_admin() { return ! empty( $GLOBALS['__test_is_admin'] ); }
function wp_doing_ajax() { return ! empty( $GLOBALS['__test_doing_ajax'] ); }
function wp_doing_cron() { return ! empty( $GLOBALS['__test_doing_cron'] ); }

$GLOBALS['__test_timer_stop'] = 0.0;
function timer_stop( $display = 1, $precision = 3 ) { return $GLOBALS['__test_timer_stop']; }

$GLOBALS['__test_hooks'] = array();
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__test_hooks']['action'][] = $hook; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__test_hooks']['filter'][] = $hook; }

require __DIR__ . '/../inc/http-diagnostics.php';

// ── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ═══════════════════════════════════════════════════════════════════════
echo "Group: sn_httpdiag_sanitize_url — scheme+host+path ONLY, secrets unstorable\n";
$hostile = 'https://api.example.com/sql?token=SECRET&key=x#f';
$clean   = sn_httpdiag_sanitize_url( $hostile );
ok( 'https://api.example.com/sql' === $clean, 'hostile URL reduced to scheme+host+path' );
ok( false === strpos( $clean, 'SECRET' ), 'the query-string SECRET is ABSENT from the stored value' );
ok( false === strpos( $clean, 'token' ), 'the query key name itself is also absent' );
ok( false === strpos( $clean, '#' ), 'the fragment is absent' );

$userinfo = 'https://user:pass@api.example.com/path?x=1';
$clean_ui = sn_httpdiag_sanitize_url( $userinfo );
ok( false === strpos( $clean_ui, 'user' ) && false === strpos( $clean_ui, 'pass' ), 'userinfo (user:pass@) is absent' );
ok( 'https://api.example.com/path' === $clean_ui, 'userinfo URL still reduces to scheme+host+path' );

ok( '' === sn_httpdiag_sanitize_url( 'not a url at all' ), 'unparseable URL (no host) returns empty string, never the raw input' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_httpdiag_capture — duration math + unstamped-call skip\n";
sn_httpdiag_buffer( null, true ); // reset

$t0 = microtime( true ) - 0.20; // ~200ms ago
sn_httpdiag_capture(
	array( 'response' => array( 'code' => 200 ) ),
	'response',
	'WP_Http',
	array( '_sn_httpdiag_t0' => $t0 ),
	'https://api.example.com/thing?token=abc'
);
$buf = sn_httpdiag_buffer();
ok( 1 === count( $buf ), 'stamped call is captured' );
ok( $buf[0]['ms'] >= 150 && $buf[0]['ms'] <= 500, 'captured duration is roughly the elapsed ~200ms (tolerant of CI jitter): got ' . $buf[0]['ms'] );
ok( 200 === $buf[0]['code'], 'captured code == 200' );
ok( false === $buf[0]['error'], 'captured error flag is false on a normal response' );
ok( 'https://api.example.com/thing' === $buf[0]['url'], 'captured url is already sanitized (no query string)' );

// Unstamped call — no '_sn_httpdiag_t0' key at all.
sn_httpdiag_capture( array( 'response' => array( 'code' => 500 ) ), 'response', 'WP_Http', array(), 'https://example.com/no-stamp' );
ok( 1 === count( sn_httpdiag_buffer() ), 'an unstamped call is skipped outright (buffer count unchanged)' );

// A WP_Error response: code must be 0, error flag true.
sn_httpdiag_buffer( null, true );
sn_httpdiag_capture(
	new WP_Error_Stub( 'http_request_failed', 'timed out' ),
	'response',
	'WP_Http',
	array( '_sn_httpdiag_t0' => microtime( true ) ),
	'https://api.example.com/timeout'
);
$err_buf = sn_httpdiag_buffer();
ok( 1 === count( $err_buf ), 'WP_Error response is still captured' );
ok( 0 === $err_buf[0]['code'], 'WP_Error response records code 0' );
ok( true === $err_buf[0]['error'], 'WP_Error response sets the error flag' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_httpdiag_screen_label — whitelist-only query keys\n";
$hostile_query = array(
	'page'  => 'sn-theme-options',
	'tab'   => 'health<script>',
	'evil'  => '<img src=x onerror=alert(1)>',
	'token' => 'super-secret-value',
);
$label = sn_httpdiag_screen_label( 'admin.php', $hostile_query );
// sanitize_key() strips '.' (faithful to WP core's own regex) — "admin.php"
// becomes "adminphp". Less pretty, but still unambiguous and safe.
ok( false !== strpos( $label, 'adminphp' ), 'pagenow present in the label (sanitize_key-normalized)' );
ok( false !== strpos( $label, 'page=sn-theme-options' ), 'whitelisted page key/value present' );
ok( false === strpos( $label, 'evil' ), 'a non-whitelisted key never appears in the label' );
ok( false === strpos( $label, 'token' ), 'a non-whitelisted "token" key never appears in the label' );
ok( false === strpos( $label, 'super-secret-value' ), 'a non-whitelisted VALUE never appears in the label' );
ok( false === strpos( $label, '<script>' ) && false === strpos( $label, '<img' ), 'raw hostile markup never survives sanitize_key()' );

$empty_label = sn_httpdiag_screen_label( 'index.php', array() );
ok( 'indexphp' === $empty_label, 'no query keys present -> label is just the sanitize_key()\'d pagenow' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_httpdiag_record — ring cap (50), per-entry http cap (20), autoload=false\n";
$GLOBALS['__test_options'] = array();
$GLOBALS['__test_option_autoload'] = array();

for ( $i = 1; $i <= 55; $i++ ) {
	sn_httpdiag_record( array(), 5.0, 'marker-' . $i, array() );
}
$log = get_option( 'snt_httpdiag_log' );
ok( 50 === count( $log ), 'ring buffer holds exactly 50 entries after 55 inserts' );
ok( false !== strpos( $log[0]['screen'], 'marker-55' ), 'newest entry (marker-55) is first (newest-first)' );
ok( false !== strpos( $log[49]['screen'], 'marker-6' ), 'oldest SURVIVING entry is marker-6' );
$screens = implode( '|', array_column( $log, 'screen' ) );
ok( false === strpos( $screens, 'marker-1|' ) && false === strpos( $screens, 'marker-5"' ), 'markers 1-5 were dropped as the oldest entries' );

ok( false === $GLOBALS['__test_option_autoload']['snt_httpdiag_log'], 'snt_httpdiag_log is always written with autoload=false' );

$many_calls = array();
for ( $i = 0; $i < 25; $i++ ) {
	$many_calls[] = array( 'url' => 'https://example.com/c' . $i, 'ms' => $i, 'code' => 200, 'error' => false );
}
$entry = sn_httpdiag_record( $many_calls, 3.0, 'cap-test', array() );
ok( 20 === count( $entry['http'] ), 'a single entry\'s http list is hard-capped at 20 even when 25 calls were captured' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_httpdiag_shutdown — the wall-clock / HTTP-buffer threshold gate\n";
$GLOBALS['__test_is_admin']   = true;
$GLOBALS['__test_doing_ajax'] = false;
$GLOBALS['__test_doing_cron'] = false;

ok( null === sn_httpdiag_shutdown( array(), 1.0 ), 'no HTTP calls + fast page (1.0s < 2.0s threshold) -> not logged' );
$slow_result = sn_httpdiag_shutdown( array(), 2.5 );
ok( null !== $slow_result, 'no HTTP calls but a SLOW page (2.5s > 2.0s threshold) -> logged' );

$fast_but_http = sn_httpdiag_shutdown( array( array( 'url' => 'https://x.test/y', 'ms' => 10, 'code' => 200, 'error' => false ) ), 0.1 );
ok( null !== $fast_but_http, 'a fast page THAT STILL made an HTTP call -> logged regardless of wall time' );

$GLOBALS['__test_is_admin'] = false;
ok( null === sn_httpdiag_shutdown( array( array( 'url' => 'x', 'ms' => 1, 'code' => 200, 'error' => false ) ), 9.9 ), 'is_admin() === false blocks the write even when everything else qualifies' );
$GLOBALS['__test_is_admin'] = true;

$GLOBALS['__test_doing_ajax'] = true;
ok( null === sn_httpdiag_shutdown( array(), 9.9 ), 'wp_doing_ajax() === true blocks the write' );
$GLOBALS['__test_doing_ajax'] = false;

$GLOBALS['__test_doing_cron'] = true;
ok( null === sn_httpdiag_shutdown( array(), 9.9 ), 'wp_doing_cron() === true blocks the write' );
$GLOBALS['__test_doing_cron'] = false;

define( 'REST_REQUEST', true );
ok( null === sn_httpdiag_shutdown( array(), 9.9 ), 'REST_REQUEST === true blocks the write' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_httpdiag_debug_information — the Site Health snt_httpdiag panel\n";
$fake_log = array(
	array(
		't' => 1, 'screen' => 'admin.php?page=mid', 'wall_s' => 3.20,
		'http' => array( array( 'url' => 'https://mid.example.com/a', 'ms' => 320, 'code' => 200, 'error' => false ) ),
	),
	array(
		't' => 2, 'screen' => 'index.php?page=slowest', 'wall_s' => 9.90,
		'http' => array(
			array( 'url' => 'https://slow.example.com/b', 'ms' => 9800, 'code' => 500, 'error' => false ),
			array( 'url' => 'https://slow.example.com/c', 'ms' => 50, 'code' => 200, 'error' => false ),
		),
	),
	array(
		't' => 3, 'screen' => 'admin.php?page=fastest', 'wall_s' => 1.50,
		'http' => array( array( 'url' => 'https://fast.example.com/d', 'ms' => 40, 'code' => 200, 'error' => false ) ),
	),
);

$info  = sn_httpdiag_debug_information( array( 'wp-core' => array( 'label' => 'WP' ) ), $fake_log );
ok( isset( $info['wp-core'] ), 'preserves incoming panels' );
ok( isset( $info['snt_httpdiag'] ), "adds the 'snt_httpdiag' section" );
$panel = $info['snt_httpdiag'];
ok( false !== strpos( $panel['label'], 'admin HTTP diagnosis' ), 'panel label names the diagnosis' );
ok( isset( $panel['fields']['slow_0'], $panel['fields']['slow_1'], $panel['fields']['slow_2'] ), 'three page-load fields present' );

ok( false !== strpos( $panel['fields']['slow_0']['label'], 'slowest' ) || false !== strpos( $panel['fields']['slow_0']['label'], '9.90s' ), 'field 0 is the 9.90s (slowest) entry' );
ok( false !== strpos( $panel['fields']['slow_1']['label'], '3.20s' ), 'field 1 is the 3.20s (middle) entry' );
ok( false !== strpos( $panel['fields']['slow_2']['label'], '1.50s' ), 'field 2 is the 1.50s (fastest-of-the-three) entry — SLOWEST-FIRST ordering' );
ok( false !== strpos( $panel['fields']['slow_0']['value'], 'slow.example.com/b' ) && false !== strpos( $panel['fields']['slow_0']['value'], '9800ms' ), 'field 0 value lists its call as "host/path — Nms (code)"' );

ok( false !== strpos( $panel['fields']['summary']['value'], '3' ), 'summary reports 3 total logged requests' );
ok( false !== strpos( $panel['fields']['summary']['value'], 'slow.example.com/b' ), 'summary names the single slowest CALL (9800ms) across the whole log' );

// The empty state.
$empty_info  = sn_httpdiag_debug_information( array(), array() );
$empty_panel = $empty_info['snt_httpdiag'];
ok( 1 === count( $empty_panel['fields'] ), 'empty log -> exactly one field' );
$empty_values = implode( ' ', array_map( function ( $f ) { return (string) ( $f['value'] ?? '' ); }, $empty_panel['fields'] ) );
ok( false !== strpos( $empty_values, 'No slow admin requests logged yet.' ), 'empty log renders the "no slow admin requests" message' );

// The 10-slowest cap: build 12 entries, verify only 10 page-load fields land.
$twelve = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$twelve[] = array( 't' => $i, 'screen' => 'page-' . $i, 'wall_s' => (float) $i, 'http' => array() );
}
$twelve_info  = sn_httpdiag_debug_information( array(), $twelve );
$twelve_panel = $twelve_info['snt_httpdiag'];
ok( isset( $twelve_panel['fields']['slow_9'] ), 'field slow_9 (the 10th) is present' );
ok( ! isset( $twelve_panel['fields']['slow_10'] ), 'field slow_10 (an 11th) is NOT present — capped at 10 slowest' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_httpdiag_register_hooks — admin-only wiring\n";

$GLOBALS['__test_hooks']    = array();
$GLOBALS['__test_is_admin'] = false;
sn_httpdiag_register_hooks();
ok( ! in_array( 'http_request_args', $GLOBALS['__test_hooks']['filter'] ?? array(), true ), 'is_admin() === false: http_request_args NOT registered' );
ok( ! in_array( 'http_api_debug', $GLOBALS['__test_hooks']['action'] ?? array(), true ), 'is_admin() === false: http_api_debug NOT registered' );
ok( in_array( 'shutdown', $GLOBALS['__test_hooks']['action'] ?? array(), true ), 'is_admin() === false: shutdown STILL registered' );
ok( in_array( 'debug_information', $GLOBALS['__test_hooks']['filter'] ?? array(), true ), 'is_admin() === false: debug_information STILL registered' );

$GLOBALS['__test_hooks']    = array();
$GLOBALS['__test_is_admin'] = true;
sn_httpdiag_register_hooks();
ok( in_array( 'http_request_args', $GLOBALS['__test_hooks']['filter'] ?? array(), true ), 'is_admin() === true: http_request_args registered' );
ok( in_array( 'http_api_debug', $GLOBALS['__test_hooks']['action'] ?? array(), true ), 'is_admin() === true: http_api_debug registered' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
