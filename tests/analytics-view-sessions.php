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
// T2-review hardening (item B1): sn_analytics_session_funnels() (loaded via
// inc/analytics-sessions.php below) reads analytics.funnels through sn_setting()
// — not otherwise exercised in this suite since the AE-dormant gate short-circuits
// before it, but the corrupt-setting group further down drives it directly.
$GLOBALS['__vs_settings'] = array();
if ( ! function_exists( 'sn_setting' ) ) {
	function sn_setting( $path, $default = null ) {
		return array_key_exists( $path, $GLOBALS['__vs_settings'] ) ? $GLOBALS['__vs_settings'][ $path ] : $default;
	}
}

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
// D4 §4: a dataless Session-quality panel now folds instead of opening a postbox
// with an inline empty message — no sessions this range means no postbox at all.
ok( false === strpos( $out, 'postbox' ), 'no sessions: no .postbox panel emitted (folds instead, D4 §4)' );
ok( false !== strpos( $out, 'sn-an-empty-fold' ) && false !== strpos( $out, 'Session quality' ), 'no sessions: title survives into the empty fold (v9.65.0 sessions naming)' );
ok( false !== strpos( $out, 'No sessions in this range yet.' ), 'no sessions: the empty why speaks sessions too' );

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
ok( false !== strpos( $out2, 'sn-kpi-row' ) && false !== strpos( $out2, 'sn-kpi-value' ), 'session quality renders as cohesive KPI cards (sn-kpi), not the bare stat list' );
// v9.40.0 D4: the cards now route through the shared snt_an_kpi_row primitive —
// pin its flat sub-descriptor rendering (the sole slot shape this view uses).
ok( false !== strpos( $out2, 'sn-delta-flat">with a pageview' ), 'shared row primitive renders the sub descriptor with its flat class' );
// v9.65.0 units-collision fix (Part 3): one dashboard, one word "Visits", two
// units — the tab's live-session-engine number is within-day SESSIONS, while
// the shared Overview headline's "Visits" is gated visitor-DAYS. The tab now
// says what it counts. LABELS ONLY — no metric changed.
ok( false !== strpos( $out2, '<span>Session quality</span>' ), 'units: the KPI panel heading says "Session quality" (was "Visit quality")' );
ok( false !== strpos( $out2, 'sn-kpi-label">Sessions<' ), 'units: the headline KPI card is labeled "Sessions" (was "Visits")' );
ok( false === strpos( $out2, 'sn-kpi-label">Visits<' ), 'units: no KPI card on this tab is labeled bare "Visits" anymore' );
ok( false !== strpos( $out2, 'Pages / session' ) && false !== strpos( $out2, 'single-page sessions' ) && false !== strpos( $out2, 'per session' ),
	'units: the sibling cards speak sessions too (pages/session, single-page sessions, per session)' );
ok( false !== strpos( $out2, 'within-day sessions' ) && false !== strpos( $out2, 'visitor-day' ),
	'units: the panel carries a one-line unit note distinguishing sessions from the Overview headline\'s visitor-days' );
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

// v9.40.0 D4: snt_analytics_render_view_sessions()'s AE-dormant gate had no prior
// coverage in this suite (it only exercised snt_analytics_render_summary_panels).
// Add it: the old idiom was a titleless bare <p> — adopting snt_an_gate() is a
// deliberate shape upgrade to full postbox chrome (same class as the other
// unified gates), which this pin now locks in.
echo "\nGroup: AE dormant gate (no live Analytics Engine data for this window)\n";
ob_start();
snt_analytics_render_view_sessions( '2026-07-01', '2026-07-07', 'human' );
$gate = (string) ob_get_clean();
ok( false !== strpos( $gate, 'sn-an-gate' ), 'AE gate: unified gate marker present (upgrade from the old titleless bare <p>)' );
ok( false !== strpos( $gate, 'Session analytics need live Analytics Engine data for this window.' ), 'AE gate: message speaks sessions (v9.65.0 units fix)' );
ok( false !== strpos( $gate, '<span>Sessions</span>' ), 'AE gate: carries the "Sessions" title (was "Visits")' );

