<?php
/**
 * Tests: the owner-only résumé lanes panel (inc/analytics-resume-lanes.php),
 * classic and native. 2026-11-02.
 *
 * Run: php tests/analytics-resume-lanes.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_attr( $s ) { return esc_html( $s ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function add_filter() {}
class WP_Error { public $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
$GLOBALS['admin'] = true;
function current_user_can( $c ) { return 'manage_options' === $c && $GLOBALS['admin']; }
$GLOBALS['rows'] = array( 'rows' => array(
	array( 'resume_lane' => 'music', 'views' => 12, 'time_avg_per_view' => 65000, 'scroll_avg_per_view' => 71.6 ),
	array( 'resume_lane' => 'operations', 'views' => 4, 'time_avg_per_view' => null, 'scroll_avg_per_view' => null ),
	array( 'resume_lane' => '(unknown)', 'views' => 900, 'time_avg_per_view' => 1, 'scroll_avg_per_view' => 1 ),
) );
function snt_ability_analytics_rows( $in ) { $GLOBALS['asked'] = $in; return $GLOBALS['rows']; }
function sn_analytics_top_events( $f, $t, $l ) {
	return array( array( 'name' => 'resume_lane_operations', 'events' => 3, 'visitors' => 2 ), array( 'name' => 'resume_hunk_music', 'events' => 7, 'visitors' => 5 ), array( 'name' => 'share_copy', 'events' => 99, 'visitors' => 9 ) );
}
function snt_kit_section( $t, $inner ) { return '<section><h2>' . $t . '</h2>' . $inner . '</section>'; }
function snt_kit_notice( $k, $m ) { return '<div class="warn">' . esc_html( $m ) . '</div>'; }
function snt_kit_table( $cols, $rows ) { $h = ''; foreach ( $cols as $c ) { $h .= '<th>' . $c['label'] . '</th>'; } foreach ( $rows as $r ) { $h .= '<tr><td>' . implode( '</td><td>', $r ) . '</td></tr>'; } return '<table>' . $h . '</table>'; }
$root = dirname( __DIR__ );
require_once $root . '/inc/analytics-render-helpers.php';
require_once $root . '/inc/analytics-panels.php';
require_once $root . '/inc/analytics-resume-lanes.php';
require_once $root . '/apps/sn-analytics/parts/painters/view-links.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$classic = static function () { ob_start(); snt_analytics_render_resume_lanes( '2026-10-01', '2026-10-31' ); snt_an_flush_empty_fold(); return (string) ob_get_clean(); };
$native  = static fn() => SignalNoise\OpenStationHost\Analytics\Painters\paint_resume_lanes( array( 'from' => '2026-10-01', 'to' => '2026-10-31' ) );

echo "\nGroup: the one résumé (2026-11-12): the panel keeps the history\n";
$emitted = (string) file_get_contents( $root . '/inc/resume-page-bands.php' ) . file_get_contents( $root . '/inc/resume-page-lanes.php' );
ok( false === strpos( $emitted, 'data-sn-goal="resume_hunk_' ) && false === strpos( $emitted, 'data-sn-goal="resume_lane_' ), 'the page no longer emits resume_hunk_<lane> or resume_lane_<lane>' );
ok( '2026-11-12' === SN_RESUME_LANES_ENDED && false !== strpos( sn_resume_lanes_note(), 'Since 2026-11-12 /resume is one résumé' ), 'the note dates the end of the lanes' );
ok( array( 'music' => array( 'switch' => 'resume_lane_music', 'hunk' => 'resume_hunk_music' ), 'operations' => array( 'switch' => 'resume_lane_operations', 'hunk' => 'resume_hunk_operations' ) ) === sn_resume_lane_goals(), 'the panel counts exactly those four names' );

echo "\nGroup: the model\n";
$m = sn_resume_lanes_read( '2026-10-01', '2026-10-31' );
ok( array( 'resume_lane' ) === $GLOBALS['asked']['dimensions'] && '2026-10-01' === $GLOBALS['asked']['from'] && '2026-10-31' === $GLOBALS['asked']['to'], 'reads the rows read by resume_lane for the window' );
ok( 2 === count( $m ) && 'music' === $m[0]['lane'] && 'operations' === $m[1]['lane'], 'both lanes, (unknown) dropped' );
ok( 12 === $m[0]['views'] && 0 === $m[0]['switches'] && 7 === $m[0]['hunks'] && 3 === $m[1]['switches'] && 0 === $m[1]['hunks'], 'views and clicks land on their lane' );

echo "\nGroup: classic and native render both lanes\n";
foreach ( array( 'classic' => $classic(), 'native' => $native() ) as $k => $html ) {
	ok( false !== strpos( $html, 'Résumé lanes' ) && false !== strpos( $html, 'Music' ) && false !== strpos( $html, 'Operations' ), "$k: titled, both lanes" );
	ok( false !== strpos( $html, '1m 05s' ) && false !== strpos( $html, '72%' ), "$k: time and scroll per view" );
	ok( false === strpos( $html, '900' ) && false === strpos( $html, '/r/' ) && false === strpos( $html, "\u{2014}" ), "$k: no (unknown) row, no link code, no em dash" );
}
$GLOBALS['rows'] = new WP_Error( 'x', 'older than 90 days' );
ok( false !== strpos( $classic(), 'older than 90 days' ) && false !== strpos( $native(), 'older than 90 days' ), 'a refused read says why, never an empty table' );

echo "\nGroup: owner only\n";
$GLOBALS['admin'] = false;
ok( null === sn_resume_lanes_read( 'a', 'b' ) && '' === $classic() && '' === $native(), 'nothing for a non-admin, classic or native' );
foreach ( glob( $root . '/inc/public-stats*.php' ) as $f ) {
	$src = (string) file_get_contents( $f );
	ok( false === strpos( $src, 'resume_lane' ) && false === strpos( $src, 'resume_hunk' ) && false === strpos( $src, 'sn_resume_lanes' ), 'public stats untouched: ' . basename( $f ) );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
