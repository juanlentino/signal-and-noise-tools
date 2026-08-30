<?php
/**
 * Standalone tests for the key-rotation PRODUCER (inc/provenance-rotation.php).
 *
 * Everything before this shipped the DETECTORS — /verify resolving by the key a
 * record names, the sweep's signing_key_unpublished leg, the DID naming retired
 * keys. They all describe a rotation that nothing could perform: the options
 * sn_prov_key_history and sn_prov_next_key_commitment were read and never
 * written. This is the writer.
 *
 * THE BOUNDARY, stated once: no function here ever sees a PRIVATE key. The
 * signing key lives in a Cloudflare Worker secret; the plugin holds only public
 * bytes. Everything below operates on public material and published documents.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_PROV_DID_TEST', true );

$GLOBALS['__options'] = array();
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'status_header' ) ) { function status_header( $c ) {} }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $n ] : $d; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $n, $v ) { $GLOBALS['__options'][ $n ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $n ) { unset( $GLOBALS['__options'][ $n ] ); return true; }
}
if ( ! function_exists( 'sn_prov_config' ) ) {
	function sn_prov_config( $const, $option ) {
		if ( defined( $const ) ) { return (string) constant( $const ); }
		return (string) get_option( $option, '' );
	}
}
$GLOBALS['__pub'] = base64_encode( str_repeat( "\x01", 32 ) );
if ( ! function_exists( 'sn_prov_pubkey_b64' ) ) { function sn_prov_pubkey_b64() { return $GLOBALS['__pub']; } }

require __DIR__ . '/../inc/provenance-did.php';
require __DIR__ . '/../inc/provenance-rotation.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "provenance key rotation — the producer\n";

$NEXT_RAW  = str_repeat( "\x02", 32 );
$NEXT_B64  = base64_encode( $NEXT_RAW );
$NEXT_HASH = hash( 'sha256', $NEXT_RAW );

/* ── 1. COMMIT: hash it ourselves, never accept a hash ──────────────── */
echo "\nGroup: committing to the next key\n";
$GLOBALS['__options'] = array();
$r = sn_prov_commit_next_key( $NEXT_B64, '2026-09-01' );
ok( true === $r['ok'], 'committing to a valid 32-byte public key succeeds' );
ok( $NEXT_HASH === $r['commitment'], 'the commitment is the sha256 WE computed from the key bytes' );
$stored = sn_prov_next_key_commitment();
ok( is_array( $stored ) && $NEXT_HASH === $stored['value'] && 'sha256' === $stored['algorithm'],
	'and it is readable back through the published reader, algorithm named' );
ok( '2026-09-01' === $stored['committed_at'], 'the commit date is recorded' );

// The caller hands us a KEY, never a digest. Accepting a digest would let a
// typo commit us to something nobody holds — unfulfillable forever.
$r = sn_prov_commit_next_key( $NEXT_HASH, '2026-09-01' );
ok( false === $r['ok'] && 'not-a-public-key' === $r['code'],
	'a sha256 hex digest is REFUSED as input — the producer hashes, the caller does not' );

$GLOBALS['__options'] = array();
$r = sn_prov_commit_next_key( $GLOBALS['__pub'], '2026-09-01' );
ok( false === $r['ok'] && 'commitment-not-safe' === $r['code'],
	'committing to the CURRENT key is refused — a commitment to the key already in use commits to nothing' );

$GLOBALS['__options'] = array( 'sn_prov_key_history' => array(
	array( 'id' => 'sn-old', 'public_key_base64' => base64_encode( str_repeat( "\x03", 32 ) ) ),
) );
$r = sn_prov_commit_next_key( base64_encode( str_repeat( "\x03", 32 ) ), '2026-09-01' );
ok( false === $r['ok'], 'committing to a RETIRED key is refused too — rotation must go forward' );

/* ── 2. ROTATE: the reveal must match, and history must be kept ─────── */
echo "\nGroup: performing the rotation\n";
$GLOBALS['__options'] = array();
sn_prov_commit_next_key( $NEXT_B64, '2026-09-01' );

$r = sn_prov_rotate_to( base64_encode( str_repeat( "\x09", 32 ) ), 'sn-ed25519-2026-09', '2026-09-15' );
ok( false === $r['ok'] && 'reveal-mismatch' === $r['code'],
	'a key that does NOT hash to the commitment is refused — this is the whole point of committing' );
ok( is_array( sn_prov_next_key_commitment() ), 'and the failed attempt did not consume the commitment' );

$before_active = sn_prov_pubkey_b64();
$r = sn_prov_rotate_to( $NEXT_B64, 'sn-ed25519-2026-09', '2026-09-15' );
ok( true === $r['ok'], 'the committed key rotates in' );
ok( 'sn-ed25519-2026-09' === get_option( 'sn_prov_pubkey_id', '' ), 'the new key id is written' );
ok( '2026-09-15' === get_option( 'sn_prov_key_introduced_at', '' ), 'and its introduction date' );
ok( $NEXT_B64 === get_option( 'sn_prov_pubkey_b64', '' ), 'the new PUBLIC key becomes active' );

