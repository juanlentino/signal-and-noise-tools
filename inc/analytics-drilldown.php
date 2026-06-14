<?php
/**
 * Signal & Noise — on-demand dimension drill-down (cross-tab → top pages).
 *
 * Click a dimension value (country, referrer, browser, …) → top pages for THAT
 * segment. The durable dims table keys each dimension independently so it holds
 * no cross-tab; the AE source writes every blob co-present on each pv row, so the
 * drill is one WHERE-filtered query. Reuses the v6.8.0 on-demand-cached-AE
 * pattern (inc/analytics-percentiles.php), NOT the rollup table.
 *
 * The clicked value is the FIRST non-constant string this subsystem puts into AE
 * SQL — whitelisted against the current durable top-N before any query, plus
 * defensive escaping. A failed/rejected query returns null → empty-state, never
 * fatal.
 *
 * @package SignalNoiseTools
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse a '<dim>:<value>' drill token. Splits on the FIRST colon (values may
 * contain colons). Returns array($dim, $value) for a known dim + non-empty value,
 * else null.
 *
 * @param string $raw
 * @return array{0:string,1:string}|null
 */
function sn_analytics_drilldown_parse( $raw ) {
	$raw = (string) $raw;
	$pos = strpos( $raw, ':' );
	if ( false === $pos || 0 === $pos ) {
		return null;
	}
	$dim   = substr( $raw, 0, $pos );
	$value = substr( $raw, $pos + 1 );
	if ( '' === $value || ! isset( SN_ANALYTICS_DIM_COLUMNS[ $dim ] ) ) {
		return null;
	}
	return array( $dim, $value );
}

/**
 * AE SQL: top pages (blob2) for one parent dimension value over [from,to], for a
 * class. All-proven primitives: sum(_sample_interval), count(DISTINCT index1),
 * WHERE blob=const equality, GROUP BY, ORDER BY. NO LIMIT (unproven vs AE — the
 * accessor PHP-sorts+slices). The value is single-quote/backslash-escaped for the
 * AE string literal (defence-in-depth; the accessor also whitelists it).
 *
 * @param string $dim   A SN_ANALYTICS_DIM_COLUMNS key.
 * @param string $value Parent value (already whitelisted by the accessor).
 * @param string $from  YYYY-MM-DD.
 * @param string $to    YYYY-MM-DD.
 * @param string $class Traffic class.
 * @return string AE SQL, or '' for an unknown dim.
 */
function sn_analytics_drilldown_sql( $dim, $value, $from, $to, $class ) {
	if ( ! isset( SN_ANALYTICS_DIM_COLUMNS[ $dim ] ) ) {
		return '';
	}
	$col   = SN_ANALYTICS_DIM_COLUMNS[ $dim ];
	$class = in_array( $class, SN_ANALYTICS_CLASSES, true ) ? $class : 'human';
	$from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) ? (string) $from : '1970-01-01';
	$to    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ? (string) $to : '1970-01-01';
	$val   = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $value );

	return implode( ' ', array(
		'SELECT blob2 AS path,',
		'sum(_sample_interval) AS views,',
		'count(DISTINCT index1) AS visits',
		'FROM ' . SN_ANALYTICS_DATASET,
		"WHERE blob1 = 'pv' AND {$col} = '{$val}' AND blob7 = '{$class}'",
		"AND timestamp >= toDateTime('{$from} 00:00:00')",
		"AND timestamp <= toDateTime('{$to} 23:59:59')",
		'GROUP BY path',
		'ORDER BY views DESC',
	) );
}
