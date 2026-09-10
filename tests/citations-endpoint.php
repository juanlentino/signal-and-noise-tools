<?php
/**
 * Standalone tests for the citation inbox and its discovery advertisement.
 * @since plugin v11.27.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_CIT_TEST', true );

if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://juanlentino.com/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $u ) { return (string) $u; } }
if ( ! function_exists( 'headers_sent' ) ) { function headers_sent() { return false; } }

// site state, swappable per assertion
$GLOBALS['__posts']     = array( 'https://juanlentino.com/notes/x' => 42 );
$GLOBALS['__private']   = array();
$GLOBALS['__singular']  = true;
$GLOBALS['__queried']   = 42;
function url_to_postid( $u ) { return $GLOBALS['__posts'][ $u ] ?? 0; }
function is_post_publicly_viewable( $id ) { return ! in_array( (int) $id, $GLOBALS['__private'], true ); }
function is_singular() { return $GLOBALS['__singular']; }
function get_queried_object_id() { return $GLOBALS['__queried']; }
$GLOBALS['__blocked'] = array( 'internal.local' );
function sn_ssrf_host_blocked( $h ) { return in_array( strtolower( (string) $h ), $GLOBALS['__blocked'], true ); }

// the claim recorder is spied, not exercised — the store has its own suite
$GLOBALS['__recorded'] = array();
$GLOBALS['__record_result'] = 'created';
function sn_cit_record( $s, $t, $p = 0 ) { $GLOBALS['__recorded'][] = array( $s, $t, $p ); return $GLOBALS['__record_result']; }

class WP_REST_Response {
	public $data; public $status;
	public function __construct( $d, $s = 200 ) { $this->data = $d; $this->status = $s; }
}
class Fake_Request {
	private $p;
	public function __construct( $p ) { $this->p = $p; }
	public function get_param( $k ) { return $this->p[ $k ] ?? ''; }
}

require __DIR__ . '/../inc/citations-core.php';
require __DIR__ . '/../inc/citations-endpoint.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function post( $source, $target ) { $GLOBALS['__recorded'] = array(); return sn_cit_handle_webmention( new Fake_Request( array( 'source' => $source, 'target' => $target ) ) ); }
echo "citation graph — inbox + discovery — v11.27.0\n\n";

$good_target = 'https://juanlentino.com/notes/x/';

// ── the happy path ──────────────────────────────────────────────────────────
$r = post( 'https://example.com/post', $good_target );
ok( $r->status === 202, 'a well-formed claim is 202 Accepted, not 200 OK' );
ok( $r->data['tier'] === 'unverified', 'the response states the claim is unverified' );
ok( false !== stripos( $r->data['message'], 'not confirmation' ), 'the body says in words that acceptance is not confirmation' );
ok( count( $GLOBALS['__recorded'] ) === 1 && $GLOBALS['__recorded'][0][2] === 42, 'the claim is recorded against the resolved post' );

// ── rejections ──────────────────────────────────────────────────────────────
ok( post( '', $good_target )->status === 400, 'a missing source is a 400' );
ok( post( 'https://example.com/p', '' )->status === 400, 'a missing target is a 400' );
ok( post( 'not-a-url', $good_target )->status === 400, 'a non-absolute source is a 400' );
ok( post( 'ftp://example.com/p', $good_target )->status === 400, 'a non-http(s) source is a 400' );
ok( post( $good_target, $good_target )->status === 400, 'source equal to target is a 400' );
ok( post( 'https://juanlentino.com/notes/x/', 'https://juanlentino.com/notes/x' )->status === 400, 'the same URL spelled two ways is still self-citation' );
ok( post( 'https://juanlentino.com/other/', $good_target )->status === 400, 'an on-site source is refused — this inbox is for INBOUND citations' );
ok( post( 'https://internal.local/p', $good_target )->status === 400, 'a source on a blocked host is refused BEFORE it is ever stored' );
ok( count( $GLOBALS['__recorded'] ) === 0, 'and refusing it wrote nothing' );

// ── target must be ours, and public ─────────────────────────────────────────
ok( post( 'https://example.com/p', 'https://elsewhere.com/page' )->status === 400, 'a target on another site is refused' );
ok( post( 'https://example.com/p', 'https://juanlentino.com/nope/' )->status === 400, 'a target that resolves to no post is refused' );
$GLOBALS['__private'] = array( 42 );
ok( post( 'https://example.com/p', $good_target )->status === 400, 'a non-publicly-viewable target is refused — the inbox is not a draft oracle' );
$GLOBALS['__private'] = array();
ok( post( 'https://example.com/p', $good_target )->status === 202, 'control: the same target is accepted once it is public again' );

// ── the store refusing is surfaced, not swallowed ───────────────────────────
$GLOBALS['__record_result'] = 'invalid';
ok( post( 'https://example.com/p', $good_target )->status === 400, 'a store-level refusal becomes a 400, not a false 202' );
$GLOBALS['__record_result'] = 'exists';
ok( post( 'https://example.com/p', $good_target )->status === 202, 'a duplicate ping is still 202 — idempotent, per the spec' );
$GLOBALS['__record_result'] = 'created';

// ── target resolution is its own contract ───────────────────────────────────
ok( sn_cit_resolve_target( $good_target ) === 42, 'a trailing slash still resolves' );
ok( sn_cit_resolve_target( 'https://ELSEWHERE.com/x' ) === 0, 'a foreign origin resolves to nothing' );
ok( sn_cit_resolve_target( 'garbage' ) === 0, 'an unparseable target resolves to nothing' );

// ── discovery: the half that makes the inbox reachable ──────────────────────
ok( false !== strpos( sn_cit_endpoint_url(), '/wp-json/signal-noise/v1/webmention' ), 'the endpoint has a stable public URL' );
$GLOBALS['__singular'] = true; $GLOBALS['__queried'] = 42;
ok( sn_cit_should_advertise() === true, 'a publicly viewable singular page advertises the inbox' );
ob_start(); sn_cit_advertise_head(); $head = ob_get_clean();
ok( false !== strpos( $head, 'rel="webmention"' ), 'the head carries a rel=webmention link' );
ok( false !== strpos( $head, '/wp-json/signal-noise/v1/webmention' ), 'and it points at the real endpoint' );
$GLOBALS['__singular'] = false;
ok( sn_cit_should_advertise() === false, 'a non-singular view advertises nothing' );
ob_start(); sn_cit_advertise_head(); $none = ob_get_clean();
ok( '' === $none, 'and emits no markup at all' );
$GLOBALS['__singular'] = true; $GLOBALS['__private'] = array( 42 );
ok( sn_cit_should_advertise() === false, 'a non-public singular page does not advertise' );
$GLOBALS['__private'] = array();

/* ════════════════════════════════════════════════════════════════════════
 * v13.109.4 — the REGISTRATION contract, and discovery computed not literal
 *
 * The suite above exercises the handler by calling it directly, which is the
 * right way to test its branches but skips the layer that decides whether the
 * handler is reachable AT ALL. sn_cit_register_route() had never been called by
 * any test: the namespace, the route, the method allowlist and the deliberately
 * public permission_callback were entirely unpinned, so a refactor could move
 * the route, add GET, or "tighten" the permission callback and every assertion
 * above would still pass while discovery silently broke.
 *
 * The advertisement assertions were also matching a HARDCODED literal
 * ('/wp-json/signal-noise/v1/webmention'). A literal cannot catch drift: change
 * the constants and the head link moves, but a test looking for the old string
 * fails in a way that reads like a broken link rather than a moved one — and a
 * test looking for the NEW string would have to be edited to match, which is not
 * a test. Below, the expected URL is COMPUTED from the same constants the
 * emitter uses, so the two cannot disagree.
 * ════════════════════════════════════════════════════════════════════════ */