$hist = sn_prov_key_history();
ok( 1 === count( $hist ), 'the outgoing key is kept, not dropped' );
ok( $before_active === $hist[0]['public_key_base64'], 'history carries the OUTGOING key bytes verbatim' );
ok( '2026-09-15' === $hist[0]['valid_until'], 'the retired key gets a CLOSED window ending the day it was replaced' );
ok( 'sn-ed25519-2026-07' === $hist[0]['id'], 'under the id it was published as' );
ok( null === sn_prov_next_key_commitment(), 'the commitment is CONSUMED — it described this rotation and cannot describe another' );

/* ── 3. PREFLIGHT: refuse what cannot take effect ───────────────────── */
echo "\nGroup: preflight refuses a rotation that could not take effect\n";
$GLOBALS['__options'] = array();
$p = sn_prov_rotation_preflight();
ok( false === $p['ready'] && in_array( 'no-commitment', $p['blockers'], true ),
	'with no commitment published there is nothing to rotate to' );

define( 'SN_PROV_PUBKEY_B64', 'constant-shadow' );
$p = sn_prov_rotation_preflight();
ok( in_array( 'active-key-is-a-constant', $p['blockers'], true ),
	'a wp-config CONSTANT holding the active key BLOCKS rotation: the option this writes would be shadowed, so the rotation would look applied and change nothing' );
$r = sn_prov_rotate_to( $NEXT_B64, 'sn-x', '2026-09-15' );
ok( false === $r['ok'] && 'active-key-is-a-constant' === $r['code'],
	'and rotate_to REFUSES rather than half-applying — writing history for a key change that never happens is worse than not rotating' );

/* ── 4. THE AUTOMATIC PATH: nothing is typed by hand ────────────────── */
echo "\nGroup: fetching the successor from the Worker and applying it\n";

$GLOBALS['__http'] = array();
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args ) { $GLOBALS['__http'][] = array( $url, $args ); return $GLOBALS['__resp']; }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error_Stub; } }
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 0; } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; } }
if ( ! function_exists( 'sn_prov_worker_url' ) ) { function sn_prov_worker_url() { return 'https://w.example'; } }
if ( ! function_exists( 'sn_prov_hmac_secret' ) ) { function sn_prov_hmac_secret() { return 'shh'; } }

// The id is DERIVED from the rotation date, not typed: a hand-typed id is one
// more thing that can disagree with what every record will carry in pubkey_id.
ok( 'sn-ed25519-2027-03' === sn_prov_next_key_id_for( '2027-03-14' ), 'the new key id is derived from the rotation month' );
ok( '' === sn_prov_next_key_id_for( 'nonsense' ), 'an unparseable date derives NO id rather than a wrong one' );

$GLOBALS['__resp'] = array( 'code' => 200, 'body' => json_encode( array(
	'ok' => true, 'configured' => true,
	'public_key_base64' => $NEXT_B64, 'sha256_commitment' => $NEXT_HASH,
) ) );
$GLOBALS['__options'] = array();
$f = sn_prov_fetch_next_key();
ok( true === $f['ok'] && $NEXT_B64 === $f['public_key_base64'], 'the successor is fetched from the Worker' );
ok( 'sha256=' . hash_hmac( 'sha256', $GLOBALS['__http'][0][1]['body'], 'shh' ) === $GLOBALS['__http'][0][1]['headers']['X-SN-Signature'],
	'the request is HMAC-signed like every other Worker call' );
ok( false !== strpos( $GLOBALS['__http'][0][0], '/next-key' ), 'and goes to the /next-key route' );

// The Worker's hash is NEVER trusted as the commitment — we recompute it from
// the key bytes. A Worker that lied, or a corrupted response, would otherwise
// publish a commitment nothing can fulfil.
$GLOBALS['__resp'] = array( 'code' => 200, 'body' => json_encode( array(
	'ok' => true, 'configured' => true,
	'public_key_base64' => $NEXT_B64, 'sha256_commitment' => str_repeat( 'f', 64 ),
) ) );
$GLOBALS['__options'] = array();
$r = sn_prov_stage_commitment( '2026-09-01' );
ok( true === $r['ok'] && $NEXT_HASH === $r['commitment'],
	'the commitment is recomputed from the KEY BYTES — the Worker\'s own hash is corroboration, never the source' );

$GLOBALS['__resp'] = array( 'code' => 200, 'body' => json_encode( array( 'ok' => true, 'configured' => false ) ) );
$GLOBALS['__options'] = array();
$r = sn_prov_stage_commitment( '2026-09-01' );
ok( false === $r['ok'] && 'no-successor-staged' === $r['code'], 'nothing staged on the Worker is a clear refusal, not a crash' );

$GLOBALS['__resp'] = array( 'code' => 500, 'body' => 'nope' );
$r = sn_prov_stage_commitment( '2026-09-01' );
ok( false === $r['ok'] && 'worker-unreachable' === $r['code'], 'a Worker failure never half-applies' );
ok( null === sn_prov_next_key_commitment(), 'and publishes no commitment' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
