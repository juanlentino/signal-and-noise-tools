<?php
/**
 * Standalone fixture tests for inc/provenance-integrity.php (v9.80.0).
 *
 * The server-side provenance integrity sweep: the /verify page checks one
 * Note at a time, client-side, only when a reader visits — the 2026-07-21
 * flattened-content_text repair was exactly the drift class a scheduled
 * fleet self-check would have caught first. This suite drives the sweep
 * through stubbed HTTP: the clean pass, EACH failure leg of the triangle
 * (hash mismatch / twin drift / twin unreachable / ledger missing / ledger
 * hash mismatch / key mismatch), the unreachable-vs-mismatch distinction
 * (an outage is not drift), the per-run cap with oldest-checked-first
 * rotation, the durable autoload=no state option, the health-scan check
 * envelope, and the readonly status ability ([object,null] input union).
 *
 * Run: php tests/provenance-integrity.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );

// ── WP stubs ────────────────────────────────────────────────────────────────
$GLOBALS['__pi_actions'] = array();
function add_action( $tag, $cb = null, $prio = 10, $args = 1 ) { $GLOBALS['__pi_actions'][ $tag ][] = $cb; return true; }
function add_filter() { return true; }
function apply_filters( $tag, $value ) { return $value; }
function do_action() {}

$GLOBALS['__pi_options'] = array();
$GLOBALS['__pi_option_autoload'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__pi_options'] ) ? $GLOBALS['__pi_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__pi_options'][ $key ] = $value;
	$GLOBALS['__pi_option_autoload'][ $key ] = $autoload;
	return true;
}

$GLOBALS['__pi_meta'] = array();
function get_post_meta( $post_id, $key, $single = false ) {
	$v = $GLOBALS['__pi_meta'][ (int) $post_id ][ $key ] ?? '';
	return $single ? $v : ( '' === $v ? array() : array( $v ) );
}
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['__pi_meta'][ (int) $post_id ][ $key ] = $value; return true; }

$GLOBALS['__pi_fleet'] = array();
$GLOBALS['__pi_get_posts_args'] = array();
function get_posts( $args = array() ) { $GLOBALS['__pi_get_posts_args'][] = $args; return $GLOBALS['__pi_fleet']; }

function get_permalink( $post_id ) { return 'https://example.com/notes/note-' . (int) $post_id . '/'; }
function get_the_title( $post ) { return 'Note ' . ( is_object( $post ) ? (int) $post->ID : (int) $post ); }
function wp_json_encode( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); }
function wp_strip_all_tags( $s, $rb = false ) {
	$s = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s );
	return trim( strip_tags( $s ) );
}
function wp_generate_uuid4() { return 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'; }

// Published key expectation (production: inc/provenance-did.php + inc/provenance-webhook.php).
$GLOBALS['__pi_pubkey_b64'] = 'PUBKEYB64==';
function sn_prov_key_id() { return 'sn-ed25519-2026-07'; }
function sn_prov_pubkey_b64() { return $GLOBALS['__pi_pubkey_b64']; }

// Ability registrar capture.
$GLOBALS['__pi_abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__pi_abilities'][ $slug ] = $args; return true; }

// sn_health_pack_check lives in inc/health-checks.php (not loadable standalone).
// Mirror the REAL envelope builder exactly (inc/health-checks.php:1239-1246).
function sn_health_pack_check( $label, $findings, $fix_hint = '' ) {
	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => $label,
		'fix_hint' => $fix_hint,
	);
}

require SNT_PATH . 'inc/provenance-core.php';
require SNT_PATH . 'inc/provenance-integrity.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── Fixture helpers ─────────────────────────────────────────────────────────

/** Build a chain commit whose payload REALLY hashes to content_hash (or is tampered). */
function pi_commit( $uid, $version, $content, $status, $tamper = false ) {
	$payload = array(
		'algo'         => 'sn-normalize-v1',
		'author'       => 'Juan Lentino',
		'content'      => $content,
		'note_uid'     => $uid,
		'published_at' => '2026-07-01T00:00:00Z',
		'title'        => 'T',
		'parent'       => null,
		'version'      => (int) $version,
	);
	$hash = sn_prov_content_hash( sn_prov_canonical_json( $payload ) );
	if ( $tamper ) { $payload['content'] = $content . ' SILENTLY EDITED'; }
	return array( 'version' => (int) $version, 'parent' => null, 'content_hash' => $hash, 'payload' => $payload, 'status' => $status );
}

