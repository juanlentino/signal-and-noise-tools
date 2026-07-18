<?php
/**
 * Signal & Noise Tools — Analytics view: Overview (v9.68.0) — the WIRED
 * landing surface. The v9.67.0 flag-gated static mock, graduated: real data,
 * shared chrome, DEFAULT tab.
 *
 * Chrome: this view INHERITS the shared header (range/class/compare controls,
 * insights band, the shared Overview KPI card — which already speaks the
 * v9.63 honest vocabulary — and the movers rail). The mock's duplicate
 * "Headline" panel is gone: the shared card IS the headline. This body renders
 * only what the header does not: session quality, realtime, and the
 * acquisition/audience/page-role minis.
 *
 * LOAD-COST RULE (hard): the default landing renders on every Analytics
 * visit, so this body reads ONLY durable rollup tables (wp_sn_session_daily,
 * wp_sn_analytics_dims, wp_sn_analytics_utm, wp_sn_analytics_pageroles) and
 * the cron-warmed realtime transient. No live Analytics Engine call happens
 * on render — the session engine's capped live fetch stays on the Sessions
 * tab, where paying that cost is a deliberate click.
 *
 * NULL DISCIPLINE per panel: a failed read renders "could not be read" (the
 * v9.65.0 lesson — never served as an empty week), an empty window folds
 * honestly, and a cold realtime transient is "warming", never a fabricated 0.
 *
 * Composition: existing snt_an_* primitives + the existing dim/pageroles
 * table renderers. Light-only, no JS, no <wpd-*> — a wp-admin view, not a
 * desktop-mode window (widgets stay widgets).
 *
 * @package SignalNoiseTools
 * @since 9.68.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php';        // panel chrome + KPI row + trend + empty-fold primitives
require_once __DIR__ . '/analytics-render-tables.php'; // snt_analytics_render_dim_table + snt_analytics_render_pageroles_table

// The session-quality bounce trend reads a FIXED trailing window (weeks),
// independent of the range control — long enough to show drift, short enough
// to stay glanceable. The panel labels the window explicitly.
const SN_OVERVIEW_TREND_WEEKS = 8;

/**
 * Aggregate the durable per-day session rollup rows into window KPIs. Pure —
 * no WP calls, unit-testable in isolation.
 *
 * The math is visits-WEIGHTED (bounce_pct and ppv are per-day ratios; a naive
 * day-mean would let a 2-session day outvote a 30-session day). The one
 * aggregate that cannot be exact from dailies is the duration median — the
 * honest substitute is the median of the daily medians, and the KPI card
 * names it exactly that.
 *
 * @since 9.68.0
 * @param array|null $rows sn_session_rollup_read() rows (typed day-ascending).
 * @return array|null {sessions:int, bounce_pct:float, ppv:float,
 *                    median_dur:int, days:int}, or null when there is nothing
 *                    to aggregate (no rows / no sessions — never zeros).
 */
function snt_analytics_overview_session_kpis( $rows ) {
	if ( ! is_array( $rows ) || array() === $rows ) {
		return null;
	}
	$sessions = 0;
	$w_bounce = 0.0;
	$w_ppv    = 0.0;
	$medians  = array();
	$days     = 0;
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) || ! isset( $r['visits'], $r['bounce_pct'], $r['ppv'], $r['median_dur'] ) ) {
			continue;
		}
		$v         = max( 0, (int) $r['visits'] );
		$sessions += $v;
		$w_bounce += $v * (float) $r['bounce_pct'];
		$w_ppv    += $v * (float) $r['ppv'];
		$medians[] = max( 0, (int) $r['median_dur'] );
		++$days;
	}
	if ( $sessions < 1 ) {
		return null; // no sessions in the window — a ratio over 0 would fabricate.
	}
	sort( $medians );
	$n      = count( $medians );
	$median = ( 0 === $n % 2 )
		? (int) round( ( $medians[ (int) ( $n / 2 ) - 1 ] + $medians[ (int) ( $n / 2 ) ] ) / 2 )
		: $medians[ (int) floor( $n / 2 ) ];
	return array(
		'sessions'   => $sessions,
		'bounce_pct' => $w_bounce / $sessions,
		'ppv'        => $w_ppv / $sessions,
		'median_dur' => $median,
		'days'       => $days,
	);
}

