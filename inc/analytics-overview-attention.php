<?php
/**
 * Signal & Noise Tools — Overview attention signals (v9.69.0).
 *
 * The pure signal layer behind the Overview's triage surface: per-panel
 * "notable movement" verdicts vs the PREVIOUS period, the amber NOTABLE chip,
 * and the needs-attention strip. Computed on EVERY Overview render — compare
 * Off included; that is the feature's point. The header's compare control
 * governs only the visible delta chips: signals always compare against the
 * adjacent prev window (via the real snt_analytics_compare_window(), at the
 * caller — zero new date math here).
 *
 * THRESHOLDS — a panel is NOTABLE only when its headline movement clears BOTH
 * a percentage bar AND an absolute floor (constants below, each with its
 * rationale). 1 view → 2 views must NEVER flag: that is +100% on a base too
 * small to mean anything.
 *
 * SENTIMENT — attention means "needs you", not "changed":
 *   Sessions (volume)      → BOTH directions flag (a surge may be bots or a
 *                            hit; a collapse may be breakage — either needs a
 *                            look before it needs a verdict).
 *   Bounce                 → RISE only (worsening). A falling bounce is good
 *                            news; good news is not attention.
 *   Pages / session        → FALL only (readers going shallower).
 *   Median duration        → FALL only (readers leaving sooner).
 *   Table views (per row)  → BOTH directions (same volume logic as sessions).
 *   Right now              → never judged: instantaneous readings have no
 *                            prior period to be notable against.
 *
 * NULL DISCIPLINE — the non-answers, each with its own render:
 *   FAILED prior read → attention UNKNOWN: no chip, no strip mention, and no
 *     false "all calm" claim either — unknown renders as silence, exactly like
 *     a quiet week, because claiming EITHER state would fabricate knowledge.
 *   EMPTY prior window ([]) → no comparison basis: indistinguishable from
 *     pre-feature history, so a "surge vs nothing" would manufacture
 *     attention. Never a flag.
 *   FAILED current read → none: unknown current — no real 0 exists to claim a
 *     collapse against; the panel's own failure fold tells that story.
 *   EMPTY current window ([], read succeeded) → an ANSWER, not a missing
 *     surface (review r1 F1): the STRONGEST collapse. It flags when the prior
 *     window cleared the views floor — strip-only at the caller (the folded
 *     panel offers no anchor/chip surface), fact "views N → none recorded".
 *
 * THE OUT-OF-TOP BOUND — a key absent from the current top-N is only known to
 * sit at-or-below the table's minimum visible views, so its collapse is
 * claimed ONLY when even that upper bound proves the percentage bar (a sound
 * lower bound on the drop — never an assumed fall to zero). Mirrored on the
 * prior side (review r1 F4): a key absent from a DEPTH-CAPPED prior read is
 * only known ≤ that read's minimum visible views, so its surge is claimed
 * against that bound and the fact never fabricates a "views 0 → N".
 *
 * Pure functions: no WP reads, no wpdb — require()-able by tests directly.
 * i18n + escaping helpers are the only WP surface.
 *
 * @package SignalNoiseTools
 * @since 9.69.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The relative bar: a quarter-shift vs the previous period. Anything smaller
// is ordinary week-to-week noise on a small personal site.
const SN_OVERVIEW_ATTN_PCT = 25;

// The table-row absolute floor: max(current, prior) views must reach it, so a
// percentage computed on a nothing base can never flag (the 1→2 shield).
const SN_OVERVIEW_ATTN_VIEWS_FLOOR = 5;

// Bounce moves in percentage POINTS, not relative percent — a relative bar on
// a ratio double-counts (50%→62.5% is "+25% relative" but only 12.5 points).
const SN_OVERVIEW_ATTN_BOUNCE_PTS = 10.0;

// The session floor, applied two ways: max(cur, prior) must reach it before
// the Sessions volume signal is judged, and min(cur, prior) must reach it
// before any session-quality RATIO (bounce, ppv, duration) is judged — a
// ratio over a handful of sessions swings wildly and would lie.
const SN_OVERVIEW_ATTN_MIN_SESSIONS = 10;

/**
 * Notable-movement verdict for one mini table (views-keyed rows) vs its
 * prev-window read.
 *
 * @since 9.69.0
 * @param array      $rows       Current-window rows (the rendered top-N).
 * @param array|null $prior_rows Prev-window rows (depth-bounded at the
 *                               caller), or null = the prior read FAILED.
 * @param string     $key_field  Dimension key field ('value' | 'path').
 * @param int        $top_n      The visible top-N (names the out-of-top fact).
 * @param int        $prior_cap  The prior read's depth cap (review r1 F4):
 *                               when the read returned cap-many rows it may be
 *                               TRUNCATED, so absence stops meaning "a real 0"
 *                               and becomes "≤ the read's minimum". 0 (the
 *                               default) = treat the read as exhaustive.
 * @return array{state:string, fact:string} state ∈ 'unknown'|'none'|'notable';
 *                                          fact '' unless notable.
 */
