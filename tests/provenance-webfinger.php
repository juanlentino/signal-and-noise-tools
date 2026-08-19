<?php
/**
 * Standalone tests for WebFinger (RFC 7033) — identity coherence.
 * @since plugin v11.27.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_PROV_DID_TEST', true );
define( 'SN_PROV_WEBFINGER_TEST', true );

ob_start(); // buffer so send()'s real header() cannot interleave with PASS lines

if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'status_header' ) ) { function status_header( $c ) { $GLOBALS['__status'] = (int) $c; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $n, $d = false ) { return $d; } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); } }
// The key is swappable at runtime so the no-key path (did.json 404s) is testable.
$GLOBALS['__pub'] = base64_encode( str_repeat( "\x01", 32 ) );
if ( ! function_exists( 'sn_prov_pubkey_b64' ) ) { function sn_prov_pubkey_b64() { return $GLOBALS['__pub']; } }

require __DIR__ . '/../inc/provenance-did.php';
require __DIR__ . '/../inc/provenance-webfinger.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "provenance WebFinger — v11.27.0\n\n";

// ── the identity ────────────────────────────────────────────────────────────
ok( sn_prov_webfinger_subject() === 'acct:juan@juanlentino.com', 'subject is acct:<name>@<host>' );
$aliases = sn_prov_webfinger_aliases();
ok( in_array( 'did:web:juanlentino.com', $aliases, true ), 'the DID is an alias — the same entity resolves from either direction' );
ok( in_array( 'https://juanlentino.com', $aliases, true ), 'the https identity is an alias, without a trailing slash' );

// ── what the endpoint answers to ────────────────────────────────────────────
ok( sn_prov_webfinger_matches( 'acct:juan@juanlentino.com' ), 'matches the acct: form' );
ok( sn_prov_webfinger_matches( 'ACCT:Juan@JuanLentino.com' ), 'matching folds case (RFC 3986: scheme and host are case-insensitive)' );
ok( sn_prov_webfinger_matches( 'did:web:juanlentino.com' ), 'matches the DID form' );
ok( sn_prov_webfinger_matches( 'https://juanlentino.com' ), 'matches the bare https identity' );
ok( sn_prov_webfinger_matches( 'https://juanlentino.com/' ), 'a trailing slash is not a different identity' );
ok( sn_prov_webfinger_matches( 'http://juanlentino.com' ), 'http and https name the same identity' );

// ── and what it does NOT answer to (the negative control) ───────────────────
ok( ! sn_prov_webfinger_matches( 'acct:someone@example.com' ), 'a foreign acct does not match' );
ok( ! sn_prov_webfinger_matches( 'acct:juan@example.com' ), 'the right name on the wrong host does not match' );
ok( ! sn_prov_webfinger_matches( 'https://example.com' ), 'a foreign origin does not match' );
ok( ! sn_prov_webfinger_matches( '' ), 'an empty resource does not match' );
ok( null === sn_prov_webfinger_document( 'acct:someone@example.com' ), 'an unmatched resource yields no document' );

// ── the link set ────────────────────────────────────────────────────────────
$doc  = sn_prov_webfinger_document( 'acct:juan@juanlentino.com' );
$rels = array_column( $doc['links'], 'rel' );
ok( in_array( 'self', $rels, true ), 'with a key configured, self points at the identity document' );
$self = $doc['links'][ array_search( 'self', $rels, true ) ];
ok( $self['type'] === 'application/did+json', 'self is typed application/did+json' );
ok( $self['href'] === 'https://juanlentino.com/.well-known/did.json', 'self resolves to the SAME did:web document the chain signs with' );
ok( in_array( 'http://webfinger.net/rel/profile-page', $rels, true ), 'the human profile page is linked' );
ok( in_array( 'describedby', $rels, true ), 'the off-ledger key mirror is linked as describedby' );

// ── rel filtering: RFC 7033 §4.3 ────────────────────────────────────────────
$only = sn_prov_webfinger_document( 'acct:juan@juanlentino.com', array( 'self' ) );
ok( count( $only['links'] ) === 1 && $only['links'][0]['rel'] === 'self', 'rel=self returns ONLY the self link' );
$none = sn_prov_webfinger_document( 'acct:juan@juanlentino.com', array( 'http://example.com/rel/nope' ) );
ok( is_array( $none['links'] ) && count( $none['links'] ) === 0, 'an unmatched rel yields an EMPTY link array, not an error (§4.3)' );
ok( $none['subject'] === sn_prov_webfinger_subject(), 'a rel-filtered document still names its subject' );

// ── repeated rel: the parse_str trap this parser exists to avoid ────────────
$q = sn_prov_webfinger_parse_query( '/.well-known/webfinger?resource=acct%3Ajuan%40juanlentino.com&rel=self&rel=describedby' );
ok( $q['resource'] === 'acct:juan@juanlentino.com', 'the resource parameter is urldecoded' );
ok( $q['rels'] === array( 'self', 'describedby' ), 'BOTH rel values survive — parse_str() would have kept only the last' );
$q2 = sn_prov_webfinger_parse_query( '/.well-known/webfinger' );
ok( $q2['resource'] === '' && $q2['rels'] === array(), 'a query-less request parses to empty, not to garbage' );

// ── the path predicate ──────────────────────────────────────────────────────
ok( sn_prov_webfinger_is_request( '/.well-known/webfinger' ), 'the bare path is the endpoint' );
ok( sn_prov_webfinger_is_request( '/.well-known/webfinger?resource=acct:juan@juanlentino.com' ), 'a query string does not change the path' );
ok( sn_prov_webfinger_is_request( '/.well-known/webfinger/' ), 'a trailing slash still routes' );
ok( ! sn_prov_webfinger_is_request( '/.well-known/did.json' ), 'the DID route is NOT claimed by WebFinger' );
ok( ! sn_prov_webfinger_is_request( '/webfinger' ), 'the endpoint lives under /.well-known/ only' );

// ── the wire ────────────────────────────────────────────────────────────────
$GLOBALS['__status'] = 0; ob_start(); sn_prov_webfinger_send( '/.well-known/webfinger' ); $out = ob_get_clean();
ok( $GLOBALS['__status'] === 400, 'a missing resource parameter is a 400 (§4.2), not a 404' );
ok( is_array( json_decode( $out, true ) ), '400 still emits valid JSON' );

$GLOBALS['__status'] = 0; ob_start(); sn_prov_webfinger_send( '/.well-known/webfinger?resource=acct%3Anobody%40example.com' ); $out = ob_get_clean();
ok( $GLOBALS['__status'] === 404, 'an unknown resource is a 404' );

$GLOBALS['__status'] = 0; ob_start(); sn_prov_webfinger_send( '/.well-known/webfinger?resource=acct%3Ajuan%40juanlentino.com' ); $out = ob_get_clean();
ok( $GLOBALS['__status'] === 200, 'the site identity is a 200' );
$parsed = json_decode( $out, true );
ok( is_array( $parsed ) && ( $parsed['subject'] ?? '' ) === 'acct:juan@juanlentino.com', 'the wire body carries the subject' );
ok( strpos( $out, '\/' ) === false, 'hrefs are emitted unescaped (JSON_UNESCAPED_SLASHES)' );

// ── the truthfulness gate: no key ⇒ did.json 404s ⇒ do not link to it ───────
$GLOBALS['__pub'] = '';
ok( null === sn_prov_did_document(), 'control: with no key configured the DID document is null' );
$bare = sn_prov_webfinger_document( 'acct:juan@juanlentino.com' );
$brel = array_column( $bare['links'], 'rel' );
ok( ! in_array( 'self', $brel, true ), 'without a key, WebFinger does NOT link to a did.json that 404s' );
ok( ! in_array( 'describedby', $brel, true ), 'without a key, the key mirror is not linked either' );
ok( in_array( 'http://webfinger.net/rel/profile-page', $brel, true ), 'the profile page survives — it is true regardless of the key' );
ok( $bare['subject'] === 'acct:juan@juanlentino.com', 'the identity still answers; it just claims less' );
$GLOBALS['__pub'] = base64_encode( str_repeat( "\x01", 32 ) );

$report = ob_get_clean(); echo $report;
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
