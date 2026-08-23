<?php
/**
 * Standalone fixture tests for inc/provenance-chain-backfill.php (v10.3.0).
 *
 * The one-shot WP-side chain import for the July worker-side backfill Notes:
 * ledger-confirmed records whose WP chain meta was never written (their
 * confirm callbacks 404ed hourly until worker v1.8.2 dropped the rows). The
 * suite drives the import through stubbed HTTP against REAL canonicalization
 * (inc/provenance-core.php is required, not mirrored): the clean import with
 * the dispatcher's exact commit shape, EVERY refusal gate (uid mismatch /
 * tampered hash / unconfirmed / not-v1 / malformed), the outage-vs-missing
 * distinction, idempotence (a chained post is never a candidate, and a chain
 * appearing mid-run is re-checked at write time), and the per-run cap.
 *
 * Run: php tests/provenance-chain-backfill.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );

// ── WP stubs (the provenance-integrity harness pattern) ────────────────────
function add_action( $tag, $cb = null, $prio = 10, $args = 1 ) { return true; }
// v12.22.1: a REAL filter seam, not a no-op. The time budget is filterable and
// this suite has to be able to move it; with add_filter stubbed away, a test
// that "exercised" the budget would have exercised the default and passed
// vacuously. Pass-through while nothing is registered, so every other group
// behaves exactly as before.
$GLOBALS['__filters'] = array();
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__filters'][ $tag ][] = $cb;
	return true;
}
function remove_filter( $tag, $cb, $prio = 10 ) {
	if ( isset( $GLOBALS['__filters'][ $tag ] ) ) {
		$GLOBALS['__filters'][ $tag ] = array_values( array_filter(
			$GLOBALS['__filters'][ $tag ],
			static function ( $c ) use ( $cb ) { return $c !== $cb; }
		) );
	}
	return true;
}
function apply_filters( $tag, $value ) {
	foreach ( (array) ( $GLOBALS['__filters'][ $tag ] ?? array() ) as $cb ) {
		$value = $cb( $value );
	}
	return $value;
}
function do_action() {}

$GLOBALS['__meta'] = array();
function get_post_meta( $post_id, $key, $single = false ) {
	$v = $GLOBALS['__meta'][ (int) $post_id ][ $key ] ?? '';
	return $single ? $v : ( '' === $v ? array() : array( $v ) );
}
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['__meta'][ (int) $post_id ][ $key ] = $value; return true; }

$GLOBALS['__fleet'] = array();
function get_posts( $args = array() ) { $GLOBALS['__get_posts_args'][] = $args; return $GLOBALS['__fleet']; }

function wp_json_encode( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function delete_transient( $k ) { return true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function __( $s, $d = null ) { return $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

require SNT_PATH . 'inc/provenance-core.php';
require SNT_PATH . 'inc/provenance-integrity.php';
require SNT_PATH . 'inc/provenance-chain-backfill.php';
// v10.67.0: the round-trip group drives sn_prov_credential() so the suite can
// assert an imported commit is actually VERIFIABLE, not merely well-shaped.
// v12.8.0: the stub gained post_type, which it had never carried. The backfill
// now resolves a subject KIND to pick the ledger directory, and post_type is the
// column WordPress uses to tell a page from a post (both live in wp_posts). A
// stub without it resolves to no kind at all — the fixture would have reported
// every candidate unresolvable while the real site was fine.
$GLOBALS['__post'] = (object) array( 'ID' => 0, 'post_status' => 'publish', 'post_password' => '', 'post_type' => 'post' );
function get_post( $id = 0 ) { $p = clone $GLOBALS['__post']; $p->ID = (int) $id; return $p; }
// Core registers this taxonomy as register_taxonomy( 'category', 'post', … ), so
// it answers for posts only — which is why a page can never be a Note.
function has_term( $term = '', $taxonomy = '', $post = null ) {
	$type = (string) ( $GLOBALS['__post']->post_type ?? 'post' );
	return 'category' === $taxonomy && 'post' === $type;
}
function get_permalink( $id ) { return 'https://juanlentino.com/notes/n-' . (int) $id . '/'; }
function get_the_title( $id ) { return 'Note ' . (int) $id; }
function get_the_date( $f, $id ) { return '2026-05-09T22:33:32-04:00'; }
function wp_strip_all_tags( $s, $b = false ) { return trim( strip_tags( (string) $s ) ); }
function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function esc_url_raw( $u ) { return $u; }
function rest_url( $p = '' ) { return 'https://juanlentino.com/wp-json/' . ltrim( (string) $p, '/' ); }
function get_option( $k, $d = false ) { return $d; }
function sn_prov_pubkey_b64() { return base64_encode( str_repeat( "\x01", 32 ) ); }
define( 'SN_PROV_CRED_TEST', true );
define( 'SN_PROV_DID_TEST', true );
require SNT_PATH . 'inc/provenance-did.php';
require SNT_PATH . 'inc/provenance-credential.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── Fixture helpers ─────────────────────────────────────────────────────────

/** A ledger record whose content_hash REALLY matches its payload (or is tampered). */
function bf_record( $uid, $tamper = false, $ots = null ) {
	$payload = array(
		'algo'         => 'sn-normalize-v1',
		'author'       => 'Juan Lentino',
		'content'      => 'Body of ' . $uid,
		'note_uid'     => $uid,
		'parent'       => 'cca0dfa924b4bd69aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa6d1f2ef9',
		'published_at' => '2026-05-09T22:33:32Z',
		'title'        => 'T ' . $uid,
		'version'      => 1,
	);
	$hash = sn_prov_content_hash( sn_prov_canonical_json( $payload ) );
	return array(
		'payload'      => $payload,
		'content_hash' => $tamper ? str_repeat( '0', 64 ) : $hash,
		'signature'    => 'sig==',
		'pubkey_id'    => 'sn-ed25519-2026-07',
		'ots'          => null === $ots ? array( 'status' => 'confirmed', 'bitcoin_block' => 958897 ) : $ots,
	);
}

