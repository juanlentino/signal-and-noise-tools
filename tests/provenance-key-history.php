<?php
/**
 * Key history with a future — /.well-known/provenance-keys.json v2 (R1).
 *
 * The row's gate is in its own prose: "the next key committed by hash BEFORE it
 * is ever used". Three properties follow, and each is pinned below:
 *
 *   1. The commitment is a HASH, never a public key. If the next key's bytes
 *      were published up front there would be no commitment at all — the whole
 *      point is that the successor is unknowable until rotation reveals it.
 *   2. A rotation whose revealed key does not hash to the prior commitment is
 *      REJECTED. That is the only thing making the commitment binding.
 *   3. Historical keys keep their validity windows, so anchors signed under a
 *      retired key still verify after rotation. Dropping a retired key silently
 *      invalidates every signature it ever made.
 *
 * Plus the ordering hazard this row introduces: assets/js/prov-verify-core.js
 * reads `keys[0].public_key_base64`. Once the array carries history, index 0
 * being the ACTIVE key is load-bearing — so it is asserted here rather than
 * left to the order the rows happen to be appended in.
 *
 * @since plugin v10.77.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_PROV_DID_TEST', true );

ob_start();

$GLOBALS['__options'] = array();
$GLOBALS['__pub']     = base64_encode( str_repeat( "\x01", 32 ) );

if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'status_header' ) ) { function status_header( $c ) { $GLOBALS['__status'] = (int) $c; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'sn_prov_pubkey_b64' ) ) { function sn_prov_pubkey_b64() { return $GLOBALS['__pub']; } }

if ( ! function_exists( 'sn_prov_config' ) ) {
	// Mirrors inc/provenance-webhook.php: constant first, then option.
	function sn_prov_config( $const, $option ) {
		if ( defined( $const ) ) { return (string) constant( $const ); }
		return (string) get_option( $option, '' );
	}
}

require __DIR__ . '/../inc/provenance-did.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
echo "provenance key history + next-key commitment (v10.77.0)\n\n";

$active_raw  = str_repeat( "\x01", 32 );
$old_raw     = str_repeat( "\x02", 32 );
$next_raw    = str_repeat( "\x03", 32 );
$old_b64     = base64_encode( $old_raw );
$next_b64    = base64_encode( $next_raw );
$next_commit = hash( 'sha256', $next_raw );

/* ═══════════════════════════════════════════════════════════════════
 * 1. THE COMMITMENT IS A HASH, NEVER A KEY
 * ═══════════════════════════════════════════════════════════════════ */

ok( true === sn_prov_commitment_is_safe( $next_commit ),
	'a sha256 hex digest is accepted as a commitment' );

ok( false === sn_prov_commitment_is_safe( $next_b64 ),
	'a BASE64 PUBLIC KEY is rejected as a commitment — publishing the successor defeats the scheme' );

ok( false === sn_prov_commitment_is_safe( bin2hex( $active_raw ) ),
	'the ACTIVE key hex-encoded is rejected — the realistic mistake is pasting a key you already hold' );

// THE LIMIT, asserted so nobody later mistakes absence of a check for presence
// of a guarantee: a sha256 digest and a raw Ed25519 public key are BOTH exactly
// 32 bytes, so hex-encoded they are both 64 chars and are indistinguishable.
// For a key we have never seen there is no test. What makes the scheme hold is
// not this predicate but the compare in sn_prov_rotation_reveal_matches(),
// which goes THROUGH sha256 — see the self-enforcement assertion below.
ok( true === sn_prov_commitment_is_safe( bin2hex( $next_raw ) ),
	'ACKNOWLEDGED LIMIT: hex of an UNKNOWN future key is indistinguishable from a digest and passes the shape check' );

ok( false === sn_prov_commitment_is_safe( '' ) && false === sn_prov_commitment_is_safe( 'not-a-hash' )
	&& false === sn_prov_commitment_is_safe( strtoupper( $next_commit ) ) && false === sn_prov_commitment_is_safe( substr( $next_commit, 0, 63 ) ),
	'empty, malformed, uppercase and short-by-one values are all rejected' );

/* ═══════════════════════════════════════════════════════════════════
 * 2. ROTATION IS BOUND BY THE PRIOR COMMITMENT
 * ═══════════════════════════════════════════════════════════════════ */

