<?php
/**
 * Signal & Noise Tools — Defense gauges (owner-only, Login-defense view).
 *
 * The two numbers the "Defense numbers" planned row names, with its gate
 * ("each gauge proven to move when the failure it watches occurs") satisfied
 * by synthetic-row fixtures in tests/login-defense-gauges.php:
 *
 * 1. FAIL-OPEN VISIBILITY. The guard's philosophy is "never lock the owner
 *    out", so its failure mode is silent permissiveness — 'failopen' (handler
 *    error, request passed) and 'degraded' (corrupted denylist enforcing
 *    nothing) are both "the door was open" states. Healthy is ZERO, and zero
 *    renders explicitly: absence of failure is a claim, not an omission.
 *
 * 2. IPv6 SHARE vs THE PRE-COMMITTED CRITERION. The worker's decision rule
 *    (fixed in advance, worker v1.5.2): build 128-bit denylist ranges when
 *    the IPv6 share of block-eligible traffic exceeds 5% sustained over 30
 *    days. This gauge automates the query and names the decision when the
 *    line is crossed, so the number triggers the call instead of reopening
 *    the argument.
 *
 *    The criterion has TWO halves and the query bound only supplies one of
 *    them. `WHERE timestamp > now() - INTERVAL '30' DAY` asks for 30 days; it
 *    does not deliver them. The family sensor (blob8) was appended in worker
 *    v1.5.0, so the same SQL returned a real share off a partial window for
 *    the first month of its life — and Analytics Engine flags none of that.
 *    So the window is MEASURED here (min(timestamp), carried through as
 *    measured_days) and NAMED in the copy, and "sustained" is withheld until
 *    the sensor has actually covered the span the rule is written over. A
 *    crossed line on an unfinished window is a real share and a decision
 *    nobody is authorised to make yet.
 *
 * Zero-vs-null honesty throughout: sn_analytics_query() returns array
 * (measured, possibly zero) or null (failure) — a fetch failure renders
 * "unknown", never a reassuring zero. Window coverage gets the same three
 * states: covered, short, or unknown — never silently assumed.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The worker's slice-2 decision criterion, percent. Mirrors the ipFamily()
 *  docblock in the worker's src/index.js — change BOTH or neither. */
const SN_LG_IPV6_THRESHOLD_PCT = 5;

/** The other half of the same criterion: the number of days the share must
 *  hold for. A threshold without its window is not the rule the worker wrote
 *  down, so this constant travels with the one above. */
const SN_LG_IPV6_CRITERION_DAYS = 30;

/**
 * AE SQL: daily fail-open + degraded counts, de-sampled. Both open-door
 * states in one query so the gauge cannot cover one and miss the other.
 *
 * @param int $days Window in days.
 * @return string
 */
function sn_login_defense_failopen_trend_sql( $days = 7 ) {
	$d = (int) $days;
	return "SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, "
		. "sum(if(blob2 = 'failopen', _sample_interval, 0)) AS failopen, "
		. "sum(if(blob2 = 'degraded', _sample_interval, 0)) AS degraded "
		. 'FROM ' . SN_LG_DATASET . ' '
		. "WHERE timestamp > now() - INTERVAL '" . $d . "' DAY "
		. 'GROUP BY day ORDER BY day';
}

/**
 * AE SQL: address-family split (blob8) over the criterion window, plus the
 * earliest row the family sensor wrote inside it.
 *
 * The denominator is complete ACROSS DECISION PATHS — every path logs, so the
 * share is the real share and not a slice of one. It is NOT automatically
 * complete across TIME: blob8 was appended in worker v1.5.0, and AE answers a
 * 30-day question with whatever days exist without saying so. `first_seen` is
 * what makes the window measurable, so the caller never has to trust the query
 * bound as if it were coverage.
 *
 * @param int $days Window in days (the criterion is written for 30).
 * @return string
 */
function sn_login_defense_family_share_sql( $days = 30 ) {
	$d = (int) $days;
	return 'SELECT blob8 AS family, sum(_sample_interval) AS hits, '
		. "formatDateTime(min(timestamp), '%Y-%m-%d %H:%M:%S') AS first_seen "
		. 'FROM ' . SN_LG_DATASET . ' '
		. "WHERE timestamp > now() - INTERVAL '" . $d . "' DAY "
		. 'GROUP BY family';
}