/** Install a Note: uid meta + chain meta. */
function pi_note( $post_id, $uid, array $chain ) {
	update_post_meta( $post_id, SN_PROV_UID_META, $uid );
	update_post_meta( $post_id, SN_PROV_CHAIN_META, $chain );
}

/** Fetcher stub: substring-matched URL map; logs every fetch. */
$GLOBALS['__pi_fetch_log'] = array();
function pi_fetcher( array $map ) {
	return function ( $url ) use ( $map ) {
		$GLOBALS['__pi_fetch_log'][] = $url;
		foreach ( $map as $needle => $resp ) {
			if ( false !== strpos( $url, $needle ) ) { return $resp; }
		}
		return array( 'code' => 404, 'body' => '' );
	};
}
function pi_json( $data ) { return array( 'code' => 200, 'body' => json_encode( $data ) ); }

$UID1  = '11111111-2222-4333-8444-555555555555';
$PARAS = "Para one has words.\n\nPara two has more words.";     // payload keeps paragraphs
$FLAT  = 'Para one has words. Para two has more words.';        // twin content_text is ONE flattened line
$KEYS_OK = pi_json( array( 'schema' => 'sn-provenance-keys-v1', 'keys' => array( array( 'id' => 'sn-ed25519-2026-07', 'public_key_base64' => 'PUBKEYB64==' ) ) ) );

// ── Group: flatten — the whitespace-collapse comparison form ────────────────
echo "\nGroup: flatten reuses sn-normalize-v1 + collapses ALL whitespace (the 2026-07-21 live-match class)\n";
ok( sn_prov_integrity_flatten( $PARAS ) === sn_prov_integrity_flatten( $FLAT ),
	'paragraph payload vs one-line twin: same words in the same order MATCH under the collapse' );
ok( sn_prov_integrity_flatten( $PARAS ) !== sn_prov_integrity_flatten( 'Para one has words. Para two has DIFFERENT words.' ),
	'different words still differ — the collapse never erases real drift' );
ok( 'A B' === sn_prov_integrity_flatten( "<p>A&nbsp;</p>\r\n<p> B\t</p>" ),
	'markup, NBSP, CRLF, tabs all fold through the SHARED normalizer (no second divergent pipeline)' );

// ── Group: twin/ledger URL derivations ──────────────────────────────────────
echo "\nGroup: URL derivations\n";
ok( 'https://example.com/notes/note-7.json' === sn_prov_integrity_twin_url( 'https://example.com/notes/note-7/' ),
	'twin URL strips the trailing slash and appends .json (mirrors the /verify JS)' );
ok( 'https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/' === sn_prov_integrity_ledger_base(),
	'ledger raw base uses the same owner/repo filters as /verify' );

// ── Group: batch selection — cap + oldest-checked-first rotation ────────────
echo "\nGroup: batch selection (pure)\n";
$ids = array( 1, 2, 3, 4, 5 );
ok( array( 3, 1, 2 ) === sn_prov_integrity_select_batch( $ids, array( 1 => 50, 2 => 60, 3 => 0, 4 => 70, 5 => 80 ), 3 ),
	'never-checked (0) first, then oldest-checked ascending, capped at 3' );
ok( array( 1, 2, 3, 4, 5 ) === sn_prov_integrity_select_batch( $ids, array(), 10 ),
	'a cap above the fleet returns the whole fleet (stable id order for ties)' );
ok( 10 === SN_PROV_INTEGRITY_NOTES_PER_RUN, 'the per-run cap is a named constant (10)' );

// ── Group: check_note — clean pass ──────────────────────────────────────────
echo "\nGroup: check_note — the clean triangle\n";
pi_note( 101, $UID1, array( pi_commit( $UID1, 1, $PARAS, 'confirmed' ) ) );
$commit1 = sn_prov_get_chain( 101 )[0];
$fetch = pi_fetcher( array(
	'/notes/note-101.json'      => pi_json( array( 'content_text' => $FLAT, 'note_uid' => $UID1 ) ),
	'/notes/' . $UID1 . '/v1.json' => pi_json( array( 'content_hash' => 'sha256:' . $commit1['content_hash'], 'ots' => array( 'bitcoin_txid' => 'ab12' ) ) ),
) );
$r = sn_prov_integrity_check_note( 101, $fetch );
ok( is_array( $r ) && array() === $r['failures'], 'all three legs hold: zero failures' );
ok( $UID1 === ( $r['uid'] ?? '' ) && 1 === ( $r['version'] ?? 0 ), 'result carries the uid + latest version' );

