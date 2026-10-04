<?php
/**
 * Signal & Noise — live custom-event (ce/cp) rollup layer (B / 05b).
 *
 * Forward-capture companion to inc/analytics-events.php. The edge worker
 * (v1.2.0) writes one blob1='ce' row per named SN_BEACON.event(name, props)
 * call plus one blob1='cp' row per property (blob16=name, blob17=property,
 * blob18=value). This module rolls the trailing window of those AE rows into
 * the SAME durable tables the v6.2.0 Plausible import feeds:
 *
 *   blob1='ce' → wp_sn_analytics_events       via sn_analytics_events_upsert()
 *   blob1='cp' → wp_sn_analytics_event_props  via sn_analytics_event_props_upsert()
 *
 * Both queries are human-only (the counted-human rule, matching the class-agnostic Events
 * tab; bots rarely fire event()) and wired into the EXISTING rollup cron
 * (sn_analytics_run_rollup) — no new cron. A complete, non-empty read replaces
 * the window's days; the pre-worker import history is older than the window.
 *
 * Per-day cardinality caps bound table growth from a noisy custom-event space:
 * top 100 names/day, top 200 (property,value)/day. AE already ORDER BYs events
 * desc; we group the flat result by day and array_slice each day's tail.
 *
 * SQL uses the proven AE dialect (count() 0-arg, count(DISTINCT bare-col),
 * sum(_sample_interval), floored trailing window, NO LIMIT — see
 * tests/analytics-sql-dialect.php).
 *
 * @package SignalNoiseTools
 * @since 6.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-generation.php'; // which dataset a read uses (legacy, or the second generation once verified).

require_once __DIR__ . '/analytics-human-rule.php'; // the ONE counted-human rule

require_once __DIR__ . '/analytics-derive.php'; // sn_analytics_rollup_day_exprs()

// Per-day write caps (bound table growth; AE pre-sorts by events desc).
const SN_ANALYTICS_EVENTS_ROLLUP_NAME_CAP = 100; // top names per day
const SN_ANALYTICS_EVENTS_ROLLUP_PROP_CAP = 200; // top (property,value) per day

/**
 * AE SQL: roll the trailing $days of ce rows into per-day-per-name event totals.
 * Human-only. $days is integer-cast + floored (defence in depth; callers pass a
 * constant). Returns a string in the proven AE dialect — NO LIMIT (PHP slices).
 *
 * @param int    $days Trailing window in days.
 * @param string $tz   Optional IANA zone (sn_analytics_site_tz_name()); '' = UTC.
 * @return string AE SQL.
 */
function sn_analytics_events_rollup_sql( $days, $tz = '' ) {
	$days = max( 1, (int) $days );
	list( $day_col, $lower ) = sn_analytics_rollup_day_exprs( $days, $tz );
	$source = sn_analytics_source( sn_analytics_trailing_from( $days ), 'events' );

	return implode( ' ', array(
		"SELECT {$day_col} AS day,",
		sn_analytics_col( 'blob16', $source ) . ' AS name,',
		'sum(_sample_interval) AS events,',
		'count(DISTINCT index1) AS visitors',
		'FROM ' . $source,
		"WHERE blob1 = 'ce' AND " . sn_analytics_class_where( 'human' ) . " AND timestamp >= {$lower}" . sn_analytics_window_upper( $tz ),
		'GROUP BY day, name',
		'ORDER BY day DESC, events DESC',
	) );
}

/**
 * AE SQL: roll the trailing $days of cp rows into per-day-per-(property,value)
 * totals. Human-only. Proven AE dialect, NO LIMIT.
 *
 * @param int    $days Trailing window in days.
 * @param string $tz   Optional IANA zone (sn_analytics_site_tz_name()); '' = UTC.
 * @return string AE SQL.
 */
function sn_analytics_event_props_rollup_sql( $days, $tz = '' ) {
	$days = max( 1, (int) $days );
	list( $day_col, $lower ) = sn_analytics_rollup_day_exprs( $days, $tz );
	$source = sn_analytics_source( sn_analytics_trailing_from( $days ), 'events' );

	return implode( ' ', array(
		"SELECT {$day_col} AS day,",
		sn_analytics_col( 'blob17', $source ) . ' AS property,',
		sn_analytics_col( 'blob18', $source ) . ' AS value,',
		'sum(_sample_interval) AS events,',
		'count(DISTINCT index1) AS visitors',
		'FROM ' . $source,
		"WHERE blob1 = 'cp' AND " . sn_analytics_class_where( 'human' ) . " AND timestamp >= {$lower}" . sn_analytics_window_upper( $tz ),
		'GROUP BY day, property, value',
		'ORDER BY day DESC, events DESC',
	) );
}