/**
 * Bucket per-day rollup rows into ISO weeks (Monday-keyed, ascending) with a
 * visits-weighted bounce rate per week. Pure — no WP calls.
 *
 * A week whose rolled-up days carry zero sessions is SKIPPED: bounce over 0
 * sessions is undefined, and a fabricated point would bend the trend line.
 *
 * @since 9.68.0
 * @param array|null $rows sn_session_rollup_read() rows.
 * @return array<int, array{week_start:string, bounce_pct:float, visits:int}>
 */
function snt_analytics_overview_weekly_bounce( $rows ) {
	if ( ! is_array( $rows ) ) {
		return array();
	}
	$weeks = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) || ! isset( $r['day'], $r['visits'], $r['bounce_pct'] ) ) {
			continue;
		}
		$ts = strtotime( (string) $r['day'] . ' 00:00:00 UTC' );
		if ( false === $ts ) {
			continue;
		}
		// ISO week key = the Monday date (gmdate 'N': Monday=1). UTC on purpose:
		// the rollup writes UTC day rows, so the bucket boundary matches the data.
		$monday = gmdate( 'Y-m-d', $ts - ( (int) gmdate( 'N', $ts ) - 1 ) * DAY_IN_SECONDS );
		if ( ! isset( $weeks[ $monday ] ) ) {
			$weeks[ $monday ] = array( 'visits' => 0, 'weighted' => 0.0 );
		}
		$v                             = max( 0, (int) $r['visits'] );
		$weeks[ $monday ]['visits']   += $v;
		$weeks[ $monday ]['weighted'] += $v * (float) $r['bounce_pct'];
	}
	ksort( $weeks ); // Y-m-d keys sort lexically = chronologically.
	$out = array();
	foreach ( $weeks as $monday => $w ) {
		if ( $w['visits'] < 1 ) {
			continue;
		}
		$out[] = array(
			'week_start' => (string) $monday,
			'bounce_pct' => $w['weighted'] / $w['visits'],
			'visits'     => (int) $w['visits'],
		);
	}
	return $out;
}

/**
 * Session quality panel: window KPIs from the durable nightly rollup + the
 * fixed 8-week bounce trend. Full-width under the shared header.
 *
 * Honest states, one per input shape (the v9.65.0 trend-panel contract):
 *   null rows  → "could not be read" fold (a read failure is NOT an empty
 *                window — real wpdb reports failure as [] + last_error, and
 *                the accessor already resolved that to null);
 *   no KPIs    → empty fold naming the nightly writer;
 *   KPIs + a trend slot that independently reports its own failure /
 *   too-few-weeks state instead of drawing a one-point lie.
 *
 * @since 9.68.0
 * @param array|null|false $range_rows Rollup rows for the header window
 *                                     (false = accessor absent, harness only).
 * @param array|null|false $trend_rows Rollup rows for the fixed trend window.
 */
