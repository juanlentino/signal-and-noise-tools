<?php
/**
 * Tests for inc/analytics-view-overview.php — the v9.68.0 WIRED Overview
 * landing view (the v9.67.0 flag-gated static mock, promoted: real data,
 * shared chrome, default tab).
 *
 * Contract under test:
 *  - The view renders under the SHARED header chrome (no duplicate Headline
 *    panel, no preview badges — the shared Overview KPI card outside this view
 *    body is the headline).
 *  - LOAD-COST RULE (hard): the body reads ONLY durable rollup tables
 *    (wp_sn_session_daily, wp_sn_analytics_dims, wp_sn_analytics_utm,
 *    wp_sn_analytics_pageroles) + the cron-warmed realtime transient — the
 *    live session-engine AE fetch (50k cap) and every other AE path are
 *    NEVER called on render.
 *  - NULL DISCIPLINE per panel: a failed read renders "could not be read"
 *    (never an empty week); an empty result folds honestly; realtime null is
 *    "warming", never a fabricated 0.
 *  - Window/class contract: panels pass the header's $from/$to/$class where
 *    their accessor supports it; the 8-week session trend is a FIXED window
 *    (labeled); entry/exit + views-today are human-only (labeled).
 *  - Pure aggregation helpers (session KPIs, weekly bounce) are value-pinned.
 *
 * Stubs mirror the REAL accessor contracts (typed rows, null-on-failure —
 * copied from the inc/ docblocks, the stub-drift rule).
 *
 * Run: php tests/analytics-view-overview.php
 * @since plugin v9.68.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );

// ---- WP stubs (the tests/analytics-admin.php idiom: realistic escaping) ----
function add_action( $h, $c = null, $p = 10, $a = 1 ) {}
function do_action( $h = '', ...$args ) {}
function apply_filters( $tag, $value ) { return $value; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return (string) $s; }
// Real number_format_i18n honors the $decimals param — the KPI pins below
// assert one-decimal bounce + two-decimal ppv, so the stub must too.
function number_format_i18n( $n, $decimals = 0 ) { return number_format( (float) $n, (int) $decimals ); }
function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ), '-' ); }
function wp_kses_post( $s ) { return (string) $s; }
function add_query_arg( $args, $url = null ) {
	if ( null === $url ) { $url = '/wp-admin/index.php?page=sn-analytics'; }
	$sep = ( strpos( (string) $url, '?' ) !== false ) ? '&' : '?';
	return $url . $sep . http_build_query( $args );
}

// ---- Accessor seams: REAL return shapes, call-recorded ---------------------
// Every stub records ($fn, args) so the window/class + load-cost groups can
// pin exactly what the render read — and what it never touched.
$GLOBALS['__ov'] = array(
	'session_rollup' => array(), // keyed "from|to" => rows|null
	'sources'        => array(),
	'campaigns'      => array(),
	'dims'           => array(), // keyed by dim
	'realtime'       => null,
	'views_today'    => null,
	'entries'        => array(),
	'exits'          => array(),
);
$GLOBALS['__ov_calls'] = array();
function ov_reset_calls() { $GLOBALS['__ov_calls'] = array(); }
function ov_calls( $fn ) {
	return array_values( array_filter( $GLOBALS['__ov_calls'], function ( $c ) use ( $fn ) { return $c[0] === $fn; } ) );
}

// Durable session rollup (inc/analytics-session-rollup.php contract: typed
// day-ascending rows, [] = no days rolled, null = failed read).
function sn_session_rollup_read( $from, $to, $class ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_session_rollup_read', $from, $to, $class );
	$key = $from . '|' . $to;
	return array_key_exists( $key, $GLOBALS['__ov']['session_rollup'] ) ? $GLOBALS['__ov']['session_rollup'][ $key ] : array();
}
// Canonical sources over the durable dims table (inc/analytics-sources.php).
function sn_analytics_top_sources( $from, $to, $class = 'human', $limit = 10 ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_top_sources', $from, $to, $class, $limit );
	return $GLOBALS['__ov']['sources'];
}
// Durable UTM rollup (inc/analytics-utm.php).
function sn_analytics_top_utm_campaigns( $from, $to, $class = 'human', $limit = 25 ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_top_utm_campaigns', $from, $to, $class, $limit );
	return $GLOBALS['__ov']['campaigns'];
}
// Durable dims rollup (inc/analytics-dims.php).
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25, $refresh = false ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_top_dimension', $dim, $from, $to, $class, $limit );
	return $GLOBALS['__ov']['dims'][ $dim ] ?? array();
}
// Cron-warmed realtime transient (inc/analytics-realtime.php: int, or null =
// never warmed).
function sn_analytics_realtime( $class = 'human' ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_realtime', $class );
	return $GLOBALS['__ov']['realtime'];
}
function sn_analytics_views_today() {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_views_today' );
	return $GLOBALS['__ov']['views_today'];
}
// Durable pageroles rollup (inc/analytics-pageroles.php: human-only, no class).
function sn_analytics_top_entry_pages( $from, $to, $limit = 25 ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_top_entry_pages', $from, $to, $limit );
	return $GLOBALS['__ov']['entries'];
}
function sn_analytics_top_exit_pages( $from, $to, $limit = 25 ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_top_exit_pages', $from, $to, $limit );
	return $GLOBALS['__ov']['exits'];
}

// FORBIDDEN on the landing render (the load-cost rule): the live session-engine
// AE fetch (50k cap — stays on the Sessions tab), the raw AE query transport,
// and the header's own totals fetch (the header region owns it, outside the
// view body). Each records so the group below can pin ZERO calls.
function sn_analytics_fetch_session_events( $from, $to, $class ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_fetch_session_events', $from, $to, $class );
	return array( 'configured' => true, 'summaries' => array(), 'capped' => false );
}
function sn_analytics_query( $sql ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_query', $sql );
	return array();
}
function sn_analytics_range_totals( $from, $to, $class = 'human' ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_range_totals', $from, $to, $class );
	return array();
}

require_once __DIR__ . '/../inc/analytics-panels.php';        // real panel primitives
require_once __DIR__ . '/../inc/analytics-render-tables.php'; // real dim + pageroles tables
require_once __DIR__ . '/../inc/analytics-view-overview.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function capture( $cb ) { ob_start(); call_user_func( $cb ); return (string) ob_get_clean(); }

echo "Overview (wired) — the v9.68.0 landing view\n\n";

// ── Fixtures shaped like the real site ──────────────────────────────────────
$RANGE_ROWS = array( // 7d window, 3 rolled days: sessions 44, weighted bounce 2700/44, weighted ppv 68/44, daily medians [65,40,90]
	array( 'day' => '2026-07-14', 'visits' => 10, 'bounce_pct' => 50.0, 'ppv' => 1.2, 'median_dur' => 65 ),
	array( 'day' => '2026-07-15', 'visits' => 30, 'bounce_pct' => 70.0, 'ppv' => 1.6, 'median_dur' => 40 ),
	array( 'day' => '2026-07-16', 'visits' => 4,  'bounce_pct' => 25.0, 'ppv' => 2.0, 'median_dur' => 90 ),
);
$TREND_ROWS = array( // 56d window: ISO weeks W28 (Mon 2026-07-06) + W29 (Mon 2026-07-13)
	array( 'day' => '2026-07-06', 'visits' => 10, 'bounce_pct' => 60.0, 'ppv' => 1.1, 'median_dur' => 30 ),
	array( 'day' => '2026-07-08', 'visits' => 30, 'bounce_pct' => 40.0, 'ppv' => 1.3, 'median_dur' => 35 ),
	array( 'day' => '2026-07-14', 'visits' => 10, 'bounce_pct' => 50.0, 'ppv' => 1.2, 'median_dur' => 65 ),
	array( 'day' => '2026-07-15', 'visits' => 30, 'bounce_pct' => 70.0, 'ppv' => 1.6, 'median_dur' => 40 ),
	array( 'day' => '2026-07-16', 'visits' => 4,  'bounce_pct' => 25.0, 'ppv' => 2.0, 'median_dur' => 90 ),
);

echo "Group: pure aggregation — snt_analytics_overview_session_kpis (value pins)\n";
$k = snt_analytics_overview_session_kpis( $RANGE_ROWS );
ok( is_array( $k ), 'kpis: returns an array for rolled days' );
ok( 44 === ( $k['sessions'] ?? null ), 'kpis: sessions = 44 (sum of daily visits)' );
ok( abs( ( $k['bounce_pct'] ?? 0 ) - ( 2700 / 44 ) ) < 0.001, 'kpis: bounce = 61.36 (visits-WEIGHTED mean, not a naive day average of 48.3)' );
ok( abs( ( $k['ppv'] ?? 0 ) - ( 68 / 44 ) ) < 0.001, 'kpis: pages/session = 1.545 (visits-weighted mean)' );
ok( 65 === ( $k['median_dur'] ?? null ), 'kpis: median_dur = 65 (median of daily medians [40,65,90])' );
ok( 3 === ( $k['days'] ?? null ), 'kpis: carries the rolled-day count (the honest coverage note)' );
$k2 = snt_analytics_overview_session_kpis( array(
	array( 'day' => '2026-07-14', 'visits' => 2, 'bounce_pct' => 50.0, 'ppv' => 1.0, 'median_dur' => 40 ),
	array( 'day' => '2026-07-15', 'visits' => 2, 'bounce_pct' => 50.0, 'ppv' => 1.0, 'median_dur' => 90 ),
) );
ok( 65 === ( $k2['median_dur'] ?? null ), 'kpis: even day count → mean of the two middle daily medians (40,90 → 65)' );
ok( null === snt_analytics_overview_session_kpis( array() ), 'kpis: [] → null (nothing to aggregate — never zeros)' );
ok( null === snt_analytics_overview_session_kpis( null ), 'kpis: null input → null (a failed read aggregates to nothing)' );
ok( null === snt_analytics_overview_session_kpis( array( array( 'day' => '2026-07-14', 'visits' => 0, 'bounce_pct' => 0.0, 'ppv' => 0.0, 'median_dur' => 0 ) ) ),
	'kpis: all-zero-visits rows → null (no sessions — a ratio over 0 would fabricate)' );

echo "\nGroup: pure aggregation — snt_analytics_overview_weekly_bounce (value pins)\n";
$w = snt_analytics_overview_weekly_bounce( $TREND_ROWS );
ok( is_array( $w ) && 2 === count( $w ), 'weekly: 5 daily rows across 2 ISO weeks → 2 buckets' );
ok( '2026-07-06' === ( $w[0]['week_start'] ?? '' ) && '2026-07-13' === ( $w[1]['week_start'] ?? '' ),
	'weekly: buckets keyed by ISO-week Monday, ascending' );
ok( abs( ( $w[0]['bounce_pct'] ?? 0 ) - 45.0 ) < 0.001, 'weekly: W28 bounce = 45.0 ((10*60+30*40)/40 — visits-weighted within the week)' );
ok( abs( ( $w[1]['bounce_pct'] ?? 0 ) - ( 2700 / 44 ) ) < 0.001, 'weekly: W29 bounce = 61.36 (weighted)' );
ok( 40 === ( $w[0]['visits'] ?? null ) && 44 === ( $w[1]['visits'] ?? null ), 'weekly: buckets carry their visit weight' );
$w0 = snt_analytics_overview_weekly_bounce( array_merge(
	array( array( 'day' => '2026-05-23', 'visits' => 0, 'bounce_pct' => 50.0, 'ppv' => 1.0, 'median_dur' => 10 ) ),
	$TREND_ROWS
) );
ok( 2 === count( $w0 ), 'weekly: a zero-visit week is SKIPPED, not fabricated (bounce over 0 sessions is undefined)' );
ok( array() === snt_analytics_overview_weekly_bounce( array() ), 'weekly: [] → []' );
ok( array() === snt_analytics_overview_weekly_bounce( null ), 'weekly: null → [] (render decides the failure copy)' );

echo "\nGroup: full render — every panel wired to its accessor\n";
$GLOBALS['__ov']['session_rollup'] = array(
	'2026-07-11|2026-07-17' => $RANGE_ROWS,  // header window
	'2026-05-23|2026-07-17' => $TREND_ROWS,  // fixed 8-week (56-day) trend window
);
$GLOBALS['__ov']['sources'] = array(
	array( 'value' => '(direct)',    'views' => 18, 'visits' => 16, 'hosts' => array() ),
	array( 'value' => 'Google',      'views' => 11, 'visits' => 9,  'hosts' => array( 'google.com' ) ),
	array( 'value' => 'Hacker News', 'views' => 7,  'visits' => 6,  'hosts' => array( 'news.ycombinator.com' ) ),
);
$GLOBALS['__ov']['campaigns'] = array(
	array( 'value' => 'qr-provhub', 'views' => 6, 'visits' => 5 ),
	array( 'value' => 'newsletter', 'views' => 3, 'visits' => 3 ),
);
$GLOBALS['__ov']['dims'] = array(
	'country' => array(
		array( 'value' => 'AR', 'views' => 14, 'visits' => 12 ),
		array( 'value' => 'US', 'views' => 9,  'visits' => 8 ),
	),
	'device'  => array(
		array( 'value' => 'desktop', 'views' => 29, 'visits' => 24 ),
		array( 'value' => 'mobile',  'views' => 17, 'visits' => 15 ),
	),
);
$GLOBALS['__ov']['realtime']    = 2;
$GLOBALS['__ov']['views_today'] = 6;
$GLOBALS['__ov']['entries']     = array(
	array( 'path' => '/',         'views' => 16, 'visits' => 14 ),
	array( 'path' => '/provhub/', 'views' => 9,  'visits' => 8 ),
);
$GLOBALS['__ov']['exits'] = array(
	array( 'path' => '/provhub/', 'views' => 11, 'visits' => 10 ),
	array( 'path' => '/notes/',   'views' => 7,  'visits' => 6 ),
);
ov_reset_calls();
$html = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );

// Session quality: durable-rollup KPIs, value-pinned to the weighted math.
ok( strpos( $html, 'Session quality' ) !== false, 'session quality: panel present' );
ok( strpos( $html, '<p class="sn-kpi-value">44</p>' ) !== false, 'session quality: Sessions 44' );
ok( strpos( $html, '<p class="sn-kpi-value">61.4%</p>' ) !== false, 'session quality: bounce 61.4% (weighted — a naive day-mean would show 48.3%)' );
ok( strpos( $html, '<p class="sn-kpi-value">1.55</p>' ) !== false, 'session quality: pages/session 1.55 (weighted)' );
ok( strpos( $html, '<p class="sn-kpi-value">65s</p>' ) !== false, 'session quality: median duration 65s' );
ok( strpos( $html, 'median of daily medians' ) !== false, 'session quality: the median aggregate names itself honestly' );
ok( strpos( $html, 'within-day sessions' ) !== false, 'session quality: unit disambiguated from the headline\'s visitor-day Visits (the v9.65.0 lesson)' );
ok( strpos( $html, 'nightly rollup' ) !== false, 'session quality: names its durable source' );
// The 8-week bounce trend: fixed window, labeled, weekly-bucketed.
ok( strpos( $html, 'snSparkFillOvBounce' ) !== false, 'trend: bounce sparkline rendered with its own gradient id' );
ok( strpos( $html, 'last 8 weeks' ) !== false, 'trend: the FIXED 8-week window is labeled explicitly (not the range control\'s window)' );
ok( strpos( $html, 'latest 61.4%' ) !== false, 'trend: meta pins the latest weekly weighted bounce' );
ok( strpos( $html, '2026-07-06' ) !== false && strpos( $html, '2026-07-13' ) !== false, 'trend: axis spans first→last rolled-up ISO-week Monday' );

// Right now: cron-warmed transient only, windows labeled.
ok( strpos( $html, 'Right now' ) !== false, 'right now: panel present' );
ok( strpos( $html, 'sn-an-rightnow' ) !== false, 'right now: compact marker class present' );
ok( strpos( $html, 'Active visitors' ) !== false && strpos( $html, '<p class="sn-kpi-value">2</p>' ) !== false, 'right now: active visitors 2' );
ok( strpos( $html, '5-minute window' ) !== false, 'right now: the range-agnostic window is labeled explicitly' );
ok( strpos( $html, 'Views today' ) !== false && strpos( $html, '<p class="sn-kpi-value">6</p>' ) !== false, 'right now: views today 6' );
ok( strpos( $html, 'site-local day' ) !== false, 'right now: views-today names its site-local day window' );

// Bento minis: sources + campaigns (left), geography + devices (right).
ok( strpos( $html, 'sn-an-overview-bento' ) !== false, 'bento: wrapper present' );
ok( substr_count( $html, 'sn-an-bento-col' ) === 2, 'bento: exactly two columns' );
ok( strpos( $html, 'Top sources' ) !== false && strpos( $html, 'Hacker News' ) !== false && strpos( $html, '(direct)' ) !== false, 'sources: canonical labels rendered' );
ok( strpos( $html, 'Campaigns (UTM)' ) !== false && strpos( $html, 'qr-provhub' ) !== false, 'campaigns: rows rendered' );
ok( strpos( $html, 'Geography' ) !== false && strpos( $html, '>AR<' ) !== false, 'geography: country rows rendered (AR first — the real mix)' );
ok( strpos( $html, 'Devices' ) !== false && strpos( $html, '>desktop<' ) !== false, 'devices: device rows rendered' );
$col_split = strpos( $html, 'sn-an-bento-col', strpos( $html, 'sn-an-bento-col' ) + 1 );
ok( strpos( $html, 'Top sources' ) < $col_split && strpos( $html, 'Campaigns (UTM)' ) < $col_split,
	'bento: sources + campaigns stacked in the LEFT column' );
ok( strpos( $html, 'Geography' ) > $col_split && strpos( $html, 'Devices' ) > $col_split,
	'bento: geography + devices in the RIGHT column' );

// Entry + exit pages: PAIRED, via the existing pageroles renderer.
ok( strpos( $html, 'sn-an-overview-pair' ) !== false, 'pair: entry/exit grid wrapper present' );
ok( strpos( $html, 'Entry pages' ) !== false && strpos( $html, 'Exit pages' ) !== false, 'pair: both panels present' );
ok( strpos( $html, '/provhub/' ) !== false && strpos( $html, '/notes/' ) !== false, 'pair: real paths rendered' );
ok( substr_count( $html, 'human' ) >= 2, 'pair: entry/exit are labeled human-only (their rollup carries no class control)' );

// Layout order: session quality → right now → bento → entry/exit pair.
$p_sq    = strpos( $html, 'Session quality' );
$p_rn    = strpos( $html, 'Right now' );
$p_bento = strpos( $html, 'sn-an-overview-bento' );
$p_pair  = strpos( $html, 'sn-an-overview-pair' );
ok( false !== $p_sq && false !== $p_rn && false !== $p_bento && false !== $p_pair
	&& $p_sq < $p_rn && $p_rn < $p_bento && $p_bento < $p_pair,
	'order: session quality (full-width) → right now (compact) → bento → entry/exit pair' );

// The graduated surface: no preview machinery, no duplicate headline.
ok( strpos( $html, 'sn-an-lab-badge' ) === false && strpos( $html, 'PREVIEW' ) === false, 'graduated: no preview badge anywhere' );
ok( strpos( $html, 'Headline' ) === false, 'graduated: no duplicate Headline panel (the shared Overview card IS the headline)' );
ok( strpos( $html, '<p class="sn-kpi-label">Views</p>' ) === false, 'graduated: no duplicate Views KPI in the body (lives in the shared header)' );

echo "\nGroup: window + class contract (recorded accessor args)\n";
$sr = ov_calls( 'sn_session_rollup_read' );
ok( 2 === count( $sr ), 'rollup: exactly two reads (range KPIs + fixed trend window)' );
ok( array( 'sn_session_rollup_read', '2026-07-11', '2026-07-17', 'human' ) === $sr[0], 'rollup: KPI read uses the header window + class' );
ok( array( 'sn_session_rollup_read', '2026-05-23', '2026-07-17', 'human' ) === $sr[1], 'rollup: trend read uses the fixed 56-day window ending at $to' );
$src = ov_calls( 'sn_analytics_top_sources' );
ok( 1 === count( $src ) && '2026-07-11' === $src[0][1] && '2026-07-17' === $src[0][2] && 'human' === $src[0][3], 'sources: header window + class' );
$utm = ov_calls( 'sn_analytics_top_utm_campaigns' );
ok( 1 === count( $utm ) && '2026-07-11' === $utm[0][1] && 'human' === $utm[0][3], 'campaigns: header window + class' );
$dims = ov_calls( 'sn_analytics_top_dimension' );
ok( 2 === count( $dims ) && 'country' === $dims[0][1] && 'device' === $dims[1][1], 'dims: exactly country + device pulled' );
ok( 'human' === $dims[0][4] && 'human' === $dims[1][4], 'dims: class passed through' );
$rt = ov_calls( 'sn_analytics_realtime' );
ok( 1 === count( $rt ) && 'human' === $rt[0][1], 'realtime: called once with the header class' );
ok( 1 === count( ov_calls( 'sn_analytics_top_entry_pages' ) ) && 1 === count( ov_calls( 'sn_analytics_top_exit_pages' ) ), 'pageroles: entry + exit read once each' );

echo "\nGroup: LOAD-COST RULE — no live AE on the landing render\n";
ok( 0 === count( ov_calls( 'sn_analytics_fetch_session_events' ) ), 'load-cost: the live session-engine AE fetch (50k cap) is NEVER called — it stays on the Sessions tab' );
ok( 0 === count( ov_calls( 'sn_analytics_query' ) ), 'load-cost: the raw AE transport is never called from the view body' );
ok( 0 === count( ov_calls( 'sn_analytics_range_totals' ) ), 'load-cost: the view body never re-fetches range totals (the shared header owns that read)' );

echo "\nGroup: class=bot render — class-aware panels follow, human-only panels say so\n";
ov_reset_calls();
$GLOBALS['__ov']['session_rollup']['2026-07-11|2026-07-17'] = $RANGE_ROWS;
$html_bot = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'bot' ); } );
$sr_bot = ov_calls( 'sn_session_rollup_read' );
ok( 'bot' === ( $sr_bot[0][3] ?? '' ), 'class: session rollup read follows the header class' );
$rt_bot = ov_calls( 'sn_analytics_realtime' );
ok( 'bot' === ( $rt_bot[0][1] ?? '' ), 'class: realtime follows the header class' );
ok( strpos( $html_bot, 'human' ) !== false, 'class: the human-only panels (entry/exit, views today) still label their unit under a bot filter' );

echo "\nGroup: NULL DISCIPLINE — failed read ≠ empty window ≠ warming\n";
// (a) Session rollup read FAILED (null): "could not be read", never zeros.
$GLOBALS['__ov']['session_rollup'] = array(
	'2026-07-11|2026-07-17' => null,
	'2026-05-23|2026-07-17' => null,
);
ov_reset_calls();
$html_fail = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );
ok( strpos( $html_fail, 'could not be read' ) !== false, 'null: a FAILED rollup read says "could not be read" (the v9.65.0 lesson)' );
ok( strpos( $html_fail, '<p class="sn-kpi-value">0</p>' ) === false, 'null: no fabricated 0 KPIs from a failed read' );
ok( strpos( $html_fail, 'read failure' ) !== false, 'null: the fold names it a read failure, not an empty window' );

// (b) Empty window ([]): honest empty fold, different copy from failure.
$GLOBALS['__ov']['session_rollup'] = array(
	'2026-07-11|2026-07-17' => array(),
	'2026-05-23|2026-07-17' => array(),
);
$html_empty = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );
ok( strpos( $html_empty, 'No data in this range yet' ) !== false, 'empty: the empty fold renders' );
ok( strpos( $html_empty, 'could not be read' ) === false, 'empty: an empty window is NOT reported as a read failure' );

// (c) KPIs present but the trend window has <2 rolled weeks: the trend slot
// says so instead of drawing a 1-point lie.
$GLOBALS['__ov']['session_rollup'] = array(
	'2026-07-11|2026-07-17' => $RANGE_ROWS,
	'2026-05-23|2026-07-17' => array( $TREND_ROWS[0] ), // one day, one week
);
$html_1wk = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );
ok( strpos( $html_1wk, 'at least two' ) !== false, 'trend: <2 rolled weeks → the slot says the trend needs at least two weeks' );
ok( strpos( $html_1wk, 'snSparkFillOvBounce' ) === false, 'trend: no sparkline drawn from a single week' );

// (d) KPIs present but the trend read failed: independent honest copy.
$GLOBALS['__ov']['session_rollup'] = array(
	'2026-07-11|2026-07-17' => $RANGE_ROWS,
	'2026-05-23|2026-07-17' => null,
);
$html_tf = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );
ok( strpos( $html_tf, '<p class="sn-kpi-value">44</p>' ) !== false, 'trend-fail: the range KPIs still render' );
ok( strpos( $html_tf, 'could not be read' ) !== false, 'trend-fail: the trend slot reports its own read failure' );

// (e) Realtime warming: null → em-dash + "warming", never 0.
$GLOBALS['__ov']['session_rollup'] = array(
	'2026-07-11|2026-07-17' => $RANGE_ROWS,
	'2026-05-23|2026-07-17' => $TREND_ROWS,
);
$GLOBALS['__ov']['realtime']    = null;
$GLOBALS['__ov']['views_today'] = null;
$html_warm = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );
ok( strpos( $html_warm, 'warming' ) !== false, 'warming: a null realtime says "warming" (cron-warmed, never a blocking query)' );
ok( preg_match( '/Active visitors<\/p>\s*<p class="sn-kpi-value">—<\/p>/', $html_warm ) === 1, 'warming: active visitors renders an em-dash, not 0' );
ok( preg_match( '/Views today<\/p>\s*<p class="sn-kpi-value">—<\/p>/', $html_warm ) === 1, 'warming: views today renders an em-dash, not 0' );
// A warmed 0 is a REAL 0 (zero ≠ null, both directions).
$GLOBALS['__ov']['realtime']    = 0;
$GLOBALS['__ov']['views_today'] = 0;
$html_zero = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );
ok( preg_match( '/Active visitors<\/p>\s*<p class="sn-kpi-value">0<\/p>/', $html_zero ) === 1, 'zero: a warmed 0 renders as a real 0 (never "warming")' );
ok( strpos( $html_zero, 'warming' ) === false, 'zero: no warming copy when both counts are warmed' );

// (f) Every mini empty: panels fold into ONE collector line, no hollow boxes.
$GLOBALS['__ov']['sources']   = array();
$GLOBALS['__ov']['campaigns'] = array();
$GLOBALS['__ov']['dims']      = array();
$GLOBALS['__ov']['entries']   = array();
$GLOBALS['__ov']['exits']     = array();
$html_folds = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );
ok( strpos( $html_folds, 'No data in this range yet' ) !== false, 'folds: the shared empty-fold line renders once for all empty minis' );
foreach ( array( 'Top sources', 'Campaigns (UTM)', 'Geography', 'Devices', 'Entry pages', 'Exit pages' ) as $t ) {
	ok( strpos( $html_folds, $t ) !== false, "folds: '$t' named in the fold (not silently dropped)" );
}
ok( substr_count( $html_folds, 'sn-an-postbox' ) <= 3, 'folds: no hollow mini panels drawn (only session quality + right now stay open)' );

echo "\nGroup: source pins — the graduated file carries no flag machinery\n";
$src_file = (string) file_get_contents( __DIR__ . '/../inc/analytics-view-overview.php' );
ok( strpos( $src_file, 'landing_preview' ) === false, 'source: no flag machinery in the wired view' );
ok( strpos( $src_file, 'sn-an-lab-badge' ) === false && strpos( $src_file, 'PREVIEW' ) === false, 'source: no preview badge markup' );
ok( strpos( $src_file, 'sn_analytics_fetch_session_events' ) === false && strpos( $src_file, 'sn_analytics_query' ) === false,
	'source: the live-AE call names never appear in the view (load-cost rule, enforced at the source level too)' );

echo "\nGroup: styles — the bento + compact classes exist in the enqueued stylesheet\n";
$css = (string) file_get_contents( __DIR__ . '/../assets/analytics/analytics-admin.css' );
ok( strpos( $css, '.sn-an-overview-bento' ) !== false, 'css: bento grid rule present' );
ok( strpos( $css, '.sn-an-bento-col' ) !== false, 'css: bento column rule present' );
ok( strpos( $css, '.sn-an-rightnow' ) !== false, 'css: compact right-now rule present' );
ok( strpos( $css, '.sn-an-overview-pair' ) !== false, 'css: entry/exit pair rule present' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