// S2 §3 T2/T3-review hardening: a corrupt analytics.funnels setting must not
// reach sn_funnel_report() malformed — the resolver falls back to the hardcoded
// defaults wholesale (inc/analytics-sessions.php). T3 sharpened the fixture in
// two ways: (1) the corrupt entry now carries STRING step elements
// (['title'=>'X','steps'=>['junk1','junk2']]) — the shape that used to pass
// resolve and TypeError sn_funnel_step_matches(array $step, ...) under live
// data; (2) the summaries fixture is NON-EMPTY, so sn_funnel_report actually
// reaches the matcher (the old empty-summaries pin never did — the report loop
// short-circuits before any step is inspected, so it discriminated nothing).
echo "\nGroup: corrupt analytics.funnels setting — Visits view renders without error under LIVE-shaped data\n";
$GLOBALS['__vs_settings'] = array( 'analytics.funnels' => array( array( 'title' => 'X', 'steps' => array( 'junk1', 'junk2' ) ) ) );
$defs                     = sn_analytics_session_funnels();
ok( 2 === count( $defs ), 'corrupt setting (string step elements) falls back to the 2 hardcoded funnels' );
$live_summaries = array(
	array(
		'visits'    => 1,
		'pageviews' => 2,
		'events'    => array(
			array( 'ev' => 'pv', 'path' => '/', 'ce' => '' ),
			array( 'ev' => 'pv', 'path' => '/notes/hello', 'ce' => '' ),
			array( 'ev' => 'ce', 'path' => '/notes/hello', 'ce' => 'subscribe' ),
		),
	),
);
$funnels = array();
foreach ( $defs as $def ) {
	$funnels[] = array( 'title' => $def['title'], 'report' => sn_funnel_report( $live_summaries, $def['steps'] ) );
}
ok( 1 === $funnels[0]['report'][2]['reached'], 'the matcher genuinely ran: the fixture visit completes all 3 steps of the first hardcoded funnel' );
ob_start();
snt_analytics_render_summary_panels(
	array( 'visits' => 1, 'bounce_rate' => 0.0, 'pages_per_visit' => 2.0, 'median_duration' => 30, 'engaged_visits' => 0, 'engaged_rate' => 0.0 ),
	array(),
	$funnels,
	false
);
$out7 = ob_get_clean();
ok( is_string( $out7 ), 'the Visits view renders without a fatal when the funnels setting was corrupt' );
ok( false !== strpos( $out7, '[dist-panel:Home → post → subscribe:3]' ), 'the fallback funnel renders as a titled panel with its 3 completed steps (the matcher output reached the view)' );
$GLOBALS['__vs_settings'] = array();

// ── v9.65.0: long-term session-quality trend panel (durable rollup) ──────────
// Fed by sn_session_rollup_read() (wp_sn_session_daily — written nightly since
// v8.8.0, read by NOTHING until now). Four input states, each with its own
// honest rendering: false = legacy caller / module absent (render NOTHING —
// byte-parity for old callers), null = read failed (fold, "couldn't read"),
// <2 rows = fold ("not enough rolled-up days"), >=2 rows = the real panel.
echo "\nGroup: session-quality trend panel (durable rollup)\n";
$__quiet_metrics = array( 'visits' => 2, 'bounce_rate' => 0.0, 'pages_per_visit' => 2.0, 'median_duration' => 30, 'engaged_visits' => 1, 'engaged_rate' => 0.5 );

// Legacy 5-arg call (all the assertions above) never mentions the panel; pin it.
ob_start();
snt_analytics_render_summary_panels( $__quiet_metrics, array(), array(), false, array() );
$t0 = ob_get_clean();
ok( false === strpos( $t0, 'Session quality trend' ), 'trend: legacy caller (no 6th arg) renders no trend panel and no fold entry' );

// null = the accessor could not read the table — fold with an honest "failed" why.
ob_start();
snt_analytics_render_summary_panels( $__quiet_metrics, array(), array(), false, array(), null );
$t1 = ob_get_clean();
ok( false === strpos( $t1, '<span>Session quality trend</span>' ), 'trend: failed read emits no titled panel' );
ok( false !== strpos( $t1, 'Session quality trend' ) && false !== strpos( $t1, 'could not be read' ),
	'trend: failed read folds with a "could not be read" why (unknown is not an empty window)' );