ok( true === sn_prov_rotation_reveal_matches( $next_b64, $next_commit ),
	'a revealed key that hashes to the prior commitment is accepted' );

ok( false === sn_prov_rotation_reveal_matches( base64_encode( str_repeat( "\x09", 32 ) ), $next_commit ),
	'REJECTED: a revealed key that does NOT match the prior commitment' );

ok( false === sn_prov_rotation_reveal_matches( $next_b64, hash( 'sha256', $old_raw ) ),
	'REJECTED: the right key against the wrong commitment' );

ok( false === sn_prov_rotation_reveal_matches( base64_encode( 'short' ), $next_commit )
	&& false === sn_prov_rotation_reveal_matches( '!!!not base64!!!', $next_commit )
	&& false === sn_prov_rotation_reveal_matches( '', $next_commit )
	&& false === sn_prov_rotation_reveal_matches( $next_b64, '' ),
	'malformed keys and an absent commitment are refused, never fatal' );

// The scheme is self-enforcing: a commitment that IS the key can never validate
// its own reveal, because the reveal is compared through a hash.
ok( false === sn_prov_rotation_reveal_matches( $next_b64, bin2hex( $next_raw ) ),
	'a commitment holding key BYTES cannot validate its own reveal (the compare goes through sha256)' );

/* ═══════════════════════════════════════════════════════════════════
 * 3. HISTORY KEEPS ITS VALIDITY WINDOWS
 * ═══════════════════════════════════════════════════════════════════ */

$GLOBALS['__options'] = array(
	'sn_prov_key_history' => array(
		array(
			'id'                => 'sn-ed25519-2025-01',
			'public_key_base64' => $old_b64,
			'valid_from'        => '2025-01-04',
			'valid_until'       => '2026-07-09',
		),
		// Junk rows must be dropped, not emitted half-formed.
		array( 'id' => 'no-key-at-all', 'valid_from' => '2024-01-01', 'valid_until' => '2025-01-04' ),
		array( 'id' => 'bad-key', 'public_key_base64' => base64_encode( 'too-short' ), 'valid_from' => '2024-01-01', 'valid_until' => '2025-01-04' ),
	),
	'sn_prov_next_key_commitment' => array( 'value' => $next_commit, 'committed_at' => '2026-08-10' ),
);

$doc = sn_prov_key_document();

ok( 'sn-provenance-keys-v2' === ( $doc['schema'] ?? '' ),
	'the schema version is bumped — the document shape changed for every consumer' );

ok( 2 === count( $doc['keys'] ),
	'exactly the active key and the one VALID historical key are published (junk rows dropped)' );

ok( 'active' === ( $doc['keys'][0]['status'] ?? '' ) && $GLOBALS['__pub'] === ( $doc['keys'][0]['public_key_base64'] ?? '' ),
	'ORDERING GUARD: the ACTIVE key is still keys[0] — prov-verify-core.js reads that index' );

$retired = $doc['keys'][1] ?? array();
ok( 'retired' === ( $retired['status'] ?? '' ) && 'sn-ed25519-2025-01' === ( $retired['id'] ?? '' ),
	'the historical key is published as retired, not dropped' );

ok( '2025-01-04' === ( $retired['valid_from'] ?? '' ) && '2026-07-09' === ( $retired['valid_until'] ?? '' ),
	'the retired key keeps its validity window, so anchors signed under it still verify' );

ok( $old_b64 === ( $retired['public_key_base64'] ?? '' )
	&& hash( 'sha256', $old_raw ) === ( $retired['sha256_fingerprint'] ?? '' ),
	'the retired key publishes its own bytes and its own fingerprint' );

// array_key_exists, NOT ??: the value IS null, and ?? cannot tell a deliberate
// null from an absent field. "Still in use" and "we forgot to say" are different
// claims, and this endpoint is consumed by verifiers that must not guess.
ok( array_key_exists( 'valid_until', $doc['keys'][0] ) && null === $doc['keys'][0]['valid_until'],
	'the active key has an OPEN validity window — valid_until is PRESENT and null, not missing and not a date' );

