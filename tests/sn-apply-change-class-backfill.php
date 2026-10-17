<?php
/**
 * sn_apply change.type "change_class_backfill": label versions that were
 * minted before change.change_class existed, through the existing primitive
 * sn_prov_set_change_class(), and nothing else.
 *
 * Pins: exactly one version per listed post on the window dates (UTC days of
 * committed_at, genesis v0 excluded) or the whole call refuses naming every
 * candidate; a dry run writes nothing; an apply labels only the planned
 * versions and leaves every payload and hash byte-identical; the fingerprint
 * binds the planned rows (missing 422, stale 409); an unknown or unclassified
 * label is a 422; mode:"revision" is refused.
 *
 * Run: php tests/sn-apply-change-class-backfill.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function eq( $e, $a, $m ) { ok( $e === $a, $m . ( $e === $a ? '' : ' (expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) . ')' ) ); }

/* WP stubs, real WP function names only (stub parity). */
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data( $key = '' ) { return $this->data; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function add_action() { return true; }
function add_filter() { return true; }
function apply_filters( $h, $v ) { return $v; }
function do_action() { return null; }
function current_user_can( $c, $id = null ) { return true; }
function get_current_user_id() { return 1; }

$GLOBALS['__posts']   = array();
$GLOBALS['__meta']    = array();
$GLOBALS['__options'] = array();
$GLOBALS['__writes']  = 0;
function get_post( $id ) { $r = $GLOBALS['__posts'][ (int) $id ] ?? null; return $r ? (object) $r : null; }
function get_the_title( $p ) { $p = is_object( $p ) ? $p : get_post( $p ); return $p ? (string) $p->post_title : ''; }
function get_post_type( $id ) { return 'post'; }
function get_posts( $args ) { return array_keys( $GLOBALS['__posts'] ); }
function get_post_meta( $id, $k, $single = false ) {
	$has = array_key_exists( $k, $GLOBALS['__meta'][ (int) $id ] ?? array() );
	$v   = $has ? $GLOBALS['__meta'][ (int) $id ][ $k ] : null;
	return $single ? ( $has ? $v : '' ) : ( $has ? array( $v ) : array() );
}
// Core contract: update_post_meta() unslashes its value, so a caller must hand it slashed data.
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function update_post_meta( $id, $k, $v ) { $GLOBALS['__writes']++; $GLOBALS['__meta'][ (int) $id ][ $k ] = wp_unslash( $v ); return true; }
function delete_post_meta( $id, $k ) { $GLOBALS['__writes']++; unset( $GLOBALS['__meta'][ (int) $id ][ $k ] ); return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__writes']++; $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( $k ) { $GLOBALS['__writes']++; unset( $GLOBALS['__options'][ $k ] ); return true; }

// The rw door's identity rails and audit log (sn_ project functions).
function sn_mcp_rw_bound_uuid() { return 'owner'; }
function sn_mcp_rw_authenticated_app_password_uuid() { return 'owner'; }
function sn_mcp_rw_audit_record() { return null; }
$GLOBALS['__audit_rows'] = array();
function sn_mcp_rw_audit_get_blob() { return array( 'schema_version' => 1, 'created_at' => 0, 'rows' => $GLOBALS['__audit_rows'] ); }

require __DIR__ . '/../inc/provenance-core.php';
require __DIR__ . '/../inc/abilities-provenance.php';
require __DIR__ . '/../inc/abilities-sn-apply.php';

echo "sn_apply change_class_backfill\n\n";

/* Fixture chains. committed_at is stored ISO-8601 UTC, exactly as sn_prov_record() writes it. */
function cb_entry( $v, $at, $extra = array() ) {
	return array_merge( array(
		'version'      => $v,
		'parent'       => null,
		'content_hash' => hash( 'sha256', "h$v$at" ),
		'bearing_hash' => hash( 'sha256', "b$v$at" ),
		'payload'      => array( 'algo' => 'sn-normalize-v2', 'version' => $v, 'content' => "body $v, a path C:\\notes\\draft" ),
		'status'       => 'confirmed',
		'committed_at' => $at,
	), $extra );
}
function cb_post( $id, array $chain ) {
	$GLOBALS['__posts'][ $id ] = array( 'ID' => $id, 'post_title' => "Note $id", 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '' );
	$GLOBALS['__meta'][ $id ]  = array( SN_PROV_UID_META => "uid-$id", SN_PROV_CHAIN_META => $chain );
}
// 501: genesis v0 on a window date (must be ignored), v1 before the window, v2 inside it.
cb_post( 501, array( cb_entry( 0, '2026-10-16T09:00:00Z' ), cb_entry( 1, '2026-10-01T12:00:00Z' ), cb_entry( 2, '2026-10-16T14:05:00Z' ) ) );
// 502: two window versions, one on each date.
cb_post( 502, array( cb_entry( 1, '2026-10-16T10:00:00Z' ), cb_entry( 2, '2026-10-17T08:00:00Z' ) ) );
// 503: nothing in the window.
cb_post( 503, array( cb_entry( 1, '2026-09-20T10:00:00Z' ) ) );
// 504: one window version at 23:59:59 UTC on the 17th, already labeled copy.
cb_post( 504, array( cb_entry( 1, '2026-10-17T23:59:59Z', array( 'change_class' => 'copy' ) ) ) );
// 505: just past the window in UTC (a site-local reading would pull it in).
cb_post( 505, array( cb_entry( 1, '2026-10-18T00:00:01Z' ) ) );

// One applied sn_apply write on the 16th, nothing on the 17th.
$GLOBALS['__audit_rows'] = array(
	array( 'ts' => strtotime( '2026-10-16 14:05:03 UTC' ), 'slug' => 'signal-noise/sn-apply', 'outcome' => 'ok', 'args_redacted' => array( 'change_type' => 'sentence_replace', 'dry_run' => false, 'applied' => true ) ),
	array( 'ts' => strtotime( '2026-10-17 09:00:00 UTC' ), 'slug' => 'signal-noise/sn-apply', 'outcome' => 'ok', 'args_redacted' => array( 'change_type' => 'sentence_replace', 'dry_run' => true, 'applied' => false ) ),
);

$window = array( '2026-10-16', '2026-10-17' );
function cb_call( array $labels, array $over = array() ) {
	global $window;
	$change = array_merge( array(
		'type'    => 'change_class_backfill',
		'payload' => array( 'window_dates' => $window, 'labels' => $labels ),
	), $over['change'] ?? array() );
	$input = array( 'target' => array( 'scope' => 'provenance_labels' ), 'mode' => $over['mode'] ?? 'publish', 'change' => $change );
	if ( array_key_exists( 'dry_run', $over ) ) {
		$input['dry_run'] = $over['dry_run'];
	}
	return snt_ability_sn_apply( $input );
}
function cb_body( $r ) { return is_wp_error( $r ) ? json_decode( (string) $r->get_error_message(), true ) : $r; }

$good = array( array( 'post_id' => 501, 'change_class' => 'reading_aid' ), array( 'post_id' => 504, 'change_class' => 'correction' ) );

echo "Observe: a dry run without a fingerprint returns the plan and the fingerprint\n";
$before_meta = $GLOBALS['__meta'];
$GLOBALS['__writes'] = 0;
$r    = cb_call( $good );
$body = cb_body( $r );
$rows = $body['diff']['rows'] ?? array();
ok( is_wp_error( $r ) && 422 === ( $r->get_error_data()['status'] ?? 0 ), 'CB1.1: a missing fingerprint is a 422 (the roadmap_board observe idiom)' );
ok( is_string( $body['gates']['fingerprint']['observed'] ?? null ) && 64 === strlen( $body['gates']['fingerprint']['observed'] ), 'CB1.2: gates.fingerprint.observed carries the plan fingerprint' );
eq( 2, count( $rows ), 'CB1.3: one planned row per listed post' );
eq( array( 501, 2, '2026-10-16T14:05:00Z', '', 'reading_aid' ), array( $rows[0]['post_id'] ?? 0, $rows[0]['version'] ?? 0, $rows[0]['minted_at'] ?? '', $rows[0]['current_class'] ?? null, $rows[0]['proposed_class'] ?? '' ), 'CB1.4: 501 plans its single window version v2 (genesis v0 on the same day ignored)' );
eq( 'Note 501', $rows[0]['title'] ?? '', 'CB1.5: the row carries the title' );
eq( array( 504, 1, 'copy', 'correction' ), array( $rows[1]['post_id'] ?? 0, $rows[1]['version'] ?? 0, $rows[1]['current_class'] ?? '', $rows[1]['proposed_class'] ?? '' ), 'CB1.6: 504 plans v1 at 23:59:59Z on the 17th, showing its current label' );
eq( 'sn_apply_write_same_day', $rows[0]['audit_match'] ?? '', 'CB1.7: 501 has an applied sn_apply write on its day' );
eq( 'no_sn_apply_write_that_day', $rows[1]['audit_match'] ?? '', 'CB1.8: 504 is flagged: no applied sn_apply write that day (the dry run row does not count)' );
eq( false, $body['diff']['audit']['records_versions'] ?? null, 'CB1.9: the response says the audit log does not record minted versions' );
$wb = $body['diff']['change_classes_after'] ?? array();
eq( array( 'reading_aid' => 1, 'correction' => 1, 'copy' => 0 ), array( 'reading_aid' => $wb['reading_aid'] ?? -1, 'correction' => $wb['correction'] ?? -1, 'copy' => $wb['copy'] ?? -1 ), 'CB1.10: the would-be totals move one copy to correction and one unclassified to reading_aid' );
eq( 0, $GLOBALS['__writes'], 'CB1.11: nothing written' );
$fp = (string) ( $body['gates']['fingerprint']['observed'] ?? '' );

echo "\nA dry run with the fingerprint previews cleanly and still writes nothing\n";
$GLOBALS['__writes'] = 0;
$r = cb_call( $good, array( 'change' => array( 'fingerprint' => $fp ) ) );
ok( ! is_wp_error( $r ) && false === ( $r['applied'] ?? null ), 'CB2.1: dry run (the default) previews without applying' );
eq( 2, count( is_array( $r ) ? ( $r['diff']['rows'] ?? array() ) : array() ), 'CB2.2: the preview carries the rows' );
eq( 0, $GLOBALS['__writes'], 'CB2.3: zero writes' );
eq( $before_meta, $GLOBALS['__meta'], 'CB2.4: postmeta byte-identical' );

echo "\nRefusals name every candidate\n";
$r    = cb_call( array( array( 'post_id' => 502, 'change_class' => 'copy' ), array( 'post_id' => 503, 'change_class' => 'copy' ), array( 'post_id' => 501, 'change_class' => 'copy' ) ) );
$body = cb_body( $r );
ok( is_wp_error( $r ) && 422 === ( $r->get_error_data()['status'] ?? 0 ), 'CB3.1: two window versions or none refuses the whole call (422)' );
$ref = array();
foreach ( $body['diff']['refused'] ?? array() as $x ) { $ref[ $x['post_id'] ] = $x; }
eq( array( 502, 503 ), array_keys( $ref ), 'CB3.2: both offending posts are listed, the good one is not' );
eq( array( array( 'version' => 1, 'minted_at' => '2026-10-16T10:00:00Z' ), array( 'version' => 2, 'minted_at' => '2026-10-17T08:00:00Z' ) ), $ref[502]['candidates'] ?? null, 'CB3.3: 502 lists both window versions with minted_at' );
eq( array(), $ref[503]['candidates'] ?? null, 'CB3.4: 503 lists zero candidates' );
ok( false !== strpos( (string) $r->get_error_message(), '502' ), 'CB3.5: the refusal message names the post' );
$r = cb_call( array( array( 'post_id' => 505, 'change_class' => 'copy' ) ) );
eq( array( 505 ), array_column( cb_body( $r )['diff']['refused'] ?? array(), 'post_id' ), 'CB3.6: a version at 00:00:01Z on the 18th is outside the window (UTC days)' );

echo "\nApply labels only the planned versions\n";
$GLOBALS['__writes'] = 0;
$r = cb_call( $good, array( 'dry_run' => false, 'change' => array( 'fingerprint' => $fp ) ) );
ok( ! is_wp_error( $r ) && true === ( $r['applied'] ?? null ), 'CB4.1: applied' );
$c501 = $GLOBALS['__meta'][501][ SN_PROV_CHAIN_META ];
$c504 = $GLOBALS['__meta'][504][ SN_PROV_CHAIN_META ];
eq( 'reading_aid', $c501[2]['change_class'] ?? null, 'CB4.2: 501 v2 labeled' );
ok( ! array_key_exists( 'change_class', $c501[0] ) && ! array_key_exists( 'change_class', $c501[1] ), 'CB4.3: 501 v0 and v1 untouched' );
eq( 'correction', $c504[0]['change_class'] ?? null, 'CB4.4: 504 v1 relabeled' );
$same = true;
foreach ( array( 501, 502, 503, 504, 505 ) as $pid ) {
	foreach ( $before_meta[ $pid ][ SN_PROV_CHAIN_META ] as $i => $e ) {
		$after = $GLOBALS['__meta'][ $pid ][ SN_PROV_CHAIN_META ][ $i ];
		$same  = $same && wp_json_encode( $e['payload'] ) === wp_json_encode( $after['payload'] ) && $e['content_hash'] === $after['content_hash'] && $e['bearing_hash'] === $after['bearing_hash'];
	}
}
ok( $same, 'CB4.5: every payload, content_hash and bearing_hash byte-identical' );
eq( $before_meta[502], $GLOBALS['__meta'][502], 'CB4.6: an unlisted post is untouched' );
eq( 1, is_array( $r ) ? ( $r['diff']['change_classes_after']['reading_aid'] ?? null ) : null, 'CB4.7: the response reports the new totals' );

echo "\nStale fingerprint, bad labels, revision mode\n";
$snap = $GLOBALS['__meta'];
$r    = cb_call( $good, array( 'dry_run' => false, 'change' => array( 'fingerprint' => $fp, 'payload' => array( 'window_dates' => $window, 'labels' => $good, 'expected_counts' => array( 'reading_aid' => 1, 'correction' => 1 ) ) ) ) );
ok( is_wp_error( $r ) && 409 === ( $r->get_error_data()['status'] ?? 0 ), 'CB5.1: the labels moved, so the old fingerprint is stale (409)' );
eq( $snap, $GLOBALS['__meta'], 'CB5.2: a stale call writes nothing' );
$r = cb_call( array( array( 'post_id' => 501, 'change_class' => 'wibble' ) ) );
ok( is_wp_error( $r ) && 422 === ( $r->get_error_data()['status'] ?? 0 ), 'CB5.3: an unknown class is a 422' );
$r = cb_call( array( array( 'post_id' => 501, 'change_class' => 'unclassified' ) ) );
ok( is_wp_error( $r ) && 422 === ( $r->get_error_data()['status'] ?? 0 ), 'CB5.4: unclassified is not a storable label (422)' );
$r = cb_call( $good, array( 'change' => array( 'payload' => array( 'window_dates' => $window, 'labels' => $good, 'expected_counts' => array( 'reading_aid' => 2 ) ) ) ) );
ok( is_wp_error( $r ) && 422 === ( $r->get_error_data()['status'] ?? 0 ), 'CB5.5: expected_counts that disagree with the plan refuse (422)' );
$fp2 = (string) ( cb_body( cb_call( $good ) )['gates']['fingerprint']['observed'] ?? '' );
$r   = cb_call( $good, array( 'mode' => 'revision', 'dry_run' => false, 'change' => array( 'fingerprint' => $fp2 ) ) );
ok( is_wp_error( $r ) && 403 === ( $r->get_error_data()['status'] ?? 0 ), 'CB5.6: mode:"revision" is refused (publish-only)' );
eq( $snap, $GLOBALS['__meta'], 'CB5.7: refusals write nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
