<?php
/**
 * The queue's cadence warnings on both Connections › Scheduled twins
 * (2026-10-12, queue calendar phase 2, item 1): the native leaf and the
 * classic page print the same rule and the same sentence per cluster and gap,
 * open (never folded), read from the real queue reader.
 *
 * Run: php tests/queue-cadence-twins.php
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
function wp_count_posts( $t = 'post' ) { return (object) array( 'draft' => count( $GLOBALS['__q']['draft'] ?? array() ) ); }
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
// The kit's <os-section> carries its heading and description as attributes,
// so they are read as text alongside the body.
$text = static fn( $h ) => trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( str_replace( '<', ' <', preg_replace( '/<os-section heading="([^"]*)"(?: description="([^"]*)")?/', '<os-section>$1 $2 ', $h ) ) ), ENT_QUOTES ) ) );

// A cluster (two notes 1 day apart), then a 12-day gap, then a 16-day gap the
// owner marked planned (the holiday shape).
$GLOBALS['__q']['future'] = array( $post( 1, 2 ), $post( 2, 3 ), $post( 3, 6 ), $post( 4, 18 ), $post( 5, 22 ), $post( 6, 38 ) );
$warn  = sn_content_queue_cadence_read();
ok( array( 'cluster', 'gap', 'gap' ) === array_column( $warn, 'kind' ), 'the reader finds the cluster and both gaps from the real queue rows' );
$GLOBALS['__opts']['sn_content_queue_planned_gaps'] = array( $warn[2]['from'] . '|' . $warn[2]['to'] );
$warn  = sn_content_queue_cadence_read();
ok( array( 'cluster', 'gap' ) === array_column( $warn, 'kind' ), 'a gap stored as planned in the existing option is left out' );

$kit     = $text( snt_leaf_paint( 'connections', 'scheduled-content' ) );
$classic = $text( snt_leaf_classic_html( 'sn_admin_render_scheduled_content_section' ) );
foreach ( array_merge( array( sn_content_queue_cadence_rule() ), array_column( $warn, 'text' ) ) as $s ) {
	ok( false !== strpos( $kit, $s ) && false !== strpos( $classic, $s ), "both twins say: $s" );
}
ok( false !== strpos( $kit, 'Cadence' ) && false !== strpos( $classic, 'Cadence' ), 'both twins head the box Cadence' );
$kit_html = snt_leaf_paint( 'connections', 'scheduled-content' );
ok( false === strpos( $kit_html, '<details' ) && false === strpos( $kit_html, 'snt-fold' ), 'the warnings are status: open, never in a fold' );
// A label gains its year across New Year ("Jan 1, 2027"): Dec 30 and 31 failed without it.
ok( 1 === preg_match( '/Cluster: 2 notes [A-Z][a-z]{2} \d+(?:, \d{4})? and [A-Z][a-z]{2} \d+/', $kit ), 'a warning names its kind in words, so it never rests on color' );

$GLOBALS['__q']['future'] = array( $post( 1, 2 ), $post( 2, 5 ) );
$kit     = $text( snt_leaf_paint( 'connections', 'scheduled-content' ) );
$classic = $text( snt_leaf_classic_html( 'sn_admin_render_scheduled_content_section' ) );
ok( false !== strpos( $kit, sn_content_queue_cadence_clear() ) && false !== strpos( $classic, sn_content_queue_cadence_clear() ), 'a steady schedule says so on both twins, never an empty box' );

// Review on #2040: a sentence is text on both twins, never markup.
$evil = array( array( 'kind' => 'gap', 'text' => 'Gap: <b>no</b> note' ) );
ob_start();
sn_admin_render_queue_cadence( $evil );
$c_html = ob_get_clean();
$k_html = \SignalNoise\OpenStationHost\Dashboard\Leaves\scheduled_content_cadence_html( $evil );
ok( false === strpos( $c_html, '<b>' ) && false !== strpos( $c_html, '&lt;b&gt;no&lt;/b&gt;' ), 'the classic box escapes a warning sentence' );
ok( false === strpos( $k_html, '<b>' ) && false !== strpos( $k_html, '&lt;b&gt;no&lt;/b&gt;' ), 'the native box escapes it too' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