function snt_analytics_attn_table_signal( $rows, $prior_rows, $key_field, $top_n, $prior_cap = 0 ) {
	if ( ! is_array( $prior_rows ) ) {
		return array( 'state' => 'unknown', 'fact' => '' ); // failed prior read — no claim either way.
	}
	if ( array() === $prior_rows ) {
		return array( 'state' => 'none', 'fact' => '' ); // empty prior window — no basis, never a fabricated surge.
	}
	if ( ! is_array( $rows ) ) {
		return array( 'state' => 'none', 'fact' => '' ); // defensive: not a read verdict — nothing to judge.
	}
	$pri = array();
	foreach ( $prior_rows as $r ) {
		if ( is_array( $r ) && isset( $r[ $key_field ] ) ) {
			$pri[ (string) $r[ $key_field ] ] = max( 0, (int) ( $r['views'] ?? 0 ) );
		}
	}
	if ( array() === $rows ) {
		// Review r1 F1 — an EMPTY current window (the read SUCCEEDED) is an
		// ANSWER: zero views recorded, the STRONGEST possible collapse. It must
		// out-flag a partial one (40 → none is louder than 40 → 11), floored on
		// the prior window's total so a nothing-week prior stays quiet. The
		// panel itself folds, so the caller surfaces this strip-only (no anchor).
		$total = array_sum( $pri );
		if ( $total >= SN_OVERVIEW_ATTN_VIEWS_FLOOR ) {
			return array(
				'state' => 'notable',
				/* translators: %s: total prior-window views across the whole table (the current window recorded none). */
				'fact'  => sprintf( __( 'views %s → none recorded', 'signal-and-noise-tools' ), number_format_i18n( $total ) ),
			);
		}
		return array( 'state' => 'none', 'fact' => '' ); // a sub-floor prior folds quietly — the 1→2 shield holds at zero.
	}
	$cur     = array();
	$cur_min = null;
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) || ! isset( $r[ $key_field ] ) ) {
			continue;
		}
		$v                              = max( 0, (int) ( $r['views'] ?? 0 ) );
		$cur[ (string) $r[ $key_field ] ] = $v;
		$cur_min                        = ( null === $cur_min ) ? $v : min( $cur_min, $v );
	}
	if ( array() === $cur || array() === $pri ) {
		return array( 'state' => 'none', 'fact' => '' );
	}

	// Review r1 F4 — a prior read that returned cap-many rows may be TRUNCATED:
	// a key absent from it is only known to sit ≤ the read's minimum visible
	// views (the out-of-top bound, mirrored onto the prior side).
	$pri_min       = (int) min( $pri );
	$pri_truncated = ( $prior_cap > 0 && count( $pri ) >= $prior_cap );

	$best_mag  = 0;
	$best_fact = '';
	foreach ( $cur as $key => $c ) {
		if ( ! isset( $pri[ $key ] ) && $pri_truncated ) {
			// The key's true prior views are unknown but provably ≤ $pri_min:
			// claim the surge ONLY when even that upper bound proves the bar,
			// and never let the fact fabricate a "views 0 → N".
			$bound = max( 0, $pri_min );
			$rise  = $c - $bound; // a sound LOWER bound on the rise.
			if ( $c < SN_OVERVIEW_ATTN_VIEWS_FLOOR || $rise <= 0 ) {
				continue;
			}
			if ( $bound > 0 && $rise * 100 < SN_OVERVIEW_ATTN_PCT * $bound ) {
				continue;
			}
			if ( $rise > $best_mag ) {
				$best_mag  = $rise;
				/* translators: 1: dimension value (a referrer, country, device, campaign, or path). 2: the prior-window read depth. 3: current-window views. */
				$best_fact = sprintf( __( '%1$s: below the prior top %2$s → views %3$s', 'signal-and-noise-tools' ), (string) $key, number_format_i18n( $prior_cap ), number_format_i18n( $c ) );
			}
			continue;
		}
		$p = $pri[ $key ] ?? 0; // absent from an EXHAUSTIVE (below-cap) prior read = a real 0 (the chips' "new" semantics).
		if ( max( $c, $p ) < SN_OVERVIEW_ATTN_VIEWS_FLOOR ) {
			continue; // the absolute floor: percentages on nothing never flag.
		}
		$moved = ( 0 === $p ) ? ( $c > 0 ) : ( abs( $c - $p ) * 100 >= SN_OVERVIEW_ATTN_PCT * $p );
		if ( ! $moved ) {
			continue;
		}
		$mag = abs( $c - $p );
		if ( $mag > $best_mag ) {
			$best_mag  = $mag;
			/* translators: 1: dimension value (a referrer, country, device, campaign, or path). 2: prior-window views. 3: current-window views. */
			$best_fact = sprintf( __( '%1$s: views %2$s → %3$s', 'signal-and-noise-tools' ), (string) $key, number_format_i18n( $p ), number_format_i18n( $c ) );
		}
	}
	foreach ( $pri as $key => $p ) {
		if ( isset( $cur[ $key ] ) ) {
			continue;
		}
		if ( $p < SN_OVERVIEW_ATTN_VIEWS_FLOOR ) {
			continue;
		}
		// The key fell out of the current top-N: its true current views are
		// unknown but provably ≤ the table's minimum visible views. Flag ONLY
		// when even that upper bound proves the percentage bar — a sound lower
		// bound on the drop, never an assumed collapse to 0.
		$bound = max( 0, (int) $cur_min );
		$drop  = $p - $bound;
		if ( $drop * 100 < SN_OVERVIEW_ATTN_PCT * $p ) {
			continue;
		}
		if ( $drop > $best_mag ) {
			$best_mag  = $drop;
			/* translators: 1: dimension value (a referrer, country, device, campaign, or path). 2: prior-window views. 3: the table's visible row count. */
			$best_fact = sprintf( __( '%1$s: %2$s views → out of the top %3$s', 'signal-and-noise-tools' ), (string) $key, number_format_i18n( $p ), number_format_i18n( $top_n ) );
		}
	}

	return ( '' !== $best_fact )
		? array( 'state' => 'notable', 'fact' => $best_fact )
		: array( 'state' => 'none', 'fact' => '' );
}

