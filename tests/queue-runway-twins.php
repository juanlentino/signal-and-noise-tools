<?php
/**
 * The queue's runway (2026-10-14, queue calendar phase 2, item 6): how far
 * ahead the schedule covers, as one sentence ("Covered through Jan 25, 2027
 * (103 days)"), days counted as calendar days from today in the SITE
 * timezone, the scheduled count as secondary text. The same sentence rides
 * the content-queue payload (the SN Queue card) and both Connections ›
 * Scheduled twins, open, never folded. An unknown is said in words.
 *
 * Run: php tests/queue-runway-twins.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post { public $ID; public $post_title; public $post_date_gmt; } // phpcs:ignore
}
$GLOBALS['__opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
function get_the_title( $p = 0 ) { return is_object( $p ) ? $p->post_title : ''; }
function get_edit_post_link( $p = 0, $c = 'display' ) { return 'https://example.test/wp-admin/post.php?post=' . ( is_object( $p ) ? $p->ID : (int) $p ); }
function get_post_time( $f, $gmt, $p ) { return 0; }
function current_time( $type, $gmt = 0 ) { return time(); }
function get_date_from_gmt( $s, $f = 'Y-m-d H:i:s' ) { return (string) $s; }
$GLOBALS['__q'] = array( 'future' => array(), 'publish' => array() );
function get_posts( $a ) { return $GLOBALS['__q'][ $a['post_status'] ] ?? array(); }
function wp_count_posts( $t = 'post' ) { return (object) array( 'draft' => 0 ); }
function sn_schedule_all() { return array(); }
function sn_schedule_future_posts() { return array(); }
function sn_schedule_swap_pairs( $f ) { return array(); }
$post = static function ( $id, $days ) {
	$p                = new WP_Post();
	$p->ID            = $id;
	$p->post_title    = "Note $id";
	$p->post_date_gmt = gmdate( 'Y-m-d H:i:s', time() + (int) ( $days * 86400 ) );
	return $p;
};

require SNT_PATH . 'inc/abilities-content-queue.php';
require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'inc/schedule-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-scheduled-content.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$text = static fn( $h ) => trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( str_replace( '<', ' <', preg_replace( '/<os-section heading="([^"]*)"(?: description="([^"]*)")?/', '<os-section>$1 $2 ', $h ) ) ), ENT_QUOTES ) ) );

// The pure sentence, on the owner's example: today Oct 14, 2026 (noon in New
// York), the last note Jan 25, 2027 at 9:38 AM: 103 calendar days.
$tz  = new DateTimeZone( 'America/New_York' );
$at  = static fn( $s ) => ( new DateTimeImmutable( $s, $tz ) )->getTimestamp();
$now = $at( '2026-10-14 12:00' );
$r   = sn_content_queue_runway( array( $at( '2026-11-02 09:38' ), $at( '2027-01-25 09:38' ), $at( '2026-10-20 09:38' ) ), $now, $tz );
ok( 'Covered through Jan 25, 2027 (103 days)' === $r['text'], 'the owner\'s sentence: the last note in any order, calendar days from today (got "' . $r['text'] . '")' );
ok( '2027-01-25' === $r['through'] && 103 === $r['days'], 'through and days ride as data' );
ok( 'Covered through Nov 2 (19 days)' === sn_content_queue_runway( array( $at( '2026-11-02 23:30' ) ), $now, $tz )['text'], 'a date in this year carries no year; a late-evening note counts its own day' );
// The site timezone, never UTC: 9 PM in New York on Oct 14 is Oct 15 in UTC.
ok( 'Covered through Oct 14 (today)' === sn_content_queue_runway( array( $at( '2026-10-14 21:00' ) ), $now, $tz )['text'], 'a note later today is covered through today, by the site clock' );
ok( 'Covered through Oct 15 (1 day)' === sn_content_queue_runway( array( $at( '2026-10-15 09:00' ) ), $now, $tz )['text'], 'one day is singular' );
$none = sn_content_queue_runway( array(), $now, $tz );
ok( 'Not covered: nothing is scheduled.' === $none['text'] && null === $none['through'] && null === $none['days'], 'nothing scheduled is said in words, never "0 days"' );
$late = sn_content_queue_runway( array( $at( '2026-10-12 09:38' ) ), $now, $tz );
ok( 'Not covered: the last scheduled note, Oct 12, is overdue.' === $late['text'] && null === $late['days'], 'an overdue last note is not counted as negative days' );
ok( '26 scheduled' === sn_content_queue_runway_count( 26 ) && 'Scheduled count not read' === sn_content_queue_runway_count( null ), 'the secondary count, and its unknown in words' );

// The payload: the card reads the same sentence.
$shape = sn_content_queue_shape( array( array( 'id' => 1, 'title' => 'A', 'ts' => $at( '2027-01-25 09:38' ) ) ), 1, array(), $now, $tz );
ok( isset( $shape['runway']['text'] ) && 'Covered through Jan 25, 2027 (103 days)' === $shape['runway']['text'], 'the content-queue payload carries the runway sentence' );

// Both twins, from the real queue reader.
$GLOBALS['__q']['future'] = array( $post( 1, 2.2 ), $post( 2, 9.2 ), $post( 3, 40.2 ) );
$read = sn_content_queue_runway_read();
$mid  = static fn( $t ) => ( new DateTimeImmutable( '@' . $t ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
$want = (int) $mid( time() )->diff( $mid( strtotime( $GLOBALS['__q']['future'][2]->post_date_gmt . ' UTC' ) ) )->format( '%a' );
ok( $want === $read['days'] && false !== strpos( $read['text'], "($want days)" ), "the reader counts to the last scheduled note ($want days by the site clock)" );
$kh = snt_leaf_paint( 'connections', 'scheduled-content' );
$ch = snt_leaf_classic_html( 'sn_admin_render_scheduled_content_section' );
$kit = $text( $kh ); $classic = $text( $ch );
foreach ( array( $read['text'], '3 scheduled', 'Runway' ) as $s ) {
	ok( false !== strpos( $kit, $s ) && false !== strpos( $classic, $s ), "both twins say: $s" );
}
ok( false === strpos( $kh, '<details' ) && false === strpos( $kh, 'snt-fold' ), 'the runway is status: open, never in a fold' );
$box = static fn( $h ) => substr( $h, strpos( $h, 'Runway' ), 400 );
ok( false === strpos( $box( $kh ), '<button' ) && false === strpos( $box( $ch ), '<form' ), 'read-only: no control in the box' );

$GLOBALS['__q']['future'] = array();
$kit = $text( snt_leaf_paint( 'connections', 'scheduled-content' ) );
$classic = $text( snt_leaf_classic_html( 'sn_admin_render_scheduled_content_section' ) );
ok( false !== strpos( $kit, 'Not covered: nothing is scheduled.' ) && false !== strpos( $classic, 'Not covered: nothing is scheduled.' ), 'an empty queue is said on both twins, never an empty box' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
