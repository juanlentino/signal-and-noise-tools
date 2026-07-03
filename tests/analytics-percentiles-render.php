<?php
/**
 * Tests for snt_analytics_render_percentiles() — the 3-chip percentile panel.
 * Mirrors tests/analytics-distribution-render.php.
 * Run: php tests/analytics-percentiles-render.php
 * @since plugin v6.8.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function number_format_i18n( $n ) { return (string) (int) $n; }
require_once __DIR__ . '/../inc/analytics-panels.php'; // v8.5.0: renderers emit chrome via the panel primitive
require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  ok: $m\n"; } else { ++$fail; echo "  FAIL: $m\n"; } }

echo "\nGroup: percentile chips render\n";
$rows = array(
	array( 'label' => 'p50', 'value' => 63.0 ),
	array( 'label' => 'p75', 'value' => 84.0 ),
	array( 'label' => 'p90', 'value' => 95.0 ),
);
ob_start(); snt_analytics_render_percentiles( 'Scroll depth — percentiles', $rows, 'pct' ); $h = ob_get_clean();
ok( strpos( $h, 'Scroll depth — percentiles' ) !== false, 'pct: title rendered' );
ok( strpos( $h, 'sn-an-pctl-chip' ) !== false, 'pct: chip markup present' );
ok( strpos( $h, '63%' ) !== false && strpos( $h, '95%' ) !== false, 'pct: values shown as integer percent' );
ok( strpos( $h, 'P50' ) !== false && strpos( $h, 'P90' ) !== false, 'pct: labels uppercased' );

echo "\nGroup: time format reuses snt_analytics_fmt_time\n";
$trows = array(
	array( 'label' => 'p50', 'value' => 38000.0 ),  // 38s
	array( 'label' => 'p75', 'value' => 72000.0 ),  // 1m 12s
	array( 'label' => 'p90', 'value' => 220000.0 ), // 3m 40s
);
ob_start(); snt_analytics_render_percentiles( 'Time on page — percentiles', $trows, 'time' ); $t = ob_get_clean();
ok( strpos( $t, '38s' ) !== false, 'time: seconds formatting' );
ok( strpos( $t, '1m 12s' ) !== false && strpos( $t, '3m 40s' ) !== false, 'time: minute+second formatting' );

echo "\nGroup: null/empty → empty-state, never fatal\n";
ob_start(); snt_analytics_render_percentiles( 'Scroll depth — percentiles', null, 'pct', 'Percentiles need live Analytics Engine data.' ); $e = ob_get_clean();
ok( strpos( $e, 'Percentiles need live Analytics Engine data.' ) !== false, 'null: custom empty message shown' );
ok( strpos( $e, 'sn-an-pctl-chip' ) === false, 'null: no chips rendered' );
ob_start(); snt_analytics_render_percentiles( 'Time on page — percentiles', array(), 'time' ); $e2 = ob_get_clean();
ok( strpos( $e2, 'No time on page — percentiles data' ) !== false, 'empty: default empty message' );

echo "\nGroup: optional retention note\n";
ob_start(); snt_analytics_render_percentiles( 'Scroll depth — percentiles', $rows, 'pct', '', '(last ~90d — AE retention)' ); $n = ob_get_clean();
ok( strpos( $n, '(last ~90d — AE retention)' ) !== false, 'note: footnote shown when provided' );
ok( strpos( $n, 'sn-an-foot' ) !== false, 'note: rendered in the footnote class' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
