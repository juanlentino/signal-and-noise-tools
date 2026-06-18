<?php
/**
 * Standalone fixture tests for inc/worker-version.php (v6.21.0).
 *
 * The analytics edge-Worker version readout (Monitoring → Analytics):
 *   - endpoint_url: rebuilt from the collector base's ORIGIN (scheme+host+port),
 *     ignoring its path; follows a workers.dev override; '' when underivable.
 *   - parse_response: 200 + valid JSON → ok + sanitized fields; null fields → '';
 *     non-JSON / missing `worker` / non-200 → failure (never a fake success).
 *   - probe: routes through the SAME outbound gate as every other probe —
 *     https-only + wp_http_validate_url() + the shared sn_ssrf_host_blocked()
 *     (resolve-then-range-check) + redirection=0. Encoded-IP metadata forms and
 *     a plain http base are blocked → 0 GET. A WP_Error → 'network'.
 *   - get: SWR cache — miss probes+caches+records last-good; hit serves the
 *     transient (no 2nd probe); a failure caches short AND leaves last-good intact.
 *
 * Run: php tests/worker-version.php
 *
 * @since plugin v6.21.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_VERSION', '6.21.0' );

// ── Settable test state ───────────────────────────────────────────────
$GLOBALS['__test_collector']  = '';                       // sn_rss_tracker_settings collector_url
$GLOBALS['__test_home']       = 'https://home.example';   // home_url base
$GLOBALS['__test_http']       = null;                     // wp_remote_get return
$GLOBALS['__test_get_calls']      = array();
$GLOBALS['__test_transients']     = array();
$GLOBALS['__test_transient_ttls'] = array();
$GLOBALS['__test_options']        = array();
$GLOBALS['__test_can']            = true;
$GLOBALS['__test_tracker_nonarray'] = false;

// ── WP function stubs ─────────────────────────────────────────────────
function sn_rss_tracker_settings() {
	// Models the tracker returning a non-array (corrupt/partial option) so the
	// is_array() defensive branch in collector_base falls back to home_url.
	if ( ! empty( $GLOBALS['__test_tracker_nonarray'] ) ) {
		return null;
	}
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
$GLOBALS['__test_get_calls'] = array();
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
// The TTL is tracked in a parallel map so tests can assert it without polluting
// the returned value (an earlier wrapper broke the warm-cache read — the kind
// of unfaithful-stub false signal this repo explicitly guards against).
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

// Render-path stubs. esc_html/esc_attr use real htmlspecialchars so escaping is
// OBSERVABLE — a captured `<script>` proves the value went through esc_html.
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
$GLOBALS['__test_can'] = true;
function current_user_can( $cap ) {
	return (bool) $GLOBALS['__test_can'];
}
// Deterministic so format/render assertions can pin the exact string.
function human_time_diff( $from, $to = 0 ) {
	return '2 days';
}

// Deterministic resolver seam for the shared SSRF guard — defined BEFORE
// inc/ssrf-guard.php so its function_exists() guard keeps THIS one (mirrors
// tests/uptime-heartbeat.php + tests/webhooks.php). Literal IPs pass through
// filter_var; the encoded forms of 169.254.169.254 map to it offline; a
// hostname models RFC-1918; everything else resolves public.
function sn_ssrf_resolve_host( $host ) {
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return $host;
	}
	$map = array(
		'2852039166'              => '169.254.169.254', // decimal-encoded metadata IP
		'0xa9.0xfe.0xa9.0xfe'     => '169.254.169.254', // dotted-hex (lower)
		'0xA9.0xFE.0xA9.0xFE'     => '169.254.169.254', // dotted-hex as parse_url returns it
		'0251.0376.0251.0376'     => '169.254.169.254', // dotted-octal-encoded
		'blocked-private.example' => '10.0.0.5',        // hostname → RFC-1918
		'unresolvable.example'    => '',                // fail-closed case
	);
	if ( array_key_exists( $host, $map ) ) {
		return $map[ $host ];
	}
	return '93.184.216.34'; // any other host → public
}
require_once __DIR__ . '/../inc/ssrf-guard.php';
require_once __DIR__ . '/../inc/worker-version.php';

// ── Harness ───────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function wv_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n";
	}
}
function wv_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
	}
}
function wv_reset() {
	$GLOBALS['__test_collector']  = '';
	$GLOBALS['__test_home']       = 'https://home.example';
	$GLOBALS['__test_http']       = null;
	$GLOBALS['__test_get_calls']      = array();
	$GLOBALS['__test_transients']     = array();
	$GLOBALS['__test_transient_ttls']   = array();
	$GLOBALS['__test_options']          = array();
	$GLOBALS['__test_can']              = true;
	$GLOBALS['__test_tracker_nonarray'] = false;
}

// A valid /_sn/version success body the worker would emit.
function wv_ok_http( $version = '1.4.0', $id = 'abc-123-uuid', $tag = 'v1.4.0', $deployed = '2026-06-17T12:00:00Z' ) {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode(
			array(
				'worker'         => 'sn-analytics',
				'version'        => $version,
				'cf_version_id'  => $id,
				'cf_version_tag' => $tag,
				'deployed_at'    => $deployed,
			)
		),
	);
}

// ─── Group A: endpoint URL derivation ─────────────────────────────────
echo "\nGroup A: endpoint_url — origin-rebuild from the collector base\n";
wv_reset();
$GLOBALS['__test_collector'] = 'https://juanlentino.com/_sn/px';
wv_eq( 'https://juanlentino.com/_sn/version', sn_worker_version_endpoint_url(), 'default collector → sibling /_sn/version' );

$GLOBALS['__test_collector'] = 'https://sn.example.workers.dev/_sn/px';
wv_eq( 'https://sn.example.workers.dev/_sn/version', sn_worker_version_endpoint_url(), 'workers.dev override is followed' );

$GLOBALS['__test_collector'] = 'https://example.com:8443/_sn/px';
wv_eq( 'https://example.com:8443/_sn/version', sn_worker_version_endpoint_url(), 'non-standard port is preserved' );

$GLOBALS['__test_collector'] = 'https://example.com/some/other/path';
wv_eq( 'https://example.com/_sn/version', sn_worker_version_endpoint_url(), 'base path is ignored — only the origin matters' );

$GLOBALS['__test_collector'] = '';
$GLOBALS['__test_home']      = 'https://fallback.example';
wv_eq( 'https://fallback.example/_sn/version', sn_worker_version_endpoint_url(), 'empty collector → home_url fallback' );

$GLOBALS['__test_collector'] = 'garbage-no-scheme';
wv_eq( '', sn_worker_version_endpoint_url(), 'unparseable base → "" (fails closed)' );

$GLOBALS['__test_collector'] = '/relative/path';
wv_eq( '', sn_worker_version_endpoint_url(), 'relative (no host) base → "" (fails closed)' );

// Defensive degradation: tracker settings come back non-array → home_url fallback.
wv_reset();
$GLOBALS['__test_tracker_nonarray'] = true;
$GLOBALS['__test_home']             = 'https://degraded.example';
wv_eq( 'https://degraded.example/_sn/version', sn_worker_version_endpoint_url(), 'non-array tracker settings → home_url fallback' );

// ─── Group B: parse_response (pure) ───────────────────────────────────
echo "\nGroup B: parse_response — shape + sanitization + failure modes\n";
$valid = json_encode(
	array(
		'worker'         => 'sn-analytics',
		'version'        => '1.4.0',
		'cf_version_id'  => 'uuid-1',
		'cf_version_tag' => 'v1.4.0',
		'deployed_at'    => '2026-06-17T12:00:00Z',
	)
);
$r = sn_worker_version_parse_response( 200, $valid );
wv_true( $r['ok'], '200 + valid JSON → ok' );
wv_eq( '1.4.0', $r['data']['version'], 'version parsed' );
wv_eq( 'uuid-1', $r['data']['cf_version_id'], 'cf_version_id parsed' );

$nulls = json_encode(
	array(
		'worker'        => 'sn-analytics',
		'version'       => null,
		'cf_version_id' => null,
		'deployed_at'   => null,
	)
);
$r = sn_worker_version_parse_response( 200, $nulls );
wv_true( $r['ok'], 'null binding fields still ok (worker present)' );
wv_eq( '', $r['data']['version'], 'null version degrades to ""' );
wv_eq( '', $r['data']['cf_version_id'], 'null cf_version_id degrades to ""' );

$r = sn_worker_version_parse_response( 200, 'not json at all' );
wv_eq( 'bad-response', $r['error'], 'non-JSON 200 → bad-response (not a fake success)' );
wv_true( ! $r['ok'], 'non-JSON 200 → ok=false' );

$r = sn_worker_version_parse_response( 200, json_encode( array( 'hello' => 'world' ) ) );
wv_eq( 'bad-response', $r['error'], 'JSON without `worker` → bad-response' );

$r = sn_worker_version_parse_response( 200, '' );
wv_eq( 'bad-response', $r['error'], 'empty body → bad-response' );

$r = sn_worker_version_parse_response( 500, 'Internal Server Error' );
wv_eq( 'http-500', $r['error'], 'non-200 → http-<code>' );
wv_true( ! $r['ok'], 'non-200 → ok=false' );

$r = sn_worker_version_parse_response( 200, '<html>error</html>' );
wv_true( ! $r['ok'], 'HTML proxy error page at 200 → ok=false' );

// Sanitization is load-bearing — the parsed fields flow straight into the
// echo-heavy render. Feed a DIRTY value and assert it is actually cleaned
// (markup stripped, whitespace collapsed). Without sanitize_text_field this
// assertion fails — pins the $clean closure behaviorally, not just in prose.
$dirty = json_encode(
	array(
		'worker'  => 'sn-analytics',
		'version' => "1.4.0 <script>x</script>\n\t",
	)
);
$r = sn_worker_version_parse_response( 200, $dirty );
wv_eq( '1.4.0 x', $r['data']['version'], 'dirty field is sanitized (tags stripped, whitespace collapsed)' );

// A non-scalar field exercises the is_scalar() false-branch of $clean (the
// `?? ''` handles null before $clean sees it, so an array is the only way in).
$nonscalar = json_encode(
	array(
		'worker'  => 'sn-analytics',
		'version' => array( 'nope' => 1 ),
	)
);
$r = sn_worker_version_parse_response( 200, $nonscalar );
wv_eq( '', $r['data']['version'], 'non-scalar field degrades to "" (is_scalar guard)' );

// ─── Group C: probe — the outbound gate ───────────────────────────────
echo "\nGroup C: probe — SSRF/scheme gate + one guarded GET\n";

wv_reset();
$GLOBALS['__test_collector'] = 'https://kuma.example.com/_sn/px';
$GLOBALS['__test_http']      = wv_ok_http();
$r = sn_worker_version_probe();
wv_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'public https endpoint → exactly one GET' );
wv_eq( 0, $GLOBALS['__test_get_calls'][0]['args']['redirection'], 'redirection === 0 (no redirect off the validated host)' );
wv_true( $r['ok'], 'public https + valid body → ok' );
wv_eq( '1.4.0', $r['data']['version'], 'probe surfaces the parsed version' );
wv_eq( 'https://kuma.example.com/_sn/version', $r['url'], 'result carries the probed endpoint' );

// Encoded-IP metadata forms + RFC-1918 hostname + unresolvable → 0 GET.
$blocked_bases = array(
	'https://169.254.169.254/_sn/px'     => 'literal metadata IP',
	'https://2852039166/_sn/px'          => 'decimal-encoded metadata IP (the bypass)',
	'https://0xA9.0xFE.0xA9.0xFE/_sn/px' => 'dotted-hex-encoded metadata IP',
	'https://0251.0376.0251.0376/_sn/px' => 'dotted-octal-encoded metadata IP',
	'https://blocked-private.example/_sn/px' => 'hostname → RFC-1918',
	'https://unresolvable.example/_sn/px'    => 'unresolvable host (fail closed)',
);
foreach ( $blocked_bases as $base => $desc ) {
	wv_reset();
	$GLOBALS['__test_collector'] = $base;
	$GLOBALS['__test_http']      = wv_ok_http();
	$r = sn_worker_version_probe();
	wv_eq( 0, count( $GLOBALS['__test_get_calls'] ), "blocked → no GET: $desc" );
	wv_eq( 'blocked', $r['error'], "blocked → error 'blocked': $desc" );
}

// Plain http base → scheme guard blocks (never probe the worker over plaintext).
wv_reset();
$GLOBALS['__test_collector'] = 'http://kuma.example.com/_sn/px';
$GLOBALS['__test_http']      = wv_ok_http();
$r = sn_worker_version_probe();
wv_eq( 0, count( $GLOBALS['__test_get_calls'] ), 'http base → no GET (https-only gate)' );
wv_eq( 'blocked', $r['error'], 'http base → error blocked' );

// Underivable endpoint → no-endpoint, no GET.
wv_reset();
$GLOBALS['__test_collector'] = 'garbage';
$r = sn_worker_version_probe();
wv_eq( 0, count( $GLOBALS['__test_get_calls'] ), 'no endpoint → no GET' );
wv_eq( 'no-endpoint', $r['error'], 'no endpoint → error no-endpoint' );

// Transport-level WP_Error → network.
wv_reset();
$GLOBALS['__test_collector'] = 'https://kuma.example.com/_sn/px';
$GLOBALS['__test_http']      = new WP_Error( 'http_request_failed', 'timeout' );
$r = sn_worker_version_probe();
wv_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'WP_Error path still attempts one GET' );
wv_eq( 'network', $r['error'], 'WP_Error → error network' );
wv_true( ! $r['ok'], 'WP_Error → ok=false' );

// ─── Group D: get — SWR cache behaviour ───────────────────────────────
echo "\nGroup D: get — transient SWR + last-good fallback\n";

// Miss → one probe, caches with the OK TTL, records last-good.
wv_reset();
$GLOBALS['__test_collector'] = 'https://kuma.example.com/_sn/px';
$GLOBALS['__test_http']      = wv_ok_http();
$r = sn_worker_version_get();
wv_true( $r['ok'], 'cache miss → live probe ok' );
wv_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'cache miss → exactly one GET' );
wv_eq( SN_WORKER_VERSION_TTL_OK, $GLOBALS['__test_transient_ttls'][ SN_WORKER_VERSION_TRANSIENT ], 'success cached with the 10-min TTL' );
wv_true( ! empty( $GLOBALS['__test_options'][ SN_WORKER_VERSION_LASTGOOD ] ), 'success recorded as last-good' );

// Hit → no second probe.
$r = sn_worker_version_get();
wv_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'warm cache → served from transient, no 2nd GET' );
wv_true( $r['ok'], 'warm cache result still ok' );

// Force → bypasses the cache.
$r = sn_worker_version_get( true );
wv_eq( 2, count( $GLOBALS['__test_get_calls'] ), 'force=true → re-probes (2nd GET)' );

// Failure → short TTL, last-good UNTOUCHED.
wv_reset();
$GLOBALS['__test_options'][ SN_WORKER_VERSION_LASTGOOD ] = array(
	'ok'   => true,
	'data' => array( 'version' => '1.3.0' ),
);
$GLOBALS['__test_collector'] = 'https://169.254.169.254/_sn/px'; // blocked
$r = sn_worker_version_get();
wv_true( ! $r['ok'], 'blocked probe → get() ok=false' );
wv_eq( SN_WORKER_VERSION_TTL_FAIL, $GLOBALS['__test_transient_ttls'][ SN_WORKER_VERSION_TRANSIENT ], 'failure cached with the short (2-min) TTL' );
wv_eq( '1.3.0', $GLOBALS['__test_options'][ SN_WORKER_VERSION_LASTGOOD ]['data']['version'], 'failure does NOT overwrite last-good' );

// Corrupt (non-array) transient → the is_array() guard forces a fresh probe
// rather than returning garbage.
wv_reset();
$GLOBALS['__test_collector']  = 'https://kuma.example.com/_sn/px';
$GLOBALS['__test_http']       = wv_ok_http();
$GLOBALS['__test_transients'][ SN_WORKER_VERSION_TRANSIENT ] = 'corrupt-scalar';
$r = sn_worker_version_get();
wv_eq( 1, count( $GLOBALS['__test_get_calls'] ), 'corrupt (non-array) transient → ignored, fresh probe' );
wv_true( $r['ok'], 'corrupt transient → returns the live probe, not the garbage' );

// ─── Group E: format_deployed — all three branches ────────────────────
echo "\nGroup E: format_deployed — empty / unparseable / valid ISO\n";
wv_eq( '', sn_worker_version_format_deployed( '' ), 'empty → ""' );
wv_eq( 'not-a-real-date', sn_worker_version_format_deployed( 'not-a-real-date' ), 'unparseable → raw passthrough' );
$expect_dep = gmdate( 'Y-m-d H:i', strtotime( '2026-06-17T12:00:00Z' ) ) . ' UTC (2 days ago)';
wv_eq( $expect_dep, sn_worker_version_format_deployed( '2026-06-17T12:00:00Z' ), 'valid ISO → "Y-m-d H:i UTC (… ago)"' );

// ─── Group F: render — admin gate, state branch, real escaping ────────
echo "\nGroup F: render_card / render_data — capability gate + 3 states + esc_html\n";

// Non-admin → renders nothing (capability gate).
wv_reset();
$GLOBALS['__test_can'] = false;
ob_start();
sn_worker_version_render_card();
wv_eq( '', ob_get_clean(), 'non-admin → render_card outputs nothing (manage_options gate)' );

// Admin + live worker → info card with the version.
wv_reset();
$GLOBALS['__test_collector'] = 'https://kuma.example.com/_sn/px';
$GLOBALS['__test_http']      = wv_ok_http( '1.4.0' );
ob_start();
sn_worker_version_render_card();
$html = ob_get_clean();
wv_true( false !== strpos( $html, 'Edge worker' ), 'live → renders the "Edge worker" heading' );
wv_true( false !== strpos( $html, 'notice-info' ), 'live → uses the info notice level' );
wv_true( false !== strpos( $html, 'v1.4.0' ), 'live → shows the semver' );

// Admin + unreachable worker, NO last-good → "unknown" warning.
wv_reset();
$GLOBALS['__test_collector'] = 'https://169.254.169.254/_sn/px'; // blocked
ob_start();
sn_worker_version_render_card();
$html = ob_get_clean();
wv_true( false !== strpos( $html, 'Worker version unknown' ), 'unreachable + no last-good → "unknown" message' );
wv_true( false !== strpos( $html, 'notice-warning' ), 'unknown → uses the warning notice level' );

// Admin + unreachable worker, WITH last-good → stale fallback card.
wv_reset();
$GLOBALS['__test_collector'] = 'https://169.254.169.254/_sn/px'; // blocked
$GLOBALS['__test_options'][ SN_WORKER_VERSION_LASTGOOD ] = array(
	'ok'         => true,
	'data'       => array( 'worker' => 'sn-analytics', 'version' => '1.3.0' ),
	'fetched_at' => time(),
	'url'        => 'https://kuma.example.com/_sn/version',
);
ob_start();
sn_worker_version_render_card();
$html = ob_get_clean();
wv_true( false !== strpos( $html, 'Live check failed' ), 'unreachable + last-good → stale-fallback message' );
wv_true( false !== strpos( $html, 'v1.3.0' ), 'stale fallback → shows the last-known version' );
wv_true( false !== strpos( $html, 'notice-warning' ), 'stale fallback → warning notice level' );

// XSS: a payload in a rendered field must be esc_html'd (behavioral proof, not
// just the static linter). render_data echoes data['version'] / data['url'].
$xss = array(
	'ok'         => true,
	'data'       => array( 'worker' => 'sn-analytics', 'version' => '<script>alert(1)</script>', 'cf_version_id' => '"><img src=x>' ),
	'fetched_at' => time(),
	'url'        => 'https://kuma.example.com/_sn/version',
);
ob_start();
sn_worker_version_render_data( $xss, false );
$html = ob_get_clean();
wv_true( false === strpos( $html, '<script>alert(1)</script>' ), 'XSS in version → raw payload NOT present (escaped)' );
wv_true( false !== strpos( $html, '&lt;script&gt;' ), 'XSS in version → appears esc_html-encoded' );
wv_true( false === strpos( $html, '<img' ), 'XSS in cf_version_id → raw <img NOT present (escaped)' );
wv_true( false !== strpos( $html, '&lt;img', 0 ), 'XSS in cf_version_id → appears esc_html-encoded' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