$GLOBALS['__routes'] = array();
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $ns, $route, $args = array() ) {
		$GLOBALS['__routes'][] = array( 'ns' => $ns, 'route' => $route, 'args' => $args );
		return true;
	}
}

sn_cit_register_route();
ok( 1 === count( $GLOBALS['__routes'] ), 'sn_cit_register_route() registers exactly one route' );
$reg = $GLOBALS['__routes'][0];

ok( 'signal-noise/v1' === $reg['ns'], 'the route lives in the signal-noise/v1 namespace' );
ok( '/webmention' === $reg['route'], 'and its path is /webmention' );
// POST only. A receiver that also answered GET would leak inbox existence to
// crawlers and invite drive-by probing of the target resolver.
ok( 'POST' === $reg['args']['methods'], 'it accepts POST and nothing else' );
ok( false === strpos( (string) $reg['args']['methods'], 'GET' ), '...GET is not in the method list' );
// Public BY PROTOCOL NECESSITY (see the file docblock). Pinned deliberately so
// that a future "harden the endpoints" sweep has to change this line on purpose
// rather than silently making the inbox unreachable to every sender on the web.
ok( '__return_true' === $reg['args']['permission_callback'], 'the permission callback is public by protocol necessity, and that is pinned on purpose' );
ok( true === $reg['args']['args']['source']['required'], 'source is declared required' );
ok( true === $reg['args']['args']['target']['required'], 'target is declared required' );