/* ═══════════════════════════════════════════════════════════════════
 * 4. THE PUBLISHED DOCUMENT LEAKS NOTHING BEYOND PUBLIC PARTS
 * ═══════════════════════════════════════════════════════════════════ */

ok( isset( $doc['next_key_commitment']['value'] ) && $next_commit === $doc['next_key_commitment']['value']
	&& 'sha256' === ( $doc['next_key_commitment']['algorithm'] ?? '' ),
	'the commitment to the next key is published with its algorithm named' );

$GLOBALS['__options']['sn_prov_next_key_commitment'] = array( 'value' => $next_b64, 'committed_at' => '2026-08-10' );
$unsafe = sn_prov_key_document();
ok( ! isset( $unsafe['next_key_commitment'] ),
	'a commitment that is actually a KEY is omitted from the document rather than published' );

$GLOBALS['__options']['sn_prov_next_key_commitment'] = array( 'value' => $next_commit, 'committed_at' => '2026-08-10' );

$json    = json_encode( sn_prov_key_document() );
$allowed = array( 'schema', 'domain', 'keys', 'next_key_commitment', 'id', 'algorithm', 'public_key_base64',
	'sha256_fingerprint', 'status', 'introduced_at', 'valid_from', 'valid_until', 'value', 'committed_at' );
$leaked = array();
preg_match_all( '/"([a-z_0-9]+)"\s*:/', $json, $km );
foreach ( array_unique( $km[1] ) as $k ) {
	if ( ! in_array( $k, $allowed, true ) ) { $leaked[] = $k; }
}
ok( array() === $leaked,
	'the document carries ONLY known public fields (no unexpected key: ' . implode( ',', $leaked ) . ')' );

ok( false === stripos( $json, 'private' ) && false === stripos( $json, 'secret' ) && false === stripos( $json, 'seed' ),
	'no private-key vocabulary appears anywhere in the served JSON' );

ok( is_array( json_decode( $json, true ) ), 'the endpoint stays valid JSON with history and a commitment present' );

/* ═══════════════════════════════════════════════════════════════════
 * 5. NO HISTORY / NO COMMITMENT — the pre-R1 shape still serves
 * ═══════════════════════════════════════════════════════════════════ */

$GLOBALS['__options'] = array();
$bare = sn_prov_key_document();
ok( 1 === count( $bare['keys'] ) && 'active' === $bare['keys'][0]['status'],
	'with no history configured the document is still a valid one-key mirror' );
ok( ! isset( $bare['next_key_commitment'] ),
	'no commitment configured → the field is absent, not null or empty' );

$GLOBALS['__pub'] = '';
ok( null === sn_prov_key_document(), 'no public key at all → null document (endpoint 404s), unchanged' );
$GLOBALS['__pub'] = base64_encode( $active_raw );

// ── Rotation is a CONFIG change, not a code release (2026-08-29) ───────────
// The key id and its introduction date were filter defaults hardcoded in PHP,
// so rotating meant editing this file and shipping a plugin release — a release
// cycle on the critical path of a key rotation. They now resolve the same way
// the public key beside them already does: constant, else option, else the
// shipped default.
echo "\nGroup: key id + introduced_at resolve through config\n";

$GLOBALS['__options'] = array();
ok( 'sn-ed25519-2026-07' === sn_prov_key_id(), 'with nothing configured the id is unchanged (the live value must not move)' );
ok( '2026-07-09' === sn_prov_key_introduced_at(), 'with nothing configured introduced_at is unchanged' );

$GLOBALS['__options'] = array( 'sn_prov_pubkey_id' => 'sn-ed25519-2027-03', 'sn_prov_key_introduced_at' => '2027-03-01' );
ok( 'sn-ed25519-2027-03' === sn_prov_key_id(), 'the option rotates the key id without touching code' );
ok( '2027-03-01' === sn_prov_key_introduced_at(), 'and the introduction date with it' );

// An EMPTY option must fall back to the shipped default, never publish an empty
// id: a key document whose active entry has id "" is worse than an old id.
$GLOBALS['__options'] = array( 'sn_prov_pubkey_id' => '   ' );
ok( 'sn-ed25519-2026-07' === sn_prov_key_id(), 'a blank option falls back to the default rather than publishing an empty id' );
$GLOBALS['__options'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
