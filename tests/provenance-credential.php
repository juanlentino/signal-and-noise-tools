<?php
/**
 * Standalone tests for the per-Note Verifiable Credential (verifiable-provenance D1).
 * @since plugin v9.23.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_PROV_CRED_TEST', true );
define( 'SN_PROV_DID_TEST', true );

if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'get_the_title' ) ) { function get_the_title( $p ) { return 'Some Note'; } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $p ) { return 'https://juanlentino.com/notes/some-note/'; } }
if ( ! function_exists( 'get_the_date' ) ) { function get_the_date( $f, $p ) { return '2026-07-01T14:32:00-04:00'; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
$GLOBALS['__post'] = (object) array( 'post_status' => 'publish', 'post_password' => '' );
if ( ! function_exists( 'get_post' ) ) { function get_post( $id = 0 ) { return $GLOBALS['__post']; } }
$GLOBALS['__pub'] = base64_encode( str_repeat( "\x01", 32 ) );
if ( ! function_exists( 'sn_prov_pubkey_b64' ) ) { function sn_prov_pubkey_b64() { return $GLOBALS['__pub']; } }

// Real canonicalization stand-ins (deterministic; the builder self-checks against them).
if ( ! function_exists( 'sn_prov_canonical_json' ) ) { function sn_prov_canonical_json( array $d ) { ksort( $d ); return json_encode( $d, JSON_UNESCAPED_SLASHES ); } }
if ( ! function_exists( 'sn_prov_content_hash' ) ) { function sn_prov_content_hash( $c ) { return hash( 'sha256', (string) $c ); } }

// A fake chain: build a signed, self-consistent commit (content_hash === sha256(canonical(payload))).
function sn_test_commit( $version, $status, $overrides = array() ) {
	$payload = array( 'title' => 'Some Note', 'version' => $version, 'published_at' => '2026-07-01T14:32:00-04:00' );
	$canon   = sn_prov_canonical_json( $payload );
	return array_merge( array(
		'version'       => $version,
		'payload'       => $payload,
		'content_hash'  => sn_prov_content_hash( $canon ),
		'signature'     => base64_encode( str_repeat( "\x09", 64 ) ),
		'pubkey_id'     => 'sn-2026',
		'status'        => $status,
		'bitcoin_txid'  => str_repeat( 'a', 64 ),
		'bitcoin_block' => 'confirmed' === $status ? 861000 : 0,
	), $overrides );
}
$GLOBALS['__chain'] = array( sn_test_commit( 1, 'confirmed' ), sn_test_commit( 2, 'pending' ) );
if ( ! function_exists( 'sn_prov_get_chain' ) ) { function sn_prov_get_chain( $id ) { return $GLOBALS['__chain']; } }

require __DIR__ . '/../inc/provenance-did.php';
require __DIR__ . '/../inc/provenance-credential.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "provenance credential — v9.23.0\n\n";

// commit selection: latest by default, specific by ?v
ok( ( sn_prov_cred_select_commit( $GLOBALS['__chain'], null )['version'] ?? 0 ) === 2, 'default selects the latest commit' );
ok( ( sn_prov_cred_select_commit( $GLOBALS['__chain'], 1 )['version'] ?? 0 ) === 1, 'v=1 selects that version' );
ok( sn_prov_cred_select_commit( $GLOBALS['__chain'], 9 ) === null, 'unknown version → null' );

// the VC (latest = v2, pending)
$vc = sn_prov_credential( 7, null );
ok( ( $vc['type'][0] ?? '' ) === 'VerifiableCredential' && in_array( 'AuthorshipCredential', $vc['type'], true ), 'type includes VerifiableCredential + AuthorshipCredential' );
ok( ( $vc['issuer'] ?? '' ) === 'did:web:juanlentino.com', 'issuer is the DID' );
ok( ( $vc['credentialSubject']['type'] ?? '' ) === 'CreativeWork' && ( $vc['credentialSubject']['author']['type'] ?? '' ) === 'Person', 'subject is a CreativeWork authored by a Person' );
ok( strpos( (string) ( $vc['credentialSubject']['author']['identifier'] ?? '' ), 'orcid.org' ) !== false, 'author carries the ORCID identifier' );
ok( ( $vc['evidence'][0]['anchor']['status'] ?? '' ) === 'pending' && ( $vc['evidence'][0]['anchor']['txid'] ?? '' ) === str_repeat( 'a', 64 ), 'evidence reflects the pending anchor + txid' );
ok( strpos( (string) ( $vc['evidence'][0]['contentHash'] ?? '' ), 'sha256:' ) === 0, 'contentHash is sha256-prefixed' );
ok( ( $vc['proof']['cryptosuite'] ?? '' ) === 'sn-ed25519-canonical-2026', 'proof names the site cryptosuite' );
ok( ( $vc['proof']['verificationMethod'] ?? '' ) === 'did:web:juanlentino.com#prov-key-1', 'proof points at the DID key' );
ok( ( $vc['proof']['proofValue'] ?? '' ) === base64_encode( str_repeat( "\x09", 64 ) ), 'proofValue is the stored signature' );
// signedPayloadB64 decodes to the exact canonical whose sha256 is the content_hash
$decoded = base64_decode( (string) ( $vc['proof']['signedPayloadB64'] ?? '' ), true );
ok( $decoded !== false && sn_prov_content_hash( $decoded ) === $GLOBALS['__chain'][1]['content_hash'], 'signedPayloadB64 is the canonical bytes whose sha256 == content_hash' );

// confirmed version
$vc1 = sn_prov_credential( 7, 1 );
ok( ( $vc1['evidence'][0]['anchor']['status'] ?? '' ) === 'confirmed' && ( $vc1['evidence'][0]['anchor']['block'] ?? 0 ) === 861000, 'v=1 shows the confirmed block' );

// unsigned commit → null (never emit a VC with an empty proof)
$GLOBALS['__chain'] = array( sn_test_commit( 1, 'unanchored', array( 'signature' => '' ) ) );
ok( sn_prov_credential( 7, null ) === null, 'unsigned commit → no VC' );

// self-check failure (payload/content_hash drift) → null
$GLOBALS['__chain'] = array( sn_test_commit( 1, 'confirmed', array( 'content_hash' => str_repeat( 'f', 64 ) ) ) );
ok( sn_prov_credential( 7, null ) === null, 'content_hash mismatch → no VC (never emit unverifiable)' );

// no chain → null
$GLOBALS['__chain'] = array();
ok( sn_prov_credential( 7, null ) === null, 'no chain → null' );

// visibility gate: password-protected / non-public Notes never emit a VC (signedPayloadB64 embeds content)
$GLOBALS['__chain'] = array( sn_test_commit( 1, 'confirmed' ) ); // a fully valid, signed chain — the ONLY reason for null is visibility
$GLOBALS['__post']  = (object) array( 'post_status' => 'publish', 'post_password' => 'secret' );
ok( sn_prov_credential( 7, null ) === null, 'password-protected Note → no VC (content-leak guard)' );
$GLOBALS['__post']  = (object) array( 'post_status' => 'draft', 'post_password' => '' );
ok( sn_prov_credential( 7, null ) === null, 'non-published Note → no VC' );
$GLOBALS['__post']  = (object) array( 'post_status' => 'publish', 'post_password' => '' ); // reset to public for the REST section below

// --- REST callback: uid resolves → VC; unknown/unsigned → 404 ---
if ( ! function_exists( 'sn_prov_post_by_uid' ) ) { function sn_prov_post_by_uid( $uid ) { return 'known' === $uid ? 7 : 0; } }
class SN_Cred_Req { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return $this->p[ $k ] ?? null; } }
if ( ! class_exists( 'WP_REST_Response' ) ) { class WP_REST_Response { public $data; public $status; public $headers = array(); function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; } function header( $k, $v ) { $this->headers[ $k ] = $v; } } }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public $code; public $data; function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->data = $d; } } }
$GLOBALS['__chain'] = array( sn_test_commit( 1, 'confirmed' ) );
$r = sn_prov_cred_rest( new SN_Cred_Req( array( 'uid' => 'known' ) ) );
ok( $r instanceof WP_REST_Response && ( $r->data['type'][0] ?? '' ) === 'VerifiableCredential', 'REST returns the VC for a known uid' );
ok( ( $r->headers['Content-Type'] ?? '' ) === 'application/vc+ld+json', 'REST sets the vc+ld+json content type' );
$e = sn_prov_cred_rest( new SN_Cred_Req( array( 'uid' => 'nope' ) ) );
ok( $e instanceof WP_Error && ( $e->data['status'] ?? 0 ) === 404, 'unknown uid → 404' );
// Oracle-closure (v9.26.1): a KNOWN uid that resolves to a published-but-
// uncredentialed Note (here: password-protected) must return the SAME opaque 404
// as an unknown uid (no distinguishable code/message), so the endpoint isn't an
// existence oracle. No content leaks in either branch; this removes the differential.
$GLOBALS['__post'] = (object) array( 'post_status' => 'publish', 'post_password' => 'secret' ); // uid 'known' → 7 resolves, but the credential is withheld → null
$e2 = sn_prov_cred_rest( new SN_Cred_Req( array( 'uid' => 'known' ) ) );
ok( $e2 instanceof WP_Error && ( $e2->data['status'] ?? 0 ) === 404, 'resolvable-but-uncredentialed Note → 404' );
ok( $e2 instanceof WP_Error && $e instanceof WP_Error && $e2->code === $e->code, 'the two 404 branches are indistinguishable (no existence oracle)' );
$GLOBALS['__post'] = (object) array( 'post_status' => 'publish', 'post_password' => '' ); // reset

// --- manifest advertisement (two entries: did + credential convention) ---
$surf = sn_prov_cred_advertise_surface( array() );
$types = array_column( $surf, 'type' );
ok( in_array( 'did-web', $types, true ) && in_array( 'verifiable-credential', $types, true ), 'advertises did-web + verifiable-credential surfaces' );

// --- verificationProcedure must point at a URL the verifier actually serves ---
//
// LIVE DEFECT (2026-08-08, fixed v10.66.1): every credential advertised
// home_url('/provenance/verify'), which returns 404. The live docket is /verify.
// This is the machine-readable field whose entire job is telling a verifier where
// to go and check the proof — and it pointed at nothing.
//
// The theme fixed the identical URL in its agents manifest back in v10.49.0 (its
// test even reads "never /provenance/verify/"), but the plugin's credential
// emitter was never swept with it. NOTHING PINNED THIS VALUE, which is exactly
// why it drifted — the JS fixtures MIRRORED the wrong URL rather than asserting
// it resolved, so they encoded the bug as expected behaviour.
//
// So this asserts a RELATIONSHIP, not a literal: the advertised path must be one
// sn_prov_verify_is_request() accepts — the router's own authority on what
// /verify means, and the function whose docblock explicitly excludes "the
// unrelated /provenance/verify Page". The two can now only move together.
require_once __DIR__ . '/../inc/provenance-verify.php';
$cred_vp = (string) ( $r->data['proof']['verificationProcedure'] ?? '' );
ok( '' !== $cred_vp, 'the credential advertises a verificationProcedure' );
$vp_path = (string) wp_parse_url( $cred_vp, PHP_URL_PATH );
ok( function_exists( 'sn_prov_verify_is_request' ), 'the verify-request matcher is loadable for this assertion' );
ok( sn_prov_verify_is_request( $vp_path ), 'verificationProcedure resolves to the LIVE /verify docket, not the 404 /provenance/verify Page' );
ok( false === strpos( $vp_path, '/provenance/verify' ), 'it is never the unrelated /provenance/verify path' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
