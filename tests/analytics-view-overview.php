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
 *  - NULL DISCIPLINE per panel: the session-rollup panels distinguish a
 *    failed read (accessor null) from an empty window ([]); the six dim/UTM/
 *    pageroles minis — whose accessors return [] for BOTH — are bracketed
 *    view-locally with a $wpdb->last_error before/after snapshot, so a failed
 *    read renders "could not be read" (never an empty week) while empty +
 *    no-error keeps the empty-window copy; realtime null is "warming", never
 *    a fabricated 0.
 *  - Window/class contract: panels pass the header's $from/$to/$class where
 *    their accessor supports it; the 8-week session trend is a fixed-LENGTH
 *    window ANCHORED at the range end (labeled with its actual endpoint;
 *    partial ISO weeks handled honestly — trailing annotated, leading
 *    trimmed); entry/exit + views-today are human-only (labeled).
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
// v9.68.0 part 4 (doorways): the tests/analytics-admin.php URL-stub idiom —
// add_query_arg(array()) must return the CURRENT URL (the tab-strip base read),
// and remove_query_arg must really strip keys, or the doorway hrefs can't be pinned.
function add_query_arg( $args, $url = null ) {
	if ( null === $url ) { $url = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/wp-admin/index.php?page=sn-analytics'; }
	$sep = ( strpos( (string) $url, '?' ) !== false ) ? '&' : '?';
	return $url . $sep . http_build_query( $args );
}
function remove_query_arg( $keys, $url ) {
	$parts = explode( '?', (string) $url, 2 );
	if ( ! isset( $parts[1] ) ) { return $url; }
	parse_str( $parts[1], $q );
	foreach ( (array) $keys as $k ) { unset( $q[ $k ] ); }
	return $q ? $parts[0] . '?' . http_build_query( $q ) : $parts[0];
}
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
$_SERVER['REQUEST_URI'] = '/wp-admin/index.php?page=sn-analytics';

// ---- wpdb stub: F1 view-local failure detection reads $wpdb->last_error ----
// Models REAL wpdb (wp-includes/class-wpdb.php, verified): query() calls
// flush() FIRST, and flush() resets last_error to '' — so after any query,
// last_error reflects THAT query alone (a successful read CLEARS a stale
// error); a FAILED query comes back from get_results(ARRAY_A) as [] WITH
// last_error set; and num_queries increments unconditionally per executed
// query (wpdb::_do_query()) — the disambiguator when two consecutive reads
// of the SAME table fail with byte-identical messages. ov_db_read() applies
// that transform to every durable-table accessor stub below (a stub for a
// transport must model the transport's TRANSFORM, not just record the call).
$GLOBALS['wpdb']              = new stdClass();
$GLOBALS['wpdb']->last_error  = '';
$GLOBALS['wpdb']->num_queries = 0;

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
	'fail'           => array(), // keyed by accessor family: true = EVERY read fails; array of "from|to" keys = only those windows fail (part 4: prior-window-only failures)
	'win'            => array(), // family => "from|to" => rows: per-window overrides (part 4: prior windows carry their own fixture)
);

/** Model one wpdb-backed read: flush (clears last_error), count the query, then fixture-driven failure ([] + last_error set) or success. */
function ov_db_read( $fn, $win, $result ) {
	$GLOBALS['wpdb']->last_error = ''; // wpdb::query() → flush() resets it per query.
	++$GLOBALS['wpdb']->num_queries; // wpdb::_do_query() counts every executed query.
	$fail = $GLOBALS['__ov']['fail'][ $fn ] ?? false;
	if ( true === $fail || ( is_array( $fail ) && in_array( $win, $fail, true ) ) ) {
		$GLOBALS['wpdb']->last_error = "Table 'wp_sn_" . $fn . "' doesn't exist";
		return array(); // real get_results(ARRAY_A) failure shape: [] beside last_error.
	}
	if ( isset( $GLOBALS['__ov']['win'][ $fn ][ $win ] ) ) {
		return $GLOBALS['__ov']['win'][ $fn ][ $win ];
	}
	return $result;
}
$GLOBALS['__ov_calls'] = array();
function ov_reset_calls() { $GLOBALS['__ov_calls'] = array(); }
function ov_calls( $fn ) {
	return array_values( array_filter( $GLOBALS['__ov_calls'], function ( $c ) use ( $fn ) { return $c[0] === $fn; } ) );
}

