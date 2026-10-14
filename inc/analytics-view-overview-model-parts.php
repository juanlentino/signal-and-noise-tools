<?php
/**
 * Signal & Noise Tools: Analytics Overview, the pure helpers (the tab doorway, the prior-window note, row deltas, session KPIs, the weekly bounce, the guarded read).
 *
 * Split from inc/analytics-view-overview.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PART A (v9.68.0 part 4): one panel-header doorway to a full tab. Built
 * EXACTLY like the tab strip's links (snt_analytics_render_view_tabs): the
 * same base — current URL minus the SHARED reset list
 * (snt_analytics_view_reset_params(), the one source of truth both builders
 * consume since review 2, F2; sn_compare deliberately not in it, so the
 * active compare mode rides along) — the same sn_view param, and the same
 * snt_analytics_window_args() carry (sn_range + sn_class; sn_from/sn_to for
 * custom ranges only). The label is the target tab's registry label, so the
 * doorway reads exactly like the tab it opens (the v9.65.0 slug/label split
 * honored: 'visits' renders "Sessions").
 *
 * @since 9.68.0
 * @param string     $slug  Target view slug.
 * @param string     $label Fallback label (partial harnesses only — production
 *                          always resolves the registry label).
 * @param int|string $range Active range token.
 * @param string     $class Active traffic class.
 * @param string     $from  Window start (Y-m-d).
 * @param string     $to    Window end (Y-m-d).
 * @return string Pre-escaped <a> markup for the panel primitive's header_meta
 *                seam (kses'd there; href esc_url'd + label esc_html'd here).
 */
function snt_analytics_overview_tab_doorway( $slug, $label, $range, $class, $from, $to ) {
	if ( function_exists( 'snt_analytics_views' ) ) {
		$views = snt_analytics_views();
		if ( isset( $views[ $slug ] ) ) {
			$label = (string) $views[ $slug ];
		}
	}
	$base = function_exists( 'snt_analytics_view_reset_params' )
		? remove_query_arg( snt_analytics_view_reset_params(), add_query_arg( array() ) )
		: ''; // partial harness without analytics-admin.php — fall through to the canonical route.
	if ( '' === (string) $base ) {
		$base = snt_analytics_page_url();
	}
	$args = array( 'sn_view' => (string) $slug );
	if ( function_exists( 'snt_analytics_window_args' ) ) {
		$args += snt_analytics_window_args( $range, $class, $from, $to );
	}
	return '<a class="sn-an-head-link" href="' . esc_url( add_query_arg( $args, $base ) ) . '">' . esc_html( $label ) . ' &rarr;</a>';
}

/**
 * PART B (v9.68.0 part 4): the once-per-panel prior-window note copy — ONE
 * pair of strings for every panel, so the whole body speaks the same two
 * sentences about its comparison basis. '' state = no note.
 *
 * @since 9.68.0
 * @param string $state '' | 'failed' | 'empty' (snt_analytics_overview_row_deltas states).
 * @return string Translated copy, or '' for the no-note state.
 */
function snt_analytics_overview_prior_note_copy( $state ) {
	if ( 'failed' === $state ) {
		return __( 'The prior window could not be read: deltas suppressed (read failure, not an empty window).', 'signal-and-noise-tools' );
	}
	if ( 'empty' === $state ) {
		return __( 'No prior data in the comparison window yet.', 'signal-and-noise-tools' );
	}
	return '';
}

/**
 * PART B (v9.68.0 part 4): per-row change-vs-prior for one mini table. Pure —
 * both guarded reads already happened at the caller.
 *
 * The honesty rules, one per input shape:
 *  - $prior null (compare off / no prior read) → no deltas, no note;
 *  - prior read FAILED → deltas suppressed, state 'failed' — the current rows
 *    still render, only the comparison goes quiet;
 *  - prior window EMPTY ([] with no error) → state 'empty' ONCE per panel,
 *    never a column of fabricated per-row "new" chips;
 *  - otherwise every current row is matched by $key_field; a row absent from a
 *    NON-EMPTY prior window deltas against 0, which sn_analytics_delta()
 *    resolves to {pct:null, dir:'up'} — the badge renders "new", never a
 *    divided-by-zero +∞%. A row that vanished from the current top-N simply
 *    does not appear: the current window drives the table.
 *
 * @since 9.68.0
 * @param array      $rows      Current-window rows.
 * @param array|null $prior     snt_analytics_overview_read_guarded() result for
 *                              the prior window, or null when compare is off.
 * @param string     $key_field Dimension key field ('value' | 'path').
 * @return array{deltas: array<string,array>|null, state: string}
 */
function snt_analytics_overview_row_deltas( $rows, $prior, $key_field ) {
	if ( ! is_array( $prior ) ) {
		return array( 'deltas' => null, 'state' => '' );
	}
	if ( ! empty( $prior['failed'] ) ) {
		return array( 'deltas' => null, 'state' => 'failed' );
	}
	$prior_rows = ( isset( $prior['rows'] ) && is_array( $prior['rows'] ) ) ? $prior['rows'] : array();
	if ( array() === $prior_rows ) {
		return array( 'deltas' => null, 'state' => 'empty' );
	}
	$prior_views = array();
	foreach ( $prior_rows as $r ) {
		if ( is_array( $r ) && isset( $r[ $key_field ] ) ) {
			$prior_views[ (string) $r[ $key_field ] ] = (int) ( $r['views'] ?? 0 );
		}
	}
	$deltas = array();
	foreach ( (array) $rows as $r ) {
		if ( ! is_array( $r ) || ! isset( $r[ $key_field ] ) ) {
			continue;
		}
		$key            = (string) $r[ $key_field ];
		$prev           = (int) ( $prior_views[ $key ] ?? 0 );
		$d              = sn_analytics_delta( (int) ( $r['views'] ?? 0 ), $prev );
		$d['previous']  = $prev;
		$deltas[ $key ] = $d;
	}
	return array( 'deltas' => $deltas, 'state' => '' );
}

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
 * Normalize ONE durable-table accessor result to {rows, failed}. Since
 * v9.68.1 the dims/UTM/pageroles accessors (and their sources/entry/exit
 * wrappers) consult $wpdb->last_error THEMSELVES and self-report a FAILED
 * read as null ([] stays the honest empty window), so the v9.68.0
 * last_error/num_queries bracket this helper used to carry is retired:
 * nothing here needs the transport channel anymore — the accessor's own
 * verdict is the one failure signal, and it cannot inherit a stale error or
 * miss a same-message consecutive failure by construction. Kept as a helper
 * (rather than inlined) so every mini panel resolves the tri-state the same
 * way and the render code below keeps its exact shape.
 *
 * @since 9.68.0
 * @param callable $read The accessor call, closed over its args.
 * @return array{rows:array, failed:bool} rows: the accessor result normalized
 *                                        to an array; failed: true iff the
 *                                        accessor did not return an array
 *                                        (its null-on-failure contract —
 *                                        unknown is never an empty window).
 */
function snt_analytics_overview_read_guarded( $read ) {
	$rows = $read();
	return array(
		'rows'   => is_array( $rows ) ? $rows : array(),
		'failed' => ! is_array( $rows ),
	);
}