// ── Group: check_note — leg (a) hash mismatch ───────────────────────────────
echo "\nGroup: leg (a) — stored payload vs anchored hash\n";
pi_note( 102, $UID1, array( pi_commit( $UID1, 1, $PARAS, 'confirmed', true ) ) );
$r = sn_prov_integrity_check_note( 102, $fetch );
ok( in_array( 'hash_mismatch', $r['failures'], true ),
	'a tampered stored payload no longer reproduces the anchored content hash → hash_mismatch' );

// ── Group: check_note — leg (b) twin drift vs unreachable ───────────────────
echo "\nGroup: leg (b) — twin drift is NOT twin unreachable\n";
pi_note( 103, $UID1, array( pi_commit( $UID1, 1, $PARAS, 'confirmed' ) ) );
$ledger_ok = array( '/notes/' . $UID1 . '/v1.json' => pi_json( array( 'content_hash' => 'sha256:' . $commit1['content_hash'] ) ) );
$r = sn_prov_integrity_check_note( 103, pi_fetcher( array(
	'/notes/note-103.json' => pi_json( array( 'content_text' => 'Para one has words. Para two got REWRITTEN.' ) ),
) + $ledger_ok ) );
ok( in_array( 'twin_drift', $r['failures'], true ), 'a twin whose words changed → twin_drift' );
ok( ! in_array( 'twin_unreachable', $r['failures'], true ), 'drift is never doubled as unreachable' );

$r = sn_prov_integrity_check_note( 103, pi_fetcher( array(
	'/notes/note-103.json' => array( 'code' => 0, 'body' => '' ),
) + $ledger_ok ) );
ok( in_array( 'twin_unreachable', $r['failures'], true ), 'a network-dead twin → twin_unreachable' );
ok( ! in_array( 'twin_drift', $r['failures'], true ), 'an outage is NOT drift: no twin_drift claim without a fetched twin' );

$r = sn_prov_integrity_check_note( 103, pi_fetcher( array(
	'/notes/note-103.json' => array( 'code' => 500, 'body' => 'edge error' ),
) + $ledger_ok ) );
ok( in_array( 'twin_unreachable', $r['failures'], true ) && ! in_array( 'twin_drift', $r['failures'], true ),
	'a 500/no-JSON twin is unreachable, never drift' );

$r = sn_prov_integrity_check_note( 103, pi_fetcher( array(
	'/notes/note-103.json' => pi_json( array( 'content_text' => $FLAT ) ),
) + $ledger_ok ) );
ok( array() === $r['failures'], 'the flattened one-line twin with the same words passes clean (the exact 2026-07-21 class, pinned server-side)' );

// ── Group: check_note — leg (c) ledger missing / unreachable / contradiction ─
echo "\nGroup: leg (c) — ledger missing vs unreachable vs contradiction\n";
$twin_ok = array( '/notes/note-103.json' => pi_json( array( 'content_text' => $FLAT ) ) );
$r = sn_prov_integrity_check_note( 103, pi_fetcher( $twin_ok + array(
	'/notes/' . $UID1 . '/v1.json' => array( 'code' => 404, 'body' => '' ),
) ) );
ok( in_array( 'ledger_missing', $r['failures'], true ), 'a 404 ledger record → ledger_missing (a real answer: the record is absent)' );
ok( ! in_array( 'ledger_unreachable', $r['failures'], true ), 'missing is not unreachable' );

$r = sn_prov_integrity_check_note( 103, pi_fetcher( $twin_ok + array(
	'/notes/' . $UID1 . '/v1.json' => array( 'code' => 0, 'body' => '' ),
) ) );
ok( in_array( 'ledger_unreachable', $r['failures'], true ) && ! in_array( 'ledger_missing', $r['failures'], true ),
	'a network-dead ledger → ledger_unreachable, never ledger_missing (an outage is not drift)' );

$r = sn_prov_integrity_check_note( 103, pi_fetcher( $twin_ok + array(
	'/notes/' . $UID1 . '/v1.json' => pi_json( array( 'content_hash' => 'sha256:' . str_repeat( 'f', 64 ) ) ),
) ) );
ok( in_array( 'ledger_hash_mismatch', $r['failures'], true ), 'a ledger record attesting a DIFFERENT hash → ledger_hash_mismatch' );

