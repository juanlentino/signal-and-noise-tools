<?php
/**
 * Render smoke test for the Visits view. Run: php tests/analytics-view-sessions.php
 *
 * The view file requires inc/analytics-panels.php (the real panel primitives),
 * which declares snt_an_panel_open/snt_an_panel_close/snt_an_note_empty/
 * snt_an_flush_empty_fold unconditionally — so those must NOT be stubbed here
 * (stubbing them would cause a "Cannot redeclare function" fatal). Only the
 * two render helpers that live in analytics-admin-render.php (NOT loaded by
 * the view) are safe to stub, plus the leaf WP functions the real panel code
 * and the view call at runtime.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );

// Leaf WP function stubs used by the real analytics-panels.php + the view.
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = '' ) { return $s; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = '' ) { return $s; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ); } }

// Render-helper stubs — these live in analytics-admin-render.php, which is NOT
// loaded by requiring the view, so stubbing them here is safe. They MIRROR the
// real helpers' empty-vs-non-empty branching (see analytics-admin-render.php:422
// and :601): an all-zero / empty dataset routes to the REAL snt_an_note_empty()
// (loaded via the view's panels require) so its title collapses into the empty
// fold with NO titled panel; a non-empty dataset emits a titled panel marker
// carrying the row count. This is what makes the empty-funnel assertion below a
// genuine behavioral check rather than a tautology.
if ( ! function_exists( 'snt_analytics_render_distribution' ) ) {
	function snt_analytics_render_distribution( $t, $r, $e = '', $w = false ) {
		$max = 0;
		foreach ( (array) $r as $row ) { $max = max( $max, (int) ( $row['views'] ?? 0 ) ); }
		if ( $max <= 0 ) { snt_an_note_empty( $t ); return; }
		echo "[dist-panel:$t:" . count( $r ) . ']';
	}
}
if ( ! function_exists( 'snt_analytics_render_dim_table' ) ) {
	function snt_analytics_render_dim_table( $t, $r, $e = '', $s = array(), $d = '', $v = 5 ) {
		if ( empty( $r ) ) { snt_an_note_empty( $t ); return; }
		echo "[dim-panel:$t:" . count( $r ) . ']';
	}
}

require __DIR__ . '/../inc/analytics-annotations.php'; // v9.5.0: the Visits-view read resolvers
require __DIR__ . '/../inc/analytics-sessions.php';
require __DIR__ . '/../inc/analytics-view-sessions.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// Empty summaries render without fatal and emit the summary panel (real markup).
ob_start();
snt_analytics_render_summary_panels(
	array( 'visits' => 0, 'bounce_rate' => 0.0, 'pages_per_visit' => 0.0, 'median_duration' => 0, 'engaged_visits' => 0, 'engaged_rate' => 0.0 ),
	array(),
	array(),
	false
);
$out = ob_get_clean();
ok( is_string( $out ), 'render produced output without fatal' );
ok( false !== strpos( $out, 'postbox' ), 'emitted a real .postbox panel' );
ok( false !== strpos( $out, 'Visit quality' ), 'emitted the Visit quality panel title' );

// Regression guard for the funnel/transition empty-state delegation: a visible
// transition row PLUS a funnel whose report is all-zero (nobody reached step 1).
// After delegating empty-state to the render helpers, the empty funnel must NOT
// produce a hollow titled panel — it must collapse into the empty fold under its
// OWN name. The visible transition must still render as a titled panel.
ob_start();
snt_analytics_render_summary_panels(
	array( 'visits' => 4, 'bounce_rate' => 0.25, 'pages_per_visit' => 1.75, 'median_duration' => 30, 'engaged_visits' => 2, 'engaged_rate' => 0.5 ),
	array( array( 'from' => '/a', 'to' => '/b', 'count' => 3 ) ),
	array( array( 'title' => 'Empty funnel', 'report' => array( array( 'label' => '/', 'reached' => 0, 'rate' => 0.0, 'drop' => 0 ) ) ) ),
	false
);
$out2 = ob_get_clean();
ok( false !== strpos( $out2, '[dim-panel:Top paths:1]' ), 'non-empty transition rendered as a titled "Top paths" panel with its 1 row' );
ok( false !== strpos( $out2, 'sn-kpi-row' ) && false !== strpos( $out2, 'sn-kpi-value' ), 'visit quality renders as cohesive KPI cards (sn-kpi), not the bare stat list' );
// v9.40.0 D4: the cards now route through the shared snt_an_kpi_row primitive —
// pin its flat sub-descriptor rendering (the sole slot shape this view uses).
ok( false !== strpos( $out2, 'sn-delta-flat">with a pageview' ), 'shared row primitive renders the sub descriptor with its flat class' );
// The OLD hollow-panel bug emitted a real .postbox with the funnel name in its
// hndle heading (<h2 class="hndle"><span>Empty funnel</span></h2>) wrapping an
// empty body. Assert that exact markup is gone.
ok( false === strpos( $out2, '<span>Empty funnel</span>' ), 'all-zero funnel did NOT emit a hollow .postbox heading' );
ok( false === strpos( $out2, '[dist-panel:Empty funnel' ), 'all-zero funnel did NOT emit a titled distribution panel' );
ok( false !== strpos( $out2, 'sn-an-empty-fold' ), 'the empty fold line was emitted' );
ok( false !== strpos( $out2, 'Empty funnel' ), 'all-zero funnel collapsed into the empty fold under its own name' );

// v9.1.0: conversion-attribution panel renders from the new 5th arg (entry → conversions).
ob_start();
snt_analytics_render_summary_panels(
	array( 'visits' => 2, 'bounce_rate' => 0.0, 'pages_per_visit' => 2.0, 'median_duration' => 30, 'engaged_visits' => 1, 'engaged_rate' => 0.5 ),
	array(),
	array(),
	false,
	array( array( 'entry' => '/services', 'conversions' => 3 ), array( 'entry' => '/contact', 'conversions' => 1 ) )
);
$out3 = ob_get_clean();
ok( false !== strpos( $out3, '[dist-panel:Contact conversions by entry page:2]' ), 'conversion-attribution panel renders its rows when data is present' );

// Empty attribution collapses into the fold — no hollow titled panel (matches funnels).
ob_start();
snt_analytics_render_summary_panels(
	array( 'visits' => 2, 'bounce_rate' => 0.0, 'pages_per_visit' => 2.0, 'median_duration' => 30, 'engaged_visits' => 1, 'engaged_rate' => 0.5 ),
	array(), array(), false, array()
);
$out4 = ob_get_clean();
ok( false === strpos( $out4, '[dist-panel:Contact conversions by entry page' ), 'empty attribution does NOT emit a titled panel' );
ok( false !== strpos( $out4, 'sn-an-empty-fold' ), 'empty attribution collapses into the empty fold' );

// v9.5.0 reads: visit quality (engaged-read band) + conversions (entry dominance).
ob_start();
snt_analytics_render_summary_panels(
	array( 'visits' => 120, 'bounce_rate' => 0.2, 'pages_per_visit' => 2.5, 'median_duration' => 60, 'engaged_visits' => 86, 'engaged_rate' => 0.72 ),
	array(), array(), false,
	array( array( 'entry' => '/contact/', 'conversions' => 8 ), array( 'entry' => '/services/', 'conversions' => 2 ) )
);
$out5 = ob_get_clean();
ok( substr_count( $out5, 'class="sn-an-note"' ) >= 2, 'both Visits-view reads emit on tripping data' );
ok( false !== strpos( $out5, 'engaged reads' ), 'visit-quality read text is rendered' );
ok( false !== strpos( $out5, 'Most contacts enter on /contact/' ), 'conversions read names the dominant entry page' );

// A typical middle range + spread conversions emit neither read.
ob_start();
snt_analytics_render_summary_panels(
	array( 'visits' => 120, 'bounce_rate' => 0.4, 'pages_per_visit' => 1.8, 'median_duration' => 30, 'engaged_visits' => 54, 'engaged_rate' => 0.45 ),
	array(), array(), false,
	array( array( 'entry' => '/contact/', 'conversions' => 4 ), array( 'entry' => '/services/', 'conversions' => 3 ), array( 'entry' => '/about/', 'conversions' => 3 ) )
);
$out6 = ob_get_clean();
ok( false === strpos( $out6, 'class="sn-an-note"' ), 'a typical range with spread conversions emits no read' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
