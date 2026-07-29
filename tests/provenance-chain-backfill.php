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
function add_filter() { return true; }
function apply_filters( $tag, $value ) { return $value; }
function do_action() {}

$GLOBALS['__meta'] = array();
function get_post_meta( $post_id, $key, $single = false ) {
	$v = $GLOBALS['__meta'][ (int) $post_id ][ $key ] ?? '';
	return $single ? $v : ( '' === $v ? array() : array( $v ) );
}
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['__meta'][ (int) $post_id ][ $key ] = $value; return true; }

$GLOBALS['__fleet'] = array();
function get_posts( $args = array() ) { return $GLOBALS['__fleet']; }

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
function bf_fetcher( $url ) {
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

echo "\nGroup: idempotence\n";
bf_reset( array( 41 => 'cccc9999-0000-4000-8000-000000000009' ) );
$GLOBALS['__meta'][41][ SN_PROV_CHAIN_META ] = array( array( 'version' => 1, 'status' => 'confirmed' ) );
ok( array() === sn_prov_backfill_candidates(), 'a post with ANY chain is never a candidate' );
bf_reset( array( 42 => 'dddd0000-0000-4000-8000-00000000000a' ) );
$rec2 = bf_record( 'dddd0000-0000-4000-8000-00000000000a' );
$GLOBALS['__http']['notes/dddd0000'] = array( 'code' => 200, 'body' => json_encode( $rec2 ) );
sn_prov_backfill_run( 'bf_fetcher' );
$sum = sn_prov_backfill_run( 'bf_fetcher' );
ok( 0 === $sum['imported'], 'a second run imports nothing (the chain now exists)' );
ok( 1 === count( sn_prov_get_chain( 42 ) ), 'and the chain still holds exactly one commit' );

echo "\nGroup: the cap bounds a surprising candidate set\n";
$many = array();
for ( $i = 100; $i < 140; $i++ ) { $many[ $i ] = sprintf( 'aaaa%04d-0000-4000-8000-00000000cap0', $i ); }
bf_reset( $many );
ok( SN_PROV_BACKFILL_CAP === count( sn_prov_backfill_candidates() ), 'candidates capped at ' . SN_PROV_BACKFILL_CAP );

echo "\nGroup: loader wiring\n";
$loader = (string) file_get_contents( SNT_PATH . 'signal-and-noise-tools.php' );
ok( false !== strpos( $loader, "inc/provenance-chain-backfill.php" ), 'the plugin loader requires inc/provenance-chain-backfill.php' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