function snt_analytics_render_overview_session_quality( $range_rows, $trend_rows ) {
	$title = __( 'Session quality', 'signal-and-noise-tools' );
	if ( false === $range_rows ) {
		return; // rollup module absent — production loads it unconditionally.
	}
	if ( null === $range_rows ) {
		snt_an_note_empty( $title, __( 'The durable session rollup could not be read — a read failure, not an empty window.', 'signal-and-noise-tools' ) );
		return;
	}
	$kpis = snt_analytics_overview_session_kpis( $range_rows );
	if ( null === $kpis ) {
		snt_an_note_empty( $title, __( 'No rolled-up days in this window yet — the nightly session rollup writes one row per day and class after each UTC day closes.', 'signal-and-noise-tools' ) );
		return;
	}

	snt_an_panel_open( $title, array(
		// The v9.65.0 units lesson: same dashboard, same word, two units — say
		// which one this is. (Sessions here ≠ the headline's visitor-day Visits.)
		'header_meta' => __( 'within-day sessions · nightly rollup — a different unit from the Overview headline&#8217;s visitor-day Visits', 'signal-and-noise-tools' ),
	) );
	snt_an_kpi_row(
		array(
			array(
				'l'        => __( 'Sessions', 'signal-and-noise-tools' ),
				'n'        => number_format_i18n( (int) $kpis['sessions'] ),
				'promoted' => true,
				/* translators: %d: number of days the nightly rollup has written in this window. */
				'sub'      => sprintf( __( '%d rolled-up days', 'signal-and-noise-tools' ), (int) $kpis['days'] ),
			),
			array(
				'l'   => __( 'Bounce', 'signal-and-noise-tools' ),
				'n'   => number_format_i18n( (float) $kpis['bounce_pct'], 1 ) . '%',
				'sub' => __( 'single-page sessions · weighted', 'signal-and-noise-tools' ),
			),
			array(
				'l'   => __( 'Pages / session', 'signal-and-noise-tools' ),
				'n'   => number_format_i18n( (float) $kpis['ppv'], 2 ),
				'sub' => __( 'weighted mean', 'signal-and-noise-tools' ),
			),
			array(
				'l'   => __( 'Median duration', 'signal-and-noise-tools' ),
				'n'   => number_format_i18n( (int) $kpis['median_dur'] ) . 's',
				'sub' => __( 'median of daily medians', 'signal-and-noise-tools' ),
			),
		),
		array( 'empty_slot' => 'omit' )
	);

	// The fixed-window bounce trend — its states are independent of the KPI
	// read above (two reads of the same table can fail separately).
	if ( false !== $trend_rows ) {
		if ( null === $trend_rows ) {
			echo '<p class="sn-an-empty">' . esc_html__( 'The 8-week bounce trend could not be read (read failure — not an empty window).', 'signal-and-noise-tools' ) . '</p>';
		} else {
			$weekly = snt_analytics_overview_weekly_bounce( $trend_rows );
			if ( count( $weekly ) < 2 ) {
				echo '<p class="sn-an-empty">' . esc_html( sprintf(
					/* translators: %d: number of rolled-up ISO weeks available so far. */
					__( 'The bounce trend needs at least two rolled-up weeks — %d so far in the fixed 8-week window.', 'signal-and-noise-tools' ),
					count( $weekly )
				) ) . '</p>';
			} else {
				$last = $weekly[ count( $weekly ) - 1 ];
				snt_an_trend_svg(
					array_map(
						static function ( $w ) {
							return (float) $w['bounce_pct'];
						},
						$weekly
					),
					array(
						// The window is FIXED (not the range control's) — the label says so.
						'head'      => __( 'Bounce rate — last 8 weeks', 'signal-and-noise-tools' ),
						/* translators: %s: visits-weighted bounce percentage of the most recent rolled-up week. */
						'meta'      => sprintf( __( 'latest %s%%', 'signal-and-noise-tools' ), number_format_i18n( (float) $last['bounce_pct'], 1 ) ),
						'axis'      => array( (string) $weekly[0]['week_start'], (string) $last['week_start'] ),
						'id_suffix' => 'OvBounce',
					)
				);
			}
		}
	}
	snt_an_panel_close();
}

/**
 * "Right now" — the compact realtime strip. Reads ONLY the cron-warmed
 * transient accessors: null means the cron has not warmed the value yet
 * ("warming" — never a blocking query, never a fabricated 0), while a warmed
 * 0 is a real 0. Both figures are range-agnostic, so each card labels its
 * actual window explicitly (the 5-minute active window; the site-local day).
 *
 * @since 9.68.0
 * @param int|null $now   Active visitors in the 5-minute window (class-filtered).
 * @param int|null $today Human pageviews so far in the site-local day.
 */
function snt_analytics_render_overview_rightnow( $now, $today ) {
	snt_an_panel_open( __( 'Right now', 'signal-and-noise-tools' ), array(
		'panel_class' => 'sn-an-rightnow',
		'header_meta' => __( 'cron-warmed — never queried on page load', 'signal-and-noise-tools' ),
	) );
	$cards   = array();
	$cards[] = ( null === $now )
		? array( 'l' => __( 'Active visitors', 'signal-and-noise-tools' ), 'n' => '—', 'sub' => __( 'warming — no cron sample yet', 'signal-and-noise-tools' ) )
		: array( 'l' => __( 'Active visitors', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $now ), 'sub' => __( '5-minute window', 'signal-and-noise-tools' ) );
	$cards[] = ( null === $today )
		? array( 'l' => __( 'Views today', 'signal-and-noise-tools' ), 'n' => '—', 'sub' => __( 'warming — no sample for today yet', 'signal-and-noise-tools' ) )
		: array( 'l' => __( 'Views today', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $today ), 'sub' => __( 'human pageviews · site-local day', 'signal-and-noise-tools' ) );
	snt_an_kpi_row( $cards, array( 'empty_slot' => 'omit' ) );
	snt_an_panel_close();
}