// A pending-only chain has no ledger record yet — the leg must be skipped.
pi_note( 104, $UID1, array( pi_commit( $UID1, 1, $PARAS, 'pending' ) ) );
$GLOBALS['__pi_fetch_log'] = array();
$r = sn_prov_integrity_check_note( 104, pi_fetcher( array(
	'/notes/note-104.json' => pi_json( array( 'content_text' => $FLAT ) ),
) ) );
ok( array() === $r['failures'], 'a pending (not-yet-confirmed) anchor skips the ledger leg — no false ledger_missing' );
$pi_ledger_fetches = array_filter( $GLOBALS['__pi_fetch_log'], static function ( $u ) { return false !== strpos( $u, 'raw.githubusercontent.com' ); } );
ok( array() === $pi_ledger_fetches, 'no ledger fetch is even attempted for an unconfirmed chain' );

// A genesis-only chain (v0 only) has nothing real to verify.
pi_note( 105, $UID1, array( array( 'version' => 0, 'status' => 'genesis', 'content_hash' => 'x' ) ) );
ok( null === sn_prov_integrity_check_note( 105, $fetch ), 'genesis-only chain → null (nothing verifiable yet)' );

// ── Group: outage classification helper ─────────────────────────────────────
echo "\nGroup: outage vs drift classification\n";
foreach ( array( 'twin_unreachable', 'ledger_unreachable', 'keys_unreachable' ) as $leg ) {
	ok( sn_prov_integrity_is_outage( $leg ), "$leg classifies as an outage" );
}
foreach ( array( 'hash_mismatch', 'twin_drift', 'ledger_missing', 'ledger_hash_mismatch', 'key_mismatch' ) as $leg ) {
	ok( ! sn_prov_integrity_is_outage( $leg ), "$leg classifies as real drift/contradiction" );
}

// ── Group: keys verdict ─────────────────────────────────────────────────────
echo "\nGroup: keys/provenance-keys.json still serves the published key id\n";
ok( 'ok' === sn_prov_integrity_keys_verdict( pi_fetcher( array( 'keys/provenance-keys.json' => $KEYS_OK ) ) ),
	'matching id + matching key bytes → ok' );
ok( 'key_mismatch' === sn_prov_integrity_keys_verdict( pi_fetcher( array(
	'keys/provenance-keys.json' => pi_json( array( 'keys' => array( array( 'id' => 'some-other-key', 'public_key_base64' => 'ZZZZ' ) ) ) ),
) ) ), 'published key id absent from the ledger key file → key_mismatch' );
ok( 'key_mismatch' === sn_prov_integrity_keys_verdict( pi_fetcher( array(
	'keys/provenance-keys.json' => pi_json( array( 'keys' => array( array( 'id' => 'sn-ed25519-2026-07', 'public_key_base64' => 'DIFFERENT==' ) ) ) ),
) ) ), 'right id but different key bytes → key_mismatch (a swapped key must not pass on its label)' );
ok( 'keys_unreachable' === sn_prov_integrity_keys_verdict( pi_fetcher( array(
	'keys/provenance-keys.json' => array( 'code' => 0, 'body' => '' ),
) ) ), 'network-dead key file → keys_unreachable — an outage, not a key rotation claim' );

// ── Group: run_sweep — cap, rotation, durable state ─────────────────────────
echo "\nGroup: run_sweep — cap + rotation + autoload=no state\n";
$GLOBALS['__pi_options'] = array();
$GLOBALS['__pi_meta']    = array();
$fleet = range( 201, 213 ); // 13 chainless notes: rotation mechanics without fixture weight
foreach ( $fleet as $pid ) { update_post_meta( $pid, SN_PROV_UID_META, 'uid-' . $pid ); }
$GLOBALS['__pi_fleet'] = $fleet;
$sweep_fetch = pi_fetcher( array( 'keys/provenance-keys.json' => $KEYS_OK ) );

$s1 = sn_prov_integrity_run_sweep( $sweep_fetch );
ok( 10 === $s1['checked'] && 13 === $s1['fleet'], 'first run checks exactly the cap (10 of 13)' );
$disc = end( $GLOBALS['__pi_get_posts_args'] );
ok( 'publish' === ( $disc['post_status'] ?? '' ) && SN_PROV_UID_META === ( $disc['meta_key'] ?? '' ),
	'discovery mirrors the provenance system: published posts carrying the uid meta' );