// Durable session rollup (inc/analytics-session-rollup.php contract: typed
// day-ascending rows, [] = no days rolled, null = failed read). The REAL
// accessor queries (so a stale last_error is flushed away), consults
// last_error ITSELF, and resolves a failed read to null before returning —
// modeled here: a null fixture leaves last_error set, exactly like production.
function sn_session_rollup_read( $from, $to, $class ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_session_rollup_read', $from, $to, $class );
	$key  = $from . '|' . $to;
	$rows = array_key_exists( $key, $GLOBALS['__ov']['session_rollup'] ) ? $GLOBALS['__ov']['session_rollup'][ $key ] : array();
	++$GLOBALS['wpdb']->num_queries;
	$GLOBALS['wpdb']->last_error = ( null === $rows ) ? "Table 'wp_sn_session_daily' doesn't exist" : '';
	return $rows;
}
// Canonical sources over the durable dims table (inc/analytics-sources.php).
function sn_analytics_top_sources( $from, $to, $class = 'human', $limit = 10 ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_top_sources', $from, $to, $class, $limit );
	return ov_db_read( 'sources', $from . '|' . $to, $GLOBALS['__ov']['sources'] );
}
// Durable UTM rollup (inc/analytics-utm.php).
function sn_analytics_top_utm_campaigns( $from, $to, $class = 'human', $limit = 25 ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_top_utm_campaigns', $from, $to, $class, $limit );
	return ov_db_read( 'campaigns', $from . '|' . $to, $GLOBALS['__ov']['campaigns'] );
}
// Durable dims rollup (inc/analytics-dims.php).
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25, $refresh = false ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_top_dimension', $dim, $from, $to, $class, $limit );
	return ov_db_read( 'dims', $dim . '|' . $from . '|' . $to, $GLOBALS['__ov']['dims'][ $dim ] ?? array() );
}
// Cron-warmed realtime transient (inc/analytics-realtime.php: int, or null =
// never warmed). Transient reads are NOT modeled through ov_db_read — they
// have no wpdb failure channel and must never disturb last_error here.
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
	return ov_db_read( 'entries', $from . '|' . $to, $GLOBALS['__ov']['entries'] );
}
function sn_analytics_top_exit_pages( $from, $to, $limit = 25 ) {
	$GLOBALS['__ov_calls'][] = array( 'sn_analytics_top_exit_pages', $from, $to, $limit );
	return ov_db_read( 'exits', $from . '|' . $to, $GLOBALS['__ov']['exits'] );
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
// v9.68.0 part 4 REAL callees — required, never stubbed (the stub-drift rule):
// the tab strip's window args, the shared card's compare-window date math +
// the view registry (doorway labels), and the delta math the chips ride on.
require_once __DIR__ . '/../inc/analytics-render-controls.php'; // snt_analytics_window_args
require_once __DIR__ . '/../inc/analytics-admin.php';           // snt_analytics_compare_window + snt_analytics_views
require_once __DIR__ . '/../inc/analytics-derived.php';         // sn_analytics_delta

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

echo "\nGroup: F4 — window-bounded weekly buckets (partial-ISO-week honesty)\n";
// The 56-day window rarely starts on a Monday and (unless $to is a Sunday)
// always ends mid-week — a cut bucket holds only its in-window days, so
// drawing it as a full weekly point lies. Chosen shape: the TRAILING partial
// is FLAGGED (the trend meta names the latest point, so annotation is clean);
// a LEADING partial is TRIMMED (the first point has no per-point label
// surface — the axis is a bare Monday date — so it cannot be annotated).
$wb = snt_analytics_overview_weekly_bounce( $TREND_ROWS, '2026-05-23', '2026-07-17' );
ok( 2 === count( $wb ), 'bounds: fixture window keeps both buckets (first data week starts inside the window — nothing to trim)' );
ok( false === ( $wb[0]['partial'] ?? null ), 'bounds: W28 (Mon 07-06 … Sun 07-12) lies fully inside the window — complete' );
ok( true === ( $wb[1]['partial'] ?? null ), 'bounds: W29 is cut at $to = Fri 2026-07-17 (its Sunday is 07-19) — flagged partial' );
$wb2 = snt_analytics_overview_weekly_bounce( $TREND_ROWS, '2026-05-25', '2026-07-19' );
ok( false === ( $wb2[1]['partial'] ?? null ), 'bounds: a window ending ON a Sunday (2026-07-19) leaves the trailing week complete — no false flag' );
$wb3 = snt_analytics_overview_weekly_bounce( $TREND_ROWS, '2026-07-08', '2026-07-17' );
ok( 1 === count( $wb3 ) && '2026-07-13' === ( $wb3[0]['week_start'] ?? '' ),
	'bounds: a LEADING partial (bucket Monday 07-06 precedes $from = Wed 07-08) is TRIMMED, never drawn as a full week' );
$wb4 = snt_analytics_overview_weekly_bounce( $TREND_ROWS );
ok( 2 === count( $wb4 ) && false === ( $wb4[0]['partial'] ?? null ) && false === ( $wb4[1]['partial'] ?? null ),
	'bounds: no bounds given → no trimming, no partial flags (back-compat; the typed shape still carries partial:false)' );

echo "\nGroup: F1 unit — snt_analytics_overview_read_guarded (the last_error bracket)\n";
// The dims/UTM/pageroles accessors return [] for BOTH a failed read and an
// empty window; real wpdb reports failure as [] + last_error, reset per query
// by flush(). The bracket treats only a CHANGED/newly-set value as THIS
// read's failure, so a stale error from an earlier unrelated query is never
// inherited (the clear-read baseline).
$GLOBALS['wpdb']->last_error = '';
$g = snt_analytics_overview_read_guarded( function () {
	$GLOBALS['wpdb']->last_error = "Table 'wp_sn_analytics_dims' doesn't exist";
	return array();
} );
ok( true === $g['failed'] && array() === $g['rows'], 'guard: a read that newly sets last_error beside [] is a FAILURE, not an empty window' );
$GLOBALS['wpdb']->last_error = 'stale error from an EARLIER unrelated query';
$g = snt_analytics_overview_read_guarded( function () {
	return array(); // performs no query at all (memo hit / early return) — last_error untouched.
} );
ok( false === $g['failed'], 'guard: an UNCHANGED stale error is NOT this read\'s failure (clear-read baseline)' );
$GLOBALS['wpdb']->last_error = 'stale error from an EARLIER unrelated query';
$g = snt_analytics_overview_read_guarded( function () {
	$GLOBALS['wpdb']->last_error = ''; // real wpdb: a successful query flush()es the stale error away.
	return array( array( 'value' => 'x', 'views' => 1, 'visits' => 1 ) );
} );
ok( false === $g['failed'] && 1 === count( $g['rows'] ), 'guard: a successful query CLEARS a stale error (wpdb flush per query) — rows served, no failure' );
$GLOBALS['wpdb']->last_error = 'error A';
$g = snt_analytics_overview_read_guarded( function () {
	$GLOBALS['wpdb']->last_error = 'error B';
	return array();
} );
ok( true === $g['failed'], 'guard: a CHANGED error is this read\'s own failure (a different query failed here)' );
$GLOBALS['wpdb']->last_error = "Table 'wp_sn_analytics_dims' doesn't exist";
$g = snt_analytics_overview_read_guarded( function () {
	// A SECOND failing read of the SAME table (the Geography→Devices case):
	// flush clears, the fresh query re-sets the byte-IDENTICAL message — only
	// the query counter betrays that a new query ran and failed.
	++$GLOBALS['wpdb']->num_queries;
	$GLOBALS['wpdb']->last_error = "Table 'wp_sn_analytics_dims' doesn't exist";
	return array();
} );
ok( true === $g['failed'], 'guard: an IDENTICAL message from a FRESH query is still this read\'s failure (num_queries tiebreaker — consecutive same-table failures)' );
$g = snt_analytics_overview_read_guarded( function () { return null; } );
ok( array() === $g['rows'], 'guard: a non-array accessor result normalizes to [] (defence in depth)' );
$GLOBALS['wpdb']->last_error = '';

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
// The 8-week bounce trend: fixed LENGTH, anchored at $to, labeled truthfully.
ok( strpos( $html, 'snSparkFillOvBounce' ) !== false, 'trend: bounce sparkline rendered with its own gradient id' );
ok( strpos( $html, 'Bounce — 8 weeks to 2026-07-17' ) !== false, 'trend: the label names its ACTUAL endpoint — the range control\'s $to (anchored, not independent)' );
ok( strpos( $html, 'last 8 weeks' ) === false, 'trend: the old "last 8 weeks" independence claim is gone (F2 — the words now match the anchoring)' );
ok( strpos( $html, 'latest 61.4% (partial week)' ) !== false, 'trend: meta pins the latest weekly weighted bounce AND flags it partial (W29 is cut at Fri $to — F4)' );
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

echo "\nGroup: F1 — a FAILED mini read says so (view-local last_error bracketing)\n";
// The six dim/UTM/pageroles minis' accessors return [] for both failure and
// empty; before this fix a failed wpdb read was served as an honest-empty
// window. Pinned both directions per panel family.
// (a) dims family (sources + campaigns + geography + devices): last_error
// newly set + [] returned → "could not be read", NOT the empty-window copy.
$GLOBALS['__ov']['session_rollup'] = array(
	'2026-07-11|2026-07-17' => $RANGE_ROWS,
	'2026-05-23|2026-07-17' => $TREND_ROWS,
);
$GLOBALS['__ov']['entries'] = array( array( 'path' => '/', 'views' => 16, 'visits' => 14 ) );
$GLOBALS['__ov']['exits']   = array( array( 'path' => '/provhub/', 'views' => 11, 'visits' => 10 ) );
$GLOBALS['__ov']['fail']    = array( 'sources' => true, 'campaigns' => true, 'dims' => true );
$html_dbfail = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );
ok( strpos( $html_dbfail, 'The durable referrer rollup could not be read' ) !== false, 'fail: Top sources renders the read-failure fold' );
ok( strpos( $html_dbfail, 'The durable UTM rollup could not be read' ) !== false, 'fail: Campaigns renders the read-failure fold' );
ok( strpos( $html_dbfail, 'The durable country rollup could not be read' ) !== false, 'fail: Geography renders the read-failure fold' );
ok( strpos( $html_dbfail, 'The durable device rollup could not be read' ) !== false, 'fail: Devices renders the read-failure fold' );
ok( strpos( $html_dbfail, 'No referrer rows in the durable rollup' ) === false
	&& strpos( $html_dbfail, 'No UTM-tagged traffic in the durable rollup' ) === false
	&& strpos( $html_dbfail, 'No country rows in the durable rollup' ) === false
	&& strpos( $html_dbfail, 'No device rows in the durable rollup' ) === false,
	'fail: the empty-window copy is NEVER served for a failed read (the F1 lie, pinned dead)' );
