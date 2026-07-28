<?php
/**
 * Signal & Noise Tools, Machine Readers: crawler-family volume deltas (R3).
 *
 * The question the tables cannot answer at a glance: which crawler families
 * moved. A pure detector diffs the current window against the window before it,
 * per family, and returns cards in the deterministic shape the analytics
 * recommendation rules already use (inc/analytics-recommendations.php:
 * id / title / detail / count / action_url / action_label, plus the numbers the
 * card is built from).
 *
 * Honest by construction:
 *  - crawler activity is "reads", never visits and never traffic (nobody
 *    visited; a machine fetched a URL),
 *  - user agents are self-reported, so a card reports observed volume and
 *    never claims proven identity,
 *  - a family with no prior-window reads gets NO card, because there is no
 *    honest percentage to state against a zero baseline (a genuinely new
 *    crawler is a different signal and is deliberately out of scope here),
 *  - an entirely missing window (either side) is silent rather than a
 *    comparison against nothing.
 *
 * Thresholds (all three must clear, and they are deliberately blunt: this is a
 * glance card, not a statistical claim):
 *  - SN_MR_DELTA_MIN_READS: the larger window must carry at least this many
 *    reads for the family. Crawler counts are spiky and long-tailed, so a
 *    1 -> 3 move is noise that must not shout.
 *  - SN_MR_DELTA_MIN_ABS: the change itself must be at least this many reads,
 *    so a small family cannot ride a large percentage into a card.
 *  - SN_MR_DELTA_PCT: the change must be at least this percent of the prior
 *    window, so a big family cannot ride a rounding wobble into a card.
 *
 * Pure: the detector takes rows and returns cards. No fetch, no option read, no
 * output. Input rows are never mutated. Escaping happens at the render sink
 * below, and again wherever a host page re-renders these strings.
 *
 * Paired fixture: tests/machine-readers-insights.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Minimum reads in the larger of the two windows before a family can card. */
const SN_MR_DELTA_MIN_READS = 25;

/** Minimum absolute change in reads before a family can card. */
const SN_MR_DELTA_MIN_ABS = 10;

/** Minimum change as a percent of the prior window before a family can card. */
const SN_MR_DELTA_PCT = 40;

/** Cards returned at most, ranked by absolute change (the glance budget). */
const SN_MR_DELTA_CARD_MAX = 3;

/**
 * Split one fetched row set into the current window and the window before it.
 *
 * Both windows are $days long and adjacent: current is [today-(days-1), today],
 * prior is [today-(2*days-1), today-days]. Rows outside both, rows dated in the
 * future, and rows whose day failed normalization ('' from
 * snt_mr_normalize_rows) belong to neither and are dropped: a row that cannot
 * be placed in time must not silently land in a window and skew its total.
 *
 * Pure: Y-m-d strings compare correctly as strings, so the split is a filter,
 * and $rows is never mutated.
 *
 * @param array  $rows  Normalized rows (snt_mr_normalize_rows shape).
 * @param int    $days  Window length in days (>= 1).
 * @param string $today Reference end day, Y-m-d. The sensor aggregates by UTC
 *                      day, so callers pass a UTC date.
 * @return array{current:array,prior:array}
 */
function snt_mr_split_windows( $rows, $days, $today ) {
	$days = max( 1, (int) $days );
	$end  = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $today ) ? (string) $today : gmdate( 'Y-m-d' );
	$ts   = (int) strtotime( $end . ' 00:00:00 UTC' );

	$cur_from = gmdate( 'Y-m-d', $ts - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
	$pri_to   = gmdate( 'Y-m-d', $ts - ( $days * DAY_IN_SECONDS ) );
	$pri_from = gmdate( 'Y-m-d', $ts - ( ( ( 2 * $days ) - 1 ) * DAY_IN_SECONDS ) );

	$current = array();
	$prior   = array();
	foreach ( (array) $rows as $r ) {
		$day = is_array( $r ) ? (string) ( $r['day'] ?? '' ) : '';
		if ( '' === $day ) {
			continue;
		}
		if ( strcmp( $day, $cur_from ) >= 0 && strcmp( $day, $end ) <= 0 ) {
			$current[] = $r;
		} elseif ( strcmp( $day, $pri_from ) >= 0 && strcmp( $day, $pri_to ) <= 0 ) {
			$prior[] = $r;
		}
	}
	return array(
		'current' => $current,
		'prior'   => $prior,
	);
}

/**
 * Sum hits per family for a window, reusing the render lane's aggregator so
 * there is exactly one definition of "reads per family" in the plugin.
 *
 * @param array $rows Normalized rows.
 * @return array<string,int> Family => reads.
 */