$state1 = get_option( SN_PROV_INTEGRITY_OPT );
ok( is_array( $state1 ) && 10 === count( $state1['notes'] ), 'per-Note last-checked state persisted for the batch' );
ok( false === $GLOBALS['__pi_option_autoload'][ SN_PROV_INTEGRITY_OPT ],
	'state is stored autoload=no (durable option — transients are flush-volatile on this stack)' );

// Age the first batch so the 3 unvisited notes (0) sort first on run 2.
$s2 = sn_prov_integrity_run_sweep( $sweep_fetch );
$state2 = get_option( SN_PROV_INTEGRITY_OPT );
ok( 13 === count( $state2['notes'] ), 'second run reaches the 3 unvisited notes first — full coverage accrues across runs' );
foreach ( array( 211, 212, 213 ) as $pid ) {
	ok( isset( $state2['notes'][ $pid ] ), "note $pid (unvisited on run 1) was picked up by run 2's rotation" );
}
ok( 'ok' === $s2['keys'], 'the run-level keys verdict rides the sweep summary' );

// A deleted note's state row is pruned.
$GLOBALS['__pi_fleet'] = array( 201, 202 );
sn_prov_integrity_run_sweep( $sweep_fetch );
$state3 = get_option( SN_PROV_INTEGRITY_OPT );
ok( 2 === count( $state3['notes'] ), 'notes gone from the fleet are pruned from state' );

// ── Group: run_sweep — honest summary counts ────────────────────────────────
echo "\nGroup: run_sweep — clean vs failed vs unreachable are distinct counts\n";
$GLOBALS['__pi_options'] = array();
$GLOBALS['__pi_meta']    = array();
$UIDA = 'aaaaaaaa-1111-4222-8333-444444444444';
$UIDB = 'bbbbbbbb-1111-4222-8333-444444444444';
$UIDC = 'cccccccc-1111-4222-8333-444444444444';
pi_note( 301, $UIDA, array( pi_commit( $UIDA, 1, $PARAS, 'confirmed' ) ) );
pi_note( 302, $UIDB, array( pi_commit( $UIDB, 1, $PARAS, 'confirmed' ) ) );
pi_note( 303, $UIDC, array( pi_commit( $UIDC, 1, $PARAS, 'confirmed' ) ) );
$hashA = sn_prov_get_chain( 301 )[0]['content_hash'];
$hashB = sn_prov_get_chain( 302 )[0]['content_hash'];
$hashC = sn_prov_get_chain( 303 )[0]['content_hash'];
$GLOBALS['__pi_fleet'] = array( 301, 302, 303 );
$mixed_fetch = pi_fetcher( array(
	'keys/provenance-keys.json'    => $KEYS_OK,
	'/notes/note-301.json'         => pi_json( array( 'content_text' => $FLAT ) ),
	'/notes/' . $UIDA . '/v1.json' => pi_json( array( 'content_hash' => 'sha256:' . $hashA ) ),
	'/notes/note-302.json'         => pi_json( array( 'content_text' => 'Totally different words now.' ) ),
	'/notes/' . $UIDB . '/v1.json' => pi_json( array( 'content_hash' => 'sha256:' . $hashB ) ),
	'/notes/note-303.json'         => array( 'code' => 0, 'body' => '' ),
	'/notes/' . $UIDC . '/v1.json' => pi_json( array( 'content_hash' => 'sha256:' . $hashC ) ),
) );
$s = sn_prov_integrity_run_sweep( $mixed_fetch );
ok( 3 === $s['checked'] && 1 === $s['clean'] && 1 === $s['failed'] && 1 === $s['unreachable'],
	'clean/failed/unreachable each count once — an outage never inflates the drift count' );

// ── Group: findings builder + the health-scan check ─────────────────────────
echo "\nGroup: findings + sn_health_check_provenance_integrity\n";
$state = sn_prov_integrity_state();
$findings = sn_prov_integrity_findings( $state );
ok( 2 === count( $findings ), 'two findings: the drifted note and the unreachable note (clean note absent)' );
$by_id = array();
foreach ( $findings as $f ) { $by_id[ (int) $f['subject_id'] ] = $f; }
ok( isset( $by_id[302] ) && false !== strpos( $by_id[302]['note'], 'twin' ) && false !== strpos( $by_id[302]['note'], 'drift' ),
	'the drifted note\'s finding names the failed leg (twin drift)' );
ok( isset( $by_id[303] ) && false !== strpos( $by_id[303]['note'], 'unreachable' ) && false !== strpos( $by_id[303]['note'], 'not drift' ),
	'the unreachable note\'s finding says outage, not drift — distinct wording' );
