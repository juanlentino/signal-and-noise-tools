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
 *  - The six minis (sources, UTM, geography, devices, entry, exit): since
 *    v9.68.1 their accessors speak the SAME contract (null = failed wpdb
 *    read, [] = empty window — upgraded at the source, so the OLDER tabs
 *    inherit the honesty too), so this view simply resolves the accessor's
 *    own verdict: null renders that panel's "could not be read" fold, []
 *    folds as an honest empty window. (The v9.68.0 view-local last_error +
 *    num_queries bracket this view carried while the accessors conflated the
 *    two is retired — the accessor verdict is the one failure signal.)
 *  - Right now: a cold cron-warmed transient is "warming", never a fabricated
 *    0, and a warmed 0 is a real 0 — but a transient read has no failure
 *    channel, so "warming" honestly covers never-warmed and lost alike.
 *
 * ATTENTION LAYER (v9.69.0, owner-approved): every panel's headline movement
 * is judged vs the PREVIOUS period on EVERY render — compare Off included
 * (that is the feature's point; the header's compare control governs only the
 * visible delta chips). Prior windows come from the real
 * snt_analytics_compare_window(...,'prev') — zero new date math — and the
 * prior reads stay durable-table-only, bounded like the v9.68.0 compare reads.
 * Flagged panels wear the amber NOTABLE chip (header_meta seam, beside the
 * doorway) and are named in one needs-attention strip at the very top of the
 * body, with in-page anchor links. Reordering (the approved AFTER geometry,
 * generalized): Session quality ALWAYS first; flagged minis promote out of
 * the bento to full width directly beneath it, in canonical relative order;
 * entry/exit promote as a PAIR if either flags; Right now never promotes
 * (instantaneous — no prior period exists for it). Thresholds, sentiment
 * table and null discipline live in inc/analytics-overview-attention.php.
 * DOCUMENTED CHOICE — a FAILED prior read is attention UNKNOWN: no chip, no
 * strip mention, and no false "all calm" claim either; unknown renders as
 * silence, byte-identical to a quiet week, because claiming either state
 * would fabricate knowledge. TOTAL COLLAPSE (review r1 F1): an EMPTY current
 * window whose read SUCCEEDED is an ANSWER — 0 recorded — so a prior window
 * that cleared the views floor flags it ("views N → none recorded"). The
 * panel itself folds, so that flag is STRIP-ONLY: a plain flag, no anchor, no
 * chip, no promotion — the strip informs without a panel target. A FAILED
 * current read stays silent (unknown — no real 0 to claim). QUIET-WEEK
 * SHIELD: when nothing flags, the body is byte-identical to the v9.68.1
 * output (golden-pinned in tests).
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
require_once __DIR__ . '/analytics-overview-attention.php'; // v9.69.0 attention signals + chip + strip (pure)

// The session-quality bounce trend reads a fixed-LENGTH trailing window (8 ISO
// weeks) ANCHORED at the range control's end date ($to) — deliberate: a
// historical range shows the 8 weeks leading up to ITS end, so the trend stays
// coherent with the header window. Long enough to show drift, short enough to
// stay glanceable; the panel label renders the actual endpoint.
const SN_OVERVIEW_TREND_WEEKS = 8;

// Prior-window read depth for the mini-table compare (v9.68.0 part 4) — WIDER
// than any visible top-N so a row at prior rank 6 is matched by key instead of
// misread as "new". The movers idiom (sn_analytics_movers_uncached reads both
// windows at 50 and diffs in memory); durable-table reads, so depth is cheap.
const SN_OVERVIEW_PRIOR_LIMIT = 50;

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
		$base = admin_url( 'index.php?page=sn-analytics' );
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
 * @param array|null|false $prior_rows Rollup rows for the COMPARE window
 *                                     (v9.68.0 part 4): false = compare off
 *                                     (byte-identical legacy body), null = the
 *                                     prior read FAILED, [] = empty prior
 *                                     window, rows = delta chips render.
 * @param array            $opts       {
 *     @type string $basis_label Chip-tooltip basis (the shared card's exact
 *                               strings: previous period / same period last year).
 *     @type string $doorway     Pre-escaped doorway <a> for the header_meta seam.
 *     @type bool   $attn_chip   v9.69.0: prepend the amber NOTABLE chip to the
 *                               header meta (the panel's attention signal
 *                               flagged). Default false — byte-identical.
 * }
 */