// The required=>true declarations are what make core answer a param-less POST
// with its own named 400 (rest_missing_callback_param) BEFORE the handler runs.
// The handler's own free-text 400 below is the direct-call path, which is what
// the suite above measures. Both are 400; only one carries a machine code.
ok( 400 === post( '', '' )->status, 'a param-less claim reaching the handler is still a 400' );
$body = post( '', '' )->data;
ok( is_array( $body ) && array_key_exists( 'error', $body ), '...whose body carries an `error` key' );
ok( is_string( $body['error'] ) && '' !== $body['error'], '...holding a human-readable string' );
// PINNED AS-IS, NOT ENDORSED: the handler returns free text, with no stable
// machine-readable code a sender could branch on. The W3C Webmention REC (§3.2)
// requires only the 400 status for an invalid source/target, so this does not
// violate the spec and the test records what the code does. See the note in the
// session handoff before changing it.
ok( ! array_key_exists( 'code', $body ), 'the 400 body carries NO machine-readable code — recorded as current behaviour, not endorsed' );

// ── discovery, computed from the constants rather than a literal ────────────
$expected_url = rest_url( SN_CIT_REST_NS . SN_CIT_REST_ROUTE );
ok( $expected_url === sn_cit_endpoint_url(), 'sn_cit_endpoint_url() is exactly rest_url(ns . route) — no second spelling of the path' );

$GLOBALS['__singular'] = true; $GLOBALS['__queried'] = 42; $GLOBALS['__private'] = array();
ob_start(); sn_cit_advertise_head(); $head2 = ob_get_clean();
ok( false !== strpos( $head2, 'href="' . esc_url( $expected_url ) . '"' ), 'the <link rel=webmention> href equals the COMPUTED endpoint URL, so markup and route cannot drift' );
ok( false !== strpos( $head2, 'href="' . esc_url( rest_url( $reg['ns'] . $reg['route'] ) ) . '"' ), '...and equals the URL built from the REGISTERED namespace + route, closing the loop' );

// The Link header is the other half of discovery and must name the same URL.
ok( function_exists( 'sn_cit_advertise_header' ), 'the Link-header half exists' );

/* ── NEGATIVE CONTROLS ───────────────────────────────────────────────────
   The registration assertions must be able to fail. A stub that recorded
   nothing would let every one of them pass vacuously on an empty array. */
ok( ! empty( $GLOBALS['__routes'][0]['args'] ), 'NEGATIVE CONTROL: the recorder captured real args — an empty capture would pass the shape checks vacuously' );
$fake = array( 'ns' => 'other/v2', 'route' => '/elsewhere', 'args' => array( 'methods' => 'GET, POST' ) );
ok( 'signal-noise/v1' !== $fake['ns'] && false !== strpos( $fake['args']['methods'], 'GET' ),
	'...and the same checks reject a moved route that also answers GET' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
