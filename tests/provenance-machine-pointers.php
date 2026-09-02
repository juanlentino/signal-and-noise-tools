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
$GLOBALS['__uid_calls'] = array();
function sn_prov_note_uid( $post_id ) { $GLOBALS['__uid_calls'][] = (int) $post_id; return $GLOBALS['__uids'][ (int) $post_id ] ?? ''; }
function sn_prov_view_data( $post_id ) { return $GLOBALS['__vms'][ (int) $post_id ] ?? array(); }
// v12.11.1 — CONTRACT-FAITHFUL STUB. The previous one returned 'note' for
// everything that was not a page, so it could never produce the EMPTY string
// the real inc/provenance-core.php returns for a page that is not opted in
// (and for a 'post' that is not a Note). The coercion this suite now pins was
// invisible for exactly that reason: the fixture could not express the input.
function sn_prov_subject_kind( $post ) {
	$type = (string) ( $post->post_type ?? '' );
	$id   = (int) ( $post->ID ?? 0 );
	if ( 'page' === $type ) { return ! empty( $GLOBALS['__opted_in'][ $id ] ) ? 'page' : ''; }
	if ( 'post' === $type ) { return ! empty( $GLOBALS['__is_note'][ $id ] ) ? 'note' : ''; }
	return '';
}

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
$GLOBALS['__is_note'][10] = true;
$GLOBALS['__vms'][10]  = array( 'version' => 3 );
$GLOBALS['__uids'][20] = 'page-uid-9';
$GLOBALS['__opted_in'][20] = true;   // v12.11.1: this fixture is a SIGNED page — the stub now demands opt-in, as the real producer does
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

// The retraction lookup. Without it a machine reader has NO WAY to discover
// that a record was withdrawn: it can fetch the record, the proof and the key,
// verify all three, and still be reading a claim we have publicly retracted.
// The call is listed even though the file is ABSENT in the normal case — a 404
// is the answer "not retracted", which is exactly what the JS reader does with
// it. Listing a call is not a verdict (P-51); it is where to look.
ok( 'https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/retractions/0abc-def1/v3.json' === $m['calls']['retraction']['url'], 'the retraction call points at retractions/<uid>/v<version>.json for the CURRENT version' );
// KIND-INDEPENDENT, unlike record/proof: retractions/ is one flat directory for
// every subject kind. A page whose retraction URL resolved to pages/ would look
// permanently un-retracted no matter what we published.
ok( false !== strpos( $p['calls']['retraction']['url'], '/retractions/page-uid-9/v' )
	&& false === strpos( $p['calls']['retraction']['url'], '/pages/' ), 'a signed PAGE uses the SAME flat retractions/ root — the kind map must not reach this URL' );

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

echo "\nGroup: '' is never coerced into a kind (v12.11.1)\n";
/* The owner-ratified rule, 2026-08-22: "'' is never coerced into a kind or a
 * directory. A missed dispatch is recoverable; a misfiled anchored record is
 * not." This emitter defaulted $kind to 'note' and only overrode it when the
 * resolved kind was a key in the roots map — so '' silently kept 'note' and
 * pointed the ledger base at notes/<uid>.
 *
 * LIVE CONSEQUENCE, measured 2026-08-22: /about/ published
 *   "subject":{"uid":"01cea10c…","kind":"note","version":2}
 * for a PAGE, matching the misfiled notes/01cea10c…/v2.json record. The site
 * asserted a false kind to every verifier that read it.
 *
 * The right answer for an unresolvable kind is ABSENCE, the same choice this
 * module already makes for an unanchored subject ("nothing to verify yet —
 * absence, not a stub"). */
$GLOBALS['__uids'][910]     = 'about-uid-1';   // present, so null can only mean the kind
$GLOBALS['__types'][910]    = 'page';
$GLOBALS['__vms'][910]      = array( 'version' => 2 );
$GLOBALS['__opted_in'][910] = false;
ok( null === sn_prov_machine_pointers_manifest( 910 ), "an unresolvable kind emits NO manifest — it does not fall back to note" );

$GLOBALS['__opted_in'][910] = true;
$m910 = sn_prov_machine_pointers_manifest( 910 );
ok( is_array( $m910 ) && 'page' === $m910['subject']['kind'], 'an opted-in page resolves to kind page' );
ok( is_array( $m910 ) && false !== strpos( $m910['calls']['record']['url'], '/pages/' ), 'and its ledger base is pages/, never notes/' );

$GLOBALS['__uids'][911]    = 'notanote-uid-1';
$GLOBALS['__types'][911]   = 'post';
$GLOBALS['__vms'][911]     = array( 'version' => 1 );
$GLOBALS['__is_note'][911] = false;
ok( null === sn_prov_machine_pointers_manifest( 911 ), 'a non-Note post emits NO manifest either' );

// The stub must mirror the REAL producer, not this fixture's convenience.
$core_src = file_get_contents( __DIR__ . '/../inc/provenance-core.php' );
ok( 1 === preg_match( "/function sn_prov_subject_kind\\(.*?return '';/s", $core_src ), 'the real sn_prov_subject_kind CAN return the empty string (the stub is not inventing a shape)' );

// The resolver-absent branch cannot be driven from a fixture — PHP will not let
// a defined function be undefined — so it is pinned at SOURCE level instead.
// Without this, a mutation changing that fallback to 'note' SURVIVES, which is
// exactly what happened when these pins were first mutation-tested.
$mp_src = file_get_contents( __DIR__ . '/../inc/provenance-machine-pointers.php' );
ok( 1 === preg_match( "/\\?\s*\(string\) sn_prov_subject_kind\( get_post\( \\\$post_id \) \)\s*:\s*'';/", $mp_src ), "the resolver-absent fallback is '' — never a kind (source-level: this branch is unreachable from a fixture)" );

// v13.69.1 — THE MINTER IS NEVER REACHED FOR A NON-SUBJECT. The real
// sn_prov_note_uid() mints and persists a UID on first read, so calling it for a
// page nobody opted in hands that page a ledger key it will never earn a chain
// for. Measured live 2026-09-01: 25 such pages, every one reported by the
// backfill panel as "cannot be verified" with advice that could not work.
$GLOBALS['__types'][930] = 'page'; $GLOBALS['__opted_in'][930] = false; $GLOBALS['__uids'][930] = 'would-be-minted';
$GLOBALS['__uid_calls'] = array();
ok( null === sn_prov_machine_pointers_identifier( 930 ) && null === sn_prov_machine_pointers_manifest( 930 ), 'a page without the opt-in yields no identifier and no manifest' );
ok( array() === $GLOBALS['__uid_calls'], 'and the UID minter is never called for it — no ledger key is handed to a non-subject' );
$GLOBALS['__types'][931] = 'post'; $GLOBALS['__is_note'][931] = false; $GLOBALS['__uid_calls'] = array();
ok( null === sn_prov_machine_pointers_identifier( 931 ) && array() === $GLOBALS['__uid_calls'], 'a post outside the Notes category: same — no identifier, no mint' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