ok( isset( $by_id[303] ) && false === strpos( $by_id[303]['note'], 'twin drift' ),
	'the unreachable finding never claims drift' );
ok( 'provenance_integrity' === ( $findings[0]['subject_type'] ?? '' ), 'findings carry the provenance_integrity subject type' );

// key_mismatch surfaces as its own finding.
$key_state = $state;
$key_state['last_sweep']['keys'] = 'key_mismatch';
$kf = sn_prov_integrity_findings( $key_state );
$key_rows = array_values( array_filter( $kf, static function ( $f ) { return 0 === (int) $f['subject_id']; } ) );
ok( 1 === count( $key_rows ) && false !== strpos( $key_rows[0]['note'], 'provenance-keys.json' ),
	'a key mismatch yields a fleet-level finding naming keys/provenance-keys.json' );

// The real check callback (runs the sweep, then reports accrued state).
// The optional $fetcher forwards to the sweep — the same injection seam the
// sweep itself exposes; production calls it bare (default HTTP fetcher).
$check = sn_health_check_provenance_integrity( $mixed_fetch );
ok( 'Provenance integrity' === $check['label'], 'check label pinned' );
ok( 2 === $check['count'], 'the check FLAGS the two failing notes' );
ok( '' !== $check['fix_hint'], 'the check ships a fix hint' );

// All-clean pass state.
$GLOBALS['__pi_options'] = array();
$GLOBALS['__pi_fleet']   = array( 301 );
$check2 = sn_health_check_provenance_integrity( $mixed_fetch );
ok( 0 === $check2['count'] && array() === $check2['findings'], 'a fully clean fleet passes with zero findings' );
ok( false !== strpos( $check2['fix_hint'], 'rotat' ), 'the pass-state hint explains the rotating coverage' );

// ── Group: the readonly status ability ──────────────────────────────────────
echo "\nGroup: signal-noise/provenance-integrity-status\n";
ok( isset( $GLOBALS['__pi_actions']['wp_abilities_api_init'] ), 'registration rides the canonical wp_abilities_api_init hook' );
foreach ( $GLOBALS['__pi_actions']['wp_abilities_api_init'] as $cb ) { call_user_func( $cb ); }
$ab = $GLOBALS['__pi_abilities']['signal-noise/provenance-integrity-status'] ?? null;
ok( is_array( $ab ), 'provenance-integrity-status registered' );
ok( 'diagnostics' === ( $ab['category'] ?? '' ), 'category comes from the REGISTERED set (diagnostics)' );
ok( 'snt_ability_perm_manage_options' === ( $ab['permission_callback'] ?? '' ), 'manage_options-gated like its diagnostics siblings' );
ok( true === ( $ab['meta']['annotations']['readonly'] ?? null ), 'annotated readonly (GET run-path)' );
ok( array( 'object', 'null' ) === ( $ab['input_schema']['type'] ?? null ),
	'input schema types the [object,null] union — bodyless GET delivers null (Group E law)' );

// Execute: null before any sweep; the summary + failing rows after; NEVER sweeps.
$GLOBALS['__pi_options'] = array();
ok( null === snt_ability_provenance_integrity_status( null ), 'no sweep yet → null (never fabricates a green fleet)' );
$GLOBALS['__pi_fleet'] = array( 301, 302, 303 );
sn_prov_integrity_run_sweep( $mixed_fetch );
$pre_fetches = count( $GLOBALS['__pi_fetch_log'] );
$pre_queries = count( $GLOBALS['__pi_get_posts_args'] );
$status = snt_ability_provenance_integrity_status( null );
ok( is_array( $status ) && 3 === $status['checked'] && 1 === $status['failed'], 'ability returns the latest sweep summary' );
ok( 2 === count( $status['failing'] ?? array() ), 'ability lists the per-note failures (drifted + unreachable)' );
$status_row = null;
foreach ( $status['failing'] as $row ) { if ( 302 === (int) $row['post_id'] ) { $status_row = $row; } }
ok( null !== $status_row && in_array( 'twin_drift', $status_row['failures'], true ), 'a failing row names WHICH leg failed' );
ok( $pre_fetches === count( $GLOBALS['__pi_fetch_log'] ) && $pre_queries === count( $GLOBALS['__pi_get_posts_args'] ),
	'the ability is a pure read: no HTTP fetch, no post query — it never triggers a sweep' );

