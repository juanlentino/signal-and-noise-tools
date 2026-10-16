<?php
/**
 * Signal & Noise Tools: rolling live snapshots, the pure half.
 *
 * Three grouped reads, each per 5-minute slot over the last 60 minutes as
 * the hour already is (inc/analytics-live-hour.php): readers by traffic
 * class, the pages being read and where readers arrived from. Each mirrors
 * its live twin's rule (the class split mirrors Reading now's per-class
 * count, pages and sources their 5-minute queries), so a stored slot reads
 * like a live panel taken at that moment. The live payload's own queries are
 * untouched: a rolling 5-minute window cannot be rebuilt from aligned slots,
 * and distinct counts do not add up.
 *
 * Aggregates only: a slot holds counts, page labels and URLs that already
 * pass the live panel's public-page resolver, and source names. No visitor
 * id, IP, user agent, cookie or request text ever reaches the store. No
 * floor, as live has none (owner, 2026-10-16).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_LIVE_SLOTS_OPT  = 'sn_live_slot_detail';
const SN_ANALYTICS_LIVE_SLOTS_KEEP = 86400;
const SN_ANALYTICS_LIVE_SLOTS_ROWS = 1000;

/**
 * The slot column every per-slot read selects. PURE.
 *
 * @return string
 */
function sn_analytics_live_slot_col() {
	return "toUnixTimestamp(toStartOfInterval(timestamp, INTERVAL '5' MINUTE)) AS slot";
}

/**
 * Readers per slot and traffic class, Reading now's rule per slot.
 *
 * @return string AE SQL.
 */
function sn_analytics_live_slot_classes_sql() {
	return implode( ' ', array(
		'SELECT ' . sn_analytics_live_slot_col() . ', ' . sn_analytics_class_select() . ' AS class, count(DISTINCT index1) AS readers',
		'FROM ' . sn_analytics_source( sn_analytics_trailing_from( 0 ) ),
		"WHERE timestamp >= now() - INTERVAL '60' MINUTE" . sn_analytics_overcap_where(),
		'GROUP BY slot, class ORDER BY slot',
	) );
}

/**
 * Human readers per slot and path, the live pages rule per slot.
 *
 * @return string AE SQL.
 */
function sn_analytics_live_slot_pages_sql() {
	return implode( ' ', array(
		'SELECT ' . sn_analytics_live_slot_col() . ', blob2 AS path, count(DISTINCT index1) AS readers',
		'FROM ' . sn_analytics_source( sn_analytics_trailing_from( 0 ) ),
		"WHERE timestamp >= now() - INTERVAL '60' MINUTE AND " . sn_analytics_class_where( 'human' ) . sn_analytics_excluded_path_sql() . sn_analytics_overcap_where(),
		'GROUP BY slot, path ORDER BY slot, readers DESC LIMIT ' . SN_ANALYTICS_LIVE_SLOTS_ROWS,
	) );
}

/**
 * Human readers per slot and referrer host, page views only, the live
 * sources rule per slot.
 *
 * @return string AE SQL.
 */
function sn_analytics_live_slot_sources_sql() {
	return implode( ' ', array(
		'SELECT ' . sn_analytics_live_slot_col() . ', blob3 AS host, count(DISTINCT index1) AS readers',
		'FROM ' . sn_analytics_source( sn_analytics_trailing_from( 0 ) ),
		"WHERE timestamp >= now() - INTERVAL '60' MINUTE AND blob1 = 'pv' AND " . sn_analytics_class_where( 'human' ) . sn_analytics_excluded_path_sql() . sn_analytics_overcap_where(),
		'GROUP BY slot, host ORDER BY slot, readers DESC LIMIT ' . SN_ANALYTICS_LIVE_SLOTS_ROWS,
	) );
}

/**
 * Rows grouped by slot start. PURE.
 *
 * @param array $rows AE rows carrying `slot`.
 * @return array<int,array<int,array>>
 */
function sn_analytics_live_slot_group( array $rows ) {
	$by = array();
	foreach ( $rows as $row ) {
		if ( is_array( $row ) && isset( $row['slot'] ) && is_numeric( $row['slot'] ) ) {
			$by[ intdiv( (int) $row['slot'], SN_ANALYTICS_LIVE_HOUR_SLOT ) * SN_ANALYTICS_LIVE_HOUR_SLOT ][] = $row;
		}
	}
	return $by;
}