ok( strpos( $html_dbfail, '/provhub/' ) !== false, 'fail: the pageroles panels (whose reads succeeded) still render their rows' );

// (b) pageroles family: entry + exit failures report independently.
$GLOBALS['__ov']['sources'] = array( array( 'value' => '(direct)', 'views' => 18, 'visits' => 16, 'hosts' => array() ) );
$GLOBALS['__ov']['dims']    = array( 'country' => array( array( 'value' => 'AR', 'views' => 14, 'visits' => 12 ) ) );
$GLOBALS['__ov']['fail']    = array( 'entries' => true, 'exits' => true );
$html_prfail = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );
ok( strpos( $html_prfail, 'The durable entry-pages rollup could not be read' ) !== false, 'fail: Entry pages renders the read-failure fold' );
ok( strpos( $html_prfail, 'The durable exit-pages rollup could not be read' ) !== false, 'fail: Exit pages renders the read-failure fold' );
ok( strpos( $html_prfail, 'No entry pages in this range yet' ) === false && strpos( $html_prfail, 'No exit pages in this range yet' ) === false,
	'fail: the pageroles empty copy is not served for a failed read' );
ok( strpos( $html_prfail, 'Entry pages' ) !== false && strpos( $html_prfail, 'Exit pages' ) !== false,
	'fail: the failed panels are still NAMED in the fold (never silently dropped)' );

