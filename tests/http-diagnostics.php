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
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }

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
// WP-faithful: real timer_stop() returns a number_format()'d STRING, not a
// float — stub fidelity on core return types (the v9.46.2 incident class).
function timer_stop( $display = 1, $precision = 3 ) { return number_format( (float) $GLOBALS['__test_timer_stop'], $precision, '.', '' ); }

$GLOBALS['__test_hooks'] = array();
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__test_hooks']['action'][] = $hook; $GLOBALS['__test_hooks']['action_args'][ $hook ] = $args; }
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
echo "\nGroup: sn_httpdiag_format_age — pure formatter, no WP calls (B1)\n";
// Fixed baseline far from zero so "$t ago" subtraction never goes negative
// for the large-diff cases below — the function itself is pure (no time()
// call), so this is just deterministic test arithmetic, not a stub.
$AGE_NOW = 10000000;

ok( '' === sn_httpdiag_format_age( 'nope', $AGE_NOW ), 'non-numeric $t -> empty string' );
ok( '' === sn_httpdiag_format_age( 0, $AGE_NOW ), '$t == 0 -> empty string' );
ok( '' === sn_httpdiag_format_age( -5, $AGE_NOW ), '$t < 0 -> empty string' );
ok( '' === sn_httpdiag_format_age( $AGE_NOW + 1, $AGE_NOW ), '$t in the future relative to $now -> empty string' );
ok( 'just now' === sn_httpdiag_format_age( $AGE_NOW - 59, $AGE_NOW ), '59s ago -> "just now"' );
ok( '1m ago' === sn_httpdiag_format_age( $AGE_NOW - 60, $AGE_NOW ), 'exactly 60s ago -> "1m ago"' );
ok( '59m ago' === sn_httpdiag_format_age( $AGE_NOW - 3599, $AGE_NOW ), '3599s ago -> "59m ago" (floor, not round)' );
ok( '1h ago' === sn_httpdiag_format_age( $AGE_NOW - 3600, $AGE_NOW ), 'exactly 3600s ago -> "1h ago"' );
ok( '23h ago' === sn_httpdiag_format_age( $AGE_NOW - 86399, $AGE_NOW ), '86399s ago -> "23h ago" (floor, not round)' );
ok( '1d ago' === sn_httpdiag_format_age( $AGE_NOW - 86400, $AGE_NOW ), 'exactly 86400s ago -> "1d ago"' );
ok( '2d ago' === sn_httpdiag_format_age( $AGE_NOW - 200000, $AGE_NOW ), 'floor division: 200000s ago -> "2d ago", not "2.3d ago"' );

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
echo "\nGroup: sn_httpdiag_record — write-time retention prune (B3)\n";
$GLOBALS['__test_options']         = array();
$GLOBALS['__test_option_autoload'] = array();

$real_now = time();
$seed_log = array(
	// Older than the 30-day retention window (relative to the entry
	// sn_httpdiag_record() is about to write) -> must be dropped.
	array( 't' => $real_now - ( 40 * 86400 ), 'screen' => 'old-40d', 'wall_s' => 1.0, 'http' => array() ),
	// Within the window -> must survive.
	array( 't' => $real_now - ( 10 * 86400 ), 'screen' => 'recent-10d', 'wall_s' => 1.0, 'http' => array() ),
	// No 't' at all -> an unknown age is never treated as stale, kept unconditionally.
	array( 'screen' => 'no-t-at-all', 'wall_s' => 1.0, 'http' => array() ),
);
update_option( 'snt_httpdiag_log', $seed_log, false );

sn_httpdiag_record( array(), 3.0, 'new-entry', array() );
$pruned_log = get_option( 'snt_httpdiag_log' );
$screens    = array_column( $pruned_log, 'screen' );

ok( ! in_array( 'old-40d', $screens, true ), 'an entry older than the 30-day retention window is dropped at write time' );
ok( in_array( 'recent-10d', $screens, true ), 'an entry within the retention window survives the write-time prune' );
ok( in_array( 'no-t-at-all', $screens, true ), 'an entry with no usable t is kept -- unknown age is never treated as stale' );
ok( 3 === count( $pruned_log ), 'ring holds the new entry plus the two surviving seeded ones (old-40d dropped)' );