/**
 * Notable-movement verdict for the Session quality panel's window KPIs vs the
 * prev window's. Sentiment-aware per metric (see the file header's table);
 * the driving fact follows the documented priority sessions > bounce > ppv >
 * median duration — volume anomalies outrank the ratios they distort.
 *
 * @since 9.69.0
 * @param array|null $kpis         snt_analytics_overview_session_kpis() for
 *                                 the current window (null = nothing to judge).
 * @param array|null $prior_kpis   Same for the prev window (null = no basis —
 *                                 an empty prior rollup window aggregates to
 *                                 null and is indistinguishable from
 *                                 pre-feature history).
 * @param bool       $prior_failed True when the prev-window rollup READ failed
 *                                 (accessor null) — attention UNKNOWN.
 * @return array{state:string, fact:string}
 */
function snt_analytics_attn_session_signal( $kpis, $prior_kpis, $prior_failed ) {
	if ( $prior_failed ) {
		return array( 'state' => 'unknown', 'fact' => '' );
	}
	if ( ! is_array( $kpis ) || ! is_array( $prior_kpis ) ) {
		return array( 'state' => 'none', 'fact' => '' );
	}
	$cs = max( 0, (int) ( $kpis['sessions'] ?? 0 ) );
	$ps = max( 0, (int) ( $prior_kpis['sessions'] ?? 0 ) );

	// 1. Sessions volume — both directions, floored on the LARGER window.
	if ( max( $cs, $ps ) >= SN_OVERVIEW_ATTN_MIN_SESSIONS ) {
		$moved = ( 0 === $ps ) ? ( $cs > 0 ) : ( abs( $cs - $ps ) * 100 >= SN_OVERVIEW_ATTN_PCT * $ps );
		if ( $moved ) {
			return array(
				'state' => 'notable',
				/* translators: 1: prior-window session count. 2: current-window session count. */
				'fact'  => sprintf( __( 'sessions %1$s → %2$s', 'signal-and-noise-tools' ), number_format_i18n( $ps ), number_format_i18n( $cs ) ),
			);
		}
	}

	// 2-4. Ratios — judged only when BOTH windows carry real volume.
	if ( min( $cs, $ps ) < SN_OVERVIEW_ATTN_MIN_SESSIONS ) {
		return array( 'state' => 'none', 'fact' => '' );
	}
	$cb = (float) ( $kpis['bounce_pct'] ?? 0 );
	$pb = (float) ( $prior_kpis['bounce_pct'] ?? 0 );
	if ( $cb - $pb >= SN_OVERVIEW_ATTN_BOUNCE_PTS ) { // worsening only — improvement is not attention.
		return array(
			'state' => 'notable',
			/* translators: 1: prior-window bounce percentage. 2: current-window bounce percentage. */
			'fact'  => sprintf( __( 'bounce %1$s%% → %2$s%%', 'signal-and-noise-tools' ), number_format_i18n( $pb, 1 ), number_format_i18n( $cb, 1 ) ),
		);
	}
	$cp = (float) ( $kpis['ppv'] ?? 0 );
	$pp = (float) ( $prior_kpis['ppv'] ?? 0 );
	if ( $pp > 0 && ( $pp - $cp ) * 100 >= SN_OVERVIEW_ATTN_PCT * $pp ) { // falling only.
		return array(
			'state' => 'notable',
			/* translators: 1: prior-window pages-per-session. 2: current-window pages-per-session. */
			'fact'  => sprintf( __( 'pages/session %1$s → %2$s', 'signal-and-noise-tools' ), number_format_i18n( $pp, 2 ), number_format_i18n( $cp, 2 ) ),
		);
	}
	$cd = max( 0, (int) ( $kpis['median_dur'] ?? 0 ) );
	$pd = max( 0, (int) ( $prior_kpis['median_dur'] ?? 0 ) );
	if ( $pd > 0 && ( $pd - $cd ) * 100 >= SN_OVERVIEW_ATTN_PCT * $pd ) { // falling only.
		return array(
			'state' => 'notable',
			/* translators: 1: prior-window median session duration in seconds. 2: current-window median session duration in seconds. */
			'fact'  => sprintf( __( 'median duration %1$ss → %2$ss', 'signal-and-noise-tools' ), number_format_i18n( $pd ), number_format_i18n( $cd ) ),
		);
	}
	return array( 'state' => 'none', 'fact' => '' );
}