/** Fetcher stub: url => {code, body}. */
$GLOBALS['__http'] = array();
$GLOBALS['__asked'] = array();
function bf_fetcher( $url ) {
	$GLOBALS['__asked'][] = $url;
	foreach ( $GLOBALS['__http'] as $needle => $res ) {
		if ( false !== strpos( $url, $needle ) ) {
			return $res;
		}
	}
	return array( 'code' => 404, 'body' => '' );
}
function bf_reset( $posts ) {
	$GLOBALS['__meta'] = array(); $GLOBALS['__fleet'] = array(); $GLOBALS['__http'] = array();
	foreach ( $posts as $id => $uid ) {
		$GLOBALS['__fleet'][] = $id;
		$GLOBALS['__meta'][ $id ][ SN_PROV_UID_META ] = $uid;
	}
}

echo "Group: the clean import — the dispatcher's exact commit shape\n";
bf_reset( array( 11 => 'aaaa1111-0000-4000-8000-000000000001' ) );
$rec = bf_record( 'aaaa1111-0000-4000-8000-000000000001' );
$GLOBALS['__http']['notes/aaaa1111-0000-4000-8000-000000000001/v1.json'] = array( 'code' => 200, 'body' => json_encode( $rec ) );
$sum = sn_prov_backfill_run( 'bf_fetcher' );
ok( 1 === $sum['imported'] && array() === $sum['skipped'], 'one candidate imports cleanly' );
$chain = sn_prov_get_chain( 11 );
ok( 1 === count( $chain ), 'exactly one commit appended' );
$c = $chain[0];
ok( 1 === $c['version'] && 'confirmed' === $c['status'] && 958897 === $c['bitcoin_block'], 'v1, confirmed, block carried' );
ok( $c['content_hash'] === $rec['content_hash'], 'content_hash equals the ledger record' );
ok( $c['parent'] === $rec['payload']['parent'], 'parent carried from the payload (genesis ref)' );
ok( $c['payload'] === $rec['payload'], 'the exact canonical payload is stored (re-dispatch stays byte-identical)' );
$bearing = $rec['payload'];
unset( $bearing['parent'], $bearing['version'] );
ok( $c['bearing_hash'] === sn_prov_content_hash( sn_prov_canonical_json( $bearing ) ), 'bearing_hash recomputed the dispatcher way (payload minus parent+version)' );
ok( '2026-05-09T22:33:32Z' === $c['committed_at'], 'committed_at is the published_at moment, not import time' );
ok( isset( $c['backfilled_at'] ) && '' !== $c['backfilled_at'], 'the row carries its own provenance: backfilled_at marker' );

