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
