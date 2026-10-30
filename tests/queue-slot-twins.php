<?php
/**
 * The suggested slot per draft on both Connections › Scheduled twins
 * (2026-10-13, queue calendar phase 2, item 2): the native leaf and the
 * classic page print the same rule and the same sentence per draft, each
 * draft's title a link to its editor, open (never folded), read from the real
 * queue reader. A suggestion only: neither twin offers a control.
 *
 * Run: php tests/queue-slot-twins.php
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
$GLOBALS['__q']    = array( 'future' => array(), 'publish' => array(), 'draft' => array() );
$GLOBALS['__args'] = array();
function get_posts( $a ) { $GLOBALS['__args'][] = $a; return $GLOBALS['__q'][ $a['post_status'] ] ?? array(); }
function wp_count_posts( $t = 'post' ) { return (object) array( 'draft' => $GLOBALS['__drafts'] ?? count( $GLOBALS['__q']['draft'] ) ); }
function sn_schedule_all() { return array(); }
function sn_schedule_future_posts() { return array(); }
function sn_schedule_swap_pairs( $f ) { return array(); }
require_once __DIR__ . '/lib/queue-post.php';
$post = 'snt_test_queue_post'; // Local noon, whole calendar days: DST-proof (tests/lib/queue-post.php).

require SNT_PATH . 'inc/abilities-content-queue.php';
require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'inc/schedule-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-scheduled-content.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$text = static fn( $h ) => trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( str_replace( '<', ' <', preg_replace( '/<os-section heading="([^"]*)"(?: description="([^"]*)")?/', '<os-section>$1 $2 ', $h ) ) ), ENT_QUOTES ) ) );
$both = static function () use ( $text ) {
	return array( snt_leaf_paint( 'connections', 'scheduled-content' ), snt_leaf_classic_html( 'sn_admin_render_scheduled_content_section' ) );
};

// A note every 4 days with a 12-day hole, and two drafts.
$GLOBALS['__q']['future'] = array( $post( 1, 2.2 ), $post( 2, 6.2 ), $post( 3, 18.2 ), $post( 4, 22.2 ) );
$GLOBALS['__q']['draft']  = array( $post( 8, 0, 'Draft <b>A</b>' ), $post( 9, 0, 'Draft B' ) );
$read = sn_content_queue_slot_read();
ok( 2 === count( $read['slots'] ) && null !== $read['slots'][0]['day'] && $read['slots'][0]['day'] !== $read['slots'][1]['day'], 'the reader gives each draft its own suggested day from the real queue rows' );
ok( false !== strpos( $read['slots'][0]['text'], 'splits the' ), 'the first draft splits the 12-day hole' );
$draft_q = array_values( array_filter( $GLOBALS['__args'], static fn( $a ) => 'draft' === $a['post_status'] ) )[0];
ok( array( 'modified' => 'DESC', 'ID' => 'DESC' ) === $draft_q['orderby'] && SN_CONTENT_QUEUE_DRAFTS === $draft_q['numberposts'], 'the twins read the same drafts as the card: newest edit first, capped' );

list( $kh, $ch ) = $both();
$kit = $text( $kh ); $classic = $text( $ch );
foreach ( array_merge( array( sn_content_queue_slot_rule() ), array_column( $read['slots'], 'text' ) ) as $s ) {
	ok( false !== strpos( $kit, $s ) && false !== strpos( $classic, $s ), "both twins say: $s" );
}
ok( false !== strpos( $kit, 'Suggested slots' ) && false !== strpos( $classic, 'Suggested slots' ), 'both twins head the box Suggested slots' );
ok( false !== strpos( $ch, '<a href="https://example.test/wp-admin/post.php?post=9">Draft B</a>' ), 'the classic twin links each title to its editor' );
ok( 1 === preg_match( '/<[^>]+post\.php\?post=9[^>]*>Draft B</', $kh ) && false === strpos( $kh, '<a href="https://example.test/wp-admin/post.php?post=9"' ), 'the leaf links it through the kit (a window door), never a raw anchor' );
ok( false === strpos( $kh, '<b>A</b>' ) && false === strpos( $ch, '<b>A</b>' ) && false !== strpos( $ch, 'Draft &lt;b&gt;A&lt;/b&gt;' ), 'a draft title is text on both twins, never markup' );
ok( false === strpos( $kh, '<details' ) && false === strpos( $kh, 'snt-fold' ), 'the slots are open, never in a fold' );
$box = static fn( $h ) => substr( $h, strpos( $h, 'Suggested slots' ) );
ok( false === strpos( $box( $kh ), '<button' ) && false === strpos( $box( $ch ), '<form' ), 'a suggestion only: no control, the editor is where a note is scheduled' );

$GLOBALS['__drafts'] = 7;
list( $kh, $ch ) = $both();
ok( false !== strpos( $text( $kh ), '+5 more drafts, not given a slot here.' ) && false !== strpos( $text( $ch ), '+5 more drafts, not given a slot here.' ), 'drafts past the list are counted on both twins' );
unset( $GLOBALS['__drafts'] );

$GLOBALS['__q']['draft'] = array();
list( $kh, $ch ) = $both();
ok( false !== strpos( $text( $kh ), 'No drafts, so no slot to suggest.' ) && false !== strpos( $text( $ch ), 'No drafts, so no slot to suggest.' ), 'no drafts is said on both twins, never an empty box' );

$GLOBALS['__q']['draft']  = array( $post( 8, 0, 'Lonely' ) );
$GLOBALS['__q']['future'] = array();
list( $kh, $ch ) = $both();
ok( false !== strpos( $text( $kh ), SN_CONTENT_QUEUE_SLOT_NOTHING ) && false !== strpos( $text( $ch ), SN_CONTENT_QUEUE_SLOT_NOTHING ), 'nothing scheduled: both twins say no slot can be read, never a guessed day' );

// Review on #2054: the classic box's title without an edit link is escaped
// too, and one paint reads the schedule once for both boxes.
ob_start();
sn_admin_render_queue_slots( array( 'empty' => '', 'more' => null, 'slots' => array( array( 'title' => 'Bare <i>x</i>', 'edit_url' => '', 'text' => 'Suggested: <b>y</b>' ) ) ) );
$bare = ob_get_clean();
ok( false === strpos( $bare, '<i>' ) && false !== strpos( $bare, 'Bare &lt;i&gt;x&lt;/i&gt;: Suggested: &lt;b&gt;y&lt;/b&gt;' ) && false === strpos( $bare, '<a ' ), 'a title with no edit link is plain escaped text on the classic twin' );
$k_bare = \SignalNoise\OpenStationHost\Dashboard\Leaves\scheduled_content_slots_html( array( 'empty' => '', 'more' => null, 'slots' => array( array( 'title' => 'Bare <i>x</i>', 'edit_url' => '', 'text' => 'Suggested: <b>y</b>' ) ) ) );
ok( false === strpos( $k_bare, '<i>' ) && false !== strpos( $k_bare, 'Bare &lt;i&gt;x&lt;/i&gt;' ), 'and on the native leaf' );
$GLOBALS['__args'] = array();
$both();
$counts = array_count_values( array_column( $GLOBALS['__args'], 'post_status' ) );
ok( 2 === ( $counts['future'] ?? 0 ) && 2 === ( $counts['publish'] ?? 0 ), 'each twin reads the scheduled and published notes once for Cadence and Suggested slots together' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
