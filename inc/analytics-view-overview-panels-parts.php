<?php
/**
 * Signal & Noise Tools: Analytics Overview, the session quality and Right now panels.
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
function snt_analytics_render_overview_rightnow( $now, $today, $class = 'human' ) {
	// data-sn-live: both figures update in place (assets/live-now.js).
	$live = static fn( $k ) => function_exists( 'sn_analytics_live_attrs' ) ? sn_analytics_live_attrs( $k, 'today' === $k ? 'human' : $class ) : array();
	snt_an_panel_open( __( 'Right now', 'signal-and-noise-tools' ), array(
		'panel_class' => 'sn-an-rightnow',
		'header_meta' => __( 'cron-warmed: never queried on page load', 'signal-and-noise-tools' ),
	) );
	$cards   = array();
	$cards[] = ( null === $now )
		? array( 'l' => __( 'Active visitors', 'signal-and-noise-tools' ), 'n' => __( 'not read', 'signal-and-noise-tools' ), 'sub' => __( 'warming: no cron sample yet', 'signal-and-noise-tools' ), 'attrs' => $live( 'now' ) )
		: array( 'l' => __( 'Active visitors', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $now ), 'sub' => __( '5-minute window', 'signal-and-noise-tools' ), 'attrs' => $live( 'now' ) );
	$cards[] = ( null === $today )
		? array( 'l' => __( 'Views today', 'signal-and-noise-tools' ), 'n' => __( 'not read', 'signal-and-noise-tools' ), 'sub' => __( 'warming: no sample for today yet', 'signal-and-noise-tools' ), 'attrs' => $live( 'today' ) )
		: array( 'l' => __( 'Views today', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $today ), 'sub' => __( 'human pageviews · site-local day', 'signal-and-noise-tools' ), 'attrs' => $live( 'today' ) );
	snt_an_kpi_row( $cards, array( 'empty_slot' => 'omit' ) );
	// The last hour, Being read now and Arrived from, filled live (inc/analytics-live-admin.php).
	if ( function_exists( 'sn_analytics_live_admin_html' ) ) {
		echo sn_analytics_live_admin_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every piece is escaped inside the builder (declared an escaping function in phpcs.xml.dist; Plugin Check reads its own ruleset).
	}
	snt_an_panel_close();
}

