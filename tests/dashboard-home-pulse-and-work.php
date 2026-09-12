<?php
/**
 * #1208 — two UI inaccuracies in apps/sn-dashboard/parts/leaves/dashboard.php:
 *
 * 1. home_pulse_html()'s Engagement tile borrowed the VIEWS delta as its own
 *    pct: an unchanged 2.0 views/session ratio (views and visits both moving
 *    in lockstep) showed "+50%" because that is what views alone did, even
 *    though the ratio itself never moved.
 * 2. home_continue_working_html()'s "View all (%d)" counted the 6-row display
 *    window (posts_per_page) instead of the query's real found_posts total —
 *    paid for via no_found_rows=false and never read.
 *
 * Standalone — no PHPUnit. Run: php tests/dashboard-home-pulse-and-work.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// ── WP stubs ────────────────────────────────────────────────────────────
function __( $s, $d = null ) { return (string) $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function wp_parse_url( $u ) { return parse_url( (string) $u ); }
function current_time( $type ) { return '9:00 am'; }
function get_edit_post_link( $id, $ctx = '' ) { return '/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
function get_post_type_object( $type ) { return (object) array( 'labels' => (object) array( 'singular_name' => ucfirst( $type ) ) ); }
function get_post_status_object( $status ) { return (object) array( 'label' => ucfirst( $status ) ); }
function get_the_title( $p ) { return $p->post_title; }
function human_time_diff( $from, $to = 0 ) { return '1 hour'; }
function add_filter( $hook, $cb = null, $priority = 10, $args = 1 ) { return true; }

// ── The deltas accessor under test's control (inc/analytics-derived.php) ──
$GLOBALS['__deltas'] = array();
function sn_analytics_period_deltas( $from, $to, $class = 'human' ) { return $GLOBALS['__deltas']; }
function sn_analytics_delta( $cur, $prev ) {
	$cur  = (float) $cur;
	$prev = (float) $prev;
	if ( $prev <= 0 ) {
		return array( 'pct' => null, 'dir' => $cur > 0 ? 'up' : 'flat' );
	}
	$pct = (int) round( ( $cur - $prev ) / $prev * 100 );
	$dir = $cur > $prev ? 'up' : ( $cur < $prev ? 'down' : 'flat' );
	return array( 'pct' => $pct, 'dir' => $dir );
}

// ── WP_Query stub: `found_posts` is the REAL total, computed before the
// posts_per_page bound is applied — the same contract core uses. ──
$GLOBALS['__posts'] = array();
class WP_Query {
	public $posts = array();
	public $found_posts = 0;
	public function __construct( array $args ) {
		$all               = $GLOBALS['__posts'];
		$this->found_posts = count( $all );
		$per               = (int) ( $args['posts_per_page'] ?? -1 );
		$this->posts       = $per > 0 ? array_slice( $all, 0, $per ) : $all;
	}
}

require_once __DIR__ . '/../inc/openstation-kit.php';
require_once __DIR__ . '/../apps/sn-dashboard/parts/leaves/dashboard.php';

$pass = 0;
$fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "dashboard.php home_pulse_html + home_continue_working_html — #1208\n";

// ── 1. Engagement delta must be the RATIO's own delta, not views' ─────────
echo "\nGroup: Engagement tile delta\n";
// Both views and visits double: the ratio (2.0 v/s) is UNCHANGED, so the
// Engagement tile's delta must read flat/absent, never the views delta.
$GLOBALS['__deltas'] = array(
	'views'  => array( 'current' => 200, 'previous' => 100, 'pct' => 100, 'dir' => 'up' ),
	'visits' => array( 'current' => 100, 'previous' => 50, 'pct' => 100, 'dir' => 'up' ),
);
$html = \SignalNoise\OpenStationHost\Dashboard\Leaves\home_pulse_html( array(), 'dashboard' );
ok( false !== strpos( $html, '2.0 v/s' ), 'the ratio itself renders (2.0 v/s)' );
ok( false === strpos( $html, 'Engagement' . '</span></div><strong>2.0 v/s</strong><span class="snt-home__metric-delta">+100%' ),
	'#1208: an UNCHANGED ratio (200/100 vs 100/50, both = 2.0) must not borrow the views delta (+100%)' );

// A real ratio change: views double, visits flat -> ratio moves 1.0 -> 2.0,
// a genuine +100%. This proves the fix computes an ACTUAL ratio delta rather
// than simply suppressing the tile's pct outright.
$GLOBALS['__deltas'] = array(
	'views'  => array( 'current' => 200, 'previous' => 100, 'pct' => 100, 'dir' => 'up' ),
	'visits' => array( 'current' => 100, 'previous' => 100, 'pct' => 0, 'dir' => 'flat' ),
);
$html2 = \SignalNoise\OpenStationHost\Dashboard\Leaves\home_pulse_html( array(), 'dashboard' );
ok( false !== strpos( $html2, '2.0 v/s' ), 'ratio renders (200/100 = 2.0 v/s)' );
ok( false !== strpos( $html2, '<strong>2.0 v/s</strong><span class="snt-home__metric-delta">+100%' ),
	'a genuine ratio move (1.0 -> 2.0) DOES show its own +100% delta' );

// ── 2. "View all (%d)" must read found_posts, not the display window ──────
echo "\nGroup: Continue working — View all count\n";
$GLOBALS['__posts'] = array();
for ( $i = 1; $i <= 9; $i++ ) {
	$GLOBALS['__posts'][] = (object) array(
		'ID'               => $i,
		'post_type'        => 'post',
		'post_status'      => 'draft',
		'post_title'       => 'Post ' . $i,
		'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - $i * 3600 ),
	);
}
$work = \SignalNoise\OpenStationHost\Dashboard\Leaves\home_continue_working_html( 'dashboard' );
ok( false !== strpos( $work, 'View all (9)' ), '#1208: "View all" reads found_posts (9 real items), not the 6-row display window' );
ok( false === strpos( $work, 'View all (6)' ), '...never the capped window size' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
