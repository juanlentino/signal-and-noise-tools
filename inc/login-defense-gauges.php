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
 * Zero-vs-null honesty throughout: sn_analytics_query() returns array
 * (measured, possibly zero) or null (failure) — a fetch failure renders
 * "unknown", never a reassuring zero.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The worker's slice-2 decision criterion, percent. Mirrors the ipFamily()
 *  docblock in the worker's src/index.js — change BOTH or neither. */
const SN_LG_IPV6_THRESHOLD_PCT = 5;

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
 * AE SQL: address-family split (blob8) over the criterion window. The
 * denominator is COMPLETE — every decision path logs — so the share is the
 * real share, not a slice of one path.
 *
 * @param int $days Window in days (the criterion is written for 30).
 * @return string
 */
function sn_login_defense_family_share_sql( $days = 30 ) {
	$d = (int) $days;
	return 'SELECT blob8 AS family, sum(_sample_interval) AS hits '
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
 * Reduce the family rows to the IPv6 share. share_pct is null when nothing
 * was measured (never-measured is not 0%); 'unknown' families stay in the
 * denominator — an unparseable address is still attacker-reachable surface.
 *
 * @param array $rows [{family, hits}].
 * @return array{v6:int, total:int, share_pct:float|null, crossed:bool}
 */
function sn_login_defense_ipv6_share( $rows ) {
	$v6    = 0;
	$total = 0;
	foreach ( (array) $rows as $r ) {
		$hits   = (int) ( $r['hits'] ?? 0 );
		$total += $hits;
		if ( 'v6' === (string) ( $r['family'] ?? '' ) ) {
			$v6 += $hits;
		}
	}
	$share = $total > 0 ? round( $v6 / $total * 100, 1 ) : null;
	return array(
		'v6'        => $v6,
		'total'     => $total,
		'share_pct' => $share,
		'crossed'   => null !== $share && $share > SN_LG_IPV6_THRESHOLD_PCT,
	);
}

/**
 * Render the Defense gauges panel. Owner-only by placement (the Login-defense
 * view is behind the admin's capability gate); numbers only, never lever
 * names. null query results say "unknown" — the one word the health family
 * has already made honest.
 *
 * @param int $days Fail-open window (the range control's days); the IPv6
 *                  criterion window stays pinned at 30 regardless.
 */
function sn_login_defense_render_gauges( $days = 7 ) {
	$trend  = sn_analytics_query( sn_login_defense_failopen_trend_sql( (int) $days ) );
	$family = sn_analytics_query( sn_login_defense_family_share_sql( 30 ) );

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
		$share = sn_login_defense_ipv6_share( $family );
		if ( null === $share['share_pct'] ) {
			echo '<p>' . esc_html__( 'IPv6 share: unknown (no checked traffic in the window yet).', 'signal-and-noise-tools' ) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: IPv6 percentage, 2: threshold percentage */
					__( 'IPv6 share of checked login traffic (30d): %1$s%% — criterion %2$s%%: ', 'signal-and-noise-tools' ),
					$share['share_pct'],
					SN_LG_IPV6_THRESHOLD_PCT
				)
			);
			echo $share['crossed']
				? '<strong>' . esc_html__( 'crossed — sustained over 30 days this triggers the pre-committed decision: build 128-bit denylist ranges.', 'signal-and-noise-tools' ) . '</strong>'
				: esc_html__( 'below the line; the unchecked share stays measured, not assumed harmless.', 'signal-and-noise-tools' );
			echo '</p>';
		}
	}

	snt_an_panel_close();
}
