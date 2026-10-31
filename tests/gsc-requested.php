<?php
/**
 * "I requested indexing", and the live check (2026-10-31).
 *
 * Properties: (1) a row's status reads Google's answer against the owner's
 * mark: indexed, crawled since the mark and still out, waiting (no crawl since),
 * or not requested; (2) a mark younger than 14 days with no crawl after it is
 * quiet, an older one or a crawl after it is not; (3) only a path the
 * inspection map holds, not indexed, can be marked; (4) marks prune when the
 * path leaves the map, 30 days after it indexed, or after 90 days; (5) Check
 * now targets the request list and the marked rows, deduped, forces each
 * inspection, stops at its time budget and says what is left, and refuses a
 * second press within 10 minutes of a complete check.
 *
 * Run: php tests/gsc-requested.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
function __( $s, $d = null ) { return $s; }
function add_action( $t, $c, $p = 10, $a = 1 ) { return true; }
class WP_Error { public $c; public function __construct( $c = '', $m = '' ) { $this->c = $c; } public function get_error_code() { return $this->c; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['opt'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function get_post_meta( $id, $k, $single = false ) { return ''; }
function get_post( $id ) { return (object) array( 'ID' => (int) $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '' ); }
function get_permalink( $p ) { return 'https://example.test/notes/' . ( is_object( $p ) ? $p->ID : $p ) . '/'; }
$GLOBALS['ready'] = true;
function snt_gsc_sync_is_ready() { return $GLOBALS['ready']; }
function snt_gsc_coverage_data() { $d = get_option( 'snt_gsc_coverage' ); return is_array( $d ) ? $d : null; }
require __DIR__ . '/../inc/post-settings.php';
require __DIR__ . '/../inc/indexable.php';
require __DIR__ . '/../inc/search-console-index-watch.php';
require __DIR__ . '/../inc/search-console-requested.php';

$now = 1793450000; // 2026-10-31
$day = DAY_IN_SECONDS;
$iso = static fn( $t ) => gmdate( 'Y-m-d\TH:i:s\Z', $t );
$e   = static fn( $state, $pid, $crawl = '' ) => array( 'coverage_state' => $state, 'indexed' => 0 === strpos( $state, 'Submitted and indexed' ), 'last_crawl_time' => $crawl, 'kind' => 'post', 'post_id' => $pid, 'url' => 'https://example.test/notes/' . $pid . '/' );

echo "status\n";
$st = static fn( $entry, $mark ) => snt_gsc_request_status( $entry, $mark, $now );
ok( 'not_requested' === $st( $e( 'Discovered - currently not indexed', 1 ), 0 )['state'], 'no mark: not requested' );
$w = $st( $e( 'Discovered - currently not indexed', 1 ), $now - 3 * $day );
ok( 'waiting' === $w['state'] && true === $w['quiet'] && 0 === $w['last_crawl'], 'marked 3 days ago, never crawled: waiting on Google, quiet' );
$w = $st( $e( 'Crawled - currently not indexed', 1, $iso( $now - 20 * $day ) ), $now - 3 * $day );
ok( 'waiting' === $w['state'] && true === $w['quiet'] && $now - 20 * $day === $w['last_crawl'], 'a crawl BEFORE the mark is not a crawl since: still waiting, and the crawl date is kept' );
$w = $st( $e( 'Crawled - currently not indexed', 1, $iso( $now - $day ) ), $now - 3 * $day );
ok( 'crawled_since' === $w['state'] && false === $w['quiet'], 'crawled after the mark and still out: asks again (not quiet)' );
$w = $st( $e( 'Discovered - currently not indexed', 1 ), $now - 15 * $day );
ok( 'waiting' === $w['state'] && false === $w['quiet'], 'a mark older than 14 days with no crawl is no longer quiet' );
ok( 'indexed' === $st( $e( 'Submitted and indexed', 1, $iso( $now - $day ) ), $now - 3 * $day )['state'], 'indexed is indexed, mark or not' );
ok( 'waiting' === $st( array(), $now - $day )['state'], 'an entry the map no longer holds reads as waiting, never as indexed' );

echo "\nstatuses and the indexed-after list\n";
$entries = array(
	'/notes/a' => $e( 'Discovered - currently not indexed', 11 ),
	'/notes/b' => $e( 'Submitted and indexed', 12, $iso( $now - $day ) ),
	'/notes/c' => $e( 'Crawled - currently not indexed', 13, $iso( $now - 2 * $day ) ),
);
$marks = array( '/notes/a' => $now - 2 * $day, '/notes/b' => $now - 5 * $day );
$x = snt_gsc_request_statuses( $entries, $marks, array( '/notes/a', '/notes/c', '/notes/a' ), $now );
ok( array( '/notes/a', '/notes/c' ) === array_keys( $x['status'] ) && 'waiting' === $x['status']['/notes/a']['state'] && 'not_requested' === $x['status']['/notes/c']['state'], 'one status per listed path, deduped' );
ok( array( '/notes/b' ) === array_column( $x['indexed_after_request'], 'path' ) && $now - 5 * $day === $x['indexed_after_request'][0]['requested_at'], 'a marked page now indexed is listed as indexed after the request' );
ok( array( '/notes/a' ) === snt_gsc_quiet_paths( $entries, $marks, $now ), 'the quiet set: marked, not crawled since, under 14 days' );

echo "\nmarking\n";
$GLOBALS['opt']['snt_gsc_coverage'] = array( 'entries' => $entries );
ok( true === snt_gsc_mark_requested( '/notes/c', $now ) && $now === snt_gsc_requested_marks()['/notes/c'], 'a path on the map, not indexed, is marked with the time of the mark' );
ok( false === snt_gsc_mark_requested( '/notes/b', $now ), 'an indexed page cannot be marked' );
ok( false === snt_gsc_mark_requested( '/not-on-the-map', $now ) && false === snt_gsc_mark_requested( '<script>', $now ), 'a path the map does not hold cannot be marked' );
$pruned = snt_gsc_requested_prune( array( '/notes/a' => $now - 91 * $day, '/notes/b' => $now - 31 * $day, '/notes/c' => $now - 31 * $day, '/gone' => $now ), $entries, $now );
ok( array( '/notes/c' ) === array_keys( $pruned ), 'prune: past 90 days, indexed past 30, and off the map go; a 31-day mark still out stays' );

echo "\ncheck now\n";
$pub     = array( 11 => $now - 40 * $day, 12 => $now - 40 * $day, 13 => $now - 40 * $day, 14 => $now - 2 * $day );
$entries['/notes/young'] = $e( 'Discovered - currently not indexed', 14 );
$marks['/notes/young']   = $now - $day;
ok( array( 11, 14, 13 ) === snt_gsc_check_now_targets( $entries, $pub, $marks, $now ), 'targets: marked rows not indexed first, then the request list (7 days on), deduped; indexed rows are not spent on' );
$GLOBALS['opt'] = array();
$calls = array();
$inspect = static function ( $id, $days, $force ) use ( &$calls ) { $calls[] = array( $id, $force ); return array( 'coverage_state' => 'x' ); };
$tick  = 0;
$clock = static function () use ( &$tick ) { return $tick; };
$r = snt_gsc_check_now( $now, array( 11, 13, 14 ), $inspect, $clock );
ok( 3 === $r['checked'] && 0 === $r['remaining'] && array( array( 11, true ), array( 13, true ), array( 14, true ) ) === $calls, 'every target is inspected with force (the 6-day freshness guard would skip a map from Oct 28)' );
ok( is_wp_error( snt_gsc_check_now( $now + 60, array( 11 ), $inspect, $clock ) ), 'a second press within 10 minutes of a complete check is refused' );
ok( ! is_wp_error( snt_gsc_check_now( $now + 601, array( 11 ), $inspect, $clock ) ), '...and allowed after' );
$GLOBALS['opt'] = array();
$calls = array();
$slow  = static function ( $id, $days, $force ) use ( &$calls, &$tick ) { $calls[] = $id; $tick += 12; return 13 === $id ? new WP_Error( 'x' ) : array( 'coverage_state' => 'x' ); };
$r = snt_gsc_check_now( $now, array( 11, 13, 14 ), $slow, $clock );
ok( 2 === $r['checked'] && 1 === $r['remaining'] && 1 === $r['errors'] && true === $r['budget_hit'], 'past the time budget it stops and says what is left; an error is counted, not hidden' );
ok( ! is_wp_error( snt_gsc_check_now( $now + 60, array( 14 ), $inspect, $clock ) ), 'a partial check can be pressed again at once for the rest' );
// Review 2026-10-31: "press again for the rest" reaches the rest, never the same rows twice.
$GLOBALS['opt'] = array();
$calls = array();
$tick  = 0;
$ents  = $entries;
$mark_seen = static function ( $id, $days, $force ) use ( &$calls, &$tick, &$ents, $now ) {
	$calls[] = $id;
	$tick   += 12;
	foreach ( $ents as $p => $en ) {
		if ( (int) $en['post_id'] === $id ) { $ents[ $p ]['inspected_at'] = $now; }
	}
	return array( 'coverage_state' => 'x' );
};
$r1 = snt_gsc_check_now( $now, snt_gsc_check_now_targets( $ents, $pub, $marks, $now, snt_gsc_check_now_since() ), $mark_seen, $clock );
$r2 = snt_gsc_check_now( $now + 5, snt_gsc_check_now_targets( $ents, $pub, $marks, $now + 5, snt_gsc_check_now_since() ), $mark_seen, $clock );
ok( 1 === $r1['remaining'] && 0 === $r2['remaining'] && array( 11, 14, 13 ) === $calls, 'two presses check every target once: the second skips what the first answered (' . implode( ',', $calls ) . ')' );
ok( 0 === snt_gsc_check_now_since(), 'after a complete check there is nothing to continue' );
// A check where nothing answered (or nothing was asked) does not lock the next press.
$GLOBALS['opt'] = array();
$fails = static fn( $id, $days, $force ) => new WP_Error( 'x' );
snt_gsc_check_now( $now, array( 11 ), $fails, $clock );
ok( ! is_wp_error( snt_gsc_check_now( $now + 60, array(), $inspect, $clock ) ), 'a check where every page errored does not start the 10-minute lock' );
ok( ! is_wp_error( snt_gsc_check_now( $now + 61, array( 11 ), $inspect, $clock ) ), '...nor does a check with nothing to ask' );
// The indexed-after list keeps its own promise: 30 days, read live, not only when a mark is written.
$x = snt_gsc_request_statuses( $entries, array( '/notes/b' => $now - 31 * $day ), array(), $now );
ok( array() === $x['indexed_after_request'], 'a page indexed after a mark older than 30 days leaves the indexed-after list' );

echo "\nhandlers\n";
function wp_unslash( $v ) { return $v; }
function get_post_time( $f, $gmt, $id ) { return $GLOBALS['pubtime'][ (int) $id ] ?? 0; }
$GLOBALS['refreshed'] = 0;
function sn_indexing_refresh_stored_report() { ++$GLOBALS['refreshed']; return true; }
function snt_gsc_inspect_one( $id, $days = 0, $force = false ) { $GLOBALS['inspected'][] = array( $id, $force ); return array( 'coverage_state' => 'x' ); }
require __DIR__ . '/../inc/admin-post-actions/gsc-indexing.php';
$GLOBALS['ready'] = true;
$GLOBALS['opt']   = array( 'snt_gsc_coverage' => array( 'entries' => $entries ) );
ok( 'gsc_marked' === sn_handle_gsc_mark_requested( array( 'path' => '/notes/a' ) ) && 1 === $GLOBALS['refreshed'] && isset( snt_gsc_requested_marks()['/notes/a'] ), 'mark: a listed path is marked and the stored report refreshed' );
ok( 'gsc_mark_invalid' === sn_handle_gsc_mark_requested( array( 'path' => '/notes/b' ) ) && 'gsc_mark_invalid' === sn_handle_gsc_mark_requested( array() ), 'mark: an indexed or missing path is refused' );
$GLOBALS['opt']['snt_gsc_coverage']['entries']['/notes/caf%c3%a9'] = $e( 'Discovered - currently not indexed', 15 );
ok( 'gsc_marked' === sn_handle_gsc_mark_requested( array( 'path' => '/notes/caf%c3%a9' ) ), 'mark: a percent-encoded join path survives the lookup' );
$GLOBALS['pubtime']   = $pub;
$GLOBALS['inspected'] = array();
ok( 'gsc_checked' === sn_handle_gsc_check_now( array() ) && array() !== $GLOBALS['inspected'] && array( true ) === array_values( array_unique( array_column( $GLOBALS['inspected'], 1 ) ) ), 'check now: every inspection is forced, and the flash says checked' );
ok( 'gsc_check_wait' === sn_handle_gsc_check_now( array() ), 'check now: a second press inside 10 minutes says so' );
$GLOBALS['ready'] = false;
ok( 'gsc_not_ready' === sn_handle_gsc_check_now( array() ), 'check now: not connected says so' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