// ── Group: wiring pins (source containment) ─────────────────────────────────
echo "\nGroup: wiring — loader + scan registration + no parallel cron\n";
$loader_src = (string) file_get_contents( SNT_PATH . 'signal-and-noise-tools.php' );
ok( false !== strpos( $loader_src, "inc/provenance-integrity.php" ), 'the plugin loader requires inc/provenance-integrity.php' );
$scan_src = (string) file_get_contents( SNT_PATH . 'inc/health-checks.php' );
ok( false !== strpos( $scan_src, "'provenance_integrity'" ) && false !== strpos( $scan_src, 'sn_health_check_provenance_integrity()' ),
	'sn_health_run_scan() registers the provenance_integrity check (rides the existing scan cadence)' );
$module_src = (string) file_get_contents( SNT_PATH . 'inc/provenance-integrity.php' );
ok( false === strpos( $module_src, 'wp_schedule_event' ), 'no parallel cron invented — the sweep rides the health scan' );
ok( false === strpos( $module_src, 'register_rest_route' ), 'no new bare REST route — the ability is the only new surface' );
ok( false === strpos( $module_src, 'set_transient' ), 'durable state never rides flush-volatile transients' );

// ── Group: v9.81.0 — persistent-404 escalation + malformed ledger record ────
echo "\nGroup: v9.81.0 — a ledger record that EXISTS but lacks content_hash is a finding, not silence\n";
pi_note( 401, $UID1, array( pi_commit( $UID1, 1, $PARAS, 'confirmed' ) ) );
$r = sn_prov_integrity_check_note( 401, pi_fetcher( array(
	'/notes/note-401.json'         => pi_json( array( 'content_text' => $FLAT ) ),
	'/notes/' . $UID1 . '/v1.json' => pi_json( array( 'ots' => array( 'bitcoin_txid' => 'ab12' ) ) ), // record EXISTS, no content_hash
) ) );
ok( in_array( 'ledger_record_malformed', $r['failures'], true ),
	'a ledger record without content_hash → ledger_record_malformed (was silent)' );
ok( ! in_array( 'ledger_hash_mismatch', $r['failures'], true ), 'malformed is not a hash contradiction' );
ok( ! sn_prov_integrity_is_outage( 'ledger_record_malformed' ), 'ledger_record_malformed classifies as real drift, not outage' );

echo "\nGroup: v9.81.0 — three consecutive twin 404s escalate to twin_missing\n";
ok( 3 === SN_PROV_INTEGRITY_404_STREAK, 'the escalation threshold is a named constant (3)' );
$GLOBALS['__pi_options'] = array();
$GLOBALS['__pi_meta']    = array();
pi_note( 501, $UID1, array( pi_commit( $UID1, 1, $PARAS, 'confirmed' ) ) );
$hash501 = sn_prov_get_chain( 501 )[0]['content_hash'];
$GLOBALS['__pi_fleet'] = array( 501 );
$twin404_fetch = pi_fetcher( array(
	'keys/provenance-keys.json'    => $KEYS_OK,
	'/notes/note-501.json'         => array( 'code' => 404, 'body' => '' ),
	'/notes/' . $UID1 . '/v1.json' => pi_json( array( 'content_hash' => 'sha256:' . $hash501 ) ),
) );
sn_prov_integrity_run_sweep( $twin404_fetch );
$row = get_option( SN_PROV_INTEGRITY_OPT )['notes'][501];
ok( in_array( 'twin_unreachable', $row['failures'], true ) && ! in_array( 'twin_missing', $row['failures'], true ),
	'sweep 1: a twin 404 stays an outage-class twin_unreachable' );
sn_prov_integrity_run_sweep( $twin404_fetch );
$row = get_option( SN_PROV_INTEGRITY_OPT )['notes'][501];
ok( ! in_array( 'twin_missing', $row['failures'], true ), 'sweep 2: still not escalated' );
sn_prov_integrity_run_sweep( $twin404_fetch );
$row = get_option( SN_PROV_INTEGRITY_OPT )['notes'][501];
ok( in_array( 'twin_missing', $row['failures'], true ) && ! in_array( 'twin_unreachable', $row['failures'], true ),
	'sweep 3: three consecutive 404s escalate to the REAL twin_missing finding (replacing the outage code)' );
