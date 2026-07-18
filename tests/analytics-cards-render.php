<?php
/**
 * Tests for the engaged-rate stat card in snt_analytics_render_cards().
 * Run: php tests/analytics-cards-render.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return (string) $s; }
// D2-T3 review: the badge tooltip's fallback basis label is i18n-wrapped now.
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return (string) $s; } }
function number_format_i18n( $n ) { return (string) (int) $n; }
// snt_analytics_fmt_time lives in the render file; snt_analytics_render_delta_badge is a thin
// delegator whose body is snt_an_delta_badge in inc/analytics-panels.php (required by the render
// file) — no stubs needed, and NEVER stub either name (redeclare fatal).

require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok: $msg\n"; }
	else         { $fail++; echo "  FAIL: $msg\n"; }
}

echo "\nGroup: engaged-rate card render\n";

$totals = array( 'views' => 100, 'visits' => 120, 'scroll_avg' => 40, 'time_avg' => 5000 );
ob_start(); snt_analytics_render_cards( 3, $totals, array(), array( 'current' => 42, 'pct' => 5, 'dir' => 'up' ) ); $h = ob_get_clean();
ok( strpos( $h, 'Engaged' ) !== false, 'engaged card label present' );
ok( strpos( $h, '42%' ) !== false, 'engaged value rendered as percent' );

ob_start(); snt_analytics_render_cards( 3, $totals, array(), null ); $h2 = ob_get_clean();
ok( strpos( $h2, 'Engaged' ) === false, 'no engaged card when null' );

ob_start(); snt_analytics_render_cards( 3, $totals, array(), array( 'current' => null ) ); $h3 = ob_get_clean();
ok( strpos( $h3, 'Engaged' ) === false, "array('current'=>null) (all-range, no timed data) hides the card" );

// ─── v9.64.0: the Overview speaks the honest vocabulary (spec §4) ────────────
// $totals is the FULL sn_analytics_range_totals() merge in production; these
// fixtures model that real contract (legacy quartet + derived + since marker).

echo "\nGroup: honest strip — modern range (every value pinned)\n";
$modern = array(
	'views'                     => 400,
	'visits'                    => 150,
	'scroll_avg'                => 62.5,
	'time_avg'                  => 102.5,
	'unique_visitor_days'       => 150,
	'pageview_visits'           => 130,
	'viewless_visits'           => 20,
	'view_visit_ratio'          => 400 / 130,
	'pageviews_per_visitor_day' => 400 / 150,
	'scroll_avg_per_view'       => 15.0,
	'time_avg_per_view'         => 12000.0,
	'scroll_avg_per_visit'      => 40.0,
	'time_avg_per_visit'        => 32000.0,
	'integrity_violation'       => false,
	'exact_metrics_since'       => '2026-04-18',
);
ob_start(); snt_analytics_render_cards( 7, $modern, array(), null ); $hm = ob_get_clean();
ok( strpos( $hm, '<p class="sn-kpi-label">Visits</p><p class="sn-kpi-value">130</p>' ) !== false, 'headline Visits card renders pageview_visits (130), NOT deprecated ungated visits (150)' );
ok( strpos( $hm, '<p class="sn-kpi-value">150</p>' ) === false, 'the ungated 150 is not any card value (it moves to the secondary line)' );
ok( strpos( $hm, '<p class="sn-kpi-label">Views</p><p class="sn-kpi-value">400</p>' ) !== false, 'Views card unchanged' );
ok( strpos( $hm, 'sn-an-visitor-note' ) !== false, 'visitor-day secondary line present' );
ok( strpos( $hm, '150 visitor-days' ) !== false && strpos( $hm, '20 viewless (no pageview)' ) !== false, 'secondary line surfaces unique_visitor_days + viewless_visits' );
ok( strpos( $hm, '<p class="sn-kpi-label">Scroll / view</p><p class="sn-kpi-value">15%</p>' ) !== false, 'exact scroll depth per view (the v9.64.0-corrected unit), not legacy scroll_avg 62.5' );
ok( strpos( $hm, '<p class="sn-kpi-label">Time / view</p><p class="sn-kpi-value">12s</p>' ) !== false, 'exact time per view (12000 ms → 12s), not legacy time_avg' );
ok( strpos( $hm, 'Avg scroll' ) === false && strpos( $hm, 'Avg time' ) === false, 'deprecated per-event labels are gone from the strip' );
ok( substr_count( $hm, 'sn-kpi-promoted' ) === 2, 'exactly Views + Visits stay promoted' );

echo "\nGroup: honest strip — delta wiring for the new metrics\n";
$deltas = array(
	'views'               => array( 'pct' => 12, 'dir' => 'up' ),
	'pageview_visits'     => array( 'pct' => -8, 'dir' => 'down', 'previous' => 141 ),
	'scroll_avg_per_view' => array( 'pct' => 25, 'dir' => 'up' ),
	'time_avg_per_view'   => array( 'pct' => null, 'dir' => 'flat' ),
	// Legacy keys still arrive from sn_analytics_period_deltas — they must NOT
	// be wired onto the exact cards (a different unit's verdict would lie).
	'visits'              => array( 'pct' => 99, 'dir' => 'up' ),
	'scroll_avg'          => array( 'pct' => 77, 'dir' => 'up' ),
	'time_avg'            => array( 'pct' => 66, 'dir' => 'up' ),
);
ob_start(); snt_analytics_render_cards( 7, $modern, $deltas, null ); $hd = ob_get_clean();
ok( strpos( $hd, '-8%' ) !== false, 'Visits card carries the pageview_visits delta (-8%)' );
ok( strpos( $hd, '+25%' ) !== false, 'Scroll/view card carries the scroll_avg_per_view delta (+25%)' );
ok( strpos( $hd, '+99%' ) === false && strpos( $hd, '+77%' ) === false && strpos( $hd, '+66%' ) === false, 'legacy visits/scroll_avg/time_avg deltas are NOT wired onto the honest cards' );

echo "\nGroup: honest strip — pre-backfill range (nulls degrade to em-dash + caveat, never 0)\n";
$legacy_range = array(
	'views'                     => 87,
	'visits'                    => 131,
	'scroll_avg'                => 44.0,
	'time_avg'                  => 90000.0,
	'unique_visitor_days'       => 131,
	'pageview_visits'           => null,
	'viewless_visits'           => null,
	'view_visit_ratio'          => null,
	'pageviews_per_visitor_day' => 87 / 131,
	'scroll_avg_per_view'       => null,
	'time_avg_per_view'         => null,
	'scroll_avg_per_visit'      => null,
	'time_avg_per_visit'        => null,
	'integrity_violation'       => false,
	'exact_metrics_since'       => '2026-04-18',
);
ob_start(); snt_analytics_render_cards( null, $legacy_range, array(), null ); $hl = ob_get_clean();
ok( strpos( $hl, '<p class="sn-kpi-label">Visits</p><p class="sn-kpi-value">—</p>' ) !== false, 'null pageview_visits → em-dash, never the ungated 131 and never a fabricated 0' );
ok( strpos( $hl, 'exact since 2026-04-18' ) !== false, 'the exact_metrics_since caveat names the discontinuity date' );
ok( strpos( $hl, '<p class="sn-kpi-label">Scroll / view</p><p class="sn-kpi-value">—</p>' ) !== false, 'null scroll depth → em-dash' );
ok( strpos( $hl, '<p class="sn-kpi-label">Time / view</p><p class="sn-kpi-value">—</p>' ) !== false, 'null time per view → em-dash' );
ok( strpos( $hl, '0%' ) === false && strpos( $hl, '>0s<' ) === false, 'no fabricated 0% / 0s anywhere on the null range' );
ok( strpos( $hl, '131 visitor-days' ) !== false, 'visitor-day line still renders (visits is known even pre-backfill)' );
ok( strpos( $hl, 'viewless' ) === false, 'null viewless_visits → the viewless clause is omitted, not zeroed' );

echo "\nGroup: honest strip — legacy caller (derived keys ABSENT) degrades without notices\n";
$notices = 0;
set_error_handler( function ( $errno, $errstr ) use ( &$notices ) { ++$notices; return true; } );
ob_start(); snt_analytics_render_cards( 3, $totals, array(), null ); $ha = ob_get_clean();
restore_error_handler();
ok( 0 === $notices, 'absent derived keys raise no notices (array_key_exists discipline)' );
ok( strpos( $ha, '<p class="sn-kpi-label">Visits</p><p class="sn-kpi-value">—</p>' ) !== false, 'absent pageview_visits behaves exactly like null (em-dash)' );
ok( strpos( $ha, 'no exact data yet' ) !== false, 'no since marker → the caveat says so instead of inventing a date' );
ok( strpos( $ha, 'sn-an-visitor-note' ) === false, 'absent unique_visitor_days → no visitor-day line (nothing fabricated)' );

echo "\nGroup: inline sparkline — smooth SVG mini-area\n";
$sp = snt_analytics_sparkline( array(
	array( 'day' => '2026-06-09', 'views' => 3 ),
	array( 'day' => '2026-06-10', 'views' => 9 ),
	array( 'day' => '2026-06-11', 'views' => 5 ),
) );
ok( strpos( $sp, 'sn-an-spark' ) !== false, 'sparkline: keeps the .sn-an-spark wrapper class' );
ok( strpos( $sp, '<svg' ) !== false && strpos( $sp, '<path' ) !== false, 'sparkline: renders an SVG path (not bars)' );
ok( strpos( $sp, 'class="b"' ) === false, 'sparkline: old grey tick bars gone' );
ok( preg_match( '/d="M [\d.]+,[\d.]+ C /', $sp ) === 1, 'sparkline: smooth bézier line' );
$se = snt_analytics_sparkline( array() );
ok( strpos( $se, 'sn-an-spark--empty' ) !== false, 'sparkline: empty-state class preserved' );
ok( strpos( $se, '<svg' ) === false, 'sparkline: empty-state has no SVG' );
// Many sparklines render per page (one per dim-table row × several tables) — the
// gradient id MUST be unique per call or duplicate ids break fill resolution.
$sa = snt_analytics_sparkline( array( array( 'day' => 'd', 'views' => 1 ), array( 'day' => 'e', 'views' => 2 ) ) );
$sb = snt_analytics_sparkline( array( array( 'day' => 'd', 'views' => 1 ), array( 'day' => 'e', 'views' => 2 ) ) );
preg_match( '/id="(sn-spark-fill-\d+)"/', $sa, $ma );
preg_match( '/id="(sn-spark-fill-\d+)"/', $sb, $mb );
ok( ! empty( $ma[1] ) && ! empty( $mb[1] ) && $ma[1] !== $mb[1], 'sparkline: gradient id is unique per call (no dup-id collision)' );
// A single-bucket dimension (one data point) must still show a visible mark — the old
// bar sparkline drew one full-height bar, so a bare-moveto (invisible) SVG would regress.
$s1 = snt_analytics_sparkline( array( array( 'day' => 'd', 'views' => 5 ) ) );
ok( strpos( $s1, '<svg' ) !== false && strpos( $s1, ' C ' ) !== false, 'sparkline: single point renders a visible flat line (not an invisible bare moveto)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