// Prune + ring cap interact: pruning stale entries must never let the ring
// exceed RING_MAX (the slice runs AFTER the filter — pin that ordering).
$GLOBALS['__test_options']         = array();
$GLOBALS['__test_option_autoload'] = array();
$overflow_seed = array(
	array( 't' => $real_now - ( 40 * 86400 ), 'screen' => 'stale-overflow', 'wall_s' => 1.0, 'http' => array() ),
);
for ( $i = 1; $i <= 54; $i++ ) {
	$overflow_seed[] = array( 't' => $real_now - $i, 'screen' => 'fresh-' . $i, 'wall_s' => 1.0, 'http' => array() );
}
update_option( 'snt_httpdiag_log', $overflow_seed, false );

sn_httpdiag_record( array(), 3.0, 'overflow-new', array() );
$overflow_log     = get_option( 'snt_httpdiag_log' );
$overflow_screens = array_column( $overflow_log, 'screen' );

ok( 50 === count( $overflow_log ), 'ring cap still holds at 50 when the prune also fires on the same write' );
ok( ! in_array( 'stale-overflow', $overflow_screens, true ), 'the stale entry is pruned, not merely pushed past the cap' );
ok( false !== strpos( $overflow_log[0]['screen'], 'overflow-new' ), 'the just-written entry is first after a combined prune+cap write' );

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

echo "\nGroup: the v9.46.2 incident — WP's argless-do_action empty-string filler must never fatal\n";
// do_action('shutdown') fires with no args, and WP hands accepted_args>=1
// callbacks '' as their first param — the exact production shape that
// fataled record()'s array type on every slow no-HTTP page (10.11s admin).
$GLOBALS['__test_is_admin'] = true; $GLOBALS['__test_doing_ajax'] = false; $GLOBALS['__test_doing_cron'] = false;
$GLOBALS['__test_timer_stop'] = 10.11;
$incident = sn_httpdiag_shutdown( '' );
ok( is_array( $incident ), 'the hook-filler empty string falls back to the real buffer — entry logged, NO fatal' );
ok( 10.11 === ( $incident['wall_s'] ?? 0.0 ), 'wall_s falls back to (float) timer_stop(0) — survives the STRING core return type' );
$incident2 = sn_httpdiag_shutdown( '', '' );
ok( is_array( $incident2 ) && 10.11 === ( $incident2['wall_s'] ?? 0.0 ), 'both filler args non-fatal (wall falls back past a non-numeric)' );
$GLOBALS['__test_timer_stop'] = 0.0;
$GLOBALS['__test_hooks'] = array();
sn_httpdiag_register_hooks();
ok( 0 === ( $GLOBALS['__test_hooks']['action_args']['shutdown'] ?? -1 ), 'shutdown registers with accepted_args 0 — WP passes the callback nothing at all' );
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

