<?php
/**
 * Tests for inc/analytics-posts-lifecycle-admin.php — the catalogue lifecycle
 * section render, including the annotation callout wired in v9.4.0. The render
 * fn had no test harness before; this adds one, anchored on the annotation.
 *
 * @since plugin v9.4.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ── WP stubs the section/table/pill render chain needs ──
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }

require_once __DIR__ . '/../inc/analytics-panels.php';
require_once __DIR__ . '/../inc/analytics-annotations.php';
require_once __DIR__ . '/../inc/analytics-posts-lifecycle-admin.php';

$pass = 0;
$fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
function cap( $fn ) { ob_start(); $fn(); return (string) ob_get_clean(); }

echo "analytics-posts-lifecycle-admin render suite (v9.4.0)\n";

echo "\nTest: empty catalogue folds to the empty state (no annotation)\n";
$empty = cap( function () { snt_analytics_render_lifecycle_section( array( 'rows' => array(), 'summary' => array() ) ); } );
ok( false !== strpos( $empty, 'No catalogue data yet' ), 'empty rows -> empty-state note' );
ok( false === strpos( $empty, 'sn-an-note' ), 'empty catalogue -> no annotation callout' );

echo "\nTest: catalogue with refresh candidates renders the annotation callout (integration)\n";
$mk_row = function ( $decay, $cand ) {
	return array( 'id' => 1, 'title' => 'X', 'permalink' => '/x', 'age' => 200, 'lifetime' => 50, 'per_day' => 0.2, 'decay' => $decay, 'evergreen' => false, 'refresh_candidate' => $cand, 'modified_ts' => 0 );
};
$rows = array();
for ( $i = 0; $i < 20; $i++ ) {
	$rows[] = $mk_row( $i < 4 ? 'cooling' : 'evergreen', $i < 3 );
}
$lifecycle = array(
	'rows'    => $rows,
	'summary' => array( 'counts' => array( 'spike' => 0, 'cooling' => 4, 'evergreen' => 16, 'unknown' => 0 ), 'refresh_candidates' => 3, 'total' => 20 ),
);
$html = cap( function () use ( $lifecycle ) { snt_analytics_render_lifecycle_section( $lifecycle ); } );
ok( false !== strpos( $html, 'sn-an-note' ), 'render integration: catalogue with candidates emits the annotation callout' );
ok( false !== strpos( $html, '4 of 20 posts are cooling, and 3 are refresh candidates.' ), 'render integration: callout carries the lifecycle read for the summary' );
// v9.40.0 D4: the glance cards now route through the shared snt_an_kpi_row
// primitive — pin the row + the candidate-count-derived sub_class (down when
// refresh_candidates > 0).
ok( false !== strpos( $html, 'sn-kpi-row' ) && false !== strpos( $html, 'sn-delta-down">cooling, not evergreen' ), 'glance renders via the shared KPI row primitive (down class when candidates > 0)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
