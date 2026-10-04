<?php
/**
 * Signal & Noise — on-demand scroll/time percentiles (p50/p75/p90).
 *
 * A companion to inc/analytics-buckets.php. Where buckets stores per-day band
 * COUNTS (additive — SUM at read time), percentiles are ORDER STATISTICS: the
 * p90 of a window is not any function of daily p90s, so the store-daily-SUM
 * pattern is invalid here. Instead we query Cloudflare AE on demand for the
 * EXACT resolved [from,to] window, sample-weighted via quantileExactWeighted,
 * and cache the three-stat result in a short transient. This honors v6.7.0's
 * arbitrary custom ranges + the page's class filter for free (both are just
 * WHERE clauses on one query) and needs no table, no dbDelta, no rollup.
 *
 * AE specifics (CF docs-confirmed; live-validated before tag):
 *   - quantileExactWeighted(q)(value, weight) — PARAMETRIC level, value first,
 *     weight (_sample_interval) second. One scalar per call → three SELECT cols.
 *   - timestamp bounded by explicit toDateTime('YYYY-MM-DD ...') literals (NOT a
 *     trailing INTERVAL, which would mis-handle past-ending presets like "last
 *     quarter").
 *
 * Both forms are NEW to this codebase (the v5.2.0/v5.3.0 422 lesson) — a failed
 * query returns null and the panel shows an empty-state, never a fatal.
 *
 * @package SignalNoiseTools
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-generation.php'; // which dataset a read uses (legacy, or the second generation once verified).

require_once __DIR__ . '/analytics-human-rule.php'; // the ONE counted-human rule

/**
 * The percentile metrics: source event + double column + label + display format.
 * Single source of truth for the SQL builder, the accessor, and the engagement
 * wiring. format: 'pct' (integer %) | 'time' (ms → snt_analytics_fmt_time).
 *
 * @return array<string, array{event:string, col:string, label:string, format:string}>
 */
function sn_analytics_percentiles_metrics() {
	return array(
		'scroll' => array( 'event' => 'sc', 'col' => 'double1', 'label' => 'Scroll depth', 'format' => 'pct' ),
		'time'   => array( 'event' => 'tm', 'col' => 'double2', 'label' => 'Time on page', 'format' => 'time' ),
		// The field Core Web Vitals, for the 75th percentile Google assesses a
		// page on (the SN Reading widget). Each is its own event with the value
		// in double7; CLS is stored x1000.
		'lcp'    => array( 'event' => 'vl', 'col' => 'double7', 'label' => 'LCP', 'format' => 'ms' ),
		'inp'    => array( 'event' => 'vi', 'col' => 'double7', 'label' => 'INP', 'format' => 'ms' ),
		'cls'    => array( 'event' => 'vc', 'col' => 'double7', 'label' => 'CLS', 'format' => 'cls' ),
	);
}

/**
 * AE SQL: p50/p75/p90 for one metric over the explicit [from,to] window, weighted
 * by _sample_interval. quantileExactWeighted(q)(value, weight) is parametric —
 * one scalar per call, so three SELECT columns. $event/$col are regex-sanitised,
 * $class is allowlisted, $from/$to are re-validated as YYYY-MM-DD (defence in depth;
 * the accessor validates too) — all interpolated into SQL string literals.
 *
 * @param string $event Event filter ('sc'|'tm').
 * @param string $col   Double column ('double1'|'double2').
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param string $class Traffic class.
 * @return string AE SQL.
 */
function sn_analytics_percentiles_sql( $event, $col, $from, $to, $class ) {
	$event = preg_replace( '/[^a-z]/', '', (string) $event );
	$col   = preg_replace( '/[^a-z0-9]/', '', (string) $col );
	$class = in_array( $class, SN_ANALYTICS_CLASSES, true ) ? $class : 'human';
	$from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) ? (string) $from : '1970-01-01';
	$to    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ? (string) $to : '1970-01-01';

	list( $lo, $hi ) = sn_analytics_local_day_bounds_utc( $from, $to );

	return implode( ' ', array(
		'SELECT',
		"quantileExactWeighted(0.5)({$col}, _sample_interval) AS p50,",
		"quantileExactWeighted(0.75)({$col}, _sample_interval) AS p75,",
		"quantileExactWeighted(0.9)({$col}, _sample_interval) AS p90",
		// The dataset follows the first UTC day the window touches: east of UTC
		// the site's day starts on the UTC day before.
		'FROM ' . sn_analytics_source( substr( $lo, 0, 10 ) ),
		"WHERE blob1 = '{$event}' AND " . sn_analytics_class_where( $class ),
		"AND timestamp >= toDateTime('{$lo}')",
		"AND timestamp <= toDateTime('{$hi}')",
	) );
}