echo "\nGroup: every refusal gate refuses\n";
bf_reset( array( 21 => 'bbbb2222-0000-4000-8000-000000000002' ) );
$GLOBALS['__http']['notes/bbbb2222'] = array( 'code' => 200, 'body' => json_encode( bf_record( 'ZZZZ-other-uid' ) ) );
$sum = sn_prov_backfill_run( 'bf_fetcher' );
ok( 0 === $sum['imported'] && 1 === ( $sum['skipped']['uid_mismatch'] ?? 0 ), 'uid mismatch refused' );
ok( array() === sn_prov_get_chain( 21 ), 'nothing written on refusal' );

bf_reset( array( 22 => 'cccc3333-0000-4000-8000-000000000003' ) );
$GLOBALS['__http']['notes/cccc3333'] = array( 'code' => 200, 'body' => json_encode( bf_record( 'cccc3333-0000-4000-8000-000000000003', true ) ) );
$sum = sn_prov_backfill_run( 'bf_fetcher' );
ok( 1 === ( $sum['skipped']['hash_mismatch'] ?? 0 ) && array() === sn_prov_get_chain( 22 ), 'tampered record (hash mismatch) refused' );

bf_reset( array( 23 => 'dddd4444-0000-4000-8000-000000000004' ) );
$GLOBALS['__http']['notes/dddd4444'] = array( 'code' => 200, 'body' => json_encode( bf_record( 'dddd4444-0000-4000-8000-000000000004', false, array( 'status' => 'pending' ) ) ) );
$sum = sn_prov_backfill_run( 'bf_fetcher' );
ok( 1 === ( $sum['skipped']['not_confirmed'] ?? 0 ), 'unconfirmed anchor refused (only confirmed truth is imported)' );

bf_reset( array( 24 => 'eeee5555-0000-4000-8000-000000000005' ) );
$bad = bf_record( 'eeee5555-0000-4000-8000-000000000005' );
$bad['payload']['version'] = 2;
$bad['content_hash'] = sn_prov_content_hash( sn_prov_canonical_json( $bad['payload'] ) );
$GLOBALS['__http']['notes/eeee5555'] = array( 'code' => 200, 'body' => json_encode( $bad ) );
$sum = sn_prov_backfill_run( 'bf_fetcher' );
ok( 1 === ( $sum['skipped']['not_v1'] ?? 0 ), 'a non-v1 record refused (this module only fills the v1 gap)' );

bf_reset( array( 25 => 'ffff6666-0000-4000-8000-000000000006' ) );
$GLOBALS['__http']['notes/ffff6666'] = array( 'code' => 200, 'body' => 'not json at all' );
$sum = sn_prov_backfill_run( 'bf_fetcher' );
ok( 1 === ( $sum['skipped']['ledger_unreachable'] ?? 0 ), 'garbage body counts as unreachable, never as data' );