/**
 * Bridge a mini panel's guarded prev-window read (snt_analytics_overview_
 * read_guarded shape) into the table signal: null = no read attempted
 * (attention gated off, or the current read FAILED — unknown current, no real
 * 0 to claim a collapse against) → none; a guarded FAILED prior read →
 * unknown; success → the rows judge (an empty CURRENT window included: the
 * total-collapse answer travels through this bridge, review r1 F1).
 *
 * @since 9.69.0
 * @param array      $rows      Current-window rows.
 * @param array|null $sig_read  {rows, failed} or null when never attempted.
 * @param string     $key_field Dimension key field.
 * @param int        $top_n     Visible row count.
 * @param int        $prior_cap The prior read's depth cap (review r1 F4 — see
 *                              snt_analytics_attn_table_signal()).
 * @return array{state:string, fact:string}
 */
function snt_analytics_attn_resolve_table( $rows, $sig_read, $key_field, $top_n, $prior_cap = 0 ) {
	if ( ! is_array( $sig_read ) ) {
		return array( 'state' => 'none', 'fact' => '' );
	}
	return snt_analytics_attn_table_signal( $rows, ! empty( $sig_read['failed'] ) ? null : ( $sig_read['rows'] ?? array() ), $key_field, $top_n, $prior_cap );
}