// (c) mixed: one failure never bleeds into a neighbor's SUCCESSFUL empty read
// (per-read bracketing — last_error is snapshotted around EACH call).
$GLOBALS['__ov']['campaigns'] = array();
$GLOBALS['__ov']['fail']      = array( 'sources' => true );
$html_mixed = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );
ok( strpos( $html_mixed, 'The durable referrer rollup could not be read' ) !== false, 'mixed: the failed read reports failure' );
ok( strpos( $html_mixed, 'No UTM-tagged traffic in the durable rollup' ) !== false, 'mixed: the neighboring successful-but-empty read keeps its honest empty-window copy' );
ok( strpos( $html_mixed, 'The durable UTM rollup could not be read' ) === false, 'mixed: the neighbor is NOT contaminated by the earlier panel\'s error (per-read baseline)' );

// (d) empty + no error: the empty-window copy stays (the other direction).
$GLOBALS['__ov']['sources'] = array();
$GLOBALS['__ov']['dims']    = array();
$GLOBALS['__ov']['entries'] = array();
$GLOBALS['__ov']['exits']   = array();
$GLOBALS['__ov']['fail']    = array();
$html_ok = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );
ok( strpos( $html_ok, 'could not be read' ) === false, 'ok: no read-failure copy anywhere when every read succeeds' );
ok( strpos( $html_ok, 'No referrer rows in the durable rollup' ) !== false && strpos( $html_ok, 'No entry pages in this range yet' ) !== false,
	'ok: successful-but-empty reads keep the empty-window copy (both directions pinned)' );

// (e) a STALE last_error left by an earlier unrelated query is never
// inherited: every read here succeeds (each query flushes), so nothing fails.
$GLOBALS['wpdb']->last_error = 'stale error from a pre-render query';
$html_stale = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );
ok( strpos( $html_stale, 'could not be read' ) === false, 'stale: a pre-render last_error is not misattributed to any panel (clear-read baseline)' );

echo "\nGroup: F4 — a Sunday-ending window draws an unannotated trailing week\n";
// $to = 2026-07-19 (a Sunday): the 56-day window is exactly 8 ISO weeks, so
// no partial exists and the meta must NOT cry partial (no false flag).
$GLOBALS['__ov']['session_rollup'] = array(
	'2026-07-13|2026-07-19' => $RANGE_ROWS,  // header window (KPIs)
	'2026-05-25|2026-07-19' => $TREND_ROWS,  // 8-week trend window, Monday→Sunday aligned
);
$html_sun = capture( function () { snt_analytics_render_view_overview( '2026-07-13', '2026-07-19', 'human' ); } );
ok( strpos( $html_sun, 'Bounce — 8 weeks to 2026-07-19' ) !== false, 'sunday: the label still names the actual endpoint' );
ok( strpos( $html_sun, 'latest 61.4%' ) !== false && strpos( $html_sun, '(partial week)' ) === false,
	'sunday: a complete trailing ISO week carries NO partial annotation' );

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

// ═══════════════════════════════════════════════════════════════════════════
// v9.68.0 PART 4 — every panel a doorway: routing links + compare-deltas.
// ═══════════════════════════════════════════════════════════════════════════