echo "\nGroup: outage vs missing — an outage is a gap in evidence, not data\n";
bf_reset( array( 31 => 'aaaa7777-0000-4000-8000-000000000007' ) );
$GLOBALS['__http']['notes/aaaa7777'] = array( 'code' => 0, 'body' => '' );
$sum = sn_prov_backfill_run( 'bf_fetcher' );
ok( 1 === ( $sum['skipped']['ledger_unreachable'] ?? 0 ), 'network error skips as unreachable' );
bf_reset( array( 32 => 'bbbb8888-0000-4000-8000-000000000008' ) );
$sum = sn_prov_backfill_run( 'bf_fetcher' ); // default stub answers 404
ok( 1 === ( $sum['skipped']['ledger_missing'] ?? 0 ), '404 skips as ledger_missing (a real answer)' );

echo "\nGroup: the v0-genesis gate (live miss, v10.3.1)\n";
// Genesis seeded a v0 entry on every Note that existed at genesis time —
// the 14 backfilled Notes have [v0]-only chains, NOT empty ones. A v0-only
// chain still 404s confirms (no v1 entry), so it IS a candidate; the import
// appends v1 AFTER the genesis entry, preserving v0 byte-identically.
bf_reset( array( 51 => 'aaaa5151-0000-4000-8000-000000000051' ) );
$v0 = array( 'version' => 0, 'status' => 'genesis', 'genesis' => true, 'content_hash' => 'leafleafleaf' );
$GLOBALS['__meta'][51][ SN_PROV_CHAIN_META ] = array( $v0 );
ok( array( 51 ) === sn_prov_backfill_candidates(), 'a v0-only genesis chain IS a candidate (it still lacks a v1)' );
$rec51 = bf_record( 'aaaa5151-0000-4000-8000-000000000051' );
$GLOBALS['__http']['notes/aaaa5151'] = array( 'code' => 200, 'body' => json_encode( $rec51 ) );
$sum = sn_prov_backfill_run( 'bf_fetcher' );
ok( 1 === $sum['imported'], 'the v0-only candidate imports' );
$chain51 = sn_prov_get_chain( 51 );
ok( 2 === count( $chain51 ) && $chain51[0] === $v0, 'v1 appended AFTER the genesis v0, which stays byte-identical' );
ok( 1 === $chain51[1]['version'] && $chain51[1]['parent'] === $rec51['payload']['parent'], 'the imported v1 keeps the RECORD-authoritative parent (what was actually hashed), never a recomputed one' );

echo "\nGroup: idempotence\n";
bf_reset( array( 41 => 'cccc9999-0000-4000-8000-000000000009' ) );
// v10.67.0: this fixture gained a signature. Its INTENT was always "a real
// commit is finished work, leave it alone" — but it expressed that with an
// UNSIGNED commit, which is exactly the broken shape the repair path now
// reclaims. Signed, it still asserts the original contract.
$GLOBALS['__meta'][41][ SN_PROV_CHAIN_META ] = array( array( 'version' => 1, 'status' => 'confirmed', 'signature' => 'sig==' ) );
ok( array() === sn_prov_backfill_candidates(), 'a post with a REAL, SIGNED (v1+) commit is never a candidate' );
bf_reset( array( 42 => 'dddd0000-0000-4000-8000-00000000000a' ) );
$rec2 = bf_record( 'dddd0000-0000-4000-8000-00000000000a' );
$GLOBALS['__http']['notes/dddd0000'] = array( 'code' => 200, 'body' => json_encode( $rec2 ) );
sn_prov_backfill_run( 'bf_fetcher' );
$sum = sn_prov_backfill_run( 'bf_fetcher' );
ok( 0 === $sum['imported'], 'a second run imports nothing (the chain now exists)' );
ok( 1 === count( sn_prov_get_chain( 42 ) ), 'and the chain still holds exactly one commit' );

function bf_zero_budget() { return 0; }

