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
 * AE SQL: top pages (blob2) for one or more parent dimension values over
 * [from,to], for a class. All-proven primitives: sum(_sample_interval),
 * count(DISTINCT index1), `IN (...)` set membership (the same shape proven by
 * sn_analytics_pageroles_rollup_sql), GROUP BY, ORDER BY. NO LIMIT (unproven vs AE
 * — the accessor PHP-sorts+slices). Each value is single-quote/backslash-escaped
 * for the AE string literal (defence-in-depth; the accessor also whitelists them).
 *
 * Most dims pass a single value; the referrer dim passes the member hosts of a
 * brand-folded source (so "Google" drills google.com + news.google.com + …).
 *
 * @param string                 $dim    A SN_ANALYTICS_DIM_COLUMNS key.
 * @param string|array<int,string> $values Parent value(s) (already whitelisted).
 * @param string                 $from   YYYY-MM-DD.
 * @param string                 $to     YYYY-MM-DD.
 * @param string                 $class  Traffic class.
 * @return string AE SQL, or '' for an unknown dim / empty value set.
 */
function sn_analytics_drilldown_sql( $dim, $values, $from, $to, $class ) {
	if ( ! isset( SN_ANALYTICS_DIM_COLUMNS[ $dim ] ) ) {
		return '';
	}
	$col   = SN_ANALYTICS_DIM_COLUMNS[ $dim ];
	$class = in_array( $class, SN_ANALYTICS_CLASSES, true ) ? $class : 'human';
	$from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) ? (string) $from : '1970-01-01';
	$to    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ? (string) $to : '1970-01-01';

	$escaped = array();
	foreach ( (array) $values as $v ) {
		$escaped[] = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $v ) . "'";
	}
	if ( empty( $escaped ) ) {
		return '';
	}
	$in = implode( ', ', $escaped );

	return implode( ' ', array(
		'SELECT blob2 AS path,',
		'sum(_sample_interval) AS views,',
		'count(DISTINCT index1) AS visits',
		'FROM ' . SN_ANALYTICS_DATASET,
		"WHERE blob1 = 'pv' AND {$col} IN ({$in}) AND blob7 = '{$class}'",
		"AND timestamp >= toDateTime('{$from} 00:00:00')",
		"AND timestamp <= toDateTime('{$to} 23:59:59')",
		'GROUP BY path',
		'ORDER BY views DESC',
	) );
}

/**
 * Cross-tab drill-down: top pages for one parent dimension value over [from,to]
 * for a class. WHITELISTS the value against the current durable top-N (so a
 * crafted value is rejected before any AE call), then transient-caches a single
 * on-demand AE query (5-min TTL; failures negative-cached). Returns the top-15
 * [{path,views,visits}] sorted by views desc, or NULL on reject / failure /
 * unconfigured AE / bad input — the panel shows an empty-state on null.
 *
 * @param string $dim   A SN_ANALYTICS_DIM_COLUMNS key.
 * @param string $value Clicked parent value (untrusted).
 * @param string $from  YYYY-MM-DD.
 * @param string $to    YYYY-MM-DD.
 * @param string $class Traffic class (default 'human').
 * @return array<int, array{path:string, views:int, visits:int}>|null
 */
function sn_analytics_drilldown( $dim, $value, $from, $to, $class = 'human' ) {
	if ( ! isset( SN_ANALYTICS_DIM_COLUMNS[ $dim ] ) ) {
		return null;
	}
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ) {
		return null;
	}
	$value = (string) $value;
	if ( '' === $value || strlen( $value ) > 256 || preg_match( '/[\x00-\x1f]/', $value ) ) {
		return null;
	}
	if ( ! function_exists( 'sn_analytics_top_dimension' ) ) {
		return null;
	}

	// Resolve the clicked parent to the raw column value(s) to query, AND whitelist
	// it in the same step (no AE call for a value not in the current top-N).
	if ( 'referrer' === $dim ) {
		// $value is a canonical SOURCE LABEL (e.g. "Google"); resolve to its member
		// hosts. Only a current top-source label with ≥1 member host resolves — so
		// '(direct)' and any crafted label return [] → rejected. This is the whitelist.
		$query_values = function_exists( 'sn_analytics_source_hosts' )
			? sn_analytics_source_hosts( $value, $from, $to, $class )
			: array();
		if ( empty( $query_values ) ) {
			return null;
		}
	} else {
		// Whitelist: the value MUST be a current durable top-N member for this dim.
		$known = false;
		foreach ( (array) sn_analytics_top_dimension( $dim, $from, $to, $class, 500 ) as $r ) {
			if ( isset( $r['value'] ) && (string) $r['value'] === $value ) {
				$known = true;
				break;
			}
		}
		if ( ! $known ) {
			return null;
		}
		$query_values = array( $value );
	}

	$cache_key = 'sn_drill_' . md5( $dim . '|' . $value . '|' . $from . '|' . $to . '|' . $class );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : null;
	}
	if ( ! function_exists( 'sn_analytics_query' ) ) {
		return null;
	}

	$res = sn_analytics_query( sn_analytics_drilldown_sql( $dim, $query_values, $from, $to, $class ) );
	if ( ! is_array( $res ) ) {
		set_transient( $cache_key, '', 5 * 60 );
		return null;
	}

	$rows = array();
	foreach ( $res as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$rows[] = array(
			'path'   => (string) ( $r['path'] ?? '' ),
			'views'  => (int) ( $r['views'] ?? 0 ),
			'visits' => (int) ( $r['visits'] ?? 0 ),
		);
	}
	usort( $rows, static function ( $a, $b ) {
		return $b['views'] <=> $a['views'];
	} );
	$rows = array_slice( $rows, 0, 15 );

	set_transient( $cache_key, $rows, 5 * 60 );
	return $rows;
}
