<?php
/**
 * Signal & Noise Tools: publish windows, the pure half.
 *
 * A note's publish window is [published_at, published_at + length): the
 * first `length` minutes after it really went live (`_sn_published_at`,
 * inc/publish-window.php). Half-open: a hit at exactly published_at + length
 * is AFTER the window. The figures are the SN Traffic Lanes beacon
 * derivation (inc/analytics-lanes-beacon.php) run on one note's path, with a
 * `part` column that labels each row 'window' or 'after'; every row lands in
 * exactly one part, so window + after is the total, lane by lane.
 * `publish_window` is a label beside the lanes, never a ninth lane.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_PUBLISH_WINDOW_META      = '_sn_published_at';
const SN_PUBLISH_WINDOW_CACHE     = 'sn_publish_window_cache';
const SN_PUBLISH_WINDOW_DEFAULT   = 60;
const SN_PUBLISH_WINDOW_MIN       = 5;
const SN_PUBLISH_WINDOW_MAX       = 1440;
const SN_PUBLISH_WINDOW_KEEP_DAYS = 90;  // Analytics Engine keeps 90 days; a figure it can no longer re-read is dropped too.
const SN_PUBLISH_WINDOW_CACHE_MAX = 200; // ponytail: a hard cap on entries; newest kept. Raise if the site ever publishes 200 notes in 90 days.

/**
 * A window length in minutes, clamped. PURE.
 *
 * @param mixed $raw Minutes.
 * @return int
 */
function sn_publish_window_clamp( $raw ) {
	return is_numeric( $raw ) ? max( SN_PUBLISH_WINDOW_MIN, min( SN_PUBLISH_WINDOW_MAX, (int) $raw ) ) : SN_PUBLISH_WINDOW_DEFAULT;
}

/**
 * Which part a hit belongs to. The PHP twin of the SQL `part` column. PURE.
 *
 * @param int $ts      Hit time.
 * @param int $start   published_at.
 * @param int $minutes Window length.
 * @return string|null 'window', 'after', or null before the note went live.
 */
function sn_publish_window_part( $ts, $start, $minutes ) {
	if ( (int) $ts < (int) $start ) {
		return null;
	}
	return (int) $ts < (int) $start + 60 * (int) $minutes ? 'window' : 'after';
}

/**
 * The note's path and its trailing-slash twin, or [] when the path is not a
 * plain URL path (nothing else is ever interpolated). PURE.
 *
 * @param string $path URL path.
 * @return string[]
 */
function sn_publish_window_paths( $path ) {
	$path = (string) $path;
	if ( 1 !== preg_match( '#^/[A-Za-z0-9\-._~/%]*\z#', $path ) ) {
		return array();
	}
	$bare = '/' === $path ? '/' : rtrim( $path, '/' );
	return '/' === $bare ? array( '/' ) : array( $bare . '/', $bare );
}

/**
 * The scope the lanes builders take: this note's path from `$from`, each row
 * labelled by `$end`. Null when the path cannot be read safely. PURE.
 *
 * @param string $path Note path.
 * @param int    $from First instant read (published_at, or the window end once cached).
 * @param int    $end  The window end.
 * @return array{where:string,part:string}|null
 */
function sn_publish_window_scope( $path, $from, $end ) {
	$paths = sn_publish_window_paths( $path );
	if ( array() === $paths ) {
		return null;
	}
	$f = gmdate( 'Y-m-d H:i:s', (int) $from );
	$e = gmdate( 'Y-m-d H:i:s', (int) $end );
	return array(
		'where' => "timestamp >= toDateTime('{$f}') AND blob2 IN ('" . implode( "','", $paths ) . "')",
		'part'  => "if(timestamp < toDateTime('{$e}'), 'window', 'after')",
	);
}

/**
 * Human readers per referrer host inside the window, page views only. PURE.
 *
 * @param string $source Dataset.
 * @param string $path   Note path.
 * @param int    $start  published_at.
 * @param int    $end    Window end.
 * @return string '' when the path cannot be read safely.
 */
function sn_publish_window_sources_sql( $source, $path, $start, $end ) {
	$paths = sn_publish_window_paths( $path );
	if ( array() === $paths ) {
		return '';
	}
	return 'SELECT blob3 AS host, count(DISTINCT index1) AS readers FROM ' . $source
		. " WHERE timestamp >= toDateTime('" . gmdate( 'Y-m-d H:i:s', (int) $start ) . "') AND timestamp < toDateTime('" . gmdate( 'Y-m-d H:i:s', (int) $end ) . "')"
		. " AND blob2 IN ('" . implode( "','", $paths ) . "') AND blob1 = 'pv' AND " . sn_analytics_class_where( 'human' ) . sn_analytics_excluded_path_sql()
		. ' GROUP BY host ORDER BY readers DESC LIMIT 40';
}

/**
 * Derived lane rows to { part => { lane => { hits, visits } } }. PURE.
 *
 * @param array<string,array<int,array>> $derived sn_lanes_beacon_derive() keyed by part.
 * @return array<string,array<string,array{hits:int,visits:int}>>
 */
function sn_publish_window_collapse( array $derived ) {
	$out = array( 'window' => array(), 'after' => array() );
	foreach ( $derived as $part => $rows ) {
		if ( ! isset( $out[ $part ] ) ) {
			continue;
		}
		foreach ( (array) $rows as $r ) {
			$lane = (string) ( $r['lane'] ?? '' );
			$cell = $out[ $part ][ $lane ] ?? array( 'hits' => 0, 'visits' => 0 );
			$cell['hits']   += (int) ( $r['hits'] ?? 0 );
			$cell['visits'] += (int) ( $r['visits'] ?? 0 );
			$out[ $part ][ $lane ] = $cell;
		}
		ksort( $out[ $part ] );
	}
	return $out;
}

/**
 * The cache with one entry set and the bound applied: entries for windows
 * older than Analytics Engine's 90 days go, then the oldest past the cap. PURE.
 *
 * @param array    $cache   post id => entry { published_at, ... }.
 * @param int      $post_id Entry to set (0: none, prune only).
 * @param array    $entry   The entry.
 * @param int      $now     Unix time.
 * @return array
 */
function sn_publish_window_cache_put( array $cache, $post_id, array $entry, $now ) {
	if ( (int) $post_id > 0 ) {
		$cache[ (int) $post_id ] = $entry;
	}
	$cache = array_filter( $cache, static fn( $e ) => is_array( $e ) && (int) ( $e['published_at'] ?? 0 ) >= (int) $now - SN_PUBLISH_WINDOW_KEEP_DAYS * 86400 );
	uasort( $cache, static fn( $a, $b ) => (int) $b['published_at'] <=> (int) $a['published_at'] );
	return array_slice( $cache, 0, SN_PUBLISH_WINDOW_CACHE_MAX, true );
}

/**
 * Whether `_sn_published_at` should be (re)written on a transition to
 * publish. Written once, on the first publish; a republish keeps it, unless
 * the post's date was moved past it (the owner rescheduled a relaunch). PURE.
 *
 * @param int|string $stored   The stored value ('' when absent).
 * @param int        $date_gmt The post's post_date_gmt as unix time.
 * @return bool
 */
function sn_publish_window_should_stamp( $stored, $date_gmt ) {
	return ! is_numeric( $stored ) || (int) $stored < 1 || (int) $date_gmt > (int) $stored;
}