/**
 * The completed slots of one refresh, in their stored shape. PURE given the
 * resolver. A completed slot the reads left out is a measured quiet slot:
 * zero readers, empty lists. Readers is the hour's own figure, so a stored
 * slot and the bar it was drawn from never disagree.
 *
 * @param array      $hour       Twelve { t, readers }, the last one current.
 * @param array      $classes    sn_analytics_live_slot_classes_sql() rows.
 * @param array      $pages      sn_analytics_live_slot_pages_sql() rows.
 * @param array      $sources    sn_analytics_live_slot_sources_sql() rows.
 * @param int        $now        Unix time.
 * @param callable   $resolve    The live pages resolver.
 * @param array|null $self_hosts The site's own hosts (null reads the site's).
 * @return array<int,array{readers:int,classes:array<string,int>,pages:array,sources:array}>
 */
function sn_analytics_live_slots_from_rows( array $hour, array $classes, array $pages, array $sources, $now, callable $resolve, $self_hosts = null ) {
	$cur  = intdiv( (int) $now, SN_ANALYTICS_LIVE_HOUR_SLOT ) * SN_ANALYTICS_LIVE_HOUR_SLOT;
	$cls  = sn_analytics_live_slot_group( $classes );
	$pg   = sn_analytics_live_slot_group( $pages );
	$src  = sn_analytics_live_slot_group( $sources );
	$out  = array();
	foreach ( $hour as $s ) {
		if ( ! is_array( $s ) || ! isset( $s['t'], $s['readers'] ) || (int) $s['t'] >= $cur ) {
			continue;
		}
		$t     = (int) $s['t'];
		$split = array( 'human' => 0, 'suspect' => 0, 'bot' => 0 );
		foreach ( $cls[ $t ] ?? array() as $row ) {
			$c = (string) ( $row['class'] ?? '' );
			if ( isset( $split[ $c ] ) ) {
				$split[ $c ] = max( 0, (int) ( $row['readers'] ?? 0 ) );
			}
		}
		$out[ $t ] = array(
			'readers' => max( 0, (int) $s['readers'] ),
			'classes' => $split,
			'pages'   => sn_analytics_live_pages_from_rows( $pg[ $t ] ?? array(), $resolve ),
			'sources' => sn_analytics_live_sources_from_rows( $src[ $t ] ?? array(), $self_hosts ),
		);
	}
	return $out;
}

/**
 * Merge new slots into the store and drop anything older than a day. PURE.
 *
 * @param array $store slot start => slot.
 * @param array $fresh slot start => slot.
 * @param int   $now   Unix time.
 * @return array
 */
function sn_analytics_live_slots_merge( array $store, array $fresh, $now ) {
	foreach ( $fresh as $t => $slot ) {
		$store[ (int) $t ] = $slot;
	}
	foreach ( array_keys( $store ) as $t ) {
		if ( (int) $t < (int) $now - SN_ANALYTICS_LIVE_SLOTS_KEEP ) {
			unset( $store[ $t ] );
		}
	}
	ksort( $store );
	return $store;
}

/**
 * The stored slots of the last `$hours`, oldest first, each in the hour's
 * shape plus its detail: { t, readers, classes, pages, sources }. Slots past
 * the day are never returned, whether or not a write has pruned them yet.
 * PURE.
 *
 * @param array $store slot start => slot.
 * @param int   $hours 1 to 24.
 * @param int   $now   Unix time.
 * @return array<int,array<string,mixed>>
 */
function sn_analytics_live_slots_window( array $store, $hours, $now ) {
	$hours = max( 1, min( 24, (int) $hours ) );
	$from  = max( (int) $now - $hours * 3600, (int) $now - SN_ANALYTICS_LIVE_SLOTS_KEEP );
	$out   = array();
	ksort( $store );
	foreach ( $store as $t => $slot ) {
		if ( (int) $t >= $from && is_array( $slot ) ) {
			$out[] = array( 't' => (int) $t ) + $slot;
		}
	}
	return $out;
}
