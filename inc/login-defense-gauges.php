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
 *    Which is exactly why the zero is qualified by its coverage. A claim needs
 *    a span, and "0 fail-opens (7d)" read identically whether the guard was
 *    clean for seven days or wrote nothing at all — the reassuring zero this
 *    panel exists to refuse. The day rows the trend query returns ARE the
 *    record of which days were watched, so the gauge names them ("over 5 of 7
 *    days the guard logged") and, with no rows at all, reports an unwatched
 *    window instead of a clean one.
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
 *    So the window is MEASURED here (min(timestamp) over rows the sensor
 *    actually wrote — the empty-family group predates blob8 and does not
 *    date it) and NAMED in the copy, and "sustained" is withheld until
 *    the sensor has actually covered the span the rule is written over. A
 *    crossed line on an unfinished window is a real share and a decision
 *    nobody is authorised to make yet.
 *
 *    TWO MEASURES OF THAT WINDOW, and they are not the same (2026-08-27).
 *    `measured_days` is min(30, now - first_seen): a SPAN. It was taken for
 *    coverage on the reasoning that the family sensor writes on EVERY guard
 *    row, so the earliest row dates the coverage. That holds only while
 *    traffic is dense enough for every day to write at least one row — a
 *    PRECONDITION, not a property, and live traffic stopped meeting it. Across
 *    five readings first_seen slid 2026-07-26 -> 07-30 while measured_days
 *    went 27 -> 29 -> 28 -> 28: the back edge sheds boundary days as fast as
 *    the front edge gains them, which can only happen where boundary days hold
 *    no rows at all. `days_covered` is the honest count, off the day dimension
 *    the family query now carries. Both are reported; only coverage answers
 *    "sustained".
 *
 *    The decision still gates on the SPAN, deliberately. The owner's call on
 *    2026-08-27 was to MEASURE coverage before re-speccing the criterion, so
 *    this gauge adds the number and changes no verdict.
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
 * The RE-SPECCED coverage half (owner decision, 2026-08-27).
 *
 * The rule asked for 30 days of coverage and got a 30-day SPAN, which is not
 * the same claim (see sn_login_defense_ipv6_share). Once coverage was measured
 * properly the span was not the only problem: ~14% of days carry no
 * block-eligible traffic at all, so coverage plateaus near 26/30 and 30/30 is
 * unreachable at this volume. A criterion nobody can satisfy does not set a
 * high bar; it withholds a decision forever while looking rigorous.
 *
 * So "sustained" is re-specced as what it always meant — enough DAYS and
 * enough OBSERVATIONS — at thresholds this traffic can actually reach:
 *
 *   BUILD 128-bit ranges when the IPv6 share exceeds 5%, over a window
 *   holding >= 20 covered days AND >= 100 block-eligible observations.
 *
 * Both halves are load-bearing and each catches what the other misses: the
 * days floor refuses a burst (300 hits in 8 days is not sustained), and the
 * observations floor refuses a trickle (25 days holding 45 hits is not
 * evidence). Fixed in advance of reading the number, exactly as v1.5.2's
 * threshold was, so the data still decides rather than the arguing.
 */
const SN_LG_IPV6_MIN_DAYS_COVERED = 20;
const SN_LG_IPV6_MIN_OBSERVATIONS = 100;

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
 * The empty-string family group is legitimate data: those rows predate blob8.
 * The reducer excludes them; this query must not.
 *
 * @param int $days Window in days (the criterion is written for 30).
 * @return string
 */
function sn_login_defense_family_share_sql( $days = 30 ) {
	$d = (int) $days;
	return 'SELECT blob8 AS family, '
		. "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, "
		. 'sum(_sample_interval) AS hits, '
		. 'min(timestamp) AS first_seen '
		. 'FROM ' . SN_LG_DATASET . ' '
		. "WHERE timestamp > now() - INTERVAL '" . $d . "' DAY "
		. 'GROUP BY family, day';
}

/**
 * Reduce the trend rows to totals AND to the coverage those totals are honest
 * over. The caller distinguishes null (query failure) BEFORE calling this.
 *
 * Zero is this gauge's healthy reading, which is exactly what makes an
 * unwatched window dangerous: "0 fail-opens (7d)" reads identically whether
 * the guard was clean for seven days or wrote nothing at all. The window is
 * therefore measured rather than assumed — and the instrument differs from the
 * IPv6 gauge's on purpose. There, the sensor writes on every row, so
 * min(timestamp) over the filtered rows DATES the sensor — which measures
 * coverage only while traffic is dense enough that every day writes at least
 * one row. Live traffic stopped meeting that precondition, so that gauge now
 * counts days too, the way this reducer always has. Here the subject is
 * a RARE EVENT: filtering to failopen/degraded and taking the earliest row
 * would report "no coverage" on every healthy system, because sensor-absence
 * and event-absence look the same. So coverage comes from the shape the query
 * already returns — it groups EVERY guard row by day, so a day the guard
 * logged nothing at all yields no row, and the day rows ARE the record of
 * which days were watched.
 *
 * The claim this supports is precisely "the guard logged on N of the last M
 * days" — not "the guard ran". A day with genuinely zero login-surface traffic
 * would also be missing, so a gap is uncovered, never proven idle.
 *
 * (The failopen/degraded decision values ship from worker v1.3.0, one day
 * after the dataset's own first row, so the value's birth is not a separate
 * floor worth gating on.)
 *
 * @param array $rows [{day, failopen, degraded}].
 * @param int   $days The window asked for.
 * @return array{failopen:int, degraded:int, days_covered:int,
 *               first_day:string|null, window_complete:bool}
 */
function sn_login_defense_failopen_totals( $rows, $days = 7 ) {
	$out   = array( 'failopen' => 0, 'degraded' => 0 );
	$seen  = array();
	$first = null;
	foreach ( (array) $rows as $r ) {
		$out['failopen'] += (int) ( $r['failopen'] ?? 0 );
		$out['degraded'] += (int) ( $r['degraded'] ?? 0 );
		$day              = trim( (string) ( $r['day'] ?? '' ) );
		if ( '' !== $day ) {
			$seen[ $day ] = true;
			if ( null === $first || $day < $first ) {
				$first = $day;
			}
		}
	}
	// A 7-day interval straddles 8 calendar days, so cap at the window asked
	// for: the query cannot have watched more of it than it holds.
	$covered = min( (int) $days, count( $seen ) );

	$out['days_covered']    = $covered;
	$out['first_day']       = $first;
	$out['window_complete'] = $covered >= (int) $days;
	return $out;
}

/**
 * Parse a timestamp as Analytics Engine returns it, into a unix instant.
 *
 * WHY THIS IS ITS OWN FUNCTION. The first version selected
 * `formatDateTime(min(timestamp), …)` — an aggregate wrapped in a scalar
 * function, the only such construct in this repo — and appended `' UTC'` before
 * `strtotime()`. Live, `first_seen` never arrived and the panel rendered
 * "coverage unknown" against real data. The fixtures could not catch it: they
 * fed rows that ALREADY contained a `first_seen` in the exact shape the reducer
 * wanted, so they proved the reducer and nothing about the API.
 *
 * The SQL now selects the aggregate RAW, which moves the shape question into
 * PHP where it is testable. AE may hand back a ClickHouse-style
 * `Y-m-d H:i:s`, or ISO-8601 with `Z`, or ISO with an offset.
 *
 * The `' UTC'` suffix is unconditional, and that is VERIFIED rather than
 * assumed. An earlier version of this function guarded it behind a
 * zone-detection regex, on the belief that `…Z UTC` would fail to parse. It
 * does not: PHP tolerates the doubled zone, and where the string carries a real
 * offset the EMBEDDED offset wins and the appended UTC is ignored — checked
 * against `+02:00` and `-05:00`, both of which resolve to the correct instant
 * with or without the suffix. The guard was an unnecessary branch justified by
 * a false claim, which is worse than no guard.
 *
 * Unparseable input returns NULL, never 0 — a fabricated instant would date the
 * sensor to 1970 and report a 30-day window as complete.
 *
 * @param mixed $raw Whatever AE put in the column.
 * @return int|null Unix seconds, or null when nothing usable was returned.
 */
function sn_login_defense_parse_ae_ts( $raw ) {
	$s = trim( (string) $raw );
	if ( '' === $s ) {
		return null;
	}
	$ts = strtotime( $s . ' UTC' );

	return false === $ts ? null : $ts;
}

/**
 * Reduce the family rows to the IPv6 share AND to the window that share was
 * actually measured over. share_pct is null when nothing was measured
 * (never-measured is not 0%).
 *
 * Three family values, one exclusion. 'v4' and 'v6' are parsed addresses.
 * 'unknown' is a present sensor that could not parse the address — it stays
 * in the denominator (an unparseable address is still attacker-reachable
 * surface) and its first_seen still dates the sensor. The empty string is
 * different in kind: the sensor was absent, the row predates blob8. Those
 * hits are not a measurement. They are counted as pre_sensor_hits and
 * excluded from both the denominator and the first_seen minimum — a filter
 * that dropped them silently would hide the coverage assumption it exists
 * to detect. If every row is empty-family, the sensor never wrote: share,
 * measured_days, and window_complete are all null.
 *
 * `crossed` answers the numeric half of the criterion (share > 5%).
 * `window_complete` answers the other half, re-specced 2026-08-27: true when
 * the window holds at least SN_LG_IPV6_MIN_DAYS_COVERED covered days AND at
 * least SN_LG_IPV6_MIN_OBSERVATIONS block-eligible hits, false when it does
 * not, NULL when the rows carry no day dimension and coverage is unknowable.
 * It no longer reads `measured_days`, which is a SPAN — two rows 30 days apart
 * complete a span while covering two days. `measured_days` is still reported,
 * as context; `days_covered` is what the decision rests on.
 * Both halves must hold before "sustained over 30 days" is a claim anyone can
 * make — see the callers, which never announce the decision on `crossed`
 * alone.
 *
 * @param array    $rows [{family, hits, first_seen}]. first_seen is UTC.
 * @param int      $days The criterion window asked for.
 * @param int|null $now  Unix time, injectable for fixtures.
 * @return array{v6:int, total:int, share_pct:float|null, crossed:bool,
 *               first_seen:string|null, measured_days:int|null,
 *               days_covered:int|null, window_complete:bool|null,
 *               pre_sensor_hits:int}
 */
function sn_login_defense_ipv6_share( $rows, $days = 30, $now = null ) {
	$v6         = 0;
	$total      = 0;
	$pre_sensor = 0;
	$first      = null;
	$days_seen  = array();
	$now        = null === $now ? time() : (int) $now;
	foreach ( (array) $rows as $r ) {
		$hits   = (int) ( $r['hits'] ?? 0 );
		$family = (string) ( $r['family'] ?? '' );
		if ( '' === $family ) {
			$pre_sensor += $hits;
			continue;
		}
		$total += $hits;
		if ( 'v6' === $family ) {
			$v6 += $hits;
		}
		$day = trim( (string) ( $r['day'] ?? '' ) );
		if ( '' !== $day ) {
			$days_seen[ $day ] = true;
		}
		$ts = sn_login_defense_parse_ae_ts( $r['first_seen'] ?? '' );
		if ( null !== $ts && ( null === $first || $ts < $first ) ) {
			$first = $ts;
		}
	}
	$share = $total > 0 ? round( $v6 / $total * 100, 1 ) : null;

	// The measured span is bounded BY the query too — a sensor older than the
	// window did not measure more of it than the window holds.
	$measured = null === $first ? null : min( (int) $days, (int) floor( ( $now - $first ) / 86400 ) );

	// Coverage the SPAN cannot see: a count of days that actually wrote rows.
	// Null, never 0, when the rows carry no day dimension — unknown coverage is
	// not zero coverage. Capped at the window asked for, because N calendar days
	// straddle an (N-1)-day span.
	$covered = empty( $days_seen ) ? null : min( (int) $days, count( $days_seen ) );

	return array(
		'v6'              => $v6,
		'total'           => $total,
		'share_pct'       => $share,
		'crossed'         => null !== $share && $share > SN_LG_IPV6_THRESHOLD_PCT,
		'first_seen'      => null === $first ? null : gmdate( 'Y-m-d', $first ),
		'measured_days'   => $measured,
		'days_covered'    => $covered,
		'window_complete' => null === $covered
			? null
			: ( $covered >= SN_LG_IPV6_MIN_DAYS_COVERED && $total >= SN_LG_IPV6_MIN_OBSERVATIONS ),
		'pre_sensor_hits' => $pre_sensor,
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
		$tot = sn_login_defense_failopen_totals( $trend, (int) $days );
		if ( 0 === $tot['days_covered'] ) {
			// The reassuring zero this panel exists to refuse: nothing was
			// watched, so there is no count to report — not even zero.
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: day window */
					__( 'Fail-open state: no telemetry in the last %sd — the guard logged nothing at all, so this is an unwatched window, not a clean one.', 'signal-and-noise-tools' ),
					(int) $days
				)
			) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: fail-open count, 2: degraded count, 3: days the guard logged, 4: day window */
					__( '%1$s fail-opens, %2$s degraded reads over %3$s of %4$s days the guard logged — every one is a request the guard let through while impaired; healthy is exactly zero.', 'signal-and-noise-tools' ),
					number_format_i18n( $tot['failopen'] ),
					number_format_i18n( $tot['degraded'] ),
					number_format_i18n( $tot['days_covered'] ),
					(int) $days
				)
			);
			if ( ! $tot['window_complete'] ) {
				$uncovered = (int) $days - $tot['days_covered'];
				echo ' ' . esc_html(
					sprintf(
						/* translators: 1: uncovered day count, 2: earliest logged day */
						_n(
							'The other %1$s day holds no telemetry at all (earliest logged day %2$s), so the count covers the days it names and no more.',
							'The other %1$s days hold no telemetry at all (earliest logged day %2$s), so the count covers the days it names and no more.',
							$uncovered,
							'signal-and-noise-tools'
						),
						number_format_i18n( $uncovered ),
						$tot['first_day']
					)
				);
			}
			echo '</p>';
		}
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

			// Coverage is NOT span. measured_days is now - first_seen; this is
			// the count of days that actually wrote. On sparse traffic a window
			// can be complete by span and nearly empty by coverage, so both are
			// named. Omitted when the rows carry no day dimension: a fabricated
			// count is worse than an absent one.
			if ( null !== $share['days_covered'] ) {
				$window .= ', ' . sprintf(
					/* translators: 1: days that wrote rows, 2: criterion window in days */
					__( '%1$s of %2$s days covered', 'signal-and-noise-tools' ),
					number_format_i18n( $share['days_covered'] ),
					SN_LG_IPV6_CRITERION_DAYS
				);
			}

			// Same fact as the measured window: what the sensor did and did
			// not see. Named when the query returned pre-sensor rows; omitted
			// when it did not — a phantom clause would invent a filter.
			if ( $share['pre_sensor_hits'] > 0 ) {
				$window .= ', ' . sprintf(
					/* translators: %s: hit count written before the family sensor existed */
					_n( '%s pre-sensor hit excluded', '%s pre-sensor hits excluded', $share['pre_sensor_hits'], 'signal-and-noise-tools' ),
					number_format_i18n( $share['pre_sensor_hits'] )
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
						/* translators: 1: covered days, 2: observation count */
						__( 'crossed — sustained across %1$s covered days and %2$s observations, this triggers the pre-committed decision: build 128-bit denylist ranges.', 'signal-and-noise-tools' ),
						number_format_i18n( $share['days_covered'] ),
						number_format_i18n( $share['total'] )
					)
				) . '</strong>';
			} elseif ( null === $share['window_complete'] ) {
				echo esc_html__( 'over the line, but not yet a decision — this window\'s sensor coverage is unknown, and "sustained" cannot be claimed off a window nobody measured.', 'signal-and-noise-tools' );
			} else {
				echo esc_html(
					sprintf(
						/* translators: 1: required covered days, 2: required observations, 3: covered days held, 4: observations held */
						__( 'over the line, but not yet sustained — the criterion asks for %1$s covered days and %2$s observations; this window holds %3$s and %4$s. Real share, unfinished window.', 'signal-and-noise-tools' ),
						SN_LG_IPV6_MIN_DAYS_COVERED,
						SN_LG_IPV6_MIN_OBSERVATIONS,
						number_format_i18n( $share['days_covered'] ),
						number_format_i18n( $share['total'] )
					)
				);
			}
			echo '</p>';
		}
	}

	snt_an_panel_close();
}
