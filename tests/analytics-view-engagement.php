<?php
/**
 * Standalone fixture tests for the v8.5.0 Engagement view extraction
 * (inc/analytics-view-engagement.php): the case body moved verbatim from the
 * dispatcher — this fixture pins the panel set + wrappers via recorders.
 *
 * D5 §6 adds: the "Field Core Web Vitals" section separator must fold away
 * (not orphan) when all three CWV distributions are dataless, and titles are
 * now wrapped in __().
 *
 * Run: php tests/analytics-view-engagement.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return trim( (string) $v ); } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }

function sn_analytics_hour_dow_grid( $f, $t, $c = 'human' ) { return array( 'grid' => array(), 'max' => 0 ); }
// CWV metrics (lcp/inp/cls) are independently controllable via $GLOBALS['__cwv'];
// every other metric (scroll/time/rtt) always has data — unaffected by this pin.
$GLOBALS['__cwv'] = array(
	'lcp' => array( array( 'views' => 5 ) ),
	'inp' => array( array( 'views' => 5 ) ),
	'cls' => array( array( 'views' => 5 ) ),
);
function sn_analytics_distribution( $m, $f, $t, $c = 'human' ) {
	if ( in_array( $m, array( 'lcp', 'inp', 'cls' ), true ) ) {
		return $GLOBALS['__cwv'][ $m ] ?? array();
	}
	return array( array( 'views' => 5 ) );
}
function sn_analytics_percentiles( $m, $f, $t, $c = 'human' ) { return array(); }
function sn_analytics_engagement_anomalies( $f, $t, $c = 'human' ) { return array( 'divergence' => array(), 'outliers' => array() ); }
function snt_analytics_render_heatmap( $h ) { echo '<!--HEATMAP-->'; }
// Mirrors the real snt_analytics_render_distribution()'s empty-vs-non-empty
// branching (max <= 0 -> fold via the real snt_an_note_empty(), loaded by the
// view's require of analytics-panels.php) so the CWV-fold pins below are a
// genuine behavioral check, not a tautology.
function snt_analytics_render_distribution( $title, $rows, $empty = '' ) {
	$max = 0;
	foreach ( (array) $rows as $r ) { $max = max( $max, (int) ( $r['views'] ?? 0 ) ); }
	if ( $max <= 0 ) { snt_an_note_empty( $title, $empty ); return; }
	echo '<!--DIST:' . $title . '-->';
}
function snt_analytics_render_percentiles( $title, $rows, $fmt = 'pct', $empty = '', $note = '' ) { echo '<!--PCTL:' . $title . '-->'; }
function snt_analytics_render_anomalies( $anom ) { echo '<!--ANOM-->'; }

require_once __DIR__ . '/../inc/analytics-view-engagement.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "analytics-view-engagement suite - plugin v8.5.0\n\nTest: extracted composition (CWV has data)\n";
ob_start();
snt_analytics_render_view_engagement( '2026-07-01', '2026-07-07', 'human' );
$html = (string) ob_get_clean();
ok( false !== strpos( $html, '<!--HEATMAP-->' ), 'heatmap renders first' );
foreach ( array( 'Scroll depth', 'Time on page', 'Connection RTT', 'LCP (field)', 'INP (field)', 'CLS (field)' ) as $t ) {
	ok( false !== strpos( $html, '<!--DIST:' . $t . '-->' ), $t . ' distribution renders' );
}
ok( false !== strpos( $html, '<!--PCTL:Scroll depth' ) && false !== strpos( $html, '<!--PCTL:Time on page' ), 'both percentile panels render' );
ok( false !== strpos( $html, 'Field Core Web Vitals' ), 'CWV separator kept when at least one CWV panel has data' );
ok( false !== strpos( $html, '<!--ANOM-->' ), 'anomalies panel renders' );

echo "\nTest: D5 §6 — CWV all-empty folds the separator instead of orphaning it\n";
$GLOBALS['__cwv'] = array( 'lcp' => array(), 'inp' => array(), 'cls' => array() );
ob_start();
snt_analytics_render_view_engagement( '2026-07-01', '2026-07-07', 'human' );
$empty_html = (string) ob_get_clean();
ok( false === strpos( $empty_html, 'Field Core Web Vitals' ), 'all-empty CWV: separator is ABSENT (no orphan)' );
// snt_an_flush_empty_fold() (called at the end of the view) clears the
// collector after emitting, so assert against the RENDERED fold markup
// (captured above, before the clear) rather than the now-empty global.
ok(
	false !== strpos( $empty_html, 'LCP (field)' ) && false !== strpos( $empty_html, 'INP (field)' ) && false !== strpos( $empty_html, 'CLS (field)' ),
	'all-empty CWV: all three panels fold under their own titles'
);
ok( false !== strpos( $empty_html, 'sn-an-empty-fold' ), 'all-empty CWV: the fold markup is emitted'  );

echo "\nTest: D5 §6 — any CWV data present keeps the separator\n";
$GLOBALS['__cwv'] = array( 'lcp' => array( array( 'views' => 3 ) ), 'inp' => array(), 'cls' => array() );
ob_start();
snt_analytics_render_view_engagement( '2026-07-01', '2026-07-07', 'human' );
$partial_html = (string) ob_get_clean();
ok( false !== strpos( $partial_html, 'Field Core Web Vitals' ), 'partial CWV data: separator PRESENT' );
ok( false !== strpos( $partial_html, '<!--DIST:LCP (field)-->' ), 'partial CWV data: the LCP panel with data renders (not folded)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
