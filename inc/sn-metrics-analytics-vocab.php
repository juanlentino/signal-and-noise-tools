<?php
/**
 * Signal & Noise Tools: analytics_query's vocabulary and its validator.
 *
 * Allowlisted words only, no SQL passthrough. Pure (no WordPress reads), so
 * sn-metrics can refuse a bad query with a 422 BEFORE dispatch: its dispatch
 * folds every WP_Error into {error:"unavailable"}, which would hide a typo
 * as an outage.
 *
 * What the storage can answer decides the rules. The rollups hold one
 * dimension per day, so two dimensions are allowed only as day plus one;
 * filters may name only grouped dimensions; scroll and time are stored per
 * page, so they need path. section, city, region, network and timezone are
 * left out on purpose (no section exists; the others narrow too far).
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_MQ_DIMENSIONS = array( 'day', 'path', 'source', 'referrer_category', 'country', 'device' );
const SNT_MQ_METRICS    = array( 'views', 'visits', 'scroll_avg', 'time_avg' );
const SNT_MQ_KEYS       = array( 'range', 'class', 'dimensions', 'metrics', 'filters', 'compare', 'order_by', 'order', 'limit' );
const SNT_MQ_MAX_ROWS   = 500;

/**
 * Validate and normalize a query.
 *
 * @param mixed $q The query input.
 * @return array|WP_Error Normalized {dimensions, metrics, filters, compare, order_by, order, limit}.
 */
function snt_mq_validate( $q ) {
	$no = static fn( $m ) => new WP_Error( 'snt_mq_invalid', $m, array( 'status' => 422 ) );
	$q  = is_array( $q ) ? $q : array();
	$extra = array_diff( array_keys( $q ), SNT_MQ_KEYS );
	if ( $extra ) {
		return $no( 'Unknown query key(s): ' . implode( ', ', $extra ) );
	}
	$dims = array_values( array_unique( array_map( 'strval', (array) ( $q['dimensions'] ?? array() ) ) ) );
	if ( ! $dims || count( $dims ) > 2 || array_diff( $dims, SNT_MQ_DIMENSIONS ) ) {
		return $no( 'dimensions: one or two of ' . implode( ', ', SNT_MQ_DIMENSIONS ) . '.' );
	}
	if ( 2 === count( $dims ) && ! in_array( 'day', $dims, true ) ) {
		return $no( 'Two dimensions must be day plus one: the rollups store one dimension per day.' );
	}
	$metrics = array_values( array_unique( array_map( 'strval', (array) ( $q['metrics'] ?? array( 'views', 'visits' ) ) ) ) );
	if ( ! $metrics || array_diff( $metrics, SNT_MQ_METRICS ) ) {
		return $no( 'metrics: any of ' . implode( ', ', SNT_MQ_METRICS ) . '.' );
	}
	if ( array_intersect( $metrics, array( 'scroll_avg', 'time_avg' ) ) && ! in_array( 'path', $dims, true ) ) {
		return $no( 'scroll_avg and time_avg are stored per page: add path to dimensions.' );
	}
	$filters = array();
	foreach ( (array) ( $q['filters'] ?? array() ) as $dim => $f ) {
		if ( ! in_array( (string) $dim, $dims, true ) ) {
			return $no( "filters: $dim is not a grouped dimension." );
		}
		if ( ! is_array( $f ) || array_diff( array_keys( $f ), array( 'include', 'exclude' ) ) ) {
			return $no( "filters.$dim takes include and/or exclude lists." );
		}
		$filters[ (string) $dim ] = array(
			'include' => array_map( 'strval', (array) ( $f['include'] ?? array() ) ),
			'exclude' => array_map( 'strval', (array) ( $f['exclude'] ?? array() ) ),
		);
	}
	$compare = (string) ( $q['compare'] ?? 'none' );
	if ( ! in_array( $compare, array( 'none', 'previous' ), true ) ) {
		return $no( 'compare: none or previous.' );
	}
	$order_by = (string) ( $q['order_by'] ?? ( array( 'day' ) === $dims ? 'day' : $metrics[0] ) );
	if ( ! in_array( $order_by, array_merge( $dims, $metrics ), true ) ) {
		return $no( 'order_by must name a chosen dimension or metric.' );
	}
	$order = (string) ( $q['order'] ?? ( 'day' === $order_by ? 'asc' : 'desc' ) );
	if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
		return $no( 'order: asc or desc.' );
	}
	$limit = (int) ( $q['limit'] ?? 25 );
	if ( $limit < 1 || $limit > SNT_MQ_MAX_ROWS ) {
		return $no( 'limit: 1 to ' . SNT_MQ_MAX_ROWS . '.' );
	}
	return compact( 'dims', 'metrics', 'filters', 'compare', 'order_by', 'order', 'limit' );
}
