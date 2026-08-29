<?php
/**
 * Standalone tests for the did:web DID document (verifiable-provenance D1).
 * @since plugin v9.23.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_PROV_DID_TEST', true );

ob_start(); // buffer so send()'s real header() doesn't warn after PASS lines

if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'status_header' ) ) { function status_header( $c ) { $GLOBALS['__status'] = (int) $c; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
// v10.77.0: the key mirror reads key history and the next-key commitment from
// options. Without this stub the suite fatals BEFORE its first assertion — and a
// suite that dies before asserting is not a passing suite, which is why CI
// treats a missing summary line as an error rather than a skip.
if ( ! function_exists( 'get_option' ) ) { function get_option( $n, $d = false ) { return $d; } }
// Mirrors inc/provenance-webhook.php's resolver: constant first, then option.
// Called UNGUARDED from inc/provenance-did.php on purpose — a missing resolver
// must fatal here rather than degrade to the hardcoded default in silence.
if ( ! function_exists( 'sn_prov_config' ) ) {
	function sn_prov_config( $const, $option ) {
		if ( defined( $const ) ) { return (string) constant( $const ); }
		return (string) get_option( $option, '' );
	}
}
// stub the plugin's pubkey accessor: a deterministic 32-byte Ed25519 public key
$GLOBALS['__pub'] = base64_encode( str_repeat( "\x01", 32 ) );
if ( ! function_exists( 'sn_prov_pubkey_b64' ) ) { function sn_prov_pubkey_b64() { return $GLOBALS['__pub']; } }

require __DIR__ . '/../inc/provenance-did.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "provenance did:web — v9.23.0\n\n";

// base64url: url-safe alphabet, no padding
ok( strpbrk( sn_prov_base64url( "\xfb\xff\xff\xff\xff" ), '+/=' ) === false, 'base64url is url-safe + unpadded (no + / =)' );
ok( sn_prov_base64url( '' ) === '', 'base64url of empty is empty' );

// the DID document
$doc = sn_prov_did_document();
ok( ( $doc['id'] ?? '' ) === 'did:web:juanlentino.com', 'DID id is did:web:<host>' );
$vm = $doc['verificationMethod'][0] ?? array();
ok( ( $vm['type'] ?? '' ) === 'JsonWebKey2020', 'verificationMethod is a JsonWebKey2020' );
ok( ( $vm['publicKeyJwk']['kty'] ?? '' ) === 'OKP' && ( $vm['publicKeyJwk']['crv'] ?? '' ) === 'Ed25519', 'JWK is OKP/Ed25519' );
ok( ( $vm['publicKeyJwk']['x'] ?? '' ) === sn_prov_base64url( str_repeat( "\x01", 32 ) ), 'JWK x is base64url of the 32-byte key' );
ok( ( $vm['id'] ?? '' ) === 'did:web:juanlentino.com#prov-key-1' && in_array( 'did:web:juanlentino.com#prov-key-1', $doc['assertionMethod'], true ), 'key id is referenced in assertionMethod' );

// off-ledger key mirror: exact key, id, and raw-key SHA-256 fingerprint.
$key_doc = sn_prov_key_document();
$key = $key_doc['keys'][0] ?? array();
ok( ( $key_doc['schema'] ?? '' ) === 'sn-provenance-keys-v2', 'key mirror has a versioned schema (v2 since v10.77.0: history + next-key commitment)' );
ok( ( $key_doc['domain'] ?? '' ) === 'juanlentino.com', 'key mirror pins the site domain' );
ok( ( $key['id'] ?? '' ) === 'sn-ed25519-2026-07', 'key mirror publishes the stable key id' );
ok( ( $key['public_key_base64'] ?? '' ) === $GLOBALS['__pub'], 'key mirror publishes the exact configured key' );
ok( ( $key['sha256_fingerprint'] ?? '' ) === hash( 'sha256', str_repeat( "\x01", 32 ) ), 'key mirror fingerprint hashes the raw 32-byte key' );

// no key configured → null → 404
$GLOBALS['__pub'] = '';
ok( sn_prov_did_document() === null, 'no pubkey → null document' );
$GLOBALS['__status'] = 0; sn_prov_did_send();
ok( $GLOBALS['__status'] === 404, 'send() with no key → 404' );
$GLOBALS['__pub'] = base64_encode( str_repeat( "\x01", 32 ) );

// wrong key length → null (Ed25519 pubkey must be 32 bytes)
$GLOBALS['__pub'] = base64_encode( str_repeat( "\x01", 20 ) );
ok( sn_prov_did_document() === null, 'non-32-byte key → null' );
$GLOBALS['__pub'] = base64_encode( str_repeat( "\x01", 32 ) );

// request matcher
ok( sn_prov_did_is_request( '/.well-known/did.json' ) === true, 'matches /.well-known/did.json' );
ok( sn_prov_did_is_request( '/.well-known/did.json?x=1' ) === true, 'matches with a query string' );
ok( sn_prov_did_is_request( '/did.json' ) === false, 'rejects /did.json outside .well-known' );
ok( sn_prov_keys_is_request( '/.well-known/provenance-keys.json' ) === true, 'matches the provenance key mirror route' );
ok( sn_prov_keys_is_request( '/.well-known/provenance-keys.json?x=1' ) === true, 'key mirror route permits a query string' );
ok( sn_prov_keys_is_request( '/provenance-keys.json' ) === false, 'rejects key mirror outside .well-known' );

// send emits 200 + valid JSON
$GLOBALS['__status'] = 0; ob_start(); sn_prov_did_send(); $out = ob_get_clean();
ok( $GLOBALS['__status'] === 200, 'send() with a key → 200' );
ok( is_array( json_decode( $out, true ) ), 'send() emits valid JSON' );
$GLOBALS['__status'] = 0; ob_start(); sn_prov_keys_send(); $keys_out = ob_get_clean();
ok( $GLOBALS['__status'] === 200, 'key mirror send() with a key → 200' );
ok( is_array( json_decode( $keys_out, true ) ), 'key mirror send() emits valid JSON' );

$report = ob_get_clean(); echo $report;
// The sn_prov_config stub above mirrors a function this suite does not load.
// Nothing else would notice if the real resolver changed precedence, so pin the
// SOURCE: constant first, then option. A stub that silently stops matching the
// thing it stands in for is worse than no stub.
$did_src = (string) file_get_contents( __DIR__ . '/../inc/provenance-webhook.php' );
ok(
	preg_match( '/function sn_prov_config\([^)]*\)\s*\{\s*if \( defined\( \$const \) \) \{\s*return \(string\) constant\( \$const \);/', $did_src ) === 1,
	'the real sn_prov_config still resolves CONSTANT first (the stub here mirrors it)'
);
ok(
	preg_match( '/return \(string\) get_option\( \$option, .. \);\s*\}/', $did_src ) === 1,
	'and falls back to the OPTION (stub parity for a project function the WP stub sweep does not cover)'
);

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