function snt_mr_family_totals( $rows ) {
	if ( function_exists( 'snt_mr_sum_hits_by' ) ) {
		return snt_mr_sum_hits_by( $rows, 'family' );
	}
	// Only reached by a harness that loads this module alone; production always
	// has the render lane loaded (see the require chain in the plugin bootstrap).
	$totals = array();
	foreach ( (array) $rows as $r ) {
		$key = is_array( $r ) ? (string) ( $r['family'] ?? '' ) : '';
		if ( '' === $key ) {
			continue;
		}
		$totals[ $key ] = (int) ( $totals[ $key ] ?? 0 ) + (int) ( $r['hits'] ?? 0 );
	}
	arsort( $totals );
	return $totals;
}

/**
 * The detector: one card per crawler family whose reads moved by a meaningful
 * margin between the two windows, ranked by absolute change and capped.
 *
 * Silence is the default. Returns an empty array when either window carries no
 * reads at all (missing data is not a comparison), and skips any family that
 * read nothing in the prior window (no honest percentage against zero) or that
 * fails any of the three thresholds documented at the top of this file.
 *
 * Pure: no WP reads beyond admin_url() for the deep link (guarded), no output,
 * no mutation of either input array.
 *
 * @param array $current_rows Normalized rows for the current window.
 * @param array $prior_rows   Normalized rows for the window before it.
 * @param int   $days         Length of each window, for the card copy.
 * @return array<int,array<string,mixed>> Cards, biggest absolute move first.
 */
function snt_mr_family_delta_cards( $current_rows, $prior_rows, $days ) {
	$days    = max( 1, (int) $days );
	$cur_tot = snt_mr_family_totals( $current_rows );
	$pri_tot = snt_mr_family_totals( $prior_rows );

	// A window with no reads is missing data, not a baseline of zero. Both
	// sides must have something to say before anything is compared. The
	// per-family zero-baseline rule below reaches the same answer on its own
	// (verified by mutation, not assumed): this early return states the
	// contract where a reader looks for it and skips the loop entirely.
	if ( array_sum( $cur_tot ) < 1 || array_sum( $pri_tot ) < 1 ) {
		return array();
	}

	// v10.2.0 (verifier finding): a window that merely CONTAINS data is not a
	// comparable window. If the prior window was observed on far fewer days
	// than the current one — the sensor was deployed mid-window, or the worker
	// was down — then a rise measured against it is mostly missing history,
	// not more crawling. Reproduced: identical per-day rates observed on 5 of
	// 30 prior days vs 30 of 30 current days rendered "up 500%". Require the
	// prior window to have been observed on at least half the days the current
	// one was, and stay silent otherwise. Silence beats a fabricated trend.
	$cur_days = count( array_unique( array_filter( array_map( static function ( $r ) {
		return (string) ( $r['day'] ?? '' );
	}, (array) $current_rows ) ) ) );
	$pri_days = count( array_unique( array_filter( array_map( static function ( $r ) {
		return (string) ( $r['day'] ?? '' );
	}, (array) $prior_rows ) ) ) );
	if ( $cur_days > 0 && $pri_days * 2 < $cur_days ) {
		return array();
	}

	$url   = function_exists( 'admin_url' )
		? admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=machine-readers' )
		: '';
	$cards = array();
	// v10.2.0 (verifier finding): iterate the UNION, not just the current
	// window. Iterating $cur_tot alone meant a family that stopped entirely
	// (900 reads -> 0) never carded, while a partial fall (200 -> 40) did —
	// the largest possible drop was the one silence, and it biased in the
	// flattering direction on a surface whose whole point is noticing whether
	// crawlers backed off after we published TDMRep/RSL.
	$families = array_keys( $cur_tot );
	foreach ( array_keys( $pri_tot ) as $pf ) {
		if ( ! in_array( $pf, $families, true ) ) {
			$families[] = $pf;
		}
	}
	foreach ( $families as $family ) {
		$current = (int) ( $cur_tot[ $family ] ?? 0 );
		$family = (string) $family;
		$prior  = (int) ( $pri_tot[ $family ] ?? 0 );
		if ( $prior < 1 ) {
			continue; // No baseline: a percentage against zero would be a fiction.
		}
		if ( max( $current, $prior ) < SN_MR_DELTA_MIN_READS ) {
			continue; // Too small to be worth a card, whatever the ratio says.
		}
		$delta = $current - $prior;
		if ( abs( $delta ) < SN_MR_DELTA_MIN_ABS ) {
			continue;
		}
		$percent = (int) round( ( abs( $delta ) / $prior ) * 100 );
		if ( $percent < SN_MR_DELTA_PCT ) {
			continue;
		}

		$up = $delta > 0;
		if ( $up ) {
			/* translators: 1: crawler family slug, 2: percent change, 3: window length in days. */
			$format = __( '%1$s reads are up %2$d%% over the last %3$s days', 'signal-and-noise-tools' );
		} else {
			/* translators: 1: crawler family slug, 2: percent change, 3: window length in days. */
			$format = __( '%1$s reads are down %2$d%% over the last %3$s days', 'signal-and-noise-tools' );
		}

		$cards[] = array(
			'id'           => 'mr_delta_' . $family,
			'title'        => sprintf( $format, $family, $percent, number_format_i18n( $days ) ),
			'detail'       => sprintf(
				/* translators: 1: reads this window, 2: reads the window before, 3: window length in days. */
				__( '%1$s reads in this window against %2$s in the %3$s days before. Counts are what the edge observed; user agents are self-reported, so this is observed volume, not proof of identity.', 'signal-and-noise-tools' ),
				number_format_i18n( $current ),
				number_format_i18n( $prior ),
				number_format_i18n( $days )
			),
			// 'count' keeps the analytics card contract (a positive magnitude);
			// 'delta' carries the sign for anything that wants to style it.
			'count'        => abs( $delta ),
			'delta'        => $delta,
			'current'      => $current,
			'prior'        => $prior,
			'percent'      => $percent,
			'direction'    => $up ? 'up' : 'down',
			'action_url'   => $url,
			'action_label' => __( 'Open Machine Readers', 'signal-and-noise-tools' ),
		);
	}

	// Biggest move first; family slug breaks ties so the order is stable across
	// requests (arsort above only orders by current-window volume).
	usort( $cards, function ( $a, $b ) {
		$cmp = abs( (int) $b['delta'] ) <=> abs( (int) $a['delta'] );
		return 0 !== $cmp ? $cmp : strcmp( (string) $a['id'], (string) $b['id'] );
	} );
	return array_slice( $cards, 0, SN_MR_DELTA_CARD_MAX );
}

