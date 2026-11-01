<?php
/**
 * Signal & Noise Tools: analytics rows, the stored half. Reads the same stored
 * tables the dashboard reads: the daily table for path and day, the dims table
 * for referrer, country, device, region and city (inc/analytics-rows-events.php
 * reads Analytics Engine for the rest). Column
 * and dimension names come from fixed arrays only; caller values reach SQL
 * as prepared parameters.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_AROWS_MAX_STORED = 20000; // Stored groups read per query; past it the answer says truncated.

/**
 * The canonical paths that are the site's own content, built once per request:
 * the home page, the known archive routes, published posts and pages, and tag
 * archives. Everything else a visitor asked for is "(unmatched)".
 *
 * @return array<string,bool>
 */
function snt_arows_real_paths() {
	static $real = null;
	if ( null !== $real ) {
		return $real;
	}
	$real  = array( '/' => true );
	$path  = static fn( $url ) => sn_analytics_canonical_path( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) );
	foreach ( (array) apply_filters( 'snt_alerts_archive_routes', array( '/notes/', '/provenance/' ) ) as $route ) {
		$real[ sn_analytics_canonical_path( (string) $route ) ] = true;
	}
	foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) as $id ) {
		$real[ $path( get_permalink( $id ) ) ] = true;
	}
	$tags = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => true ) );
	foreach ( is_array( $tags ) ? $tags : array() as $t ) {
		$link = get_term_link( $t );
		if ( is_string( $link ) ) {
			$real[ $path( $link ) ] = true;
		}
	}
	unset( $real[''] );
	return $real;
}

/**
 * The stored rows for a validated query over [$from,$to].
 *
 * @param array  $q    From snt_arows_validate().
 * @param string $from First day, YYYY-MM-DD.
 * @param string $to   Last day, YYYY-MM-DD.
 * @return array{rows:array,capped:bool}|WP_Error
 */
function snt_arows_fetch( array $q, $from, $to ) {
	global $wpdb;
	$other = array_values( array_diff( $q['dims'], array( 'day' ) ) )[0] ?? null;
	$day   = in_array( 'day', $q['dims'], true );
	$cap   = SNT_AROWS_MAX_STORED + 1;
	if ( in_array( $other, SNT_AROWS_STORED_DIMS, true ) || null !== $q['referrer'] ) {
		$dim  = $other ?? 'referrer';
		$sql  = 'SELECT value AS ' . array_combine( SNT_AROWS_STORED_DIMS, SNT_AROWS_STORED_DIMS )[ $dim ] . ( $day ? ', day' : '' )
			. ', SUM(views) AS views, SUM(visits) AS visits FROM ' . $wpdb->prefix . SN_ANALYTICS_DIMS_TABLE
			. ' WHERE day >= %s AND day <= %s AND dim = %s AND class = %s';
		$args = array( $from, $to, $dim, $q['class'] );
		if ( null !== $q['referrer'] ) {
			$sql   .= ' AND value IN (%s, %s)'; // older rows may keep "www.".
			$args[] = $q['referrer'];
			$args[] = 'www.' . $q['referrer'];
		}
		$sql .= ' GROUP BY value' . ( $day ? ', day' : '' ) . ' LIMIT %d';
	} else {
		$canon = sn_analytics_canonical_path_sql( 'path' );
		// An engagement sum is returned only when every row in the group has
		// one: a legacy (pre-v5) row has none, and a partial sum would pass for
		// a measured one.
		$full = static fn( $col ) => "CASE WHEN COUNT({$col}) = COUNT(*) THEN SUM({$col}) END AS {$col}";
		$sql  = 'SELECT ' . ( in_array( $other, array( 'path', 'link_code' ), true ) ? "{$canon} AS {$other}, " : '' ) . ( $day ? 'day, ' : '' )
			. 'SUM(views) AS views, SUM(visits) AS visits, ' . implode( ', ', array_map( $full, array( 'pageview_visits', 'time_sum', 'time_events', 'scroll_events' ) ) )
			. ' FROM ' . $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE . ' WHERE day >= %s AND day <= %s AND class = %s';
		$args = array( $from, $to, $q['class'] );
		if ( null !== $q['path'] ) {
			$sql   .= " AND {$canon} = %s";
			$args[] = $q['path'];
		}
		if ( 'link_code' === $other ) { // coded résumé links only; snt_arows_clean() reads the code out.
			$sql   .= ' AND path LIKE %s';
			$args[] = $wpdb->esc_like( '/r/' ) . '%';
		}
		$group = array_filter( array( in_array( $other, array( 'path', 'link_code' ), true ) ? $canon : '', $day ? 'day' : '' ) );
		$sql  .= ( $group ? ' GROUP BY ' . implode( ', ', $group ) : '' ) . ' LIMIT %d';
	}
	$args[] = $cap;
	$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragments come from fixed arrays; values are placeholders.
	if ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) {
		return new WP_Error( 'sn_analytics_read_failed', 'The stored analytics read failed; this is not an empty answer.', array( 'status' => 500 ) );
	}
	$rows = is_array( $rows ) ? $rows : array();
	return array( 'rows' => array_slice( $rows, 0, SNT_AROWS_MAX_STORED ), 'capped' => count( $rows ) > SNT_AROWS_MAX_STORED );
}