/**
 * Render the Overview view body — dispatched from snt_analytics_render_dashboard()
 * under the SHARED header chrome. Layout (owner-approved): session quality
 * full-width → compact "Right now" → balanced bento (sources + campaigns left,
 * geography + devices right) → entry/exit pages paired.
 *
 * Every read below is a durable local table or a cron-warmed transient/option
 * — see the file header's load-cost contract. function_exists guards are
 * defence in depth for partial harnesses; production loads every module.
 *
 * @since 9.68.0
 * @param string $from  Window start (Y-m-d).
 * @param string $to    Window end (Y-m-d).
 * @param string $class Traffic class.
 */
function snt_analytics_render_view_overview( $from, $to, $class ) {
	// ── Session quality: two reads of the durable wp_sn_session_daily table —
	// the header window (KPIs) + the fixed 8-week trend window.
	$has_rollup = function_exists( 'sn_session_rollup_read' );
	$range_rows = $has_rollup ? sn_session_rollup_read( $from, $to, $class ) : false;
	$t8_from    = gmdate( 'Y-m-d', strtotime( $to . ' 00:00:00 UTC' ) - ( SN_OVERVIEW_TREND_WEEKS * 7 - 1 ) * DAY_IN_SECONDS );
	$trend_rows = $has_rollup ? sn_session_rollup_read( $t8_from, $to, $class ) : false;
	snt_analytics_render_overview_session_quality( $range_rows, $trend_rows );

	// ── Right now: the cron-warmed transient pair (realtime is class-aware;
	// views-today is human-only by construction and its card says so).
	snt_analytics_render_overview_rightnow(
		function_exists( 'sn_analytics_realtime' ) ? sn_analytics_realtime( $class ) : null,
		function_exists( 'sn_analytics_views_today' ) ? sn_analytics_views_today() : null
	);

	// ── Balanced bento: acquisition left (sources + UTM), audience right
	// (countries + devices) — all four from durable rollup tables. These
	// accessors return [] for both an empty window and a failed read (their
	// existing contract), so the honest copy names the rollup, not the window.
	$sources   = function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $from, $to, $class, 5 ) : array();
	$campaigns = function_exists( 'sn_analytics_top_utm_campaigns' ) ? sn_analytics_top_utm_campaigns( $from, $to, $class, 5 ) : array();
	$countries = function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'country', $from, $to, $class, 5 ) : array();
	$devices   = function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'device', $from, $to, $class, 5 ) : array();

	echo '<div class="sn-an-overview-bento">';
	echo '<div class="sn-an-bento-col">';
	snt_analytics_render_dim_table( __( 'Top sources', 'signal-and-noise-tools' ), $sources, __( 'No referrer rows in the durable rollup for this range yet.', 'signal-and-noise-tools' ) );
	snt_analytics_render_dim_table( __( 'Campaigns (UTM)', 'signal-and-noise-tools' ), $campaigns, __( 'No UTM-tagged traffic in the durable rollup for this range yet.', 'signal-and-noise-tools' ) );
	echo '</div>';
	echo '<div class="sn-an-bento-col">';
	snt_analytics_render_dim_table( __( 'Geography', 'signal-and-noise-tools' ), $countries, __( 'No country rows in the durable rollup for this range yet.', 'signal-and-noise-tools' ) );
	snt_analytics_render_dim_table( __( 'Devices', 'signal-and-noise-tools' ), $devices, __( 'No device rows in the durable rollup for this range yet.', 'signal-and-noise-tools' ) );
	echo '</div>';
	echo '</div>';

	// ── Entry + exit pages, PAIRED — the durable pageroles rollup (exits fed
	// nightly by the session bridge since v9.66.0). Human-only tables: their
	// rollup carries no class column, so the class control does not apply and
	// the header meta says so.
	$entries = function_exists( 'sn_analytics_top_entry_pages' ) ? sn_analytics_top_entry_pages( $from, $to, 10 ) : array();
	$exits   = function_exists( 'sn_analytics_top_exit_pages' ) ? sn_analytics_top_exit_pages( $from, $to, 10 ) : array();
	echo '<div class="sn-an-grid sn-an-overview-pair">';
	snt_analytics_render_pageroles_table( $entries, 'entry', __( 'human traffic · durable rollup', 'signal-and-noise-tools' ) );
	snt_analytics_render_pageroles_table( $exits, 'exit', __( 'human traffic · nightly session bridge', 'signal-and-noise-tools' ) );
	echo '</div>';

	snt_an_flush_empty_fold();
}
