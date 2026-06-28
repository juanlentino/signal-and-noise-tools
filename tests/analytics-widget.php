<?php
/**
 * Tests for the re-pointed dashboard widgets (inc/analytics-widget.php reads
 * the first-party analytics accessors).
 * Run: php tests/analytics-widget.php
 * @since plugin v5.0.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['__actions'] = array();
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = $c; } }
$GLOBALS['__widgets'] = array();
function wp_add_dashboard_widget( $id, $title, $cb ) { $GLOBALS['__widgets'][ $id ] = array( 'title' => $title, 'cb' => $cb ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function current_user_can( $c ) { return true; }
function human_time_diff( $a, $b = 0 ) { return '2 mins'; }
function wp_kses_post( $s ) { return $s; }
function add_query_arg( $args, $url = '' ) { return (string) $url . ( strpos( (string) $url, '?' ) !== false ? '&' : '?' ) . http_build_query( (array) $args ); }
// Seams the canonical-source mapper (used by sn_aw_sources) touches.
function home_url( $path = '' ) { return 'https://juanlentino.com' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( $tag, $value ) { return $value; }

// First-party analytics seam.
$GLOBALS['__pw_config'] = true;
function sn_analytics_config() { return $GLOBALS['__pw_config'] ? array( 'a' => 1 ) : null; }
$GLOBALS['__pw'] = array( 'totals' => array(), 'realtime' => null, 'paths' => array(), 'refs' => array(), 'engaged' => null, 'classes' => array() );
function sn_analytics_range_totals( $from, $to, $class = 'human' ) { return $GLOBALS['__pw']['totals']; }
function sn_analytics_realtime( $class = 'human' ) { return $GLOBALS['__pw']['realtime']; }
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__pw']['paths']; }
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__pw']['refs']; }
function sn_analytics_engaged_rate( $from, $to, $class = 'human' ) { return $GLOBALS['__pw']['engaged']; }
function sn_analytics_class_totals( $from, $to ) { return $GLOBALS['__pw']['classes']; }
// v6.44.0 enrichment seams: the Filtered WoW delta + the 7-day views sparkline.
// Guarded by function_exists in the widget, so the stubs gate whether they render.
function sn_analytics_prior_window( $from, $to ) { return array( '2019-12-25', '2019-12-31' ); }
function sn_analytics_delta( $cur, $prev ) {
	if ( (float) $prev <= 0 ) { return array( 'pct' => null, 'dir' => $cur > 0 ? 'up' : 'flat' ); }
	$pct = (int) round( ( $cur - $prev ) / $prev * 100 );
	return array( 'pct' => $pct, 'dir' => $cur > $prev ? 'up' : ( $cur < $prev ? 'down' : 'flat' ) );
}
function sn_analytics_daily_series( $from, $to, $class = 'human', $g = 'day' ) { return $GLOBALS['__pw']['series'] ?? array(); }
function snt_analytics_sparkline( $series ) { return '<span class="sn-an-spark"><svg viewBox="0 0 72 18"></svg></span>'; }

require_once __DIR__ . '/../inc/analytics-sources.php'; // sn_aw_sources folds raw referrers → canonical sources
require_once __DIR__ . '/../inc/analytics-widget.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function cap( $cb ) { ob_start(); $cb(); return ob_get_clean(); }

echo "Re-pointed dashboard widgets\n\n";

echo "Group: configured renders new-source data\n";
$GLOBALS['__pw_config'] = true;
$GLOBALS['__pw']['totals']   = array( 'views' => 1204, 'visits' => 389, 'scroll_avg' => 62.0, 'time_avg' => 108000.0 );
$GLOBALS['__pw']['realtime'] = 7;
$GLOBALS['__pw']['paths']    = array( array( 'path' => '/notes/x', 'views' => 412, 'visits' => 158, 'scroll_avg' => 71.0, 'time_avg' => 150000.0 ) );
$GLOBALS['__pw']['refs']     = array( array( 'value' => 'news.ycombinator.com', 'views' => 312, 'visits' => 98 ) );
$GLOBALS['__pw']['engaged']  = 74;
$GLOBALS['__pw']['classes']  = array( 'human' => array( 'views' => 1163, 'visits' => 370 ), 'suspect' => array( 'views' => 18, 'visits' => 9 ), 'bot' => array( 'views' => 23, 'visits' => 10 ) );
$GLOBALS['__pw']['series']   = array( array( 'day' => '2020-01-01', 'views' => 100 ), array( 'day' => '2020-01-02', 'views' => 140 ) );
$snap = cap( 'sn_aw_snapshot' );
ok( strpos( $snap, '1,204' ) !== false, 'snapshot: shows analytics views' );
ok( strpos( $snap, '62%' ) !== false, 'snapshot: avg scroll rendered as percent' );
ok( strpos( $snap, '1m 48s' ) !== false, 'snapshot: avg time converted ms→s (108000ms → 1m 48s)' );
ok( strpos( $snap, '>74%</div><div class="sn-aw-stat-l">Engaged<' ) !== false, 'snapshot: Engaged tile pairs 74% with the Engaged label' );
// v6.44.0: Filtered now carries a WoW delta badge like the other five KPIs, so the
// label is followed by the .sn-aw-delta span rather than closing immediately.
ok( strpos( $snap, '>41</div><div class="sn-aw-stat-l">Filtered' ) !== false, 'snapshot: Filtered tile sums suspect(18)+bot(23)=41 noise pageviews' );
ok( strpos( $snap, 'Filtered <span class="sn-aw-delta' ) !== false, 'snapshot: Filtered now carries a week-over-week delta badge (KPI parity)' );
ok( strpos( $snap, 'sn-aw-trend' ) !== false && strpos( $snap, 'sn-an-spark' ) !== false, 'snapshot: renders a 7-day views sparkline under the KPI grid' );
ok( strpos( cap( 'sn_aw_realtime' ), '<div class="sn-aw-big">7</div>' ) !== false, 'realtime: shows the visitor count in the big-number element (not CSS/footer 7s)' );
ok( strpos( cap( 'sn_aw_pages' ), '/notes/x' ) !== false, 'pages: shows top path from new source' );
$src_html = cap( 'sn_aw_sources' );
ok( strpos( $src_html, 'Hacker News' ) !== false, 'sources: raw host folds to its brand label (news.ycombinator.com → Hacker News)' );
ok( strpos( $src_html, 'sn_drill=referrer' ) !== false, 'sources: a branded source renders as a drill link into the Analytics page' );

echo "\nGroup: consolidated into 2 widgets (Overview + Top content)\n";
// Fire the captured wp_dashboard_setup callback to record what gets registered.
$GLOBALS['__widgets'] = array();
foreach ( $GLOBALS['__actions']['wp_dashboard_setup'] ?? array() as $cb ) { $cb(); }
ok( count( $GLOBALS['__widgets'] ) === 2, 'exactly 2 dashboard widgets registered (was 4)' );
ok( isset( $GLOBALS['__widgets']['sn_plausible_snapshot'] )
	&& 'Analytics — Overview' === $GLOBALS['__widgets']['sn_plausible_snapshot']['title']
	&& 'sn_aw_overview' === $GLOBALS['__widgets']['sn_plausible_snapshot']['cb'],
	'Overview reuses the sn_plausible_snapshot id (layout preserved) + renders sn_aw_overview' );
ok( isset( $GLOBALS['__widgets']['sn_plausible_pages'] )
	&& 'Analytics — Top content' === $GLOBALS['__widgets']['sn_plausible_pages']['title']
	&& 'sn_aw_top_content' === $GLOBALS['__widgets']['sn_plausible_pages']['cb'],
	'Top content reuses the sn_plausible_pages id + renders sn_aw_top_content' );
ok( ! isset( $GLOBALS['__widgets']['sn_plausible_realtime'] ) && ! isset( $GLOBALS['__widgets']['sn_plausible_sources'] ),
	'old realtime + sources widget ids no longer registered (orphaned, harmless)' );

echo "\nGroup: consolidated render-smoke (configured)\n";
$GLOBALS['__pw_config']      = true;
$GLOBALS['__pw']['totals']   = array( 'views' => 1204, 'visits' => 389, 'scroll_avg' => 62.0, 'time_avg' => 108000.0 );
$GLOBALS['__pw']['realtime'] = 7;
$GLOBALS['__pw']['paths']    = array( array( 'path' => '/notes/x', 'views' => 412 ) );
$GLOBALS['__pw']['refs']     = array( array( 'value' => 'news.ycombinator.com', 'views' => 312 ) );
$GLOBALS['__pw']['engaged']  = 74;
$GLOBALS['__pw']['classes']  = array( 'human' => array( 'views' => 1163 ), 'suspect' => array( 'views' => 18 ), 'bot' => array( 'views' => 23 ) );
$ov = cap( 'sn_aw_overview' );
ok( strpos( $ov, 'Right now' ) !== false && strpos( $ov, 'Last 7 days' ) !== false, 'overview: Right now + Last 7 days subheads' );
ok( strpos( $ov, '<div class="sn-aw-big">7</div>' ) !== false, 'overview: shows the realtime count' );
ok( strpos( $ov, '1,204' ) !== false, 'overview: shows the 7-day KPI views' );
ok( substr_count( $ov, 'Open Analytics' ) === 1, 'overview: exactly ONE Open-Analytics footer link (no double footer)' );
$tc = cap( 'sn_aw_top_content' );
ok( strpos( $tc, 'Top pages' ) !== false && strpos( $tc, 'Top sources' ) !== false, 'top content: Top pages + Top sources subheads' );
ok( strpos( $tc, '/notes/x' ) !== false && strpos( $tc, 'Hacker News' ) !== false, 'top content: shows top path + folded top source' );
ok( substr_count( $tc, 'Open Analytics' ) === 1, 'top content: exactly ONE Open-Analytics footer link' );

echo "\nGroup: measured zero renders 0 (not em-dash)\n";
$GLOBALS['__pw_config']     = true;
$GLOBALS['__pw']['engaged'] = 0;
$GLOBALS['__pw']['classes'] = array( 'human' => array( 'views' => 500, 'visits' => 200 ) );
$z = cap( 'sn_aw_snapshot' );
ok( strpos( $z, '>0%</div><div class="sn-aw-stat-l">Engaged<' ) !== false, 'snapshot: genuine 0% engaged renders 0% (signal measured, not missing)' );
ok( strpos( $z, '>0</div><div class="sn-aw-stat-l">Filtered' ) !== false, 'snapshot: classified traffic with zero noise renders Filtered 0 (not em-dash)' );

echo "\nGroup: no rollup data degrades to em-dash\n";
$GLOBALS['__pw_config']     = true;
$GLOBALS['__pw']['engaged'] = null;
$GLOBALS['__pw']['classes'] = array();
$d = cap( 'sn_aw_snapshot' );
ok( strpos( $d, '>—</div><div class="sn-aw-stat-l">Engaged<' ) !== false, 'snapshot: no time-distribution data → Engaged em-dash' );
ok( strpos( $d, '>—</div><div class="sn-aw-stat-l">Filtered<' ) !== false, 'snapshot: empty class_totals → Filtered em-dash (not 0)' );

echo "\nGroup: unconfigured shows config empty state\n";
$GLOBALS['__pw_config'] = false;
$html = cap( 'sn_aw_snapshot' );
ok( stripos( $html, 'SN_CF_ANALYTICS_TOKEN' ) !== false || stripos( $html, 'not configured' ) !== false, 'snapshot: unconfigured → config copy' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