/**
 * Reduce the trend rows to totals. Empty rows are a measured ZERO — the
 * caller distinguishes null (unknown) BEFORE calling this.
 *
 * @param array $rows [{day, failopen, degraded}].
 * @return array{failopen:int, degraded:int}
 */
function sn_login_defense_failopen_totals( $rows ) {
	$out = array( 'failopen' => 0, 'degraded' => 0 );
	foreach ( (array) $rows as $r ) {
		$out['failopen'] += (int) ( $r['failopen'] ?? 0 );
		$out['degraded'] += (int) ( $r['degraded'] ?? 0 );
	}
	return $out;
}

/**
 * Reduce the family rows to the IPv6 share AND to the window that share was
 * actually measured over. share_pct is null when nothing was measured
 * (never-measured is not 0%); 'unknown' families stay in the denominator — an
 * unparseable address is still attacker-reachable surface.
 *
 * `crossed` answers the numeric half of the criterion (share > 5%).
 * `window_complete` answers the other half, the one a 30-day query bound can
 * only assume: true when the sensor covered the whole window, false when it
 * did not, NULL when the rows carry no first_seen and coverage is unknown.
 * Both halves must hold before "sustained over 30 days" is a claim anyone can
 * make — see the callers, which never announce the decision on `crossed`
 * alone.
 *
 * @param array    $rows [{family, hits, first_seen}]. first_seen is UTC.
 * @param int      $days The criterion window asked for.
 * @param int|null $now  Unix time, injectable for fixtures.
 * @return array{v6:int, total:int, share_pct:float|null, crossed:bool,
 *               first_seen:string|null, measured_days:int|null,
 *               window_complete:bool|null}
 */
function sn_login_defense_ipv6_share( $rows, $days = 30, $now = null ) {
	$v6    = 0;
	$total = 0;
	$first = null;
	$now   = null === $now ? time() : (int) $now;
	foreach ( (array) $rows as $r ) {
		$hits   = (int) ( $r['hits'] ?? 0 );
		$total += $hits;
		if ( 'v6' === (string) ( $r['family'] ?? '' ) ) {
			$v6 += $hits;
		}
		$seen = trim( (string) ( $r['first_seen'] ?? '' ) );
		if ( '' !== $seen ) {
			// AE reports UTC; strtotime() would otherwise read it as server-local.
			$ts = strtotime( $seen . ' UTC' );
			if ( false !== $ts && ( null === $first || $ts < $first ) ) {
				$first = $ts;
			}
		}
	}
	$share = $total > 0 ? round( $v6 / $total * 100, 1 ) : null;

	// The measured span is bounded BY the query too — a sensor older than the
	// window did not measure more of it than the window holds.
	$measured = null === $first ? null : min( (int) $days, (int) floor( ( $now - $first ) / 86400 ) );

	return array(
		'v6'              => $v6,
		'total'           => $total,
		'share_pct'       => $share,
		'crossed'         => null !== $share && $share > SN_LG_IPV6_THRESHOLD_PCT,
		'first_seen'      => null === $first ? null : gmdate( 'Y-m-d', $first ),
		'measured_days'   => $measured,
		'window_complete' => null === $measured ? null : $measured >= (int) $days,
	);
}

/**
 * Render the Defense gauges panel. Owner-only by placement (the Login-defense
 * view is behind the admin's capability gate); numbers only, never lever
 * names. null query results say "unknown" — the one word the health family
 * has already made honest.
 *
 * @param int $days Fail-open window (the range control's days); the IPv6
 *                  criterion window stays pinned at SN_LG_IPV6_CRITERION_DAYS
 *                  regardless — the rule is written for that span, so letting
 *                  the range control move it would quietly restate the rule.
 */
