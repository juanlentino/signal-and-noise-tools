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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
