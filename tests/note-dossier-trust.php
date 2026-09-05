<?php
/**
 * Standalone test: inc/note-dossier-trust.php — the anchor proof read from the
 * ledger record of the newest CONFIRMED version, the signer against the keys
 * the ledger publishes, the citations received, and the re-check verdict.
 * Every provenance and citation reader is a house-prefixed stub; HTTP is the
 * integrity module's fetcher seam.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function __( $t, $d = null ) { return $t; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__meta'][ (int) $id ][ $key ] ?? ''; }
function get_permalink( $p ) { return 'https://example.test/notes/foo/'; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
class WP_Post { public $ID; public $post_type = 'post'; public $post_status = 'publish'; public $post_password = ''; public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } } }

// ── provenance stubs (house prefix, free) ────────────────────────────────
$GLOBALS['__chain'] = array();
function sn_prov_get_chain( $id ) { return $GLOBALS['__chain']; }
function sn_prov_subject_kind( $post ) { return $GLOBALS['__kind'] ?? 'note'; }
function sn_prov_ledger_dir( $kind ) { return 'note' === $kind ? 'notes' : ( 'page' === $kind ? 'pages' : '' ); }
function sn_prov_integrity_ledger_base() { return 'https://raw.githubusercontent.com/o/r/main/'; }
function sn_prov_key_id() { return 'sn-ed25519-2026-07'; }
function sn_prov_integrity_fetch_json( $url, $fetcher ) { $r = $fetcher( $url ); $j = 200 === (int) $r['code'] ? json_decode( (string) $r['body'], true ) : null; return array( 'code' => (int) $r['code'], 'json' => is_array( $j ) ? $j : null ); }
function sn_prov_integrity_keys_probe( $fetcher ) { return $GLOBALS['__probe']; }
function sn_prov_integrity_check_note( $id, $fetcher, $ids = null ) { $GLOBALS['__check_ids'] = $ids; return $GLOBALS['__check']; }
function sn_prov_integrity_is_outage( $c ) { return in_array( $c, array( 'twin_unreachable', 'ledger_unreachable', 'keys_unreachable' ), true ); }
function sn_prov_integrity_failure_sentence( $c ) { return 'S:' . $c; }
function sn_prov_integrity_http_fetch( $url ) { return array( 'code' => 0, 'body' => '' ); }
function sn_cit_for_post( $id, $public_only = true ) { return $GLOBALS['__cits'] ?? array(); }
function sn_cit_tier_pill_kind( $t ) { return array( 'verified' => 'ok', 'asserted' => 'warn', 'unverified' => 'muted' )[ $t ] ?? ''; }
function sn_cit_ago_label( $gmt ) { return null === $gmt || '' === $gmt ? 'never' : '2 hours ago'; }
function snt_desktop_admin_url( $slug, $sub = '' ) { return 'https://example.test/wp-admin/admin.php?page=sn-theme-options&slug=' . $slug . '&sub=' . $sub; }

require __DIR__ . '/../inc/note-dossier.php';
require __DIR__ . '/../inc/note-dossier-trust.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function fetcher( array $map ) { return function ( $url ) use ( $map ) { foreach ( $map as $needle => $resp ) { if ( false !== strpos( $url, $needle ) ) { return $resp; } } return array( 'code' => 404, 'body' => '' ); }; }
function jsonr( $d ) { return array( 'code' => 200, 'body' => json_encode( $d ) ); }
function by_heading( $blocks, $h ) { foreach ( $blocks as $b ) { if ( $b['heading'] === $h ) { return $b; } } return null; }
echo "note dossier -- trust\n\n";

$GLOBALS['__posts'] = array( 7 => new WP_Post( array( 'ID' => 7 ) ) );
$GLOBALS['__meta']  = array( 7 => array( '_sn_prov_uid' => 'u-7' ) );
$GLOBALS['__chain'] = array(
	array( 'version' => 1, 'status' => 'confirmed', 'content_hash' => 'aaa', 'pubkey_id' => 'sn-ed25519-2026-07', 'block_time' => '2026-08-20T10:00:00Z' ),
	array( 'version' => 2, 'status' => 'pending', 'content_hash' => 'bbb' ),
);
$GLOBALS['__probe'] = array( 'verdict' => 'ok', 'code' => 200, 'published_ids' => array( 'sn-ed25519-2026-07' ) );
$GLOBALS['__cits']  = array( (object) array( 'tier' => 'verified', 'source_url' => 'https://blog.example.org/p', 'source_title' => 'A post', 'last_checked_gmt' => '2026-09-01 10:00:00' ), (object) array( 'tier' => 'unverified', 'source_url' => 'https://x.example.net/y', 'source_title' => '', 'last_checked_gmt' => null ) );
$fetch = fetcher( array( '/notes/u-7/v1.json' => jsonr( array( 'content_hash' => 'sha256:AAA', 'pubkey_id' => 'sn-ed25519-2026-07', 'ots' => array( 'bitcoin_block' => 910123, 'bitcoin_txid' => str_repeat( 'ab', 32 ), 'confirmations' => 6 ) ) ) ) );

$b = sn_note_dossier_trust( 7, $fetch );
$anchor = by_heading( $b, 'Anchor proof' );
ok( 'status' === $anchor['kind'] && 'success' === $anchor['tone'] && false !== strpos( $anchor['text'], 'v1' ) && false !== strpos( $anchor['text'], '910,123' ) && false !== strpos( $anchor['text'], '6 confirmations' ), 'the anchor proof reads the ledger record of the newest CONFIRMED version (v1, not the pending head v2)' );
ok( false !== strpos( $anchor['meta'], 'same content hash' ) && false !== strpos( $anchor['meta'], '2026-08-20T10:00:00Z' ), 'the record attests the same hash (sha256: prefix and case ignored); the local block time is named as the worker reported it' );
ok( 'View the transaction' === $anchor['door']['label'] && false !== strpos( $anchor['door']['url'], 'mempool.space/tx/' . str_repeat( 'ab', 32 ) ), 'the door opens the transaction on the explorer' );
$signer = by_heading( $b, 'Signer' );
ok( 'success' === $signer['tone'] && false !== strpos( $signer['text'], 'sn-ed25519-2026-07' ) && false !== strpos( $signer['text'], 'the followed key' ), 'signed by the followed key, and the ledger publishes it' );
$cits = by_heading( $b, 'Citations received' );
ok( 'table' === $cits['kind'] && 2 === count( $cits['rows'] ) && 'success' === $cits['rows'][0]['tier']['tone'] && 'A post' === $cits['rows'][0]['source'] && 'x.example.net' === $cits['rows'][1]['source'] && 'never' === $cits['rows'][1]['checked'], 'citations: tier as a toned cell, title or host, never vs a time' );

echo "\nthe gaps\n";
$b = sn_note_dossier_trust( 7, fetcher( array( '/notes/u-7/v1.json' => array( 'code' => 0, 'body' => '' ) ) ) );
$anchor = by_heading( $b, 'Anchor proof' );
ok( 'warning' === $anchor['tone'] && false !== stripos( $anchor['text'], 'could not be reached' ), 'the ledger unreachable is a gap, never "not anchored"' );
$b = sn_note_dossier_trust( 7, fetcher( array() ) );
ok( 'warning' === by_heading( $b, 'Anchor proof' )['tone'] && false !== stripos( by_heading( $b, 'Anchor proof' )['text'], 'no record' ), 'a 404 is a real absence and says so' );
$b = sn_note_dossier_trust( 7, fetcher( array( '/notes/u-7/v1.json' => jsonr( array( 'content_hash' => 'zzz' ) ) ) ) );
ok( 'danger' === by_heading( $b, 'Anchor proof' )['tone'], 'a record that attests a different hash is danger' );
$GLOBALS['__probe'] = array( 'verdict' => 'keys_unreachable', 'code' => 0, 'published_ids' => null );
$b = sn_note_dossier_trust( 7, $fetch );
ok( 'warning' === by_heading( $b, 'Signer' )['tone'] && false !== strpos( by_heading( $b, 'Signer' )['text'], 'could not be checked' ), 'keys unreachable: the signer could not be checked, never a mismatch' );
$GLOBALS['__probe'] = array( 'verdict' => 'ok', 'code' => 200, 'published_ids' => array( 'sn-ed25519-2026-09' ) );
$b = sn_note_dossier_trust( 7, $fetch );
ok( 'danger' === by_heading( $b, 'Signer' )['tone'] && false !== strpos( by_heading( $b, 'Signer' )['text'], 'no longer publishes' ), 'a key the ledger dropped is danger' );
$GLOBALS['__probe'] = array( 'verdict' => 'ok', 'code' => 200, 'published_ids' => array( 'sn-ed25519-2026-07', 'sn-ed25519-2026-09' ) );
$GLOBALS['__chain'][0]['pubkey_id'] = 'sn-ed25519-2026-09';
$b = sn_note_dossier_trust( 7, fetcher( array( '/notes/u-7/v1.json' => jsonr( array( 'content_hash' => 'aaa', 'pubkey_id' => 'sn-ed25519-2026-09' ) ) ) ) );
ok( 'info' === by_heading( $b, 'Signer' )['tone'] && false !== strpos( by_heading( $b, 'Signer' )['text'], 'the followed key is now' ), 'a published key that is not the followed one is info, and names the followed one' );
$GLOBALS['__chain'] = array( array( 'version' => 1, 'status' => 'pending', 'content_hash' => 'aaa' ) );
$b = sn_note_dossier_trust( 7, $fetch );
ok( 'neutral' === by_heading( $b, 'Anchor proof' )['tone'] && false !== strpos( by_heading( $b, 'Anchor proof' )['text'], 'No confirmed anchor' ) && 'neutral' === by_heading( $b, 'Signer' )['tone'] && false !== strpos( by_heading( $b, 'Signer' )['text'], 'not recorded' ), 'a pending-only chain: no anchor to read, no signer recorded' );
$GLOBALS['__chain'] = array();
$GLOBALS['__cits']  = array();
$b = sn_note_dossier_trust( 7, $fetch );
ok( 'neutral' === by_heading( $b, 'Anchor proof' )['tone'] && 'text' === by_heading( $b, 'Citations received' )['kind'] && false !== strpos( by_heading( $b, 'Citations received' )['text'], 'No citations' ), 'no chain, no citations: said plainly' );
$GLOBALS['__chain'] = array( array( 'version' => 1, 'status' => 'confirmed', 'content_hash' => 'aaa' ) );
$GLOBALS['__meta']  = array( 7 => array() );
$b = sn_note_dossier_trust( 7, $fetch );
ok( 'warning' === by_heading( $b, 'Anchor proof' )['tone'] && false !== strpos( by_heading( $b, 'Anchor proof' )['text'], 'no ledger UID' ), 'a confirmed commit without a UID is a gap in the lookup, never "no confirmed anchor"' );
$GLOBALS['__meta']  = array( 7 => array( '_sn_prov_uid' => 'u-7' ) );

echo "\nverify\n";
$GLOBALS['__chain'] = array( array( 'version' => 1, 'status' => 'confirmed', 'content_hash' => 'aaa' ) );
$GLOBALS['__probe'] = array( 'verdict' => 'ok', 'code' => 200, 'published_ids' => array( 'sn-ed25519-2026-07' ) );
$GLOBALS['__check'] = array( 'post_id' => 7, 'uid' => 'u-7', 'version' => 1, 'anchored_version' => 1, 'failures' => array(), 'twin_code' => 200 );
$v = sn_note_dossier_verify( 7, $fetch );
ok( 7 === $v['post_id'] && 'success' === $v['tone'] && false !== strpos( $v['text'], 'holds' ) && false !== strpos( $v['meta'], 'twin' ) && false !== strpos( $v['meta'], 'ledger record' ) && false !== strpos( $v['meta'], 'key' ) && preg_match( '/^\d{4}-\d{2}-\d{2}T/', $v['checked_at'] ), 'a clean check: success, says what was checked, stamped' );
ok( array( 'sn-ed25519-2026-07' ) === $GLOBALS['__check_ids'], 'the published key ids from the probe reach the note check (leg d)' );
$GLOBALS['__check']['failures'] = array( 'twin_unreachable' );
$v = sn_note_dossier_verify( 7, $fetch );
ok( 'warning' === $v['tone'] && false !== strpos( $v['meta'], 'S:twin_unreachable' ), 'an outage-only failure is a warning carrying the house sentence' );
$GLOBALS['__check']['failures'] = array( 'twin_unreachable', 'ledger_hash_mismatch' );
$v = sn_note_dossier_verify( 7, $fetch );
ok( 'danger' === $v['tone'] && false !== strpos( $v['meta'], 'S:ledger_hash_mismatch' ), 'a real mismatch is danger even beside an outage' );
$GLOBALS['__probe'] = array( 'verdict' => 'keys_unreachable', 'code' => 0, 'published_ids' => null );
$GLOBALS['__check']['failures'] = array();
$v = sn_note_dossier_verify( 7, $fetch );
ok( 'warning' === $v['tone'] && false !== strpos( $v['meta'], 'S:keys_unreachable' ), 'keys unreachable joins the failures as an outage' );
$GLOBALS['__probe'] = array( 'verdict' => 'ok', 'code' => 200, 'published_ids' => array( 'sn-ed25519-2026-07' ) );
$GLOBALS['__check'] = array( 'post_id' => 7, 'uid' => '', 'version' => 1, 'anchored_version' => 1, 'failures' => array(), 'twin_code' => 200 );
$v = sn_note_dossier_verify( 7, $fetch );
ok( 'success' === $v['tone'] && false === strpos( $v['meta'], 'ledger record for' ) && false !== strpos( $v['meta'], 'no ledger UID' ), 'without a UID the ledger leg never ran (integrity.php:353), and the verdict does not claim it did' );
$GLOBALS['__check'] = array( 'post_id' => 7, 'uid' => 'u-7', 'version' => 1, 'anchored_version' => 1, 'failures' => array( 'subject_kind_unresolved' ), 'twin_code' => 200 );
$v = sn_note_dossier_verify( 7, $fetch );
ok( 'warning' === $v['tone'] && false !== strpos( $v['meta'], 'S:subject_kind_unresolved' ), 'an unresolved subject kind is a gap, never "does not hold"' );
$GLOBALS['__check'] = null;
$v = sn_note_dossier_verify( 7, $fetch );
ok( 'neutral' === $v['tone'] && false !== strpos( $v['text'], 'Nothing to verify' ), 'no signed version: nothing to verify, said so' );
$v = sn_note_dossier_verify( 999, $fetch );
ok( 'warning' === $v['tone'] && 999 === $v['post_id'], 'not a note: a warning verdict, never a crash' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
