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
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function current_user_can( $c ) { return true; }
function human_time_diff( $a, $b = 0 ) { return '2 mins'; }
function wp_kses_post( $s ) { return $s; }

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
$snap = cap( 'sn_aw_snapshot' );
ok( strpos( $snap, '1,204' ) !== false, 'snapshot: shows analytics views' );
ok( strpos( $snap, '62%' ) !== false, 'snapshot: avg scroll rendered as percent' );
ok( strpos( $snap, '1m 48s' ) !== false, 'snapshot: avg time converted ms→s (108000ms → 1m 48s)' );
ok( strpos( $snap, '>74%</div><div class="sn-aw-stat-l">Engaged<' ) !== false, 'snapshot: Engaged tile pairs 74% with the Engaged label' );
ok( strpos( $snap, '>41</div><div class="sn-aw-stat-l">Filtered<' ) !== false, 'snapshot: Filtered tile sums suspect(18)+bot(23)=41 noise pageviews' );
ok( strpos( cap( 'sn_aw_realtime' ), '<div class="sn-aw-big">7</div>' ) !== false, 'realtime: shows the visitor count in the big-number element (not CSS/footer 7s)' );
ok( strpos( cap( 'sn_aw_pages' ), '/notes/x' ) !== false, 'pages: shows top path from new source' );
ok( strpos( cap( 'sn_aw_sources' ), 'news.ycombinator.com' ) !== false, 'sources: shows top referrer from dims' );

echo "\nGroup: measured zero renders 0 (not em-dash)\n";
$GLOBALS['__pw_config']     = true;
$GLOBALS['__pw']['engaged'] = 0;
$GLOBALS['__pw']['classes'] = array( 'human' => array( 'views' => 500, 'visits' => 200 ) );
$z = cap( 'sn_aw_snapshot' );
ok( strpos( $z, '>0%</div><div class="sn-aw-stat-l">Engaged<' ) !== false, 'snapshot: genuine 0% engaged renders 0% (signal measured, not missing)' );
ok( strpos( $z, '>0</div><div class="sn-aw-stat-l">Filtered<' ) !== false, 'snapshot: classified traffic with zero noise renders Filtered 0 (not em-dash)' );

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
