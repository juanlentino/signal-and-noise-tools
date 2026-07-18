<?php
/**
 * Standalone fixture tests for the v8.5.0 Technology view extraction
 * (inc/analytics-view-technology.php): the case body moved verbatim from the
 * dispatcher — this fixture pins the panel set + wrappers via recorders.
 *
 * Run: php tests/analytics-view-technology.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return trim( (string) $v ); } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } } // D5 §6: view now wraps panel titles

// v9.68.1: the real accessors return NULL for a FAILED wpdb read ([] = empty
// window) — the stub mirrors that contract via a per-dim fail list.
$GLOBALS['__tech_fail']   = array();
$GLOBALS['__series_dims'] = array();
function sn_analytics_top_dimension( $d, $f, $t, $c = 'human', $l = 25 ) {
	if ( in_array( $d, $GLOBALS['__tech_fail'], true ) ) { return null; }
	return array( array( 'value' => 'X', 'views' => 1, 'visits' => 1 ) );
}
function sn_analytics_dimension_series( $d, $v, $f, $t, $c = 'human', $g = 'day' ) { $GLOBALS['__series_dims'][] = (string) $d; return array(); }
function snt_analytics_render_dim_table( $title, $rows, $empty, $series = array(), $drill = '' ) { echo '<!--DIM:' . $title . ( null === $rows ? ':NULL' : '' ) . '-->'; }

require_once __DIR__ . '/../inc/analytics-view-technology.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "analytics-view-technology suite - plugin v8.5.0\n\nTest: extracted composition\n";
ob_start();
snt_analytics_render_view_technology( '2026-07-01', '2026-07-07', 'human', 'day' );
$html = (string) ob_get_clean();
ok( false !== strpos( $html, 'sn-an-grid' ), 'grid wrapper kept' );
foreach ( array( 'Browsers', 'Operating systems', 'Devices', 'Protocols', 'TLS versions' ) as $t ) {
	ok( false !== strpos( $html, '<!--DIM:' . $t . '-->' ), $t . ' table renders' );
}

echo "\nTest: v9.68.1 — a FAILED dim read (accessor null) reaches the renderer as null, skips its series read, no fatal\n";
$GLOBALS['__tech_fail']   = array( 'browser', 'device' );
$GLOBALS['__series_dims'] = array();
ob_start();
snt_analytics_render_view_technology( '2026-07-01', '2026-07-07', 'human', 'day' );
$hf = (string) ob_get_clean();
ok( false !== strpos( $hf, '<!--DIM:Browsers:NULL-->' ), 'failed browsers read hands NULL to the renderer (its read-failure fold owns the copy)' );
ok( false !== strpos( $hf, '<!--DIM:Devices:NULL-->' ), 'failed devices read hands NULL too' );
ok( false !== strpos( $hf, '<!--DIM:Operating systems-->' ), 'a neighboring healthy read still renders rows (per-read verdicts, no bleed)' );
ok( ! in_array( 'browser', $GLOBALS['__series_dims'], true ) && ! in_array( 'device', $GLOBALS['__series_dims'], true ),
	'failed dims issue NO series read (nothing to key it on — and array_map over null would fatal)' );
ok( in_array( 'os', $GLOBALS['__series_dims'], true ) && in_array( 'protocol', $GLOBALS['__series_dims'], true ) && in_array( 'tls', $GLOBALS['__series_dims'], true ),
	'healthy dims still request their trend series' );
$GLOBALS['__tech_fail'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
