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
 * NULL DISCIPLINE per panel — exactly what each one distinguishes:
 *  - Session quality (KPIs + trend): its accessor consults $wpdb->last_error
 *    itself and returns null on a FAILED read vs [] for an empty window, so
 *    failure renders "could not be read" (the v9.65.0 lesson — never served
 *    as an empty week) and [] folds honestly.
 *  - The six minis (sources, UTM, geography, devices, entry, exit): their
 *    accessors return [] for BOTH failure and empty (their existing contract,
 *    unchanged here), so this view brackets each call with a
 *    $wpdb->last_error + num_queries before/after snapshot (real wpdb clears
 *    last_error per query via flush() and counts every executed query); an
 *    error this read newly set — changed message, or same message from a
 *    fresh query — renders that panel's "could not be read" fold, [] without
 *    one folds as an honest empty window. A stale pre-existing error with no
 *    query run is never inherited.
 *  - Right now: a cold cron-warmed transient is "warming", never a fabricated
 *    0, and a warmed 0 is a real 0 — but a transient read has no failure
 *    channel, so "warming" honestly covers never-warmed and lost alike.
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

// The session-quality bounce trend reads a fixed-LENGTH trailing window (8 ISO
// weeks) ANCHORED at the range control's end date ($to) — deliberate: a
// historical range shows the 8 weeks leading up to ITS end, so the trend stays
// coherent with the header window. Long enough to show drift, short enough to
// stay glanceable; the panel label renders the actual endpoint.
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
 * Window honesty (v9.68.0 pre-merge review, F4): the trend window's bounds
 * rarely align with ISO weeks — the 56-day window seldom starts on a Monday
 * and (unless $to is a Sunday) always ends mid-week, so a bucket the window
 * CUTS holds only its in-window days and would otherwise draw as a silent
 * full weekly point. When bounds are given: a TRAILING cut bucket is flagged
 * partial:true (the trend meta names the latest point, so annotation is
 * clean there); a LEADING cut bucket is TRIMMED — the first point has no
 * per-point label surface (the axis is a bare Monday date), so it cannot be
 * annotated honestly. Without bounds: no trimming, no flags (back-compat).
 *
 * @since 9.68.0
 * @param array|null $rows sn_session_rollup_read() rows.
 * @param string     $from Optional window start (Y-m-d) for partial-week
 *                         detection. '' = boundless.
 * @param string     $to   Optional inclusive window end (Y-m-d). '' = boundless.
 * @return array<int, array{week_start:string, bounce_pct:float, visits:int, partial:bool}>
 */
function snt_analytics_overview_weekly_bounce( $rows, $from = '', $to = '' ) {
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
			'partial'    => false,
		);
	}
	// Leading partial: the first bucket's Monday precedes the window start, so
	// the window cut its early days — trimmed (no label surface to annotate).
	if ( array() !== $out && '' !== (string) $from && (string) $out[0]['week_start'] < (string) $from ) {
		array_shift( $out );
	}
	// Trailing partial: the last bucket's Sunday exceeds the window end, so
	// the window cut its late days — flagged for the meta annotation.
	if ( array() !== $out && '' !== (string) $to ) {
		$last_i  = count( $out ) - 1;
		$mon_ts  = strtotime( (string) $out[ $last_i ]['week_start'] . ' 00:00:00 UTC' );
		if ( false !== $mon_ts && gmdate( 'Y-m-d', $mon_ts + 6 * DAY_IN_SECONDS ) > (string) $to ) {
			$out[ $last_i ]['partial'] = true;
		}
	}
	return $out;
}

/**
 * Bracket ONE durable-table accessor call with a $wpdb->last_error baseline
 * (v9.68.0 pre-merge review, F1). The dims/UTM/pageroles accessors return []
 * for BOTH a failed read and an empty window (their existing contract —
 * deliberately not changed here; that ripple is a future wave), so [] alone
 * cannot distinguish "no rows" from "the table is missing/corrupt". Real wpdb
 * reports a failed query as [] from get_results(ARRAY_A) WITH
 * $wpdb->last_error set, and wpdb::query() calls flush() first — which resets
 * last_error to '' per query — so immediately after the call, last_error
 * reflects the accessor's OWN read (a successful read even CLEARS a stale
 * error). The before-snapshot guards the one gap: an accessor that performs
 * NO query (memo hit, early return) leaves an EARLIER unrelated query's error
 * in place — a stale value must never count as this read's failure.
 *
 * A changed/newly-set message alone is NOT enough: two consecutive failing
 * reads of the SAME table (Geography then Devices both read wp_sn_analytics_
 * dims) produce IDENTICAL messages, so "unchanged" would misread the second
 * failure as an empty window. Real wpdb exposes the disambiguator:
 * $wpdb->num_queries increments unconditionally per executed query
 * (wpdb::_do_query()), so queries-ran + non-empty last_error = the call's
 * last query failed, no matter whether the message matches the baseline.
 *
 * @since 9.68.0
 * @param callable $read The accessor call, closed over its args.
 * @return array{rows:array, failed:bool} rows: the accessor result,
 *                                        normalized to an array; failed: true
 *                                        iff $wpdb->last_error is non-empty
 *                                        after the call AND either changed
 *                                        from the baseline or at least one
 *                                        query ran during the call.
 */