echo "\nGroup: the COUNT is a census; the RUN is what is bounded (v12.22.1)\n";
// This group used to assert the opposite — that candidates() returned at most
// SN_PROV_BACKFILL_CAP. That contract is what produced the panel reading
// "25 published Notes cannot currently be verified" when the true number was
// higher and unknowable: a guard had quietly become the answer. The bound moved
// onto the run, where the cost actually is, so the number a human reads is now
// the real one.
$many = array();
for ( $i = 100; $i < 140; $i++ ) { $many[ $i ] = sprintf( 'aaaa%04d-0000-4000-8000-00000000cap0', $i ); }
bf_reset( $many );
ok( 40 === count( sn_prov_backfill_candidates() ), 'every candidate is counted, not the first SN_PROV_BACKFILL_CAP of them (' . count( sn_prov_backfill_candidates() ) . ')' );
ok( SN_PROV_BACKFILL_CAP > 40, 'the per-run ceiling is a backstop above this fixture, so the TIME budget is what this group exercises' );

// A zero-second budget stops the run before the first fetch. The point is not
// the number zero: it is that a bounded run REPORTS what it did not reach, so a
// partial pass can never read as a finished one.
add_filter( 'sn_prov_backfill_time_budget', 'bf_zero_budget' );
$bounded = sn_prov_backfill_run( 'bf_fetcher' );
remove_filter( 'sn_prov_backfill_time_budget', 'bf_zero_budget' );
ok( 'time' === ( $bounded['stopped'] ?? '' ), 'a run that hits its time budget says so' );
ok( 40 === (int) ( $bounded['total'] ?? 0 ), 'and reports the full population it was working against' );
ok( 40 === (int) ( $bounded['remaining'] ?? 0 ), 'and reports what is still left, recomputed rather than subtracted' );
ok( 0 === (int) ( $bounded['imported'] ?? -1 ), 'having imported nothing' );

echo "\nGroup: an imported commit must be VERIFIABLE (v10.67.0 — the assertion nobody wrote)\n";
// THE DEFECT THIS SUITE MISSED FOR A YEAR. Every gate below was tested: uid
// match, tampered hash, unconfirmed, not-v1, malformed, idempotence, the cap.
// Not one asked whether the thing the import PRODUCES actually works. It did
// not: the built commit dropped the record's `signature` and `pubkey_id`, so
// sn_prov_credential() refused it ("unsigned - the proof does not exist yet")
// and /verify answered "No public credential exists for this Note" for
// **18 of 30 live Notes** while every dashboard read CONFIRMED and the
// integrity sweep read clean.
//
// The lesson is the shape of the gap, not the field: the suite verified every
// REFUSAL and never verified the SUCCESS end to end.
$rec_v = bf_record( 'aaaabbbb-cccc-4ddd-8eee-ffff00001111' );
$built_v = sn_prov_backfill_commit_from_record( 'aaaabbbb-cccc-4ddd-8eee-ffff00001111', $rec_v );
ok( ! empty( $built_v['ok'] ), 'the record builds' );
ok( 'sig==' === ( $built_v['commit']['signature'] ?? '' ), 'the built commit CARRIES the ledger signature' );
ok( 'sn-ed25519-2026-07' === ( $built_v['commit']['pubkey_id'] ?? '' ), 'and the pubkey_id that names the key it was signed with' );

// End to end: a post whose ONLY commit is an imported one must produce a credential.
bf_reset( array( 77 => 'aaaabbbb-cccc-4ddd-8eee-ffff00001111' ) );
$GLOBALS['__http']['notes/aaaabbbb-cccc-4ddd-8eee-ffff00001111/v1.json'] = array( 'code' => 200, 'body' => json_encode( $rec_v ) );
$run_v = sn_prov_backfill_run( 'bf_fetcher' );
ok( 1 === (int) $run_v['imported'], 'it imports' );
$cred = sn_prov_credential( 77 );
ok( null !== $cred, 'THE ROUND TRIP: an imported commit produces a credential (was null -> a live 404)' );
ok( is_array( $cred ) && '' !== (string) ( $cred['proof']['proofValue'] ?? '' ), 'the credential carries the signature as proofValue' );