/**
 * The UTC instants that bound the SITE's days [from, to]. The rollups bucket
 * by the site's day, so a percentile over the same dates has to cover the
 * same hours; UTC midnights would shift it by the site's offset. The bounds
 * are computed here and sent as plain UTC literals, the form Analytics Engine
 * already takes, so no timezone argument enters the statement. PURE given the
 * zone; with no zone available (a standalone test) the days are UTC days.
 *
 * @param string            $from Y-m-d (already validated).
 * @param string            $to   Y-m-d (already validated).
 * @param DateTimeZone|null $zone The site's zone; null reads wp_timezone().
 * @return array{0:string,1:string} 'Y-m-d H:i:s' in UTC.
 */
function sn_analytics_local_day_bounds_utc( $from, $to, $zone = null ) {
	if ( null === $zone ) {
		$zone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}
	$utc = new DateTimeZone( 'UTC' );
	try {
		$lo = ( new DateTimeImmutable( $from . ' 00:00:00', $zone ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$hi = ( new DateTimeImmutable( $to . ' 23:59:59', $zone ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
	} catch ( Exception $e ) {
		return array( $from . ' 00:00:00', $to . ' 23:59:59' );
	}
	return array( $lo, $hi );
}

/**
 * On-demand percentiles for one metric over [from,to] for a class. Checks a short
 * transient cache; on a miss issues ONE AE query and caches the result (a failure
 * is cached briefly so a broken config isn't re-hit every render). Returns an
 * ordered [{label,value}] list (p50/p75/p90) or NULL on any failure / unconfigured
 * AE / bad input — the renderer shows an empty-state on null.
 *
 * @param string $metric 'scroll'|'time'.
 * @param string $from   Inclusive start day, YYYY-MM-DD.
 * @param string $to     Inclusive end day, YYYY-MM-DD.
 * @param string $class  Traffic class (default 'human').
 * @return array<int, array{label:string, value:float}>|null
 */
function sn_analytics_percentiles( $metric, $from, $to, $class = 'human' ) {
	$metrics = sn_analytics_percentiles_metrics();
	if ( ! isset( $metrics[ $metric ] ) ) {
		return null;
	}
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ) {
		return null;
	}

	// The dataset is part of the key: when the daily verdict moves a window from
	// one generation to the other, an answer cached from the one just left is
	// not served.
	$source    = sn_analytics_source( substr( sn_analytics_local_day_bounds_utc( $from, $to )[0], 0, 10 ) );
	$cache_key = 'sn_pctl_' . md5( $metric . '|' . $from . '|' . $to . '|' . $class . ( SN_ANALYTICS_DATASET === $source ? '' : '|' . $source ) );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : null; // '' sentinel → cached failure.
	}

	if ( ! function_exists( 'sn_analytics_query' ) ) {
		return null;
	}

	$m   = $metrics[ $metric ];
	$res = sn_analytics_query( sn_analytics_percentiles_sql( $m['event'], $m['col'], $from, $to, $class ) );
	$ttl = defined( 'SN_ANALYTICS_ROLLUP_TTL' ) ? SN_ANALYTICS_ROLLUP_TTL : ( 15 * 60 );

	if ( ! is_array( $res ) || ! isset( $res[0] ) || ! is_array( $res[0] ) ) {
		set_transient( $cache_key, '', 5 * 60 ); // brief negative cache
		return null;
	}

	$row = $res[0];
	$out = array(
		array( 'label' => 'p50', 'value' => (float) ( $row['p50'] ?? 0 ) ),
		array( 'label' => 'p75', 'value' => (float) ( $row['p75'] ?? 0 ) ),
		array( 'label' => 'p90', 'value' => (float) ( $row['p90'] ?? 0 ) ),
	);
	set_transient( $cache_key, $out, $ttl );
	return $out;
}