/**
 * Fetch both windows in one sensor read and run the detector over them.
 *
 * One call for 2*$days of rows (the sensor clamps at 90, so $days clamps at 45)
 * split locally, rather than two fetches: the read path already caches per
 * window length, and one call keeps the two halves consistent with each other.
 * A failed or unconfigured read yields no cards, never a card built on half a
 * comparison.
 *
 * @param int $days Window length in days, clamped 1..45.
 * @return array<int,array<string,mixed>> Cards (possibly empty).
 */
function snt_mr_delta_cards( $days = 30 ) {
	$days = max( 1, min( 45, (int) $days ) );
	if ( ! function_exists( 'snt_mr_fetch' ) ) {
		return array();
	}
	$result = snt_mr_fetch( $days * 2 );
	if ( empty( $result['ok'] ) ) {
		return array();
	}
	// The sensor aggregates by UTC day, so the reference day is a UTC date.
	$split = snt_mr_split_windows( (array) ( $result['rows'] ?? array() ), $days, gmdate( 'Y-m-d' ) );
	return snt_mr_family_delta_cards( $split['current'], $split['prior'], $days );
}

/**
 * Render the delta cards as a list, in the .sn-an-recs idiom the analytics
 * recommendations panel uses. Empty is first-class: no cards renders nothing at
 * all, so a host page can place this without an emptiness check of its own.
 *
 * Every dynamic value is escaped here, at the sink, even though the detector
 * builds these strings from allowlisted family slugs and integers.
 *
 * @param array $cards snt_mr_family_delta_cards() output.
 * @return string HTML, or '' when there is nothing to say.
 */
function snt_mr_render_delta_cards( $cards ) {
	$cards = (array) $cards;
	if ( empty( $cards ) ) {
		return '';
	}
	$out = '<ul class="sn-an-recs sn-mr-deltas">';
	foreach ( $cards as $c ) {
		if ( ! is_array( $c ) ) {
			continue;
		}
		$dir  = 'down' === (string) ( $c['direction'] ?? '' ) ? 'down' : 'up';
		$out .= '<li class="sn-an-rec sn-mr-delta sn-mr-delta--' . esc_attr( $dir ) . '">';
		$out .= '<p class="sn-an-rec-title">' . esc_html( (string) ( $c['title'] ?? '' ) ) . '</p>';
		$detail = (string) ( $c['detail'] ?? '' );
		if ( '' !== $detail ) {
			$out .= '<p class="sn-an-rec-detail">' . esc_html( $detail ) . '</p>';
		}
		$url = (string) ( $c['action_url'] ?? '' );
		if ( '' !== $url ) {
			$out .= '<a class="button button-small" href="' . esc_url( $url ) . '">'
				. esc_html( (string) ( $c['action_label'] ?? __( 'Open', 'signal-and-noise-tools' ) ) ) . '</a>';
		}
		$out .= '</li>';
	}
	return $out . '</ul>';
}