function sn_login_defense_render_gauges( $days = 7 ) {
	$trend  = sn_analytics_query( sn_login_defense_failopen_trend_sql( (int) $days ) );
	$family = sn_analytics_query( sn_login_defense_family_share_sql( SN_LG_IPV6_CRITERION_DAYS ) );

	snt_an_panel_open( __( 'Defense gauges', 'signal-and-noise-tools' ) );

	if ( ! is_array( $trend ) ) {
		echo '<p>' . esc_html__( 'Fail-open state: unknown (measurement unavailable).', 'signal-and-noise-tools' ) . '</p>';
	} else {
		$tot = sn_login_defense_failopen_totals( $trend );
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: fail-open count, 2: degraded count, 3: day window */
				__( '%1$s fail-opens, %2$s degraded reads (%3$sd) — every one is a request the guard let through while impaired; healthy is exactly zero.', 'signal-and-noise-tools' ),
				number_format_i18n( $tot['failopen'] ),
				number_format_i18n( $tot['degraded'] ),
				(int) $days
			)
		) . '</p>';
	}

	if ( ! is_array( $family ) ) {
		echo '<p>' . esc_html__( 'IPv6 share: unknown (measurement unavailable).', 'signal-and-noise-tools' ) . '</p>';
	} else {
		$share = sn_login_defense_ipv6_share( $family, SN_LG_IPV6_CRITERION_DAYS );
		if ( null === $share['share_pct'] ) {
			echo '<p>' . esc_html__( 'IPv6 share: unknown (no checked traffic in the window yet).', 'signal-and-noise-tools' ) . '</p>';
		} else {
			// Name the window that was MEASURED, never the one that was asked
			// for: AE answers a 30-day question with whatever days it holds.
			if ( null === $share['measured_days'] ) {
				$window = sprintf(
					/* translators: %s: criterion window in days */
					__( '%sd asked, coverage unknown', 'signal-and-noise-tools' ),
					SN_LG_IPV6_CRITERION_DAYS
				);
			} elseif ( $share['window_complete'] ) {
				$window = sprintf(
					/* translators: 1: measured days, 2: criterion window in days */
					__( '%1$sd measured of %2$sd', 'signal-and-noise-tools' ),
					$share['measured_days'],
					SN_LG_IPV6_CRITERION_DAYS
				);
			} else {
				$window = sprintf(
					/* translators: 1: measured days, 2: criterion window in days, 3: first sensor row date */
					__( '%1$sd measured of %2$sd, family sensor since %3$s', 'signal-and-noise-tools' ),
					$share['measured_days'],
					SN_LG_IPV6_CRITERION_DAYS,
					$share['first_seen']
				);
			}

			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: measured window, 2: IPv6 percentage, 3: threshold percentage */
					__( 'IPv6 share of checked login traffic (%1$s): %2$s%% — criterion %3$s%%: ', 'signal-and-noise-tools' ),
					$window,
					$share['share_pct'],
					SN_LG_IPV6_THRESHOLD_PCT
				)
			);

			if ( ! $share['crossed'] ) {
				echo esc_html__( 'below the line; the unchecked share stays measured, not assumed harmless.', 'signal-and-noise-tools' );
			} elseif ( true === $share['window_complete'] ) {
				// Both halves of the criterion hold: the share is over the line
				// AND the sensor covered the whole window it is claimed over.
				echo '<strong>' . esc_html(
					sprintf(
						/* translators: %s: criterion window in days */
						__( 'crossed — sustained over the full %sd, this triggers the pre-committed decision: build 128-bit denylist ranges.', 'signal-and-noise-tools' ),
						SN_LG_IPV6_CRITERION_DAYS
					)
				) . '</strong>';
			} elseif ( null === $share['window_complete'] ) {
				echo esc_html__( 'over the line, but not yet a decision — this window\'s sensor coverage is unknown, and "sustained" cannot be claimed off a window nobody measured.', 'signal-and-noise-tools' );
			} else {
				echo esc_html(
					sprintf(
						/* translators: 1: criterion window in days, 2: measured days */
						__( 'over the line, but not yet sustained — the criterion asks for %1$sd of sensor coverage and this window holds %2$sd. Real share, unfinished window.', 'signal-and-noise-tools' ),
						SN_LG_IPV6_CRITERION_DAYS,
						$share['measured_days']
					)
				);
			}
			echo '</p>';
		}
	}

	snt_an_panel_close();
}
