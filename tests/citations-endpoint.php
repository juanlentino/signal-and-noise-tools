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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