/** Re-seed the standard CURRENT-window fixtures (the "full render" group's shapes). */
function ov_seed_current() {
	$GLOBALS['__ov']['session_rollup'] = array(
		'2026-07-11|2026-07-17' => $GLOBALS['__ov_range_rows'],
		'2026-05-23|2026-07-17' => $GLOBALS['__ov_trend_rows'],
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
	$GLOBALS['__ov']['fail']     = array();
	$GLOBALS['__ov']['win']      = array();
	$GLOBALS['wpdb']->last_error = '';
}
$GLOBALS['__ov_range_rows'] = $RANGE_ROWS;
$GLOBALS['__ov_trend_rows'] = $TREND_ROWS;

/** Seed the standard PRIOR-window fixtures (default: the prev window of the 07-11..07-17 range). */
function ov_seed_priors( $pf = '2026-07-04', $pt = '2026-07-10' ) {
	$pw = $pf . '|' . $pt;
	// Prior rollup: sessions 40, weighted bounce 50.0, weighted ppv 1.5, median 60.
	$GLOBALS['__ov']['session_rollup'][ $pw ] = array(
		array( 'day' => $pf, 'visits' => 20, 'bounce_pct' => 55.0, 'ppv' => 1.25, 'median_dur' => 50 ),
		array( 'day' => $pt, 'visits' => 20, 'bounce_pct' => 45.0, 'ppv' => 1.75, 'median_dur' => 70 ),
	);
	$GLOBALS['__ov']['win'] = array(
		'sources'   => array( $pw => array(
			array( 'value' => '(direct)', 'views' => 20, 'visits' => 18, 'hosts' => array() ),
			array( 'value' => 'Google',   'views' => 5,  'visits' => 4,  'hosts' => array( 'google.com' ) ),
			// Hacker News deliberately ABSENT → its row must read "new".
		) ),
		'campaigns' => array( $pw => array(
			array( 'value' => 'qr-provhub', 'views' => 6, 'visits' => 5 ), // unchanged → flat 0%
		) ),
		'dims'      => array(
			'country|' . $pw => array(
				array( 'value' => 'AR', 'views' => 7, 'visits' => 6 ),
				array( 'value' => 'US', 'views' => 9, 'visits' => 8 ),
			),
			'device|' . $pw  => array(
				array( 'value' => 'desktop', 'views' => 30, 'visits' => 26 ),
			),
		),
		'entries'   => array( $pw => array(
			array( 'path' => '/', 'views' => 10, 'visits' => 9 ),
		) ),
		'exits'     => array( $pw => array(
			array( 'path' => '/notes/', 'views' => 14, 'visits' => 12 ),
		) ),
	);
}

echo "\nGroup: PART A — every panel a doorway (the tab strip's exact href discipline)\n";
ov_seed_current();
ov_seed_priors();
$_SERVER['REQUEST_URI'] = '/wp-admin/index.php?page=sn-analytics&sn_view=overview&sn_range=30&sn_class=human&sn_compare=prev&sn_drill=' . rawurlencode( 'referrer:Google' );
$html_a = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human', '30', 'prev' ); } );
preg_match_all( '/class="sn-an-head-link" href="([^"]+)"/', $html_a, $ovm );
$ov_hrefs = $ovm[1];
ok( 7 === count( $ov_hrefs ), 'doorways: exactly seven — session quality + the six minis ("Right now" is instantaneous; no fuller view exists for it)' );
$ov_views = array_map( function ( $h ) { parse_str( (string) parse_url( $h, PHP_URL_QUERY ), $q ); return (string) ( $q['sn_view'] ?? '' ); }, $ov_hrefs );
ok( array( 'visits', 'content', 'campaigns', 'geography', 'technology', 'content', 'content' ) === $ov_views,
	'doorways: the panel→tab map — session quality→visits(Sessions), sources→content, campaigns→campaigns, geography→geography, devices→technology, entry/exit→content' );
$ov_carry = true; $ov_drill = false; $ov_dates = false;
foreach ( $ov_hrefs as $h ) {
	parse_str( (string) parse_url( $h, PHP_URL_QUERY ), $q );
	if ( '30' !== ( $q['sn_range'] ?? '' ) || 'human' !== ( $q['sn_class'] ?? '' ) || 'prev' !== ( $q['sn_compare'] ?? '' ) || 'sn-analytics' !== ( $q['page'] ?? '' ) ) { $ov_carry = false; }
	if ( isset( $q['sn_drill'] ) ) { $ov_drill = true; }
	if ( isset( $q['sn_from'] ) || isset( $q['sn_to'] ) ) { $ov_dates = true; }
}
ok( $ov_carry, 'doorways: every href carries the ACTIVE window (sn_range=30), class AND compare mode — the tab strip\'s exact param carry, same page route' );
ok( ! $ov_drill, 'doorways: the view-local sn_drill filter is STRIPPED (the tab strip\'s one reset point in the param-carry matrix)' );
ok( ! $ov_dates, 'doorways: a non-custom range carries no sn_from/sn_to (the snt_analytics_window_args contract)' );
ok( strpos( $html_a, '>Sessions &rarr;</a>' ) !== false, 'doorways: the session-quality doorway wears its tab\'s REGISTRY label ("Sessions" — the v9.65.0 slug/label split honored)' );
ok( strpos( $html_a, '>Technology &rarr;</a>' ) !== false, 'doorways: the devices doorway names the Technology tab' );
$html_ac = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human', 'custom', 'prev' ); } );
preg_match_all( '/class="sn-an-head-link" href="([^"]+)"/', $html_ac, $ovmc );
parse_str( (string) parse_url( $ovmc[1][0] ?? '', PHP_URL_QUERY ), $qc );
ok( 'custom' === ( $qc['sn_range'] ?? '' ) && '2026-07-11' === ( $qc['sn_from'] ?? '' ) && '2026-07-17' === ( $qc['sn_to'] ?? '' ),
	'doorways: a custom range carries sn_from/sn_to exactly as the tab strip does (dates ARE the token there)' );
ok( strpos( $css, '.sn-an-head-link' ) !== false, 'doorways: the head-link class exists in the enqueued stylesheet' );

echo "\nGroup: doorway base — ONE reset list shared with the tab strip (review 2, F2)\n";
// The 8-param strip list was duplicated verbatim between the tab strip
// (inc/analytics-admin.php) and the doorway builder — a drift hazard: a future
// param added to one silently diverges the other. ONE helper now owns it;
// both call sites are source-pinned to consume it, and the literal list is
// pinned to live exactly ONCE (inside the helper), so the drift class is
// structurally dead.
ok( function_exists( 'snt_analytics_view_reset_params' )
	&& array( 'sn_view', 'sn_range', 'sn_class', 'sn_from', 'sn_to', 'sn_drill', 'sn_event_prop', 'sn_lg_range' ) === snt_analytics_view_reset_params(),
	'reset params: the shared helper returns the tab strip\'s exact 8-param strip list (sn_compare deliberately absent — compare rides along)' );
$rp_view_src = (string) file_get_contents( __DIR__ . '/../inc/analytics-view-overview.php' );
$rp_adm_src  = (string) file_get_contents( __DIR__ . '/../inc/analytics-admin.php' );
ok( strpos( $rp_view_src, 'remove_query_arg( snt_analytics_view_reset_params(), add_query_arg( array() ) )' ) !== false,
	'parity: the doorway builder consumes the shared helper (source pin)' );
ok( strpos( $rp_adm_src, 'remove_query_arg( snt_analytics_view_reset_params(), add_query_arg( array() ) )' ) !== false,
	'parity: the tab strip consumes the SAME helper (source pin)' );
ok( 1 === substr_count( $rp_adm_src, "'sn_lg_range'" ) && false === strpos( $rp_view_src, "'sn_lg_range'" ),
	'parity: the reset-list literal lives exactly ONCE — inside the helper; neither call site carries its own copy' );

echo "\nGroup: PART B — compare window derivation (the shared card's helpers, reused)\n";
ok( array( '2026-07-04', '2026-07-10' ) === snt_analytics_compare_window( '2026-07-11', '2026-07-17', 'prev' ),
	'derivation: prev = the adjacent same-length window (the REAL snt_analytics_compare_window — never reimplemented)' );
ok( array( '2025-07-11', '2025-07-17' ) === snt_analytics_compare_window( '2026-07-11', '2026-07-17', 'yoy' ),
	'derivation: yoy = the same dates one year earlier' );
ov_seed_current();
ov_seed_priors();
$_SERVER['REQUEST_URI'] = '/wp-admin/index.php?page=sn-analytics';
ov_reset_calls();
$html_p = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human', '30', 'prev' ); } );
$sr_p = ov_calls( 'sn_session_rollup_read' );
ok( 3 === count( $sr_p ) && array( 'sn_session_rollup_read', '2026-07-04', '2026-07-10', 'human' ) === $sr_p[2],
	'prior reads: the session rollup gains exactly ONE extra read — the derived prior window, same class' );
$src_p = ov_calls( 'sn_analytics_top_sources' );
ok( 2 === count( $src_p ) && '2026-07-04' === $src_p[1][1] && '2026-07-10' === $src_p[1][2] && 'human' === $src_p[1][3] && 50 === $src_p[1][4],
	'prior reads: sources read once more over the prior window at depth 50 (the movers idiom — wider than the visible top-5, so a prior rank-6 row is matched, never misread as "new")' );
$utm_p = ov_calls( 'sn_analytics_top_utm_campaigns' );
ok( 2 === count( $utm_p ) && '2026-07-04' === $utm_p[1][1] && 50 === $utm_p[1][4], 'prior reads: campaigns second read = prior window, depth 50' );
$dims_p = ov_calls( 'sn_analytics_top_dimension' );
ok( 4 === count( $dims_p ) && 'country' === $dims_p[1][1] && '2026-07-04' === $dims_p[1][2] && 'device' === $dims_p[3][1] && '2026-07-04' === $dims_p[3][2],
	'prior reads: country + device each read once more over the prior window' );
ok( 2 === count( ov_calls( 'sn_analytics_top_entry_pages' ) ) && 2 === count( ov_calls( 'sn_analytics_top_exit_pages' ) ), 'prior reads: entry + exit pages read once more each' );
ok( 1 === count( ov_calls( 'sn_analytics_realtime' ) ), 'no compare on Right now: instantaneous — realtime still read exactly once' );
ok( 0 === count( ov_calls( 'sn_analytics_fetch_session_events' ) ) && 0 === count( ov_calls( 'sn_analytics_query' ) ) && 0 === count( ov_calls( 'sn_analytics_range_totals' ) ),
	'load-cost: compare adds durable-table reads ONLY — still zero live AE on the landing' );

echo "\nGroup: PART B — session-quality KPI delta chips (the shared card's exact idiom)\n";
ok( strpos( $html_p, '<span class="sn-kpi-delta sn-delta-up" title="previous period: 40"><span class="sn-delta-arrow">▲</span> +10%</span>' ) !== false,
	'kpi chips: Sessions 44 vs 40 → ▲ +10%, tooltip names the basis + prior value (snt_an_kpi_row\'s real delta slot)' );
ok( strpos( $html_p, 'title="previous period: 50"' ) !== false && strpos( $html_p, '+23%' ) !== false,
	'kpi chips: bounce 61.4% vs 50% → +23% against the raw weighted prior' );
ok( strpos( $html_p, 'title="previous period: 1.5"' ) !== false && strpos( $html_p, '+3%' ) !== false, 'kpi chips: pages/session 1.55 vs 1.5 → +3%' );
ok( strpos( $html_p, 'title="previous period: 60"' ) !== false && strpos( $html_p, '+8%' ) !== false, 'kpi chips: median duration 65s vs 60s → +8%' );
ok( 4 === substr_count( $html_p, 'title="previous period:' ), 'kpi chips: exactly four — one per KPI, none invented elsewhere' );
ok( strpos( $html_p, '3 rolled-up days' ) === false && strpos( $html_p, 'single-page sessions · weighted' ) === false,
	'kpi chips: the delta slot REPLACES the static descriptor while compare is on (the primitive\'s live>delta>sub precedence — the shared card\'s exact behavior)' );

echo "\nGroup: bounce is lower-is-better — the chip colors by sentiment, arrows by direction (review 2, F1)\n";
// Bounce is the FIRST lower-is-better metric to wear a delta chip: a RISING
// bounce must be a RED chip with a real ▲ (never a green "improvement"), a
// FALLING one a GREEN chip with ▼. Full-string pins, both directions, plus an
// up-is-good control (Sessions) proving the other three KPIs keep direction
// colors. $html_p above: bounce 61.4% vs prior 50 → rising.
ok( strpos( $html_p, '<span class="sn-kpi-delta sn-delta-bad" title="previous period: 50"><span class="sn-delta-arrow">▲</span> +23%</span>' ) !== false,
	'bounce rising: red BAD chip, real ▲ arrow, signed +23% (full-string pin)' );
ok( strpos( $html_p, 'sn-delta-up" title="previous period: 50"' ) === false,
	'bounce rising: the green up class never touches the bounce chip' );
ok( strpos( $html_p, '<span class="sn-kpi-delta sn-delta-up" title="previous period: 40"><span class="sn-delta-arrow">▲</span> +10%</span>' ) !== false,
	'control: Sessions (up-is-good) keeps the green ▲ +10% chip untouched' );
// Falling bounce: re-seed the prior rollup at weighted bounce 80 (sessions 40,
// ppv 1.5, median 60 unchanged) → 61.4 vs 80 = ▼ -23%, GOOD.
ov_seed_current();
ov_seed_priors();
$GLOBALS['__ov']['session_rollup']['2026-07-04|2026-07-10'] = array(
	array( 'day' => '2026-07-04', 'visits' => 20, 'bounce_pct' => 80.0, 'ppv' => 1.25, 'median_dur' => 50 ),
	array( 'day' => '2026-07-10', 'visits' => 20, 'bounce_pct' => 80.0, 'ppv' => 1.75, 'median_dur' => 70 ),
);
$html_bf = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human', '30', 'prev' ); } );
ok( strpos( $html_bf, '<span class="sn-kpi-delta sn-delta-good" title="previous period: 80"><span class="sn-delta-arrow">▼</span> -23%</span>' ) !== false,
	'bounce falling: green GOOD chip, real ▼ arrow, signed -23% (full-string pin)' );
ok( strpos( $html_bf, 'sn-delta-down" title="previous period: 80"' ) === false,
	'bounce falling: the red down class never touches the bounce chip' );
ok( 3 === substr_count( $html_bf, 'sn-delta-up' ) && 1 === substr_count( $html_bf, 'sn-delta-good' ) && 0 === substr_count( $html_bf, 'sn-delta-bad' ),
	'audit: sessions/ppv/median-duration stay up-is-good (three direction-colored up chips) — bounce is the only sentiment-colored KPI' );
ok( strpos( $css, '.sn-delta-good' ) !== false && strpos( $css, '.sn-delta-bad' ) !== false,
	'css: the good/bad sentiment classes exist in the enqueued stylesheet (the badge CSS idiom)' );

echo "\nGroup: PART B — per-row mini-table chips (rows matched by dimension key)\n";
ok( strpos( $html_p, 'data-colname="Views">11 <span class="sn-an-delta sn-an-delta--up">▲ +120%</span></td>' ) !== false,
	'rows: Google 11 vs 5 → ▲ +120% chip beside the Views figure' );
ok( strpos( $html_p, 'data-colname="Views">18 <span class="sn-an-delta sn-an-delta--down">▼ -10%</span></td>' ) !== false,
	'rows: (direct) 18 vs 20 → ▼ -10%' );
ok( strpos( $html_p, '>7 <span class="sn-an-delta sn-an-delta--up">▲ new</span></td>' ) !== false,
	'rows: Hacker News absent from a NON-EMPTY prior window → "new" (sn_analytics_delta\'s no-division rule — never a fabricated +∞%)' );
ok( strpos( $html_p, '>6 <span class="sn-an-delta sn-an-delta--flat">■ 0%</span></td>' ) !== false,
	'rows: qr-provhub 6 vs 6 → flat 0% (an unchanged row is still an answer)' );
ok( strpos( $html_p, '>14 <span class="sn-an-delta sn-an-delta--up">▲ +100%</span></td>' ) !== false, 'rows: AR 14 vs 7 → +100% (geography matched by country key)' );
ok( strpos( $html_p, '>29 <span class="sn-an-delta sn-an-delta--down">▼ -3%</span></td>' ) !== false, 'rows: desktop 29 vs 30 → -3% (devices)' );
ok( strpos( $html_p, '>16 <span class="sn-an-delta sn-an-delta--up">▲ +60%</span></td>' ) !== false, 'rows: entry "/" 16 vs 10 → +60% (pageroles matched by path)' );
ok( strpos( $html_p, '>7 <span class="sn-an-delta sn-an-delta--down">▼ -50%</span></td>' ) !== false, 'rows: exit /notes/ 7 vs 14 → -50%' );
ok( strpos( $html_p, 'sn-an-prior-note' ) === false, 'rows: healthy priors → no prior-window notes anywhere' );

echo "\nGroup: PART B — an EMPTY prior window says \"no prior data\" once per panel (no per-row new-spam)\n";
ov_seed_current();
$ov_pw = '2026-07-04|2026-07-10';
$GLOBALS['__ov']['session_rollup'][ $ov_pw ] = array();
$GLOBALS['__ov']['win'] = array(
	'sources'   => array( $ov_pw => array() ),
	'campaigns' => array( $ov_pw => array() ),
	'dims'      => array( 'country|' . $ov_pw => array(), 'device|' . $ov_pw => array() ),
	'entries'   => array( $ov_pw => array() ),
	'exits'     => array( $ov_pw => array() ),
);
$html_ep = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human', '30', 'prev' ); } );
ok( 7 === substr_count( $html_ep, 'No prior data in the comparison window yet.' ),
	'empty prior: ONE "no prior data" note per panel (session quality + six minis) — the decided copy, pinned' );
ok( strpos( $html_ep, '▲ new' ) === false && strpos( $html_ep, 'sn-an-delta--' ) === false,
	'empty prior: ZERO chips — an empty prior window never manufactures per-row "new" spam' );
ok( strpos( $html_ep, 'title="previous period:' ) === false, 'empty prior: no KPI chips either' );
ok( strpos( $html_ep, '<p class="sn-kpi-value">44</p>' ) !== false && strpos( $html_ep, 'Google' ) !== false,
	'empty prior: the current window still renders in full' );

echo "\nGroup: PART B — a FAILED prior read suppresses deltas honestly (current data intact)\n";
ov_seed_current();
$GLOBALS['__ov']['session_rollup'][ $ov_pw ] = null; // the prior rollup read fails
$GLOBALS['__ov']['fail'] = array(
	'sources'   => array( $ov_pw ),
	'campaigns' => array( $ov_pw ),
	'dims'      => array( 'country|' . $ov_pw, 'device|' . $ov_pw ),
	'entries'   => array( $ov_pw ),
	'exits'     => array( $ov_pw ),
);
$html_fp = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human', '30', 'prev' ); } );
ok( 7 === substr_count( $html_fp, 'The prior window could not be read — deltas suppressed (read failure, not an empty window).' ),
	'failed prior: one small note per panel — the decided copy, pinned' );
ok( strpos( $html_fp, 'sn-an-delta--' ) === false && strpos( $html_fp, 'title="previous period:' ) === false, 'failed prior: zero chips' );
ok( strpos( $html_fp, '<p class="sn-kpi-value">44</p>' ) !== false && strpos( $html_fp, 'Google' ) !== false && strpos( $html_fp, '/provhub/' ) !== false,
	'failed prior: the CURRENT window still renders in full — only the comparison is suppressed' );
ok( strpos( $html_fp, 'No referrer rows in the durable rollup' ) === false && strpos( $html_fp, 'The durable referrer rollup could not be read' ) === false,
	'failed prior: a failed PRIOR read is never misreported as a failed/empty CURRENT read' );
ok( strpos( $html_fp, 'No prior data in the comparison window yet.' ) === false, 'failed prior: failure copy ≠ empty copy (both directions pinned)' );

echo "\nGroup: PART B — yoy basis (window + label switch together)\n";
ov_seed_current();
ov_seed_priors( '2025-07-11', '2025-07-17' );
ov_reset_calls();
$html_y = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human', '30', 'yoy' ); } );
$sr_y = ov_calls( 'sn_session_rollup_read' );
ok( array( 'sn_session_rollup_read', '2025-07-11', '2025-07-17', 'human' ) === ( $sr_y[2] ?? null ),
	'yoy: the prior window is the SAME dates one year earlier (the reused helper, mode-threaded)' );
ok( strpos( $html_y, 'title="same period last year: 40"' ) !== false,
	'yoy: the chip tooltip names the yoy basis — window and label switch from the SAME mode (the D2 one-frame rule)' );
ok( strpos( $html_y, 'title="previous period:' ) === false, 'yoy: no stray prev-basis tooltips' );

echo "\nGroup: PART B — compare Off: byte-identical body, zero prior reads (the default-view shield)\n";
ov_seed_current();
ov_seed_priors(); // present but MUST NOT be read
$html_off3 = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human' ); } );
ov_reset_calls();
$html_off5 = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human', '7', 'off' ); } );
ok( $html_off3 === $html_off5, 'off: the 5-arg off render is BYTE-IDENTICAL to the legacy 3-arg render — the entire compare feature contributes zero bytes' );
ok( 2 === count( ov_calls( 'sn_session_rollup_read' ) ) && 1 === count( ov_calls( 'sn_analytics_top_sources' ) )
	&& 2 === count( ov_calls( 'sn_analytics_top_dimension' ) ) && 1 === count( ov_calls( 'sn_analytics_top_entry_pages' ) ),
	'off: zero prior-window reads — the read pattern is exactly the pre-part-4 one' );
ok( strpos( $html_off5, 'sn-an-prior-note' ) === false && strpos( $html_off5, 'title="previous period' ) === false
	&& strpos( $html_off5, 'sn-an-delta--' ) === false && strpos( $html_off5, 'sn-delta-up' ) === false && strpos( $html_off5, 'sn-delta-down' ) === false,
	'off: no chips, no notes, no basis tooltips' );
ok( strpos( $html_off5, 'single-page sessions · weighted' ) !== false, 'off: the static sub descriptors still hold the KPI slots' );

echo "\nGroup: PART B — range=all suppresses compare entirely (the shared card's exact gate)\n";
ov_reset_calls();
$html_all = capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human', 'all', 'prev' ); } );
ok( 2 === count( ov_calls( 'sn_session_rollup_read' ) ) && 1 === count( ov_calls( 'sn_analytics_top_sources' ) ),
	'all: no prior reads — no adjacent window exists for all-history (the header suppresses its deltas at all too)' );
ok( strpos( $html_all, 'sn-an-prior-note' ) === false && strpos( $html_all, 'sn-an-delta--' ) === false, 'all: no compare markup' );

echo "\nGroup: PART B — a folded (empty-current) panel triggers no prior read\n";
ov_seed_current();
ov_seed_priors();
$GLOBALS['__ov']['sources'] = array();
ov_reset_calls();
capture( function () { snt_analytics_render_view_overview( '2026-07-11', '2026-07-17', 'human', '30', 'prev' ); } );
ok( 1 === count( ov_calls( 'sn_analytics_top_sources' ) ),
	'economy: a current-empty panel folds — no rows to chip, so its prior read is skipped entirely' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