/**
 * The amber NOTABLE chip — pre-escaped markup for the panel primitive's
 * header_meta seam (kses'd there), coexisting with the doorway link.
 * Uppercase comes from CSS so the msgid stays a translatable word.
 *
 * @since 9.69.0
 * @return string
 */
function snt_analytics_attn_chip() {
	return '<span class="sn-an-attn-chip">▲ ' . esc_html__( 'Notable', 'signal-and-noise-tools' ) . '</span>';
}

/**
 * The needs-attention strip: one line at the very top of the Overview body —
 * "Needs attention: {Panel} — {driving fact} · …" with in-page anchor links to
 * the flagged panels. No flags → no output (not even an empty shell): the
 * quiet-week shield's first rule.
 *
 * @since 9.69.0
 * @param array $items List of {label:string, anchor:string, fact:string}, in
 *                     canonical panel order (the caller owns the order). An
 *                     EMPTY anchor marks a flag from a FOLDED panel (total
 *                     collapse, review r1 F1): no surface exists to jump to,
 *                     so it renders as a plain flag — never a dead "#" link.
 * @return void
 */
function snt_analytics_attn_render_strip( $items ) {
	$items = is_array( $items ) ? $items : array();
	$parts = array();
	foreach ( $items as $item ) {
		if ( ! is_array( $item ) || '' === (string) ( $item['label'] ?? '' ) ) {
			continue;
		}
		$anchor = (string) ( $item['anchor'] ?? '' );
		$part   = ( '' !== $anchor )
			? '<a class="sn-an-attn-link" href="' . esc_url( '#' . $anchor ) . '">' . esc_html( (string) $item['label'] ) . '</a>'
			: '<span class="sn-an-attn-flag">' . esc_html( (string) $item['label'] ) . '</span>';
		if ( '' !== (string) ( $item['fact'] ?? '' ) ) {
			$part .= '. <span class="sn-an-attn-fact">' . esc_html( (string) $item['fact'] ) . '</span>';
		}
		$parts[] = $part;
	}
	if ( array() === $parts ) {
		return;
	}
	echo '<div class="sn-an-attn-strip"><span class="sn-an-attn-label">' . esc_html__( 'Needs attention:', 'signal-and-noise-tools' ) . '</span> '
		. implode( ' &middot; ', $parts ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each part assembled from esc_url/esc_html'd fragments above; the separator is a static entity (the empty-fold idiom).
		. '</div>';
}
