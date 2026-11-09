<?php
/**
 * The Weekday rules box on both Connections › Scheduled twins (2026-11-09,
 * queue calendar phase 2, item 4): the native leaf and the classic page say
 * the same rule in words, post the same fields to the same handler, open on
 * the stored rule, and the off day it finds is said under Cadence on both.
 *
 * Run: php tests/queue-weekday-twins.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post { public $ID; public $post_title; public $post_date_gmt; } // phpcs:ignore
}
$GLOBALS['__opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
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
require_once __DIR__ . '/lib/queue-post.php';

require SNT_PATH . 'inc/abilities-content-queue.php';
require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'inc/schedule-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-scheduled-content.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$text  = static fn( $h ) => trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( str_replace( '<', ' <', preg_replace( '/<os-section heading="([^"]*)"(?: description="([^"]*)")?/', '<os-section>$1 $2 ', $h ) ) ), ENT_QUOTES ) ) );
$kbox  = static function ( $h ) { $a = strpos( $h, 'heading="Weekday rules"' ); return false === $a ? '' : substr( $h, $a, strpos( $h, '</os-section>', $a ) - $a ); };
$cbox  = static function ( $h ) { $a = strpos( $h, 'id="sn-queue-weekdays"' ); return false === $a ? '' : substr( $h, $a, strpos( $h, '</section>', $a ) - $a ); };
$paint = static fn() => array( snt_leaf_paint( 'connections', 'scheduled-content' ), snt_leaf_classic_html( 'sn_admin_render_scheduled_content_section' ) );

// The owner rule, a Monday a week out that keeps it, and a Tuesday that does not.
$GLOBALS['__opts']['sn_content_queue_weekday_rules'] = array( '2027-01-04||14' );
list( $kit, $classic ) = $paint();
$k = $kbox( $kit );
$c = $cbox( $classic );
ok( '' !== $k && '' !== $c, 'both twins paint a Weekday rules box' );
$line = 'From Jan 4, 2027, notes go out on Mon and Thu';
ok( false !== strpos( $text( $k ), $line ) && false !== strpos( $text( $c ), $line ), "both twins say the rule in words: $line" );
ok( false !== strpos( $text( $k ), sn_content_queue_weekday_help() ) && false !== strpos( $text( $c ), sn_content_queue_weekday_help() ), 'both twins say how the rules work' );
$fields = array( 'from', 'to', 'wd_1', 'wd_2', 'wd_3', 'wd_4', 'wd_5', 'wd_6', 'wd_7' );
ok( $fields === snt_leaf_names( $k ) && $fields === snt_leaf_names( $c ), 'both forms post the same fields' );
ok( array( 'queue_weekday_save' ) === snt_leaf_actions( $k ) && array( 'queue_weekday_save' ) === snt_leaf_actions( $c ), 'both post to the same handler' );
$table = (string) file_get_contents( SNT_PATH . 'inc/admin-post-handler.php' );
$flash = (string) file_get_contents( SNT_PATH . 'inc/admin-flash-messages.php' );
ok( 1 === preg_match( "/'queue_weekday_save'\\s*=>\\s*'sn_handle_queue_weekday_save'/", $table ) && function_exists( 'sn_handle_queue_weekday_save' ), 'the action is in the admin-post table, and its handler loads with the queue' );
ok( 3 === preg_match_all( "/'queue_weekday_(saved|removed|invalid)'\\s*=>/", $flash ), 'each code the handler returns has a flash message' );
ok( 1 === preg_match( '/name="from"[^>]*value="2027-01-04"|value="2027-01-04"[^>]*name="from"/', $k ) && false !== strpos( $c, 'name="from" required value="2027-01-04"' ), 'both open on the stored rule\'s start' );
$checked = static fn( $h, $n ) => 1 === preg_match( '/<[^>]*name="wd_' . $n . '"[^>]*checked/', $h );
ok( $checked( $k, 1 ) && $checked( $k, 4 ) && ! $checked( $k, 2 ) && $checked( $c, 1 ) && $checked( $c, 4 ) && ! $checked( $c, 2 ), 'both check Mon and Thu, and only those' );
ok( false === strpos( $kit, '<details' ) && false === strpos( $kit, 'snt-fold' ), 'the box is open, never in a fold' );

// An off day reaches the Cadence box on both twins. Find the next Tuesday after 2027-01-04 relative to today.
$today = ( new DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 12, 0 );
$tue   = new DateTimeImmutable( 'tuesday', wp_timezone() );
$tue   = $tue < new DateTimeImmutable( '2027-01-04', wp_timezone() ) ? new DateTimeImmutable( '2027-01-05', wp_timezone() ) : $tue->modify( '+7 days' );
$days  = (int) $today->setTime( 0, 0 )->diff( $tue->setTime( 0, 0 ) )->format( '%a' );
$GLOBALS['__q']['future'] = array( snt_test_queue_post( 1, $days ) );
list( $kit, $classic ) = $paint();
$off = sn_content_queue_cadence_read()[0]['text'] ?? '';
ok( 0 === strpos( $off, 'Off day: a note on Tue, ' ), 'the reader finds the Tuesday note as an off day' );
ok( '' !== $off && false !== strpos( $text( $kit ), $off ) && false !== strpos( $text( $classic ), $off ), 'both twins say it under Cadence' );

// No rule stored: both say so, and the form opens empty.
$GLOBALS['__opts']['sn_content_queue_weekday_rules'] = array();
list( $kit, $classic ) = $paint();
ok( false !== strpos( $text( $kbox( $kit ) ), sn_content_queue_weekday_none() ) && false !== strpos( $text( $cbox( $classic ) ), sn_content_queue_weekday_none() ), 'no rule: both twins say any day is fine' );
ok( ! $checked( $kbox( $kit ), 1 ) && ! $checked( $cbox( $classic ), 1 ), 'and no weekday is checked' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
