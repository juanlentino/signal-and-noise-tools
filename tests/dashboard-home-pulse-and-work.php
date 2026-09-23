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
require_once __DIR__ . '/../inc/openstation-kit-display.php';
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

// ── #1596: every relative time in the window is an os-relative-time. The
// helper pin: an ISO 8601 UTC datetime attribute, the server's own signed
// human_time_diff() reading as the light-DOM fallback, both escaped.
echo "\nGroup: #1596 snt_kit_relative_time()\n";
$ts = 1700000000; // 2023-11-14T22:13:20Z
ok( '<os-relative-time datetime="2023-11-14T22:13:20Z">1 hour ago</os-relative-time>' === snt_kit_relative_time( $ts ), 'a past moment: ISO 8601 UTC attribute, "%s ago" fallback from human_time_diff()' );
ok( '<os-relative-time datetime="' . gmdate( 'Y-m-d\TH:i:s\Z', time() + 7200 ) . '">in 1 hour</os-relative-time>' === snt_kit_relative_time( time() + 7200 ), 'a future moment: the fallback is signed, "in %s", no overdue branch outside the helper' );
ok( '<os-relative-time datetime="2023-11-14T22:13:20Z">&lt;b&gt;x&lt;/b&gt; &amp; y</os-relative-time>' === snt_kit_relative_time( $ts, '<b>x</b> & y' ), 'a caller fallback is painted verbatim, escaped' );
ok( false !== strpos( snt_kit_relative_time( '1700000000"><script>' ), 'datetime="2023-11-14T22:13:20Z"' ), 'the timestamp is cast to int before it becomes the attribute' );

// The Continue working row paints its modified moment through the helper,
// anchored on post_modified_gmt, where 17.4.3 painted a frozen "1 hour ago".
$GLOBALS['__posts'] = array( (object) array( 'ID' => 1, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'One', 'post_modified_gmt' => '2023-11-14 22:13:20' ) );
$work = \SignalNoise\OpenStationHost\Dashboard\Leaves\home_continue_working_html( 'dashboard' );
ok( false !== strpos( $work, '<span class="snt-home__row-time"><os-relative-time datetime="2023-11-14T22:13:20Z">1 hour ago</os-relative-time></span>' ), '#1596: .snt-home__row-time holds an os-relative-time anchored on post_modified_gmt' );
ok( 1 === preg_match( '#<span class="snt-home__timestamp">Updated <os-relative-time datetime="\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ">just now</os-relative-time></span>#', $html2 ), '#1596: the Site pulse "Updated" stamp is an os-relative-time on the paint moment, not a clock reading' );

// ── 3. #1593: with no recent post, Continue working paints the kit's empty
// state, the component Station Home paints for the same section; the
// all-clear row stays with Needs attention.
echo "\nGroup: Continue working, empty state is os-empty-state\n";
$GLOBALS['__posts'] = array();
$empty = \SignalNoise\OpenStationHost\Dashboard\Leaves\home_continue_working_html( 'dashboard' );
ok( 1 === preg_match( '/<os-empty-state[^>]*heading="Your desk is clear"/', $empty ) && 1 === preg_match( '/<os-empty-state[^>]*icon="welcome-write-blog"/', $empty ), '#1593: the empty desk is <os-empty-state heading="Your desk is clear" icon="welcome-write-blog">, as Station Home paints it' );
ok( false !== strpos( $empty, 'Start something new and it will be waiting here when you return.' ), '...carrying the same description' );
ok( false === strpos( $empty, 'snt-home__all-clear' ), '...and the .snt-home__work block carries no all-clear row' );
$css = (string) file_get_contents( __DIR__ . '/../apps/sn-dashboard/sn-dashboard.css' );
ok( 1 === preg_match( '/\.snt-home__work os-empty-state\s*\{[^}]*min-block-size/', preg_replace( '#/\*.*?\*/#s', '', $css ) ), 'the sheet carries the Station Home twin for the empty state (.snt-home__work os-empty-state, min-block-size)' );

// 17.9.0: os-button forwards a host aria-label to its inner button since
// OpenStation 1.1.11 (#857), so the refresh button's hidden slotted name went.
$home_src = (string) file_get_contents( dirname( __DIR__ ) . '/apps/sn-dashboard/parts/leaves/dashboard.php' );
ok( 1 === preg_match( '/<os-button class="snt-home__refresh"[^>]*aria-label="/', $home_src ), 'the refresh button is named by its host aria-label' );
ok( false === strpos( $home_src, 'snt-sr-only' ) && false === strpos( preg_replace( '#/\*.*?\*/#s', '', $css ), '.snt-sr-only' ), 'no hidden slotted text and no sr-only rule: the forwarded label is the one name' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