// [] = the nightly rollup has written nothing in this window — fold, honest why.
ob_start();
snt_analytics_render_summary_panels( $__quiet_metrics, array(), array(), false, array(), array() );
$t2 = ob_get_clean();
ok( false === strpos( $t2, '<span>Session quality trend</span>' ), 'trend: zero rolled-up days emits no titled panel' );
ok( false !== strpos( $t2, 'Session quality trend' ) && false !== strpos( $t2, 'no rolled-up days' ),
	'trend: zero rows folds with the "no rolled-up days" why' );

// One row cannot draw a trend — fold says so instead of pretending "no data".
$__one_day = array( array( 'day' => '2026-06-01', 'visits' => 12, 'bounce_pct' => 42.5, 'ppv' => 1.75, 'median_dur' => 55 ) );
ob_start();
snt_analytics_render_summary_panels( $__quiet_metrics, array(), array(), false, array(), $__one_day );
$t3 = ob_get_clean();
ok( false === strpos( $t3, '<span>Session quality trend</span>' ), 'trend: a single rolled-up day emits no titled panel' );
ok( false !== strpos( $t3, 'needs at least two' ), 'trend: single-day fold says the trend needs at least two days (not "no data")' );

// >=2 rows: the real panel — three sparklines, explicit sessions unit, pinned values.
$__trend_rows = array(
	array( 'day' => '2026-06-01', 'visits' => 12, 'bounce_pct' => 42.5, 'ppv' => 1.75, 'median_dur' => 55 ),
	array( 'day' => '2026-06-02', 'visits' => 9,  'bounce_pct' => 50.0, 'ppv' => 1.5,  'median_dur' => 30 ),
	array( 'day' => '2026-06-04', 'visits' => 20, 'bounce_pct' => 35.5, 'ppv' => 2.1,  'median_dur' => 61 ),
);
ob_start();
snt_analytics_render_summary_panels( $__quiet_metrics, array(), array(), false, array(), $__trend_rows );
$t4 = ob_get_clean();
ok( false !== strpos( $t4, '<span>Session quality trend</span>' ), 'trend: >=2 rolled-up days renders the titled postbox panel' );
ok( false !== strpos( $t4, 'Bounce rate' ) && false !== strpos( $t4, 'Pages / session' ) && false !== strpos( $t4, 'Median duration' ),
	'trend: all three metric sparklines carry their heads' );
ok( 3 === substr_count( $t4, '<svg class="sn-spark"' ), 'trend: exactly three sparkline SVGs' );
ok( false !== strpos( $t4, 'snSparkFillSessBounce' ) && false !== strpos( $t4, 'snSparkFillSessPpv' ) && false !== strpos( $t4, 'snSparkFillSessDur' ),
	'trend: the three gradients carry DISTINCT ids (three svgs share one page)' );
// Value pins from the fixture's LAST row (not label echoes).
ok( false !== strpos( $t4, 'latest 35.5%' ), 'trend: bounce meta pins the last day\'s real value' );
ok( false !== strpos( $t4, 'latest 2.10' ), 'trend: pages/session meta pins the last day\'s real value' );
ok( false !== strpos( $t4, 'latest 61s' ), 'trend: median-duration meta pins the last day\'s real value' );
// Axis = the ACTUAL rolled-up day span (not the requested window) — honest about gaps.
ok( false !== strpos( $t4, '<span>2026-06-01</span>' ) && false !== strpos( $t4, '<span>2026-06-04</span>' ),
	'trend: the axis spans the first..last ROLLED-UP day' );
// The unit is explicit: sessions, not the Overview headline's visitor-days (Part 3 contract).
ok( false !== strpos( $t4, 'within-day sessions' ) && false !== strpos( $t4, 'visitor-day' ),
	'trend: the unit note names within-day sessions AND distinguishes them from visitor-days' );
ok( false !== strpos( $t4, '3 rolled-up days' ), 'trend: the note states how many days actually rolled up' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