/**
 * Group flat AE result rows by their 'day' field, then keep only the first
 * $cap rows of each day (AE already ORDER BYs events desc, so "first" = "top").
 * Returns the re-flattened, capped row list. A row with no/blank day is dropped.
 *
 * @param array $rows Flat AE rows (each an assoc array with a 'day' key).
 * @param int   $cap  Max rows to keep per day.
 * @return array Capped flat row list.
 */
function sn_analytics_events_rollup_cap_per_day( $rows, $cap ) {
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return array();
	}
	$cap    = max( 1, (int) $cap );
	$by_day = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$day = isset( $r['day'] ) ? (string) $r['day'] : '';
		if ( '' === $day ) {
			continue;
		}
		$by_day[ $day ][] = $r;
	}

	$out = array();
	foreach ( $by_day as $day_rows ) {
		foreach ( array_slice( $day_rows, 0, $cap ) as $r ) {
			$out[] = $r;
		}
	}
	return $out;
}

/**
 * Run BOTH live custom-event rollups (ce → events, cp → event_props) in one
 * pass, applying the per-day cardinality caps, and UPSERT via the existing
 * inc/analytics-events.php upserts. Called from sn_analytics_run_rollup()
 * behind a function_exists guard — NO new cron.
 *
 * No-ops when AE isn't configured; a query failure (null), empty or truncated
 * result only upserts (nothing deleted); a complete one replaces the window's days.
 */
function sn_analytics_events_run_rollup() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! function_exists( 'sn_analytics_query' ) ) {
		return;
	}
	if ( ! sn_analytics_config() ) {
		return;
	}

	// Roll by the SITE-LOCAL day like the pageview rollup (#1201), falling back
	// to UTC within the run when the zoned query fails (pre-timezone AE -> 422).
	$tz = function_exists( 'sn_analytics_site_tz_name' ) ? sn_analytics_site_tz_name() : '';

	// Events (blob1='ce'): AE aliases day/name/events/visitors already match the
	// upsert's {day,name,visitors,events} shape — cap, then upsert.
	$used = $tz;
	$ce_rows = sn_analytics_query( sn_analytics_events_rollup_sql( sn_analytics_rollup_window()['days'], $tz ) );
	if ( '' !== $tz && ! is_array( $ce_rows ) ) {
		$used = '';
		$ce_rows = sn_analytics_query( sn_analytics_events_rollup_sql( sn_analytics_rollup_window()['days'], '' ) );
	}
	if ( is_array( $ce_rows ) && function_exists( 'sn_analytics_events_upsert' ) ) {
		$capped = sn_analytics_events_rollup_cap_per_day( $ce_rows, SN_ANALYTICS_EVENTS_ROLLUP_NAME_CAP );
		$write  = static function () use ( $capped ) {
			if ( ! empty( $capped ) ) {
				sn_analytics_events_upsert( $capped );
			}
		};
		function_exists( 'sn_analytics_rollup_replace' ) ? sn_analytics_rollup_replace( ! empty( $ce_rows ) && ( ! function_exists( 'sn_analytics_last_result_truncated' ) || ! sn_analytics_last_result_truncated() ), 'sn_analytics_events', $used, $write ) : $write();
	}

	// Event props (blob1='cp'): AE aliases day/property/value/events/visitors
	// match the upsert's {day,property,value,visitors,events} shape — cap, upsert.
	$used = $tz;
	$cp_rows = sn_analytics_query( sn_analytics_event_props_rollup_sql( sn_analytics_rollup_window()['days'], $tz ) );
	if ( '' !== $tz && ! is_array( $cp_rows ) ) {
		$used = '';
		$cp_rows = sn_analytics_query( sn_analytics_event_props_rollup_sql( sn_analytics_rollup_window()['days'], '' ) );
	}
	if ( is_array( $cp_rows ) && function_exists( 'sn_analytics_event_props_upsert' ) ) {
		$capped = sn_analytics_events_rollup_cap_per_day( $cp_rows, SN_ANALYTICS_EVENTS_ROLLUP_PROP_CAP );
		$write  = static function () use ( $capped ) {
			if ( ! empty( $capped ) ) {
				sn_analytics_event_props_upsert( $capped );
			}
		};
		function_exists( 'sn_analytics_rollup_replace' ) ? sn_analytics_rollup_replace( ! empty( $cp_rows ) && ( ! function_exists( 'sn_analytics_last_result_truncated' ) || ! sn_analytics_last_result_truncated() ), 'sn_analytics_event_props', $used, $write ) : $write();
	}
}
