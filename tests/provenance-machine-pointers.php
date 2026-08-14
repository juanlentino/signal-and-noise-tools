<?php
/**
 * R5 rows 3+4 (v11.7.0): inc/provenance-machine-pointers.php — the in-page
 * verification manifest + the schema identifier pointer.
 *
 * The pins are the threat model's §9.5 preconditions made executable:
 * P-51 as an ABSENCE (no verdict-shaped key exists), P-53 as a host
 * allowlist plus structural parity with the ONE endpoint producer the
 * /verify shell consumes, P-56 as an exact key-shape walk (data, never
 * prose). Plus the JS mirror: the PHP kind→directory map must equal
 * prov-verify-core.js SUBJECT_ROOTS, pinned by reading the shipped JS.
 *
 * Run: php tests/provenance-machine-pointers.php
 * @since plugin v11.7.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

error_reporting( E_ALL );
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__php_errors'][] = "$str @ $file:$line";
	return true;
} );

// ── WP stubs ─────────────────────────────────────────────────────────────
function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }
function rest_url( $p = '' ) { return 'https://juanlentino.com/wp-json/' . $p; }
function apply_filters( $t, $v ) { return $v; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function get_permalink( $id ) { return 'https://juanlentino.com/notes/subject-' . (int) $id . '/'; }
function get_post( $id ) { return (object) array( 'ID' => (int) $id, 'post_type' => $GLOBALS['__types'][ (int) $id ] ?? 'post' ); }
function get_the_ID() { return $GLOBALS['__ctx_id'] ?? 0; }
function is_singular( $t = '' ) { return $GLOBALS['__ctx_singular'] ?? false; }
function add_action( ...$a ) { return true; }

// Chain-state stubs the module resolves through (the REAL registration
// shapes: uid from meta, view data from the chain).
$GLOBALS['__uids']  = array();
$GLOBALS['__vms']   = array();
$GLOBALS['__types'] = array();
function sn_prov_note_uid( $post_id ) { return $GLOBALS['__uids'][ (int) $post_id ] ?? ''; }
function sn_prov_view_data( $post_id ) { return $GLOBALS['__vms'][ (int) $post_id ] ?? array(); }
function sn_prov_subject_kind( $post ) { return ( 'page' === ( $post->post_type ?? '' ) ) ? 'page' : 'note'; }

// The REAL endpoint producer — required from the verify module so parity is
// structural, not stubbed. Its dependencies (rest_url/home_url/apply_filters)
// are the stubs above.
define( 'SN_PROV_VERIFY_TEST', true );
require __DIR__ . '/../inc/provenance-verify.php';
require __DIR__ . '/../inc/provenance-machine-pointers.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Provenance machine pointers (v11.7.0)\n";

$GLOBALS['__uids'][10] = '0abc-def1';
$GLOBALS['__vms'][10]  = array( 'version' => 3 );
$GLOBALS['__uids'][20] = 'page-uid-9';
$GLOBALS['__vms'][20]  = array( 'version' => 1 );
$GLOBALS['__types'][20] = 'page';
$GLOBALS['__uids'][30] = 'gen-only';
$GLOBALS['__vms'][30]  = array( 'version' => 0 );

echo "\nGroup: the resolver's honest absences\n";
ok( null === sn_prov_machine_pointers_manifest( 99 ), 'no uid → no manifest (absence, not a stub)' );
ok( null === sn_prov_machine_pointers_manifest( 30 ), 'genesis-only (version 0) → no manifest — nothing anchored means nothing to verify YET' );
ok( null === sn_prov_machine_pointers_identifier( 99 ), 'no uid → no schema identifier' );

echo "\nGroup: the manifest for a signed note\n";
$m = sn_prov_machine_pointers_manifest( 10 );
ok( is_array( $m ) && '0abc-def1' === $m['subject']['uid'] && 3 === $m['subject']['version'] && 'note' === $m['subject']['kind'], 'subject block carries uid/kind/version' );
ok( 'https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/notes/0abc-def1/v3.json' === $m['calls']['record']['url'], 'the record call resolves kind→notes/ with the CURRENT version' );
ok( str_ends_with( $m['calls']['proof']['url'], '/v3.ots' ), 'the proof call points at the matching OTS' );
ok( 'https://juanlentino.com/wp-json/signal-noise/v1/credential/0abc-def1' === $m['calls']['credential']['url'], 'the credential call uses rest_url, never a hand-built prefix' );
ok( false !== strpos( $m['calls']['block_header']['url_template'], '{height}' ), 'block header is a TEMPLATE — the caller fills it from the record, the origin fetches nothing' );
$p = sn_prov_machine_pointers_manifest( 20 );
ok( false !== strpos( $p['calls']['record']['url'], '/pages/page-uid-9/' ), 'a signed PAGE resolves kind→pages/ (the v10.86.0 directory rule)' );

echo "\nGroup: P-51 — the manifest asserts nothing (absence pins)\n";
$flat = strtolower( (string) wp_json_encode( $m ) );
foreach ( array( 'verified', 'verdict', '"valid"', 'status', 'result' ) as $w ) {
	ok( false === strpos( $flat, $w ), "P-51: no verdict-shaped token '$w' anywhere — the origin publishes inputs, the caller computes" );
}

echo "\nGroup: P-53 — every URL inside the pinned host set, parity with THE producer\n";
$ep    = sn_prov_verify_endpoints();
$hosts = array( 'juanlentino.com', 'raw.githubusercontent.com', 'github.com', 'mempool.space' );
$urls  = array( $m['spec'], $m['subject']['url'], $m['standalone']['repository'], $m['standalone']['documentation'] );
foreach ( $m['calls'] as $call ) { $urls[] = $call['url'] ?? $call['url_template']; }
$off = array();
foreach ( $urls as $u ) {
	$h = parse_url( $u, PHP_URL_HOST );
	if ( ! in_array( $h, $hosts, true ) ) { $off[] = $h; }
}
ok( array() === $off, 'P-53: every manifest URL resolves to the pinned host set (site, ledger raw, repo, explorer): ' . implode( ',', $off ) );
ok( 0 === strpos( $m['calls']['record']['url'], $ep['ledger_base'] ), 'PARITY: the record URL starts with the SAME ledger_base the /verify shell consumes — one producer, structural' );
ok( $m['calls']['key_history']['url'] === $ep['keys_url'] && $m['calls']['did']['url'] === $ep['did_url'], 'PARITY: keys + DID are the producer\'s own values, byte-identical' );
$src = (string) file_get_contents( __DIR__ . '/../inc/provenance-verify.php' );
ok( substr_count( $src, 'sn_prov_verify_endpoints()' ) >= 2, 'and the /verify SHELL consumes the producer too (definition + call) — the extraction is real, not a copy left behind' );

echo "\nGroup: P-56 — data-shaped, exactly (key-shape walk)\n";
ok( array( 'spec', 'subject', 'calls', 'standalone' ) === array_keys( $m ), 'top-level keys are exactly the four — no prose slot exists' );
$bad_shape = array();
foreach ( $m['calls'] as $name => $call ) {
	$keys = array_keys( $call );
	sort( $keys );
	if ( ! in_array( $keys, array( array( 'method', 'type', 'url' ), array( 'method', 'type', 'url_template' ) ), true ) ) {
		$bad_shape[] = $name;
	}
}
ok( array() === $bad_shape, 'every call is exactly {method, url|url_template, type} — schema-shaped fields only, per §9.5 P-56: ' . implode( ',', $bad_shape ) );

echo "\nGroup: the JS mirror\n";
$js = (string) file_get_contents( __DIR__ . '/../assets/js/prov-verify-core.js' );
foreach ( sn_prov_machine_pointers_roots() as $kind => $dir ) {
	ok( 1 === preg_match( '/SUBJECT_ROOTS\s*=\s*\{[^}]*' . preg_quote( $kind, '/' ) . '\s*:\s*[\'"]' . preg_quote( $dir, '/' ) . '[\'"]/s', $js ), "kind '$kind' → '$dir' matches prov-verify-core.js SUBJECT_ROOTS — the PHP map mirrors the shipped JS, not an assumption" );
}

echo "\nGroup: emission gating + tag safety\n";
$GLOBALS['__ctx_singular'] = true;
$GLOBALS['__ctx_id']       = 10;
ob_start(); sn_prov_machine_pointers_emit(); $out = ob_get_clean();
ok( false !== strpos( $out, 'id="sn-verification-manifest"' ) && false !== strpos( $out, '0abc-def1' ), 'a signed singular subject emits the manifest' );
ok( 1 === substr_count( $out, '</script>' ) && false !== strpos( $out, '\/' ), 'slash-escaping is ON (every / in the JSON is \/) and exactly ONE </script> exists — the tag closer — so no payload byte sequence can close the tag early' );
$GLOBALS['__ctx_id'] = 99;
ob_start(); sn_prov_machine_pointers_emit(); $out2 = ob_get_clean();
ok( '' === $out2, 'an unsigned subject emits NOTHING — absence, no empty scaffold' );
$GLOBALS['__ctx_singular'] = false;
$GLOBALS['__ctx_id']       = 10;
ob_start(); sn_prov_machine_pointers_emit(); $out3 = ob_get_clean();
ok( '' === $out3, 'off singular contexts nothing emits — archives never carry per-subject manifests' );

echo "\nGroup: the schema identifier\n";
$ident = sn_prov_machine_pointers_identifier( 10 );
ok( is_array( $ident ) && 'PropertyValue' === $ident['@type'] && '0abc-def1' === $ident['value'], 'a signed subject yields the PropertyValue identifier' );
ok( 'https://juanlentino.com/verify#uid' === $ident['propertyID'], 'propertyID anchors on the site\'s own /verify — a consumer can resolve what the value means' );

echo "\nGroup: no PHP notices\n";
ok( array() === $GLOBALS['__php_errors'], 'zero notices/warnings: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
