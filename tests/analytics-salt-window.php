<?php
/**
 * Standalone fixture tests for inc/analytics-salt-window.php (v9.71.0).
 *
 * The identity-salt window readout (Monitoring → Analytics, reference column):
 * a PASSIVE date-window readout of the Worker's daily-rotating visitor-identity
 * salt, read from the SAME /_sn/version endpoint the worker-version card
 * probes. Worker v1.14.0+ adds a top-level public "salt" object:
 *   { rotate_tz, today_day, today_present, today_expires_at, prev_day,
 *     prev_present, prev_expires_at, next_present, key_count }
 * Salt VALUES never appear anywhere — key names are dates, expirations are
 * unix seconds (the transparency artifact). On a KV list failure the whole
 * "salt" member is null (never a fabricated shape).
 *
 * Contract under test:
 *   - parse: absent-vs-null discipline via array_key_exists — a MISSING "salt"
 *     member (old worker) and "salt": null (KV failure) are DIFFERENT answers;
 *     within the window, absent fields degrade to null, never to invented values.
 *   - probe: the shared outbound gate (https-only + wp_http_validate_url +
 *     sn_ssrf_host_blocked + redirection=0); WP_Error → unreachable.
 *   - get: ~10-min transient cache — a warm cache serves with ZERO new fetches;
 *     the worker-version card's nonce-verified "Re-check now" forces a live
 *     probe here too (one click refreshes BOTH readouts of the shared endpoint).
 *   - render: value-pinned strings for every state — full healthy, prev-expired,
 *     today-not-minted, no-expiry-recorded, past-expiry, salt:null, WP_Error,
 *     non-200, old-worker — plus the manage_options gate and real escaping.
 *     kv-failed ("salt": null) and unreachable (transport failure) render
 *     DISTINCT copy: the worker WAS read in the former, and saying otherwise
 *     sends the operator diagnosing in the wrong direction.
 *
 * The wp_remote_get stub models the transport's REAL shapes (WP_Error object,
 * array with response.code + body, non-200) — the transport-transform rule.
 *
 * Run: php tests/analytics-salt-window.php
 *
 * @since plugin v9.71.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_VERSION', '9.71.0' );

// ── Settable test state ───────────────────────────────────────────────
$GLOBALS['__test_collector']      = '';                     // sn_rss_tracker_settings collector_url
$GLOBALS['__test_home']           = 'https://home.example'; // home_url base
$GLOBALS['__test_http']           = null;                   // wp_remote_get return
$GLOBALS['__test_get_calls']      = array();
$GLOBALS['__test_transients']     = array();
$GLOBALS['__test_transient_ttls'] = array();
$GLOBALS['__test_options']        = array();
$GLOBALS['__test_can']            = true;
$GLOBALS['__test_nonce_valid']    = true;   // wp_verify_nonce stub return

// ── WP function stubs (the tests/worker-version.php known-good set) ────
function sn_rss_tracker_settings() {
	return array( 'collector_url' => $GLOBALS['__test_collector'] );
}
function home_url( $path = '' ) {
	return $GLOBALS['__test_home'] . $path;
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}
// wp_http_validate_url — mirror WP core's gate: only http/https with a host pass.
function wp_http_validate_url( $u ) {
	if ( ! is_string( $u ) || '' === $u ) {
		return false;
	}
	$parts = parse_url( $u );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return false;
	}
	if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	return $u;
}
function sanitize_text_field( $s ) {
	$s = strip_tags( (string) $s );
	$s = preg_replace( '/[\r\n\t ]+/', ' ', $s );
	return trim( $s );
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); }
}
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__test_get_calls'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	return $GLOBALS['__test_http'];
}
function wp_remote_retrieve_response_code( $resp ) {
	return is_array( $resp ) && isset( $resp['response']['code'] ) ? $resp['response']['code'] : 0;
}
function wp_remote_retrieve_body( $resp ) {
	return is_array( $resp ) && isset( $resp['body'] ) ? $resp['body'] : '';
}
class WP_Error {
	public $code;
	public $message;
	public function __construct( $c = '', $m = '' ) {
		$this->code    = $c;
		$this->message = $m;
	}
}
function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}
// Faithful to WP: get_transient returns EXACTLY what was stored (no wrapper).
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['__test_transients'] ) ? $GLOBALS['__test_transients'][ $key ] : false;
}
function set_transient( $key, $value, $exp = 0 ) {
	$GLOBALS['__test_transients'][ $key ]     = $value;
	$GLOBALS['__test_transient_ttls'][ $key ] = $exp;
	return true;
}
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__test_options'][ $key ] = $value;
	return true;
}

// Render-path stubs. esc_html uses real htmlspecialchars so escaping is OBSERVABLE.
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function current_user_can( $cap ) {
	return (bool) $GLOBALS['__test_can'];
}
// Deterministic so render assertions can pin the exact string.
function human_time_diff( $from, $to = 0 ) {
	return '2 days';
}
// Re-check control stubs (the shared force-bypass wiring — tests/worker-version.php set).
function wp_unslash( $v ) {
	return $v;
}
function wp_verify_nonce( $nonce, $action = -1 ) {
	return ! empty( $GLOBALS['__test_nonce_valid'] ) ? 1 : false;
}
// Site-local formatter: deterministic UTC in the harness (the site tz seam).
function wp_date( $format, $ts = null ) {
	return gmdate( $format, null === $ts ? time() : (int) $ts );
}
// i18n passthroughs (default locale returns the msgid unchanged).
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
function number_format_i18n( $n ) { return (string) (int) $n; }

// Deterministic resolver seam for the shared SSRF guard — defined BEFORE
// inc/ssrf-guard.php so its function_exists() guard keeps THIS one (mirrors
// tests/worker-version.php).
function sn_ssrf_resolve_host( $host ) {
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return $host;
	}
	return '93.184.216.34'; // any hostname → public
}
require_once __DIR__ . '/../inc/ssrf-guard.php';
require_once __DIR__ . '/../inc/worker-version.php'; // endpoint derivation dep
require_once __DIR__ . '/../inc/analytics-salt-window.php';

// ── Harness ───────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function sw_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n";
	}
}
function sw_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
	}
}
function sw_reset() {
	$GLOBALS['__test_collector']      = 'https://juanlentino.com/_sn/px';
	$GLOBALS['__test_home']           = 'https://home.example';
	$GLOBALS['__test_http']           = null;
	$GLOBALS['__test_get_calls']      = array();
	$GLOBALS['__test_transients']     = array();
	$GLOBALS['__test_transient_ttls'] = array();
	$GLOBALS['__test_options']        = array();
	$GLOBALS['__test_can']            = true;
	$GLOBALS['__test_nonce_valid']    = true;
	unset( $_GET['sn_worker_recheck'], $_GET['_wpnonce'] );
}

// The FIXED /_sn/version "salt" contract shape (both sides build to this exactly).
function sw_salt( $overrides = array(), $remove = array() ) {
	$salt = array_merge(
		array(
			'rotate_tz'        => 'America/Argentina/Buenos_Aires',
			'today_day'        => '2026-07-18',
			'today_present'    => true,
			'today_expires_at' => time() + 200000,
			'prev_day'         => '2026-07-17',
			'prev_present'     => true,
			'prev_expires_at'  => time() + 100000,
			'next_present'     => true,
			'key_count'        => 3,
		),
		$overrides
	);
	foreach ( $remove as $k ) {
		unset( $salt[ $k ] );
	}
	return $salt;
}
// A full /_sn/version body; $salt === '__ABSENT__' models an OLD worker (no key).
function sw_http( $salt, $code = 200 ) {
	$json = array(
		'worker'  => 'sn-analytics',
		'version' => '1.14.0',
	);
	if ( '__ABSENT__' !== $salt ) {
		$json['salt'] = $salt;
	}
	return array(
		'response' => array( 'code' => $code ),
		'body'     => json_encode( $json ),
	);
}
function sw_render() {
	ob_start();
	sn_salt_window_render_card();
	return ob_get_clean();
}

// ─── Group A: parse_response — absent vs null are DIFFERENT answers ───
echo "\nGroup A: parse_response: the absent-vs-null discipline (array_key_exists)\n";

$r = sn_salt_window_parse_response( 500, 'Internal Server Error' );
sw_eq( 'unreachable', $r['state'], 'non-200 → unreachable' );
sw_eq( null, $r['window'], 'non-200 → window null (never fabricated)' );

$r = sn_salt_window_parse_response( 200, 'not json at all' );
sw_eq( 'unreachable', $r['state'], 'non-JSON 200 → unreachable (not a fake success)' );

$r = sn_salt_window_parse_response( 200, json_encode( array( 'worker' => 'sn-analytics', 'version' => '1.13.0' ) ) );
sw_eq( 'old-worker', $r['state'], 'MISSING "salt" member → old-worker (pre-v1.14.0)' );
sw_eq( null, $r['window'], 'old-worker → window null' );

$r = sn_salt_window_parse_response( 200, json_encode( array( 'worker' => 'sn-analytics', 'salt' => null ) ) );
sw_eq( 'kv-failed', $r['state'], '"salt": null (KV list failure) → kv-failed. NOT old-worker (the ?? trap)' );
sw_eq( null, $r['window'], 'kv-failed → window null (never a fabricated shape)' );

$r = sn_salt_window_parse_response( 200, json_encode( array( 'worker' => 'sn-analytics', 'salt' => 'scalar-junk' ) ) );
sw_eq( 'kv-failed', $r['state'], 'scalar "salt" (contract violation) → kv-failed, never a window' );

$r = sn_salt_window_parse_response( 200, json_encode( array( 'worker' => 'sn-analytics', 'salt' => sw_salt() ) ) );
sw_eq( 'ok', $r['state'], 'full contract shape → ok' );
$w = $r['window'];
sw_eq( 'America/Argentina/Buenos_Aires', $w['rotate_tz'], 'rotate_tz parsed' );
sw_eq( '2026-07-18', $w['today_day'], 'today_day parsed' );
sw_true( true === $w['today_present'], 'today_present is a STRICT bool true' );
sw_true( is_int( $w['today_expires_at'] ), 'today_expires_at is a STRICT int (unix seconds)' );
sw_eq( '2026-07-17', $w['prev_day'], 'prev_day parsed' );
sw_true( true === $w['prev_present'], 'prev_present is a STRICT bool true' );
sw_true( is_int( $w['prev_expires_at'] ), 'prev_expires_at is a STRICT int' );
sw_true( true === $w['next_present'], 'next_present is a STRICT bool true' );
sw_true( 3 === $w['key_count'], 'key_count is a STRICT int 3' );

// Within the window: present-null and ABSENT both degrade to null — never invented.
$r = sn_salt_window_parse_response( 200, sw_http( sw_salt( array( 'prev_expires_at' => null ) ) )['body'] );
sw_eq( null, $r['window']['prev_expires_at'], 'prev_expires_at: null in JSON → null (a real "no expiry", per contract)' );
$r = sn_salt_window_parse_response( 200, sw_http( sw_salt( array(), array( 'prev_expires_at' ) ) )['body'] );
sw_eq( null, $r['window']['prev_expires_at'], 'prev_expires_at: ABSENT → null (unknown, never invented)' );
$r = sn_salt_window_parse_response( 200, sw_http( sw_salt( array(), array( 'prev_present' ) ) )['body'] );
sw_eq( null, $r['window']['prev_present'], 'prev_present: ABSENT → null. NOT false (absent ≠ "expired")' );
$r = sn_salt_window_parse_response( 200, sw_http( sw_salt( array( 'today_present' => 1 ) ) )['body'] );
sw_eq( null, $r['window']['today_present'], 'today_present: non-bool 1 → null (strict-type discipline, no coercion)' );
$r = sn_salt_window_parse_response( 200, sw_http( sw_salt( array( 'key_count' => '3' ) ) )['body'] );
sw_true( 3 === $r['window']['key_count'], 'key_count: numeric string coerces to int (transport-safe)' );
$r = sn_salt_window_parse_response( 200, sw_http( sw_salt( array( 'rotate_tz' => "UTC<script>x</script>\n" ) ) )['body'] );
sw_eq( 'UTCx', $r['window']['rotate_tz'], 'rotate_tz is sanitized (tags stripped, whitespace collapsed)' );

// ─── Group B: probe — the shared outbound gate ────────────────────────
echo "\nGroup B: probe. SSRF/scheme gate + one guarded GET\n";

sw_reset();
$GLOBALS['__test_http'] = sw_http( sw_salt() );
$r = sn_salt_window_probe();
sw_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'public https endpoint → exactly one GET' );
sw_eq( 'https://juanlentino.com/_sn/version', $GLOBALS['__test_get_calls'][0]['url'], 'GET targets the derived sibling /_sn/version' );
sw_eq( 0, $GLOBALS['__test_get_calls'][0]['args']['redirection'], 'redirection === 0 (no redirect off the validated host)' );
sw_eq( 'ok', $r['state'], 'healthy probe → ok' );

sw_reset();
$GLOBALS['__test_collector'] = 'https://169.254.169.254/_sn/px'; // metadata IP
$GLOBALS['__test_http']      = sw_http( sw_salt() );
$r = sn_salt_window_probe();
sw_eq( 0, count( $GLOBALS['__test_get_calls'] ), 'SSRF-blocked base → no GET' );
sw_eq( 'unreachable', $r['state'], 'SSRF-blocked → unreachable' );

sw_reset();
$GLOBALS['__test_collector'] = 'http://juanlentino.com/_sn/px'; // plaintext
$r = sn_salt_window_probe();
sw_eq( 0, count( $GLOBALS['__test_get_calls'] ), 'http base → no GET (https-only gate)' );
sw_eq( 'unreachable', $r['state'], 'http base → unreachable' );

sw_reset();
$GLOBALS['__test_http'] = new WP_Error( 'http_request_failed', 'timeout' );
$r = sn_salt_window_probe();
sw_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'WP_Error path still attempts one GET' );
sw_eq( 'unreachable', $r['state'], 'WP_Error → unreachable (transport failure is an answer)' );
sw_eq( null, $r['window'], 'WP_Error → window null' );

// ─── Group C: get — the ~10-min transient cache ───────────────────────
echo "\nGroup C: get: transient cache (readout freshness, not monitoring)\n";

sw_reset();
$GLOBALS['__test_http'] = sw_http( sw_salt() );
$r = sn_salt_window_get();
sw_eq( 'ok', $r['state'], 'cache miss → live probe ok' );
sw_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'cache miss → exactly one GET' );
sw_eq( SN_SALT_WINDOW_TTL_OK, $GLOBALS['__test_transient_ttls'][ SN_SALT_WINDOW_TRANSIENT ], 'success cached with the 10-min TTL' );
$r = sn_salt_window_get();
sw_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'warm cache → served from transient, ZERO new fetches' );
$r = sn_salt_window_get( true );
sw_eq( 2, count( $GLOBALS['__test_get_calls'] ), 'force=true → re-probes (2nd GET)' );

sw_reset();
$GLOBALS['__test_http'] = new WP_Error( 'http_request_failed', 'timeout' );
$r = sn_salt_window_get();
sw_eq( SN_SALT_WINDOW_TTL_FAIL, $GLOBALS['__test_transient_ttls'][ SN_SALT_WINDOW_TRANSIENT ], 'failure cached with the short retry TTL' );

sw_reset();
$GLOBALS['__test_transients'][ SN_SALT_WINDOW_TRANSIENT ] = 'corrupt-scalar';
$GLOBALS['__test_http'] = sw_http( sw_salt() );
$r = sn_salt_window_get();
sw_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'corrupt (non-array) transient → ignored, fresh probe' );
sw_eq( 'ok', $r['state'], 'corrupt transient → returns the live probe, not the garbage' );

// ─── Group D: render — value-pinned strings per state ─────────────────
echo "\nGroup D: render: the seven states, value-pinned\n";

// 1. Full healthy shape.
sw_reset();
$prev_exp = time() + 100000;
$GLOBALS['__test_http'] = sw_http( sw_salt( array( 'prev_expires_at' => $prev_exp ) ) );
$html = sw_render();
sw_true( false !== strpos( $html, 'Identity salt window' ), 'healthy: heading present' );
sw_true( false !== strpos( $html, 'salt values never leave the Worker' ), 'healthy: the no-values disclosure line renders' );
sw_true( false !== strpos( $html, 'Today’s salt: 2026-07-18: rotates at midnight (America/Argentina/Buenos_Aires).' ), 'healthy: today line pinned verbatim' );
sw_true( false !== strpos( $html, 'Yesterday’s salt (2026-07-17) expires ' . gmdate( 'Y-m-d H:i', $prev_exp ) . ' (in 2 days).' ), 'healthy: yesterday expiry line pinned verbatim (site-local date + relative)' );
sw_true( false !== strpos( $html, '3 salt keys at the edge.' ), 'healthy: key count pinned' );
sw_true( false !== strpos( $html, 'Checked 2 days ago.' ), 'healthy: freshness line pinned' );
sw_true( false !== strpos( $html, 'notice-info' ), 'healthy: calm info notice, never an alarm' );
sw_true( false === strpos( $html, 'could not read the worker' ), 'healthy: no error copy' );

// 2. Prev-expired shape (forward secrecy already holding).
sw_reset();
$GLOBALS['__test_http'] = sw_http( sw_salt( array( 'prev_present' => false, 'prev_expires_at' => null, 'key_count' => 2 ) ) );
$html = sw_render();
sw_true( false !== strpos( $html, 'Yesterday’s salt (2026-07-17) has already expired: forward secrecy holding.' ), 'prev-expired: line pinned verbatim' );
sw_true( false !== strpos( $html, 'Today’s salt: 2026-07-18: rotates at midnight (America/Argentina/Buenos_Aires).' ), 'prev-expired: today line still renders' );
sw_true( false !== strpos( $html, '2 salt keys at the edge.' ), 'prev-expired: key count follows the payload' );
sw_true( false === strpos( $html, 'expires ' ), 'prev-expired: no expiry date is fabricated' );

// 3. Today not minted yet (today_present false).
sw_reset();
$GLOBALS['__test_http'] = sw_http( sw_salt( array( 'today_present' => false, 'today_expires_at' => null ) ) );
$html = sw_render();
sw_true( false !== strpos( $html, 'Today’s salt: 2026-07-18 (not minted yet (it appears with the first visit of the day)) rotates at midnight (America/Argentina/Buenos_Aires).' ), 'not-minted: today line pinned verbatim' );

// 4. Prev present but no expiry recorded (null expiry, per contract).
sw_reset();
$GLOBALS['__test_http'] = sw_http( sw_salt( array( 'prev_expires_at' => null ) ) );
$html = sw_render();
sw_true( false !== strpos( $html, 'Yesterday’s salt (2026-07-17) has no expiry recorded.' ), 'null expiry: honest "no expiry recorded", never an invented date' );

// 5. Past expiry (still present) → the "ago" branch.
sw_reset();
$past = time() - 100000;
$GLOBALS['__test_http'] = sw_http( sw_salt( array( 'prev_expires_at' => $past ) ) );
$html = sw_render();
sw_true( false !== strpos( $html, 'Yesterday’s salt (2026-07-17) expires ' . gmdate( 'Y-m-d H:i', $past ) . ' (2 days ago).' ), 'past expiry: relative flips to "ago"' );

// 6. "salt": null (KV list failure) → honest em-dash, DISTINCT from a failed
// fetch: the worker WAS read here — "could not read the worker" would be false
// and send the operator curling a healthy endpoint (the update-checker-failure-
// modes class). The copy names the real failure: the worker's own KV list.
sw_reset();
$GLOBALS['__test_http'] = sw_http( null );
$html = sw_render();
sw_true( false !== strpos( $html, 'worker reachable, but it could not list its salt keys (KV read failed at the edge).' ), 'salt:null → em-dash + the KV-failure copy (verbatim)' );
sw_true( false === strpos( $html, 'could not read the worker' ), 'salt:null → NOT the failed-fetch copy (the worker WAS read)' );
sw_true( false === strpos( $html, 'rotates at midnight' ), 'salt:null → no fabricated today line' );
sw_true( false === strpos( $html, 'notice-error' ) && false === strpos( $html, 'notice-warning' ), 'salt:null → never a red alarm (readout, not monitoring)' );
sw_true( false !== strpos( $html, 'Identity salt window' ), 'salt:null → the heading still frames the readout' );

// 7. Fetch WP_Error → the failed-fetch state (and NOT the kv-failed copy).
sw_reset();
$GLOBALS['__test_http'] = new WP_Error( 'http_request_failed', 'timeout' );
$html = sw_render();
sw_true( false !== strpos( $html, 'could not read the worker.' ), 'WP_Error → em-dash + could-not-read' );
sw_true( false === strpos( $html, 'salt keys (KV read failed' ), 'WP_Error → NOT the kv-failed copy (nothing answered)' );
sw_true( false === strpos( $html, 'rotates at midnight' ), 'WP_Error → no fabricated dates' );

// 8. Old worker (no "salt" member) → the version-gap message, NOT the error copy.
sw_reset();
$GLOBALS['__test_http'] = sw_http( '__ABSENT__' );
$html = sw_render();
sw_true( false !== strpos( $html, 'Worker predates the salt window readout (needs v1.14.0+).' ), 'old-worker: message pinned verbatim' );
sw_true( false === strpos( $html, 'could not read the worker' ), 'old-worker: NOT conflated with a read failure' );

// 9. Non-200 → the honest error state (and NOT the kv-failed copy).
sw_reset();
$GLOBALS['__test_http'] = array( 'response' => array( 'code' => 503 ), 'body' => 'edge sad' );
$html = sw_render();
sw_true( false !== strpos( $html, 'could not read the worker.' ), 'non-200 → em-dash + could-not-read' );
sw_true( false === strpos( $html, 'salt keys (KV read failed' ), 'non-200 → NOT the kv-failed copy (no valid answer)' );

// 10. Transient-cache pin: a SECOND render costs zero fetches.
sw_reset();
$GLOBALS['__test_http'] = sw_http( sw_salt() );
$first = sw_render();
sw_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'first render → exactly one GET' );
$second = sw_render();
sw_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'second render → ZERO new fetches (10-min transient)' );
sw_eq( $first, $second, 'second render is byte-identical (served from cache)' );

// 11. Capability gate.
sw_reset();
$GLOBALS['__test_can'] = false;
sw_eq( '', sw_render(), 'non-admin → renders nothing (manage_options gate)' );
sw_eq( 0, count( $GLOBALS['__test_get_calls'] ), 'non-admin → no fetch either' );

// 12. Escaping: a hostile window straight from a warm transient (bypasses the
// parse-side sanitizer) must still be esc_html'd at the point of output.
sw_reset();
$GLOBALS['__test_transients'][ SN_SALT_WINDOW_TRANSIENT ] = array(
	'state'      => 'ok',
	'window'     => array(
		'rotate_tz'        => '<script>alert(1)</script>',
		'today_day'        => '"><img src=x>',
		'today_present'    => true,
		'today_expires_at' => null,
		'prev_day'         => '2026-07-17',
		'prev_present'     => null,
		'prev_expires_at'  => null,
		'next_present'     => null,
		'key_count'        => null,
	),
	'url'        => 'https://juanlentino.com/_sn/version',
	'fetched_at' => time(),
);
$html = sw_render();
sw_true( false === strpos( $html, '<script>alert(1)</script>' ), 'XSS in rotate_tz → raw payload NOT present (escaped)' );
sw_true( false !== strpos( $html, '&lt;script&gt;' ), 'XSS in rotate_tz → appears esc_html-encoded' );
sw_true( false === strpos( $html, '<img' ), 'XSS in today_day → raw <img NOT present (escaped)' );
// prev_present null (absent upstream) → the yesterday line is SKIPPED, not invented.
sw_true( false === strpos( $html, 'Yesterday’s salt' ), 'prev_present null → no yesterday line fabricated' );
sw_true( false === strpos( $html, 'salt key' ), 'key_count null → no count line fabricated' );

// ─── Group F: "Re-check now" refreshes THIS card too ──────────────────
// The worker-version card's nonce-verified re-check link probes the SAME
// /_sn/version endpoint. Both cards must refresh on one click — otherwise the
// version card flips to the new deploy while the salt card serves a stale
// transient for up to 10 minutes (adjacent cards from one endpoint disagreeing).
echo "\nGroup F: Re-check now: the shared force flag drives the salt card too\n";

// A verified re-check bypasses a warm (stale) transient.
sw_reset();
$GLOBALS['__test_transients'][ SN_SALT_WINDOW_TRANSIENT ] = array(
	'state'      => 'old-worker', // cached before the worker deploy
	'window'     => null,
	'url'        => 'https://juanlentino.com/_sn/version',
	'fetched_at' => time() - 60,
);
$GLOBALS['__test_http']    = sw_http( sw_salt() ); // the freshly deployed worker
$_GET['sn_worker_recheck'] = '1';
$_GET['_wpnonce']          = 'testnonce';
$html = sw_render();
sw_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'verified re-check → warm cache bypassed, one live GET' );
sw_true( false !== strpos( $html, 'Today’s salt: 2026-07-18' ), 're-check → renders the LIVE window, not the stale transient' );
sw_true( false === strpos( $html, 'predates the salt window readout' ), 're-check → the stale old-worker line is gone (cards agree again)' );

// The re-probe refreshed the transient: dropping the trigger serves from cache.
unset( $_GET['sn_worker_recheck'], $_GET['_wpnonce'] );
$html2 = sw_render();
sw_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'after re-check, a plain render is cache-served (transient refreshed)' );
sw_eq( $html, $html2, 'the cache-served render matches the re-checked one byte-for-byte' );

// A FORGED re-check (bad nonce) must not bypass the cache — the nonce gate is
// the worker-version card's own, verified before any effect.
sw_reset();
$GLOBALS['__test_transients'][ SN_SALT_WINDOW_TRANSIENT ] = array(
	'state'      => 'old-worker',
	'window'     => null,
	'url'        => 'https://juanlentino.com/_sn/version',
	'fetched_at' => time() - 60,
);
$GLOBALS['__test_http']        = sw_http( sw_salt() );
$_GET['sn_worker_recheck']     = '1';
$_GET['_wpnonce']              = 'forged';
$GLOBALS['__test_nonce_valid'] = false;
$html = sw_render();
sw_eq( 0, count( $GLOBALS['__test_get_calls'] ), 'forged re-check → no live probe (nonce gate holds)' );
sw_true( false !== strpos( $html, 'Worker predates the salt window readout (needs v1.14.0+).' ), 'forged re-check → served from the cached transient' );

// ─── Group E: wiring — mount + loader source contracts ────────────────
echo "\nGroup E: wiring: settings-column mount + loader require\n";

$admin_src = file_get_contents( __DIR__ . '/../inc/analytics-admin.php' );
sw_true( false !== strpos( $admin_src, "function_exists( 'sn_salt_window_render_card' )" ), 'mount: settings section calls the readout behind a function_exists guard' );
$wv_at = strpos( $admin_src, 'sn_worker_version_render_card' );
$sw_at = strpos( $admin_src, 'sn_salt_window_render_card' );
sw_true( false !== $wv_at && false !== $sw_at && $wv_at < $sw_at, 'mount: the salt window renders AFTER the worker-version card (reference column)' );
$loader_src = file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' );
sw_true( false !== strpos( $loader_src, "inc/analytics-salt-window.php" ), 'loader: the module is required by the plugin bootstrap' );
sw_true( strpos( $loader_src, 'inc/worker-version.php' ) < strpos( $loader_src, 'inc/analytics-salt-window.php' ), 'loader: loads AFTER worker-version (its endpoint-derivation dependency)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