ok( ! sn_prov_integrity_is_outage( 'twin_missing' ), 'twin_missing classifies as real drift, not outage' );
$mf = sn_prov_integrity_findings( sn_prov_integrity_state() );
$m501 = null;
foreach ( $mf as $f ) { if ( 501 === (int) $f['subject_id'] ) { $m501 = $f; } }
ok( null !== $m501 && false !== strpos( $m501['note'], 'missing' ) && false === strpos( $m501['note'], 'outage, not drift' ),
	'the twin_missing finding speaks as a real finding, not outage wording' );

// A network error (code 0) between 404s resets the streak — CONSECUTIVE means consecutive.
$twin_net_fetch = pi_fetcher( array(
	'keys/provenance-keys.json'    => $KEYS_OK,
	'/notes/note-501.json'         => array( 'code' => 0, 'body' => '' ),
	'/notes/' . $UID1 . '/v1.json' => pi_json( array( 'content_hash' => 'sha256:' . $hash501 ) ),
) );
sn_prov_integrity_run_sweep( $twin_net_fetch );
sn_prov_integrity_run_sweep( $twin404_fetch );
$row = get_option( SN_PROV_INTEGRITY_OPT )['notes'][501];
ok( ! in_array( 'twin_missing', $row['failures'], true ),
	'a network error resets the 404 streak (an outage between 404s never counts toward escalation)' );

// A recovered twin clears both the streak and the failure.
$twin_ok_fetch = pi_fetcher( array(
	'keys/provenance-keys.json'    => $KEYS_OK,
	'/notes/note-501.json'         => pi_json( array( 'content_text' => $FLAT ) ),
	'/notes/' . $UID1 . '/v1.json' => pi_json( array( 'content_hash' => 'sha256:' . $hash501 ) ),
) );
sn_prov_integrity_run_sweep( $twin_ok_fetch );
$row = get_option( SN_PROV_INTEGRITY_OPT )['notes'][501];
ok( array() === $row['failures'], 'a recovered twin clears the note clean again' );

echo "\nGroup: v9.81.0 — three consecutive keys-file 404s escalate to keys_missing\n";
$GLOBALS['__pi_options'] = array();
$keys404_fetch = pi_fetcher( array(
	'/notes/note-501.json'         => pi_json( array( 'content_text' => $FLAT ) ),
	'/notes/' . $UID1 . '/v1.json' => pi_json( array( 'content_hash' => 'sha256:' . $hash501 ) ),
	// keys/provenance-keys.json intentionally unmatched → the stub's default 404.
) );
$s = sn_prov_integrity_run_sweep( $keys404_fetch );
ok( 'keys_unreachable' === $s['keys'], 'sweep 1: a keys 404 stays keys_unreachable' );
$s = sn_prov_integrity_run_sweep( $keys404_fetch );
ok( 'keys_unreachable' === $s['keys'], 'sweep 2: still not escalated' );
$s = sn_prov_integrity_run_sweep( $keys404_fetch );
ok( 'keys_missing' === $s['keys'], 'sweep 3: three consecutive 404s escalate to keys_missing' );
ok( ! sn_prov_integrity_is_outage( 'keys_missing' ), 'keys_missing classifies as real drift, not outage' );
$kf2 = sn_prov_integrity_findings( sn_prov_integrity_state() );
$key_rows2 = array_values( array_filter( $kf2, static function ( $f ) { return 0 === (int) $f['subject_id']; } ) );
ok( 1 === count( $key_rows2 ) && false !== strpos( $key_rows2[0]['note'], 'provenance-keys.json' )
	&& false !== strpos( $key_rows2[0]['note'], 'absent' ),
	'keys_missing yields a fleet-level finding naming the absent key file' );
// A network error resets the keys streak too.
$keys_net_fetch = pi_fetcher( array(
	'keys/provenance-keys.json'    => array( 'code' => 0, 'body' => '' ),
	'/notes/note-501.json'         => pi_json( array( 'content_text' => $FLAT ) ),
	'/notes/' . $UID1 . '/v1.json' => pi_json( array( 'content_hash' => 'sha256:' . $hash501 ) ),
) );
sn_prov_integrity_run_sweep( $keys_net_fetch );
$s = sn_prov_integrity_run_sweep( $keys404_fetch );
ok( 'keys_unreachable' === $s['keys'], 'a keys network error resets the 404 streak' );
// Recovery clears the streak + verdict.
$s = sn_prov_integrity_run_sweep( $twin_ok_fetch );
ok( 'ok' === $s['keys'], 'a recovered keys file goes back to ok' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
