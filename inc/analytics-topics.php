<?php
/**
 * Signal & Noise — topic-level analytics join (v10.21.0, ML pipeline #4's
 * consumer): the stored topic partition (post groupings from the corpus
 * artifact — NEVER reader data; aggregates only, per the ML program's
 * reader-profiling never) × the durable path-keyed rollup table.
 *
 * Local reads only — never Analytics Engine: per-topic totals want the
 * forever-retained, sample-corrected daily table, and the admin page's
 * durable-first rule holds.
 *
 * Zero-vs-null contract (both directions, the analytics convention):
 *   - topics artifact never built → null (unknown, not zero);
 *   - a FAILED rollup read (wpdb last_error) → null, never [];
 *   - a built-but-clusterless corpus, no mapped paths, or an all-quiet
 *     window → [] (a real answer).
 * Callers own the [$from,$to] window — this file adds ZERO date math.
 *
 * @package SignalNoiseTools
 * @since 10.21.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregate human views/visits per topic over a window.
 *
 * One bounded query covers every member path; sums group per topic in PHP.
 * Members without a resolvable path shrink their topic honestly (notes counts
 * members, paths counts the mapped ones). Topics with zero measured traffic
 * in the window are dropped — the panel reports movement, not existence.
 *
 * @param string $from Window start (Y-m-d), caller-owned zone.
 * @param string $to   Window end (Y-m-d), caller-owned zone.
 * @return array<int,array{label:string,notes:int,paths:int,views:int,visits:int,member_paths:array<int,string>}>|null
 *         Rows views-descending; [] = a real empty answer; null = unknown
 *         (artifact never built, or the rollup read failed).
 */
function sn_analytics_topic_totals( $from, $to ) {
	if ( ! function_exists( 'snt_ml_topics_get' ) || ! function_exists( 'sn_analytics_post_path' ) ) {
		return null; // Partial install: unknown, never a confident zero.
	}
	$topics = snt_ml_topics_get();
	if ( null === $topics ) {
		return null; // Never built: unknown. The renderer speaks this state itself.
	}
	if ( array() === $topics ) {
		return array();
	}

	// Map members → paths once; collect the union for the single query.
	$mapped    = array();
	$all_paths = array();
	foreach ( $topics as $ti => $topic ) {
		$paths = array();
		foreach ( (array) ( $topic['members'] ?? array() ) as $member ) {
			$path = sn_analytics_post_path( (int) $member );
			if ( is_string( $path ) && '' !== $path ) {
				$paths[]              = $path;
				$all_paths[ $path ] = true;
			}
		}
		$mapped[ $ti ] = $paths;
	}
	if ( array() === $all_paths ) {
		return array(); // No member resolves to a path: nothing to measure, no query.
	}

	global $wpdb;
	$paths        = array_keys( $all_paths );
	$placeholders = implode( ',', array_fill( 0, count( $paths ), '%s' ) );
	$sql          = "SELECT path, SUM(views) AS views, SUM(visits) AS visits FROM {$wpdb->prefix}sn_analytics_daily WHERE day BETWEEN %s AND %s AND class = %s AND path IN ($placeholders) GROUP BY path"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only; values ride prepare below.
	$results      = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( (string) $from, (string) $to, 'human' ), $paths ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line up.
	if ( ! is_array( $results ) || '' !== (string) $wpdb->last_error ) {
		return null; // A failed read is UNKNOWN — never dressed as an empty window.
	}

	$by_path = array();
	foreach ( $results as $row ) {
		$by_path[ (string) $row->path ] = array( 'views' => (int) $row->views, 'visits' => (int) $row->visits );
	}

	$out = array();
	foreach ( $topics as $ti => $topic ) {
		$views  = 0;
		$visits = 0;
		foreach ( $mapped[ $ti ] as $path ) {
			$views  += (int) ( $by_path[ $path ]['views'] ?? 0 );
			$visits += (int) ( $by_path[ $path ]['visits'] ?? 0 );
		}
		if ( 0 === $views && 0 === $visits ) {
			continue; // Movement report: a topic nobody read this window stays out.
		}
		$out[] = array(
			'label'        => (string) ( $topic['label'] ?? '' ),
			'notes'        => count( (array) ( $topic['members'] ?? array() ) ),
			'paths'        => count( $mapped[ $ti ] ),
			'views'        => $views,
			'visits'       => $visits,
			'member_paths' => $mapped[ $ti ],
		);
	}
	usort( $out, static function ( $a, $b ) {
		if ( $a['views'] === $b['views'] ) {
			return strcmp( $a['label'], $b['label'] ); // Deterministic ties.
		}
		return $b['views'] <=> $a['views'];
	} );
	return $out;
}