// $now_override pinned near the fixture's own tiny `t` markers (1/2/3) so
// this pre-existing fixture stays inside the retention window regardless
// of the real wall-clock time the suite happens to run at.
$info  = sn_httpdiag_debug_information( array( 'wp-core' => array( 'label' => 'WP' ) ), $fake_log, 100 );
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
$twelve_info  = sn_httpdiag_debug_information( array(), $twelve, 100 );
$twelve_panel = $twelve_info['snt_httpdiag'];
ok( isset( $twelve_panel['fields']['slow_9'] ), 'field slow_9 (the 10th) is present' );
ok( ! isset( $twelve_panel['fields']['slow_10'] ), 'field slow_10 (an 11th) is NOT present — capped at 10 slowest' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_httpdiag_debug_information — row label carries the age (B2)\n";
$AGE_NOW2 = 10000000;
$age_log  = array(
	array( 't' => $AGE_NOW2 - 120, 'screen' => 'admin.php?page=has-age', 'wall_s' => 2.00, 'http' => array() ),
	array( 'screen' => 'admin.php?page=no-t', 'wall_s' => 1.00, 'http' => array() ), // no 't' key at all.
);
$age_info  = sn_httpdiag_debug_information( array(), $age_log, $AGE_NOW2 );
$age_panel = $age_info['snt_httpdiag'];

ok( false !== strpos( $age_panel['fields']['slow_0']['label'], '2m ago' ), 'entry with a usable t -> label carries the formatted age' );
ok( 2 === substr_count( $age_panel['fields']['slow_0']['label'], '—' ), 'three-part label ("screen — Xs — age") has exactly two separators' );
ok( 1 === substr_count( $age_panel['fields']['slow_1']['label'], '—' ), 'entry with no usable t keeps the two-part label' );
ok( '—' !== substr( rtrim( $age_panel['fields']['slow_1']['label'] ), -1 ), 'the two-part label never ends on a dangling separator' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_httpdiag_debug_information — render-time retention hide (B3)\n";
$AGE_NOW3  = 10000000;
$mixed_log = array(
	array(
		't' => $AGE_NOW3 - ( 40 * 86400 ), 'screen' => 'stale-page', 'wall_s' => 99.0,
		'http' => array( array( 'url' => 'https://stale.example.com/x', 'ms' => 99999, 'code' => 200, 'error' => false ) ),
	),
	array(
		't' => $AGE_NOW3 - 100, 'screen' => 'fresh-page', 'wall_s' => 5.0,
		'http' => array( array( 'url' => 'https://fresh.example.com/y', 'ms' => 400, 'code' => 200, 'error' => false ) ),
	),
);
$mixed_info  = sn_httpdiag_debug_information( array(), $mixed_log, $AGE_NOW3 );
$mixed_panel = $mixed_info['snt_httpdiag'];

ok( isset( $mixed_panel['fields']['slow_0'] ) && ! isset( $mixed_panel['fields']['slow_1'] ), 'a stale entry is excluded from the rows -- only the fresh one renders' );
ok( false !== strpos( $mixed_panel['fields']['slow_0']['label'], 'fresh-page' ), 'the surviving row is the fresh entry, not the stale one' );
ok( false !== strpos( $mixed_panel['fields']['summary']['value'], '1 logged request' ), 'summary count reflects the FILTERED set (1), not the raw log (2)' );
ok( false !== strpos( $mixed_panel['fields']['summary']['value'], 'fresh.example.com/y' ), 'slowest-call in the summary is computed on the FILTERED set (the stale 99999ms call must never win)' );
ok( false === strpos( $mixed_panel['fields']['summary']['value'], 'stale.example.com' ), 'the stale entry\'s call never appears anywhere in the summary' );
ok( false !== strpos( $mixed_panel['fields']['summary']['value'], '1 older entry hidden' ), 'summary appends the count-aware "older entries hidden" suffix (singular)' );

// Plural form: a second stale entry pushes the hidden count to 2.
$mixed_log2 = array_merge(
	$mixed_log,
	array( array( 't' => $AGE_NOW3 - ( 35 * 86400 ), 'screen' => 'stale-page-2', 'wall_s' => 50.0, 'http' => array() ) )
);
$mixed_info2 = sn_httpdiag_debug_information( array(), $mixed_log2, $AGE_NOW3 );
ok( false !== strpos( $mixed_info2['snt_httpdiag']['fields']['summary']['value'], '2 older entries hidden' ), 'plural suffix when more than one stale entry is hidden' );

// Every entry stale -> falls back to the existing empty-state message.
$all_stale = array(
	array( 't' => $AGE_NOW3 - ( 31 * 86400 ), 'screen' => 'gone-1', 'wall_s' => 9.0, 'http' => array() ),
	array( 't' => $AGE_NOW3 - ( 60 * 86400 ), 'screen' => 'gone-2', 'wall_s' => 9.0, 'http' => array() ),
);
$all_stale_info  = sn_httpdiag_debug_information( array(), $all_stale, $AGE_NOW3 );
$all_stale_panel = $all_stale_info['snt_httpdiag'];
ok( 1 === count( $all_stale_panel['fields'] ), 'log entirely filtered out by retention -> exactly one field (the empty state)' );
$all_stale_values = implode( ' ', array_map( function ( $f ) { return (string) ( $f['value'] ?? '' ); }, $all_stale_panel['fields'] ) );
ok( false !== strpos( $all_stale_values, 'No slow admin requests logged yet.' ), 'log entirely filtered out by retention -> falls back to the existing empty-state message' );

// Boundary: exactly at the retention edge is kept; one second past it is dropped.
$edge_log = array(
	array( 't' => $AGE_NOW3 - SN_HTTPDIAG_RETENTION_S, 'screen' => 'edge-kept', 'wall_s' => 2.0, 'http' => array() ),
	array( 't' => $AGE_NOW3 - SN_HTTPDIAG_RETENTION_S - 1, 'screen' => 'edge-dropped', 'wall_s' => 2.0, 'http' => array() ),
);
$edge_info  = sn_httpdiag_debug_information( array(), $edge_log, $AGE_NOW3 );
$edge_panel = $edge_info['snt_httpdiag'];
ok( isset( $edge_panel['fields']['slow_0'] ) && ! isset( $edge_panel['fields']['slow_1'] ), 'exactly-at-the-retention-window entry is kept, one second past it is hidden' );

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