// The WRITE-BOUNDARY guard, and the reason there is no 19th health check for
// this: the invariant is one our own code controls, so it is enforced where the
// write happens rather than watched on a 24h cadence. An unsigned record can
// never become a verifiable credential, so importing one would recreate the
// exact defect this release repairs.
$rec_uns = bf_record( 'dddd0000-1111-4222-8333-444455556666' );
unset( $rec_uns['signature'] );
$built_uns = sn_prov_backfill_commit_from_record( 'dddd0000-1111-4222-8333-444455556666', $rec_uns );
ok( empty( $built_uns['ok'] ), 'an UNSIGNED ledger record is refused, never imported' );
ok( 'record_unsigned' === ( $built_uns['reason'] ?? '' ), 'and the refusal carries its own reason, so a run says exactly why' );
$rec_blank = bf_record( 'eeee0000-1111-4222-8333-444455556666' );
$rec_blank['signature'] = '';
ok( 'record_unsigned' === ( sn_prov_backfill_commit_from_record( 'eeee0000-1111-4222-8333-444455556666', $rec_blank )['reason'] ?? '' ), 'an EMPTY signature is refused identically to a missing one' );

echo "\nGroup: repairing the ALREADY-imported unsigned commits (v10.67.0)\n";
// Fixing the builder repairs nothing already written: those posts have a real
// v1 commit, so chain_has_real_commit() excludes them and the panel never
// offers them again. They need their own, narrower path.
$uid_r = 'bbbbcccc-dddd-4eee-8fff-000011112222';
$rec_r = bf_record( $uid_r );
$unsigned = $built_v['commit'];
$unsigned['payload']      = $rec_r['payload'];
$unsigned['content_hash'] = $rec_r['content_hash'];
unset( $unsigned['signature'], $unsigned['pubkey_id'] );

bf_reset( array( 88 => $uid_r ) );
$GLOBALS['__meta'][88][ SN_PROV_CHAIN_META ] = array( $unsigned );
ok( true === sn_prov_backfill_chain_has_real_commit( array( $unsigned ) ), 'precondition: an unsigned v1 IS a real commit, so the old gate skips it' );
ok( true === sn_prov_backfill_chain_needs_signature( array( $unsigned ) ), 'an unsigned v1+ commit is flagged as repairable' );
ok( false === sn_prov_backfill_chain_needs_signature( array( $built_v['commit'] ) ), 'a SIGNED commit is never flagged' );
ok( in_array( 88, sn_prov_backfill_candidates(), true ), 'the post becomes a candidate again, so the panel offers it' );

$GLOBALS['__http'][ 'notes/' . $uid_r . '/v1.json' ] = array( 'code' => 200, 'body' => json_encode( $rec_r ) );
$run_r = sn_prov_backfill_run( 'bf_fetcher' );
ok( 1 === (int) ( $run_r['repaired'] ?? 0 ), 'the run reports it as REPAIRED, distinct from imported' );
$chain_r = sn_prov_get_chain( 88 );
ok( 1 === count( $chain_r ), 'repair REPLACES in place - it never appends a second v1' );
ok( 'sig==' === ( $chain_r[0]['signature'] ?? '' ), 'the signature is now present' );
ok( null !== sn_prov_credential( 88 ), 'and the Note can finally produce a credential' );