function snt_analytics_render_overview_session_quality( $range_rows, $trend_rows, $trend_from, $trend_to, $prior_rows = false, $opts = array() ) {
	$title = __( 'Session quality', 'signal-and-noise-tools' );
	if ( false === $range_rows ) {
		return; // rollup module absent — production loads it unconditionally.
	}
	if ( null === $range_rows ) {
		snt_an_note_empty( $title, __( 'The durable session rollup could not be read: a read failure, not an empty window.', 'signal-and-noise-tools' ) );
		return;
	}
	$kpis = snt_analytics_overview_session_kpis( $range_rows );
	if ( null === $kpis ) {
		snt_an_note_empty( $title, __( 'No rolled-up days in this window yet: the nightly session rollup writes one row per day and class after each UTC day closes.', 'signal-and-noise-tools' ) );
		return;
	}

	// The v9.65.0 units lesson: same dashboard, same word, two units — say
	// which one this is. (Sessions here ≠ the headline's visitor-day Visits.)
	$header_meta = __( 'within-day sessions · nightly rollup: a different unit from the Overview headline&#8217;s visitor-day Visits', 'signal-and-noise-tools' );
	$doorway     = (string) ( $opts['doorway'] ?? '' );
	if ( '' !== $doorway ) {
		$header_meta .= ' · ' . $doorway; // PART A: the doorway to the full Sessions tab.
	}
	if ( ! empty( $opts['attn_chip'] ) ) {
		// v9.69.0: the amber NOTABLE chip leads the meta, coexisting with the
		// units note and the doorway in the one header_meta seam.
		$header_meta = snt_analytics_attn_chip() . ' · ' . $header_meta;
	}
	snt_an_panel_open( $title, array( 'header_meta' => $header_meta ) );

	// PART B: KPI chips vs the prior window — the same null discipline as the
	// panel's own read (false = compare off, null = failed read, [] aggregates
	// to null = no prior sessions), resolved to the same two note sentences the
	// mini tables use. Delta math is sn_analytics_delta() (no division by a
	// zero prior — its own contract).
	$prior_state = '';
	$prior_kpis  = null;
	if ( false !== $prior_rows ) {
		if ( null === $prior_rows ) {
			$prior_state = 'failed';
		} else {
			$prior_kpis = snt_analytics_overview_session_kpis( $prior_rows );
			if ( null === $prior_kpis ) {
				$prior_state = 'empty';
			}
		}
	}
	$sq_chip = static function ( $cur, $prev ) {
		$d             = sn_analytics_delta( (float) $cur, (float) $prev );
		$d['previous'] = $prev;
		return $d;
	};
	$cards = array(
		array(
			'l'        => __( 'Sessions', 'signal-and-noise-tools' ),
			'n'        => number_format_i18n( (int) $kpis['sessions'] ),
			'promoted' => true,
			/* translators: %d: number of days the nightly rollup has written in this window. */
			'sub'      => sprintf( __( '%d rolled-up days', 'signal-and-noise-tools' ), (int) $kpis['days'] ),
		),
		array(
			'l'         => __( 'Bounce', 'signal-and-noise-tools' ),
			'n'         => number_format_i18n( (float) $kpis['bounce_pct'], 1 ) . '%',
			'sub'       => __( 'single-page sessions · weighted', 'signal-and-noise-tools' ),
			// The ONE lower-is-better KPI in this strip (review 2, F1): its chip
			// colors by sentiment — rising bounce = red ▲, falling = green ▼.
			// Sessions/pages-per-session/median-duration are up-is-good (audited)
			// and keep the default direction colors.
			'sentiment' => 'down_good',
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
	);
	if ( null !== $prior_kpis ) {
		// The primitive's slot precedence (live > delta > sub) swaps the static
		// descriptor for the chip while compare is on — the shared Overview
		// card's exact behavior; the descriptor returns the moment compare is off.
		$cards[0]['delta'] = $sq_chip( (int) $kpis['sessions'], (int) $prior_kpis['sessions'] );
		$cards[1]['delta'] = $sq_chip( (float) $kpis['bounce_pct'], (float) $prior_kpis['bounce_pct'] );
		$cards[2]['delta'] = $sq_chip( (float) $kpis['ppv'], (float) $prior_kpis['ppv'] );
		$cards[3]['delta'] = $sq_chip( (int) $kpis['median_dur'], (int) $prior_kpis['median_dur'] );
	}
	snt_an_kpi_row( $cards, array(
		'empty_slot'  => 'omit',
		'basis_label' => (string) ( $opts['basis_label'] ?? '' ),
	) );
	$sq_note = snt_analytics_overview_prior_note_copy( $prior_state );
	if ( '' !== $sq_note ) {
		echo '<p class="sn-an-compare-note sn-an-prior-note">' . esc_html( $sq_note ) . '</p>';
	}

	// The 8-week bounce trend — its states are independent of the KPI read
	// above (two reads of the same table can fail separately). The window is a
	// fixed LENGTH (8 ISO weeks) ANCHORED at the range control's $to — the
	// label renders the actual endpoint rather than claiming an independence
	// it doesn't have (v9.68.0 pre-merge review, F2).
	if ( false !== $trend_rows ) {
		if ( null === $trend_rows ) {
			echo '<p class="sn-an-empty">' . esc_html( snt_an_read_failed_copy( __( 'The 8-week bounce trend', 'signal-and-noise-tools' ) ) ) . '</p>';
		} else {
			$weekly = snt_analytics_overview_weekly_bounce( $trend_rows, $trend_from, $trend_to );
			if ( count( $weekly ) < 2 ) {
				echo '<p class="sn-an-empty">' . esc_html( sprintf(
					/* translators: %d: number of rolled-up ISO weeks available so far. */
					__( 'The bounce trend needs at least two rolled-up weeks. %d so far in the 8-week window.', 'signal-and-noise-tools' ),
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
						'head'      => sprintf( __( 'Bounce: 8 weeks to %s', 'signal-and-noise-tools' ), $trend_to ),
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
		'header_meta' => __( 'cron-warmed: never queried on page load', 'signal-and-noise-tools' ),
	) );
	$cards   = array();
	$cards[] = ( null === $now )
		? array( 'l' => __( 'Active visitors', 'signal-and-noise-tools' ), 'n' => '—', 'sub' => __( 'warming: no cron sample yet', 'signal-and-noise-tools' ) )
		: array( 'l' => __( 'Active visitors', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $now ), 'sub' => __( '5-minute window', 'signal-and-noise-tools' ) );
	$cards[] = ( null === $today )
		? array( 'l' => __( 'Views today', 'signal-and-noise-tools' ), 'n' => '—', 'sub' => __( 'warming: no sample for today yet', 'signal-and-noise-tools' ) )
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
 * v9.68.0 part 4: $range + $compare arrive from the dispatcher so the body can
 * (a) build doorway links that carry the window exactly as the tab strip does,
 * and (b) render change-vs-prior when the header's compare control is on. Both
 * default to the legacy landing ('7' / 'off') so every existing caller renders
 * byte-identically (test-pinned).
 *
 * @since 9.68.0
 * @param string     $from    Window start (Y-m-d).
 * @param string     $to      Window end (Y-m-d).
 * @param string     $class   Traffic class.
 * @param int|string $range   Active range token (doorway param carry).
 * @param string     $compare Compare mode: 'prev' | 'yoy' | 'off' (default).
 */
function snt_analytics_render_view_overview( $from, $to, $class, $range = '7', $compare = 'off' ) {
	// ── PART B frame: mode + prior window derive EXACTLY as the shared
	// Overview card's one comparison frame does (inc/analytics-header-region.php
	// v9.38.0 D2): the same sn_compare vocabulary, the same range=all gate, and
	// the SAME snt_analytics_compare_window() date math — never reimplemented.
	// The prior-window READS stay durable-table-only (the load-cost rule) and
	// ride the same guarded-read bracket as the current reads.
	$compare    = (string) $compare;
	$compare_on = ( 'off' !== $compare && 'all' !== (string) $range
		&& function_exists( 'snt_analytics_compare_window' )
		&& function_exists( 'sn_analytics_delta' ) );
	$cwin        = $compare_on ? snt_analytics_compare_window( $from, $to, $compare ) : array( '', '' );
	$basis_label = ( 'yoy' === $compare )
		? __( 'same period last year', 'signal-and-noise-tools' )
		: __( 'previous period', 'signal-and-noise-tools' );

	// ── PART C frame (v9.69.0): the attention window is ALWAYS the adjacent
	// prev window, whatever the compare control says — signals run on EVERY
	// render, compare Off included (the feature's point; the compare control
	// governs only the visible chips). range=all has no adjacent window (the
	// shared card's exact gate), so attention is off there — and so is "Right
	// now" always: an instantaneous reading has no prior period.
	$attn_on = ( 'all' !== (string) $range && function_exists( 'snt_analytics_compare_window' ) );
	$awin    = $attn_on ? snt_analytics_compare_window( $from, $to, 'prev' ) : array( '', '' );

	// ── Session quality reads: the header window (KPIs) + the 8-week trend
	// window anchored at $to + (attention) the prev window; in prev compare
	// mode the chips REUSE the attention read — one query serves both — while
	// yoy adds its own chip-basis read. The attention prior read runs whenever
	// the CURRENT read SUCCEEDED — [] included (review r1 F1: an empty window
	// is a real 0 and must be judged for total collapse); only a FAILED
	// current read (unknown — no real 0 to claim) skips it. Compare chips
	// still need visible rows ($sess_ok).
	$has_rollup = function_exists( 'sn_session_rollup_read' );
	$range_rows = $has_rollup ? sn_session_rollup_read( $from, $to, $class ) : false;
	$t8_from    = gmdate( 'Y-m-d', strtotime( $to . ' 00:00:00 UTC' ) - ( SN_OVERVIEW_TREND_WEEKS * 7 - 1 ) * DAY_IN_SECONDS );
	$trend_rows = $has_rollup ? sn_session_rollup_read( $t8_from, $to, $class ) : false;
	$sess_ok    = ( $has_rollup && is_array( $range_rows ) && array() !== $range_rows );
	$sig_sess   = ( $attn_on && $has_rollup && is_array( $range_rows ) ) ? sn_session_rollup_read( $awin[0], $awin[1], $class ) : false; // false = never attempted; null = the read FAILED.
	$prior_rows = false;
	if ( $compare_on && $sess_ok ) {
		$prior_rows = ( 'prev' === $compare && false !== $sig_sess )
			? $sig_sess
			: sn_session_rollup_read( $cwin[0], $cwin[1], $class );
	}
	$sq_signal = array( 'state' => 'none', 'fact' => '' );
	$sq_synth  = false;
	if ( false !== $sig_sess ) {
		$cur_kpis = snt_analytics_overview_session_kpis( $range_rows );
		// Track the synthesis (converged review, LOW): null KPIs can arrive from
		// rows-[] OR from zero-visit rows (the rollup writes a per-day row for
		// every class even at 0 sessions) — rows-non-empty ($sess_ok) alone
		// cannot tell the second shape from a healthy window, but the renderer
		// folds on BOTH (its own null-KPIs gate), so a collapse flag from either
		// must stay strip-only: no anchor to a folded panel.
		$sq_synth = ( null === $cur_kpis );
		if ( null === $cur_kpis ) {
			// An EMPTY current rollup window (the read succeeded — $sig_sess only
			// exists when $range_rows is an array) is an ANSWER: zero sessions,
			// not a missing surface. Synthesize the zero shape so the volume
			// signal can flag a TOTAL collapse (40 → 0 must out-flag 40 → 11,
			// review r1 F1). Every ratio stays un-judged: min(0, prior) can
			// never reach the session floor.
			$cur_kpis = array( 'sessions' => 0, 'bounce_pct' => 0.0, 'ppv' => 0.0, 'median_dur' => 0 );
		}
		$sq_signal = snt_analytics_attn_session_signal(
			$cur_kpis,
			is_array( $sig_sess ) ? snt_analytics_overview_session_kpis( $sig_sess ) : null,
			null === $sig_sess
		);
	}

	// ── Mini reads: current window (top-N), then the attention prev window
	// (depth SN_OVERVIEW_PRIOR_LIMIT, the same guarded bracket), then the
	// compare-chip basis (reused in prev mode; its own read in yoy). Since
	// v9.68.1 the accessors self-report a failed read as null ([] = empty
	// window) and snt_analytics_overview_read_guarded() resolves that verdict.
	// The attention prior read runs whenever the CURRENT read succeeded — []
	// included (review r1 F1: an empty window is a real 0, judged for total
	// collapse); only a FAILED current read (unknown) skips it.
	$sources     = snt_analytics_overview_read_guarded( static function () use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $from, $to, $class, 5 ) : array();
	} );
	$sources_sig = ( $attn_on && ! $sources['failed'] ) ? snt_analytics_overview_read_guarded( static function () use ( $awin, $class ) {
		return function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $awin[0], $awin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
	} ) : null;
	$sources_prior = null;
	if ( $compare_on && array() !== $sources['rows'] ) {
		$sources_prior = ( 'prev' === $compare && null !== $sources_sig ) ? $sources_sig : snt_analytics_overview_read_guarded( static function () use ( $cwin, $class ) {
			return function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $cwin[0], $cwin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
		} );
	}
	$campaigns     = snt_analytics_overview_read_guarded( static function () use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_utm_campaigns' ) ? sn_analytics_top_utm_campaigns( $from, $to, $class, 5 ) : array();
	} );
	$campaigns_sig = ( $attn_on && ! $campaigns['failed'] ) ? snt_analytics_overview_read_guarded( static function () use ( $awin, $class ) {
		return function_exists( 'sn_analytics_top_utm_campaigns' ) ? sn_analytics_top_utm_campaigns( $awin[0], $awin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
	} ) : null;
	$campaigns_prior = null;
	if ( $compare_on && array() !== $campaigns['rows'] ) {
		$campaigns_prior = ( 'prev' === $compare && null !== $campaigns_sig ) ? $campaigns_sig : snt_analytics_overview_read_guarded( static function () use ( $cwin, $class ) {
			return function_exists( 'sn_analytics_top_utm_campaigns' ) ? sn_analytics_top_utm_campaigns( $cwin[0], $cwin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
		} );
	}
	$countries     = snt_analytics_overview_read_guarded( static function () use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'country', $from, $to, $class, 5 ) : array();
	} );
	$countries_sig = ( $attn_on && ! $countries['failed'] ) ? snt_analytics_overview_read_guarded( static function () use ( $awin, $class ) {
		return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'country', $awin[0], $awin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
	} ) : null;
	$countries_prior = null;
	if ( $compare_on && array() !== $countries['rows'] ) {
		$countries_prior = ( 'prev' === $compare && null !== $countries_sig ) ? $countries_sig : snt_analytics_overview_read_guarded( static function () use ( $cwin, $class ) {
			return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'country', $cwin[0], $cwin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
		} );
	}
	$devices     = snt_analytics_overview_read_guarded( static function () use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'device', $from, $to, $class, 5 ) : array();
	} );
	$devices_sig = ( $attn_on && ! $devices['failed'] ) ? snt_analytics_overview_read_guarded( static function () use ( $awin, $class ) {
		return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'device', $awin[0], $awin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
	} ) : null;
	$devices_prior = null;
	if ( $compare_on && array() !== $devices['rows'] ) {
		$devices_prior = ( 'prev' === $compare && null !== $devices_sig ) ? $devices_sig : snt_analytics_overview_read_guarded( static function () use ( $cwin, $class ) {
			return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'device', $cwin[0], $cwin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
		} );
	}

	// ── Entry + exit pages (read before render since v9.69.0 — their signals
	// join the same roll-up): the durable pageroles rollup, human-only (their
	// rollup carries no class column; the header meta says so).
	$entries     = snt_analytics_overview_read_guarded( static function () use ( $from, $to ) {
		return function_exists( 'sn_analytics_top_entry_pages' ) ? sn_analytics_top_entry_pages( $from, $to, 10 ) : array();
	} );
	$entries_sig = ( $attn_on && ! $entries['failed'] ) ? snt_analytics_overview_read_guarded( static function () use ( $awin ) {
		return function_exists( 'sn_analytics_top_entry_pages' ) ? sn_analytics_top_entry_pages( $awin[0], $awin[1], SN_OVERVIEW_PRIOR_LIMIT ) : array();
	} ) : null;
	$entries_prior = null;
	if ( $compare_on && array() !== $entries['rows'] ) {
		$entries_prior = ( 'prev' === $compare && null !== $entries_sig ) ? $entries_sig : snt_analytics_overview_read_guarded( static function () use ( $cwin ) {
			return function_exists( 'sn_analytics_top_entry_pages' ) ? sn_analytics_top_entry_pages( $cwin[0], $cwin[1], SN_OVERVIEW_PRIOR_LIMIT ) : array();
		} );
	}
	$exits     = snt_analytics_overview_read_guarded( static function () use ( $from, $to ) {
		return function_exists( 'sn_analytics_top_exit_pages' ) ? sn_analytics_top_exit_pages( $from, $to, 10 ) : array();
	} );
	$exits_sig = ( $attn_on && ! $exits['failed'] ) ? snt_analytics_overview_read_guarded( static function () use ( $awin ) {
		return function_exists( 'sn_analytics_top_exit_pages' ) ? sn_analytics_top_exit_pages( $awin[0], $awin[1], SN_OVERVIEW_PRIOR_LIMIT ) : array();
	} ) : null;
	$exits_prior = null;
	if ( $compare_on && array() !== $exits['rows'] ) {
		$exits_prior = ( 'prev' === $compare && null !== $exits_sig ) ? $exits_sig : snt_analytics_overview_read_guarded( static function () use ( $cwin ) {
			return function_exists( 'sn_analytics_top_exit_pages' ) ? sn_analytics_top_exit_pages( $cwin[0], $cwin[1], SN_OVERVIEW_PRIOR_LIMIT ) : array();
		} );
	}

	$src_cmp = snt_analytics_overview_row_deltas( $sources['rows'], $sources_prior, 'value' );
	$utm_cmp = snt_analytics_overview_row_deltas( $campaigns['rows'], $campaigns_prior, 'value' );
	$geo_cmp = snt_analytics_overview_row_deltas( $countries['rows'], $countries_prior, 'value' );
	$dev_cmp = snt_analytics_overview_row_deltas( $devices['rows'], $devices_prior, 'value' );
	$ent_cmp = snt_analytics_overview_row_deltas( $entries['rows'], $entries_prior, 'path' );
	$ext_cmp = snt_analytics_overview_row_deltas( $exits['rows'], $exits_prior, 'path' );

	// ── The attention roll-up, canonical panel order. 'unknown' (a FAILED
	// prior read) contributes nothing: no chip, no strip mention — and no
	// false "all calm" either; unknown renders as silence (see file header).
	// Review r1 F1: a flag from a FOLDED (empty-current) panel is STRIP-ONLY —
	// no panel surface exists to anchor, promote, or chip — marked by an empty
	// anchor. The prior depth cap rides into every table signal so absence
	// from a truncated read is bounded, never read as a real 0 (review r1 F4).
	$flags = array();
	if ( 'notable' === $sq_signal['state'] ) {
		$flags['quality'] = array( 'label' => __( 'Session quality', 'signal-and-noise-tools' ), 'anchor' => ( $sess_ok && ! $sq_synth ) ? 'sn-ov-quality' : '', 'fact' => $sq_signal['fact'] );
	}
	foreach ( array(
		'sources'   => array( __( 'Top sources', 'signal-and-noise-tools' ), snt_analytics_attn_resolve_table( $sources['rows'], $sources_sig, 'value', 5, SN_OVERVIEW_PRIOR_LIMIT ), array() !== $sources['rows'] ),
		'campaigns' => array( __( 'Campaigns (UTM)', 'signal-and-noise-tools' ), snt_analytics_attn_resolve_table( $campaigns['rows'], $campaigns_sig, 'value', 5, SN_OVERVIEW_PRIOR_LIMIT ), array() !== $campaigns['rows'] ),
		'geography' => array( __( 'Geography', 'signal-and-noise-tools' ), snt_analytics_attn_resolve_table( $countries['rows'], $countries_sig, 'value', 5, SN_OVERVIEW_PRIOR_LIMIT ), array() !== $countries['rows'] ),
		'devices'   => array( __( 'Devices', 'signal-and-noise-tools' ), snt_analytics_attn_resolve_table( $devices['rows'], $devices_sig, 'value', 5, SN_OVERVIEW_PRIOR_LIMIT ), array() !== $devices['rows'] ),
		'entry'     => array( __( 'Entry pages', 'signal-and-noise-tools' ), snt_analytics_attn_resolve_table( $entries['rows'], $entries_sig, 'path', 10, SN_OVERVIEW_PRIOR_LIMIT ), array() !== $entries['rows'] ),
		'exit'      => array( __( 'Exit pages', 'signal-and-noise-tools' ), snt_analytics_attn_resolve_table( $exits['rows'], $exits_sig, 'path', 10, SN_OVERVIEW_PRIOR_LIMIT ), array() !== $exits['rows'] ),
	) as $slug => $spec ) {
		if ( 'notable' === $spec[1]['state'] ) {
			$flags[ $slug ] = array( 'label' => $spec[0], 'anchor' => $spec[2] ? 'sn-ov-' . $slug : '', 'fact' => $spec[1]['fact'] );
		}
	}
	$quality_anchored = isset( $flags['quality'] ) && '' !== $flags['quality']['anchor'];
	$entry_anchored   = isset( $flags['entry'] ) && '' !== $flags['entry']['anchor'];
	$exit_anchored    = isset( $flags['exit'] ) && '' !== $flags['exit']['anchor'];

	// ── The strip: one triage line at the very top of the body, in-page
	// anchor links to the flagged panels (anchor-less flags — folded panels,
	// total collapse — render as plain flags). No flags → no strip at all.
	if ( array() !== $flags ) {
		snt_analytics_attn_render_strip( array_values( $flags ) );
	}

	// ── Session quality: ALWAYS first, flagged or not — flagging only gains
	// it the chip + anchor, never a new position (and a folded panel gains
	// neither: its collapse flag lives in the strip alone).
	if ( $quality_anchored ) {
		echo '<div class="sn-an-attn-anchor" id="sn-ov-quality">';
	}
	snt_analytics_render_overview_session_quality( $range_rows, $trend_rows, $t8_from, $to, $prior_rows, array(
		'basis_label' => $basis_label,
		'doorway'     => snt_analytics_overview_tab_doorway( 'visits', __( 'Sessions', 'signal-and-noise-tools' ), $range, $class, $from, $to ),
		'attn_chip'   => $quality_anchored,
	) );
	if ( $quality_anchored ) {
		echo '</div>';
	}

	// ── Mini renderers, defined ONCE and placed by the promotion geometry: a
	// closure per panel so the promoted and bento placements emit byte-identical
	// panel markup — promotion changes position, never content. PART A doorways:
	// sources/entry/exit all open the Content tab (one href, built once); the
	// other minis each open their own tab.
	$doorway_content = snt_analytics_overview_tab_doorway( 'content', __( 'Content', 'signal-and-noise-tools' ), $range, $class, $from, $to );
	$attn_meta       = static function ( $chip, $meta ) {
		return ( $chip ? snt_analytics_attn_chip() . ' · ' : '' ) . $meta;
	};
	$mini_render = array(
		'sources'   => function ( $chip ) use ( $sources, $src_cmp, $doorway_content, $attn_meta ) {
			snt_analytics_render_dim_table(
				__( 'Top sources', 'signal-and-noise-tools' ),
				$sources['rows'],
				$sources['failed']
					? snt_an_read_failed_copy( __( 'The durable referrer rollup', 'signal-and-noise-tools' ) )
					: __( 'No referrer rows in the durable rollup for this range yet.', 'signal-and-noise-tools' ),
				array(),
				'',
				5,
				array(
					'header_meta' => $attn_meta( $chip, $doorway_content ),
					'deltas'      => $src_cmp['deltas'],
					'prior_note'  => snt_analytics_overview_prior_note_copy( $src_cmp['state'] ),
				)
			);
		},
		'campaigns' => function ( $chip ) use ( $campaigns, $utm_cmp, $range, $class, $from, $to, $attn_meta ) {
			snt_analytics_render_dim_table(
				__( 'Campaigns (UTM)', 'signal-and-noise-tools' ),
				$campaigns['rows'],
				$campaigns['failed']
					? snt_an_read_failed_copy( __( 'The durable UTM rollup', 'signal-and-noise-tools' ) )
					: __( 'No UTM-tagged traffic in the durable rollup for this range yet.', 'signal-and-noise-tools' ),
				array(),
				'',
				5,
				array(
					'header_meta' => $attn_meta( $chip, snt_analytics_overview_tab_doorway( 'campaigns', __( 'Campaigns', 'signal-and-noise-tools' ), $range, $class, $from, $to ) ),
					'deltas'      => $utm_cmp['deltas'],
					'prior_note'  => snt_analytics_overview_prior_note_copy( $utm_cmp['state'] ),
				)
			);
		},
		'geography' => function ( $chip ) use ( $countries, $geo_cmp, $range, $class, $from, $to, $attn_meta ) {
			snt_analytics_render_dim_table(
				__( 'Geography', 'signal-and-noise-tools' ),
				$countries['rows'],
				$countries['failed']
					? snt_an_read_failed_copy( __( 'The durable country rollup', 'signal-and-noise-tools' ) )
					: __( 'No country rows in the durable rollup for this range yet.', 'signal-and-noise-tools' ),
				array(),
				'',
				5,
				array(
					'header_meta' => $attn_meta( $chip, snt_analytics_overview_tab_doorway( 'geography', __( 'Geography', 'signal-and-noise-tools' ), $range, $class, $from, $to ) ),
					'deltas'      => $geo_cmp['deltas'],
					'prior_note'  => snt_analytics_overview_prior_note_copy( $geo_cmp['state'] ),
				)
			);
		},
		'devices'   => function ( $chip ) use ( $devices, $dev_cmp, $range, $class, $from, $to, $attn_meta ) {
			snt_analytics_render_dim_table(
				__( 'Devices', 'signal-and-noise-tools' ),
				$devices['rows'],
				$devices['failed']
					? snt_an_read_failed_copy( __( 'The durable device rollup', 'signal-and-noise-tools' ) )
					: __( 'No device rows in the durable rollup for this range yet.', 'signal-and-noise-tools' ),
				array(),
				'',
				5,
				array(
					'header_meta' => $attn_meta( $chip, snt_analytics_overview_tab_doorway( 'technology', __( 'Technology', 'signal-and-noise-tools' ), $range, $class, $from, $to ) ),
					'deltas'      => $dev_cmp['deltas'],
					'prior_note'  => snt_analytics_overview_prior_note_copy( $dev_cmp['state'] ),
				)
			);
		},
	);

	// ── Entry + exit as ONE placeable unit (they promote as a PAIR if either
	// flags — the owner-approved geometry). Same null-verdict resolution as
	// the bento: a failed read (accessor null) folds as "could not be read".
	$pair_render = function () use ( $entries, $exits, $ent_cmp, $ext_cmp, $doorway_content, $entry_anchored, $exit_anchored, $attn_meta ) {
		echo '<div class="sn-an-grid sn-an-overview-pair">';
		if ( $entries['failed'] ) {
			snt_an_note_empty( __( 'Entry pages', 'signal-and-noise-tools' ), snt_an_read_failed_copy( __( 'The durable entry-pages rollup', 'signal-and-noise-tools' ) ) );
		} else {
			if ( $entry_anchored ) {
				echo '<div class="sn-an-attn-anchor" id="sn-ov-entry">';
			}
			snt_analytics_render_pageroles_table(
				$entries['rows'],
				'entry',
				$attn_meta( $entry_anchored, __( 'human traffic · durable rollup', 'signal-and-noise-tools' ) . ' · ' . $doorway_content ),
				array(
					'deltas'     => $ent_cmp['deltas'],
					'prior_note' => snt_analytics_overview_prior_note_copy( $ent_cmp['state'] ),
				)
			);
			if ( $entry_anchored ) {
				echo '</div>';
			}
		}
		if ( $exits['failed'] ) {
			snt_an_note_empty( __( 'Exit pages', 'signal-and-noise-tools' ), snt_an_read_failed_copy( __( 'The durable exit-pages rollup', 'signal-and-noise-tools' ) ) );
		} else {
			if ( $exit_anchored ) {
				echo '<div class="sn-an-attn-anchor" id="sn-ov-exit">';
			}
			snt_analytics_render_pageroles_table(
				$exits['rows'],
				'exit',
				$attn_meta( $exit_anchored, __( 'human traffic · nightly session bridge', 'signal-and-noise-tools' ) . ' · ' . $doorway_content ),
				array(
					'deltas'     => $ext_cmp['deltas'],
					'prior_note' => snt_analytics_overview_prior_note_copy( $ext_cmp['state'] ),
				)
			);
			if ( $exit_anchored ) {
				echo '</div>';
			}
		}
		echo '</div>';
	};

	// ── Promotion (the approved AFTER geometry, generalized): flagged minis
	// leave the bento for FULL WIDTH directly beneath Session quality, in
	// canonical relative order; the entry/exit pair promotes as a unit.
	// Strip-only flags (empty anchor — folded panels) never promote: there is
	// no panel markup to move (review r1 F1).
	$mini_order = array( 'sources', 'campaigns', 'geography', 'devices' );
	foreach ( $mini_order as $slug ) {
		if ( isset( $flags[ $slug ] ) && '' !== $flags[ $slug ]['anchor'] ) {
			echo '<div class="sn-an-attn-anchor" id="sn-ov-' . esc_attr( $slug ) . '">';
			$mini_render[ $slug ]( true );
			echo '</div>';
		}
	}
	$pair_promoted = ( $entry_anchored || $exit_anchored );
	if ( $pair_promoted ) {
		$pair_render();
	}

	// ── Right now: the cron-warmed transient pair (realtime is class-aware;
	// views-today is human-only by construction and its card says so). Never
	// promotes: instantaneous — it cannot be notable vs a prior period.
	snt_analytics_render_overview_rightnow(
		function_exists( 'sn_analytics_realtime' ) ? sn_analytics_realtime( $class ) : null,
		function_exists( 'sn_analytics_views_today' ) ? sn_analytics_views_today() : null
	);

	// ── The bento: the UNPROMOTED minis, re-packed into the standard two
	// columns (first half left, rest right). With all four unpromoted this is
	// byte-identical to the v9.68.1 layout — sources + campaigns left,
	// geography + devices right (the quiet-week shield). A strip-only flag
	// (folded panel) keeps its slot: it emits nothing here anyway, and
	// excluding it would silently shift the quiet panels' packing.
	$bento = array_values( array_filter( $mini_order, static function ( $slug ) use ( $flags ) {
		return ! isset( $flags[ $slug ] ) || '' === $flags[ $slug ]['anchor'];
	} ) );
	if ( array() !== $bento ) {
		$left  = array_slice( $bento, 0, (int) ceil( count( $bento ) / 2 ) );
		$right = array_slice( $bento, count( $left ) );
		echo '<div class="sn-an-overview-bento">';
		echo '<div class="sn-an-bento-col">';
		foreach ( $left as $slug ) {
			$mini_render[ $slug ]( false );
		}
		echo '</div>';
		if ( array() !== $right ) {
			echo '<div class="sn-an-bento-col">';
			foreach ( $right as $slug ) {
				$mini_render[ $slug ]( false );
			}
			echo '</div>';
		}
		echo '</div>';
	}

	// ── Entry + exit in their standard spot when neither flags.
	if ( ! $pair_promoted ) {
		$pair_render();
	}

	snt_an_flush_empty_fold();
}

