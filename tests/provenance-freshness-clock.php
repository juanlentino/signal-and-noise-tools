<?php
/**
 * Standalone fixture tests for the denormalized freshness clock (v11.11.8).
 *
 * Check 4 (stale posts) used to filter on post_modified_gmt, which bumps on ANY
 * save — a block migration, a bulk re-save — so the staleness clock silently
 * reset without a word of prose changing. The clock is now
 * `_sn_prov_last_commit_gmt`, written from the provenance chain, which only
 * commits on substantive change.
 *
 * The suite pins the parts that can silently be wrong:
 *   - the ISO → MySQL datetime CONVERSION, including a negative control proving
 *     the raw ISO form would compare wrong against Check 4's cutoff;
 *   - newest-wins across a chain, not last-element-wins;
 *   - an unparsable or absent commit time yields '' (unknown stays unknown)
 *     and REMOVES the stamp rather than leaving a stale one;
 *   - BOTH write paths stamp — append AND the supersede/replace path.
 *
 * Run: php tests/provenance-freshness-clock.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );

function add_action( $tag, $cb = null, $prio = 10, $args = 1 ) { return true; }
function add_filter() { return true; }
function apply_filters( $tag, $value ) { return $value; }
function do_action() {}
function wp_json_encode( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); }

$GLOBALS['__meta'] = array();
function get_post_meta( $post_id, $key, $single = false ) {
	$v = $GLOBALS['__meta'][ (int) $post_id ][ $key ] ?? '';
	return $single ? $v : ( '' === $v ? array() : array( $v ) );
}
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['__meta'][ (int) $post_id ][ $key ] = $value; return true; }
function delete_post_meta( $post_id, $key ) { unset( $GLOBALS['__meta'][ (int) $post_id ][ $key ] ); return true; }
function get_posts( $args = array() ) { return array(); }
function get_option( $k, $d = false ) { return $d; }
function update_option( $k, $v, $a = null ) { return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function current_time( $t = 'mysql', $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function esc_html( $s ) { return $s; }
function __( $s, $d = null ) { return $s; }

require_once SNT_PATH . 'inc/provenance-core.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; }
	else { $fail++; echo "  FAIL $label\n"; }
}
function commit_at( $iso ) { return array( 'version' => 1, 'committed_at' => $iso ); }

echo "\nGroup: ISO → MySQL conversion (the format trap)\n";
ok(
	'2026-08-14 09:30:00' === sn_prov_last_commit_gmt_from_chain( array( commit_at( '2026-08-14T09:30:00Z' ) ) ),
	'an ISO commit time converts to MySQL GMT datetime'
);

// THE NEGATIVE CONTROL for the conversion. Check 4 compares the stored clock
// against a cutoff built by gmdate('Y-m-d H:i:s'). String-compared, 'T' (0x54)
// sorts ABOVE ' ' (0x20), so the raw ISO form reads NEWER than a cutoff on the
// very same day — a stale post would never surface. This asserts the bug the
// conversion prevents actually exists, so the conversion cannot be "simplified"
// away later by someone who sees two date strings and assumes they compare.
$cutoff  = '2026-08-14 12:00:00';
$raw_iso = '2026-08-14T09:30:00Z';
ok( ! ( $raw_iso < $cutoff ), 'negative control: raw ISO compares NEWER than a same-day cutoff (the bug)' );
ok( sn_prov_last_commit_gmt_from_chain( array( commit_at( $raw_iso ) ) ) < $cutoff, 'the converted value compares correctly (the fix)' );

echo "\nGroup: which commit wins\n";
ok(
	'2026-08-14 09:30:00' === sn_prov_last_commit_gmt_from_chain( array(
		commit_at( '2026-05-01T00:00:00Z' ),
		commit_at( '2026-08-14T09:30:00Z' ),
		commit_at( '2026-06-20T00:00:00Z' ),
	) ),
	'newest wins, NOT last-element — a superseded head is replaced in place'
);
ok( '' === sn_prov_last_commit_gmt_from_chain( array() ), 'an empty chain commits nothing → ""' );
ok( '' === sn_prov_last_commit_gmt_from_chain( array( commit_at( 'not-a-date' ) ) ), 'an unparsable stamp is not a fresh one → ""' );
ok( '' === sn_prov_last_commit_gmt_from_chain( array( array( 'version' => 0 ) ) ), 'a commit with no committed_at → ""' );
ok(
	'2026-08-14 09:30:00' === sn_prov_last_commit_gmt_from_chain( array( commit_at( 'not-a-date' ), commit_at( '2026-08-14T09:30:00Z' ) ) ),
	'one unparsable entry does not poison a chain that has a real time'
);

echo "\nGroup: the stamp is written, and removed\n";
$GLOBALS['__meta'] = array();
sn_prov_stamp_last_commit( 7, array( commit_at( '2026-08-14T09:30:00Z' ) ) );
ok( '2026-08-14 09:30:00' === get_post_meta( 7, SN_PROV_LAST_COMMIT_META, true ), 'stamp written to _sn_prov_last_commit_gmt' );

// A chain that no longer justifies a stamp must REMOVE it. Leaving the old
// value would report a post as freshly-committed forever; absence makes Check 4
// fall back to post_modified_gmt, which is the honest answer.
sn_prov_stamp_last_commit( 7, array( commit_at( 'not-a-date' ) ) );
ok( '' === get_post_meta( 7, SN_PROV_LAST_COMMIT_META, true ), 'an unstampable chain DELETES the stamp rather than leaving a stale one' );

echo "\nGroup: both write paths stamp\n";
$GLOBALS['__meta'] = array();
sn_prov_append_commit( 11, commit_at( '2026-03-01T00:00:00Z' ) );
ok( '2026-03-01 00:00:00' === get_post_meta( 11, SN_PROV_LAST_COMMIT_META, true ), 'sn_prov_append_commit() stamps' );

// The supersede path replaces the head IN PLACE. If only append stamped, a
// settled edit would leave the clock reading the commit it just replaced.
sn_prov_replace_head_commit( 11, commit_at( '2026-09-09T08:00:00Z' ) );
ok( '2026-09-09 08:00:00' === get_post_meta( 11, SN_PROV_LAST_COMMIT_META, true ), 'sn_prov_replace_head_commit() REFRESHES the stamp (settle window)' );
ok( 1 === count( sn_prov_get_chain( 11 ) ), 'supersede replaced the head rather than appending' );

// Replace on an EMPTY chain falls through to append; the stamp must still land.
$GLOBALS['__meta'] = array();
sn_prov_replace_head_commit( 12, commit_at( '2026-04-04T04:04:04Z' ) );
ok( '2026-04-04 04:04:04' === get_post_meta( 12, SN_PROV_LAST_COMMIT_META, true ), 'replace on an empty chain still stamps (falls through to append)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