// The safety gate: repair may only ever fill in a missing signature, never
// rewrite content. A record that disagrees with the stored commit is refused.
$uid_x = 'ccccdddd-eeee-4fff-8000-111122223333';
$rec_x = bf_record( $uid_x );
$mismatched = $unsigned;
$mismatched['content_hash'] = str_repeat( '9', 64 );
bf_reset( array( 99 => $uid_x ) );
$GLOBALS['__meta'][99][ SN_PROV_CHAIN_META ] = array( $mismatched );
$GLOBALS['__http'][ 'notes/' . $uid_x . '/v1.json' ] = array( 'code' => 200, 'body' => json_encode( $rec_x ) );
$run_x = sn_prov_backfill_run( 'bf_fetcher' );
ok( 0 === (int) ( $run_x['repaired'] ?? 0 ), 'a record that disagrees with the stored commit is NOT repaired' );
ok( isset( $run_x['skipped']['repair_hash_mismatch'] ), 'and the refusal is counted by its own reason' );
ok( ! isset( sn_prov_get_chain( 99 )[0]['signature'] ), 'the stored commit is left exactly as it was' );

echo "\nGroup: loader wiring\n";
$loader = (string) file_get_contents( SNT_PATH . 'signal-and-noise-tools.php' );
ok( false !== strpos( $loader, "inc/provenance-chain-backfill.php" ), 'the plugin loader requires inc/provenance-chain-backfill.php' );

echo "\nGroup: v12.8.0 — a signed PAGE is a backfill candidate, and lives under pages/\n";

// THE CORPUS. get_posts() defaults to post_type 'post' (documented) and this
// module took that default, so a signed page whose chain meta was missing —
// exactly the gap this module fills — was never eligible to be found.
$GLOBALS['__get_posts_args'] = array();
bf_reset( array() );
sn_prov_backfill_run( 'bf_fetcher' );
$bf_args = $GLOBALS['__get_posts_args'][0] ?? array();
ok( is_array( $bf_args['post_type'] ?? '' ) && in_array( 'page', (array) $bf_args['post_type'], true ) && in_array( 'post', (array) $bf_args['post_type'], true ),
	'the candidate query asks for BOTH post types' );

// THE DIRECTORY. This fetch said notes/ unconditionally, so a page's record was
// looked up where it can never be — and that 404 was counted as ledger_missing,
// a real-sounding answer to a question asked of the wrong directory.
$PUID = 'bbbb2222-0000-4000-8000-000000000002';
bf_reset( array( 31 => $PUID ) );
$GLOBALS['__post']->post_type = 'page';
$GLOBALS['__meta'][31][ SN_PROV_SIGN_META ] = '1';
$GLOBALS['__http'][ 'pages/' . $PUID . '/v1.json' ] = array( 'code' => 200, 'body' => json_encode( bf_record( $PUID ) ) );
$GLOBALS['__asked'] = array();
$sum = sn_prov_backfill_run( 'bf_fetcher' );
ok( 1 === $sum['imported'], 'a signed page imports from pages/ — its record was found where it actually lives' );
ok( false !== strpos( implode( ' ', $GLOBALS['__asked'] ), '/pages/' . $PUID ), 'the fetch asks pages/ for a page' );
ok( false === strpos( implode( ' ', $GLOBALS['__asked'] ), '/notes/' . $PUID ), 'and never asks notes/ — that lookup could only 404 and be counted as ledger_missing' );

// AN UNRESOLVED KIND IS A SKIP WITH ITS OWN REASON, AND FETCHES NOTHING.
bf_reset( array( 32 => $PUID ) );
$GLOBALS['__post']->post_type = 'page';
$GLOBALS['__meta'][32][ SN_PROV_SIGN_META ] = ''; // opt-in gone: not a subject
$GLOBALS['__asked'] = array();
$sum = sn_prov_backfill_run( 'bf_fetcher' );
ok( 1 === ( $sum['skipped']['kind_unresolved'] ?? 0 ), 'an unresolvable kind is its own skip reason' );
ok( 0 === ( $sum['skipped']['ledger_missing'] ?? 0 ), 'and is NOT counted as a missing ledger record — that would blame the ledger for our own unanswered question' );
ok( array() === $GLOBALS['__asked'], 'no URL is fetched at all when the directory is unknown — nothing is guessed' );

$GLOBALS['__post']->post_type = 'post'; // leave the shared stub as we found it

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