function snt_analytics_overview_read_guarded( $read ) {
	$before   = isset( $GLOBALS['wpdb']->last_error ) ? (string) $GLOBALS['wpdb']->last_error : '';
	$q_before = isset( $GLOBALS['wpdb']->num_queries ) ? (int) $GLOBALS['wpdb']->num_queries : 0;
	$rows     = $read();
	$after    = isset( $GLOBALS['wpdb']->last_error ) ? (string) $GLOBALS['wpdb']->last_error : '';
	$q_after  = isset( $GLOBALS['wpdb']->num_queries ) ? (int) $GLOBALS['wpdb']->num_queries : 0;
	return array(
		'rows'   => is_array( $rows ) ? $rows : array(),
		'failed' => ( '' !== $after && ( $after !== $before || $q_after > $q_before ) ),
	);
}

/**
 * Session quality panel: window KPIs from the durable nightly rollup + the
 * 8-week bounce trend anchored at the range end. Full-width under the shared
 * header.
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
 * @param array|null|false $trend_rows Rollup rows for the trend window.
 * @param string           $trend_from Trend window start (Y-m-d) — for
 *                                     partial-week detection.
 * @param string           $trend_to   Trend window end (Y-m-d) — the range
 *                                     control's $to; rendered in the label.
 */
function snt_analytics_render_overview_session_quality( $range_rows, $trend_rows, $trend_from, $trend_to ) {
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

	// The 8-week bounce trend — its states are independent of the KPI read
	// above (two reads of the same table can fail separately). The window is a
	// fixed LENGTH (8 ISO weeks) ANCHORED at the range control's $to — the
	// label renders the actual endpoint rather than claiming an independence
	// it doesn't have (v9.68.0 pre-merge review, F2).
	if ( false !== $trend_rows ) {
		if ( null === $trend_rows ) {
			echo '<p class="sn-an-empty">' . esc_html__( 'The 8-week bounce trend could not be read (read failure — not an empty window).', 'signal-and-noise-tools' ) . '</p>';
		} else {
			$weekly = snt_analytics_overview_weekly_bounce( $trend_rows, $trend_from, $trend_to );
			if ( count( $weekly ) < 2 ) {
				echo '<p class="sn-an-empty">' . esc_html( sprintf(
					/* translators: %d: number of rolled-up ISO weeks available so far. */
					__( 'The bounce trend needs at least two rolled-up weeks — %d so far in the 8-week window.', 'signal-and-noise-tools' ),
					count( $weekly )
				) ) . '</p>';
			} else {
				$last = $weekly[ count( $weekly ) - 1 ];
				// A trailing ISO week the window cuts mid-week is annotated in
				// the meta, never drawn as a silent full weekly point (F4).
				$meta = ! empty( $last['partial'] )
					/* translators: %s: visits-weighted bounce percentage of the most recent rolled-up week (an incomplete ISO week — the window ends mid-week). */
					? sprintf( __( 'latest %s%% (partial week)', 'signal-and-noise-tools' ), number_format_i18n( (float) $last['bounce_pct'], 1 ) )
					/* translators: %s: visits-weighted bounce percentage of the most recent rolled-up week. */
					: sprintf( __( 'latest %s%%', 'signal-and-noise-tools' ), number_format_i18n( (float) $last['bounce_pct'], 1 ) );
				snt_an_trend_svg(
					array_map(
						static function ( $w ) {
							return (float) $w['bounce_pct'];
						},
						$weekly
					),
					array(
						/* translators: %s: the trend window's inclusive end date (Y-m-d) — the range control's end date. */
						'head'      => sprintf( __( 'Bounce — 8 weeks to %s', 'signal-and-noise-tools' ), $trend_to ),
						'meta'      => $meta,
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
	// the header window (KPIs) + the 8-week trend window anchored at $to.
	$has_rollup = function_exists( 'sn_session_rollup_read' );
	$range_rows = $has_rollup ? sn_session_rollup_read( $from, $to, $class ) : false;
	$t8_from    = gmdate( 'Y-m-d', strtotime( $to . ' 00:00:00 UTC' ) - ( SN_OVERVIEW_TREND_WEEKS * 7 - 1 ) * DAY_IN_SECONDS );
	$trend_rows = $has_rollup ? sn_session_rollup_read( $t8_from, $to, $class ) : false;
	snt_analytics_render_overview_session_quality( $range_rows, $trend_rows, $t8_from, $to );

	// ── Right now: the cron-warmed transient pair (realtime is class-aware;
	// views-today is human-only by construction and its card says so).
	snt_analytics_render_overview_rightnow(
		function_exists( 'sn_analytics_realtime' ) ? sn_analytics_realtime( $class ) : null,
		function_exists( 'sn_analytics_views_today' ) ? sn_analytics_views_today() : null
	);

	// ── Balanced bento: acquisition left (sources + UTM), audience right
	// (countries + devices) — all four from durable rollup tables. Their
	// accessors return [] for BOTH an empty window and a failed read (their
	// existing contract, deliberately untouched), so each call is bracketed by
	// snt_analytics_overview_read_guarded(): a read that newly set
	// $wpdb->last_error folds as "could not be read", never as an empty week.
	$sources   = snt_analytics_overview_read_guarded( static function () use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $from, $to, $class, 5 ) : array();
	} );
	$campaigns = snt_analytics_overview_read_guarded( static function () use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_utm_campaigns' ) ? sn_analytics_top_utm_campaigns( $from, $to, $class, 5 ) : array();
	} );
	$countries = snt_analytics_overview_read_guarded( static function () use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'country', $from, $to, $class, 5 ) : array();
	} );
	$devices   = snt_analytics_overview_read_guarded( static function () use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'device', $from, $to, $class, 5 ) : array();
	} );

	echo '<div class="sn-an-overview-bento">';
	echo '<div class="sn-an-bento-col">';
	snt_analytics_render_dim_table(
		__( 'Top sources', 'signal-and-noise-tools' ),
		$sources['rows'],
		$sources['failed']
			? __( 'The durable referrer rollup could not be read (read failure — not an empty window).', 'signal-and-noise-tools' )
			: __( 'No referrer rows in the durable rollup for this range yet.', 'signal-and-noise-tools' )
	);
	snt_analytics_render_dim_table(
		__( 'Campaigns (UTM)', 'signal-and-noise-tools' ),
		$campaigns['rows'],
		$campaigns['failed']
			? __( 'The durable UTM rollup could not be read (read failure — not an empty window).', 'signal-and-noise-tools' )
			: __( 'No UTM-tagged traffic in the durable rollup for this range yet.', 'signal-and-noise-tools' )
	);
	echo '</div>';
	echo '<div class="sn-an-bento-col">';
	snt_analytics_render_dim_table(
		__( 'Geography', 'signal-and-noise-tools' ),
		$countries['rows'],
		$countries['failed']
			? __( 'The durable country rollup could not be read (read failure — not an empty window).', 'signal-and-noise-tools' )
			: __( 'No country rows in the durable rollup for this range yet.', 'signal-and-noise-tools' )
	);
	snt_analytics_render_dim_table(
		__( 'Devices', 'signal-and-noise-tools' ),
		$devices['rows'],
		$devices['failed']
			? __( 'The durable device rollup could not be read (read failure — not an empty window).', 'signal-and-noise-tools' )
			: __( 'No device rows in the durable rollup for this range yet.', 'signal-and-noise-tools' )
	);
	echo '</div>';
	echo '</div>';

	// ── Entry + exit pages, PAIRED — the durable pageroles rollup (exits fed
	// nightly by the session bridge since v9.66.0). Human-only tables: their
	// rollup carries no class column, so the class control does not apply and
	// the header meta says so. Same last_error bracketing as the bento: a
	// failed read folds as "could not be read" via the panel's own title (the
	// shared renderer's empty copy is reserved for a truly empty window).
	$entries = snt_analytics_overview_read_guarded( static function () use ( $from, $to ) {
		return function_exists( 'sn_analytics_top_entry_pages' ) ? sn_analytics_top_entry_pages( $from, $to, 10 ) : array();
	} );
	$exits   = snt_analytics_overview_read_guarded( static function () use ( $from, $to ) {
		return function_exists( 'sn_analytics_top_exit_pages' ) ? sn_analytics_top_exit_pages( $from, $to, 10 ) : array();
	} );
	echo '<div class="sn-an-grid sn-an-overview-pair">';
	if ( $entries['failed'] ) {
		snt_an_note_empty( __( 'Entry pages', 'signal-and-noise-tools' ), __( 'The durable entry-pages rollup could not be read (read failure — not an empty window).', 'signal-and-noise-tools' ) );
	} else {
		snt_analytics_render_pageroles_table( $entries['rows'], 'entry', __( 'human traffic · durable rollup', 'signal-and-noise-tools' ) );
	}
	if ( $exits['failed'] ) {
		snt_an_note_empty( __( 'Exit pages', 'signal-and-noise-tools' ), __( 'The durable exit-pages rollup could not be read (read failure — not an empty window).', 'signal-and-noise-tools' ) );
	} else {
		snt_analytics_render_pageroles_table( $exits['rows'], 'exit', __( 'human traffic · nightly session bridge', 'signal-and-noise-tools' ) );
	}
	echo '</div>';

	snt_an_flush_empty_fold();
}
