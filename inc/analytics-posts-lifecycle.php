<?php
/**
 * Signal & Noise — A4: path decay at scale (the "Lifecycle" leaderboard).
 *
 * sn_analytics_posts_decay() (inc/analytics-posts.php) classifies a single post's
 * decay shape from its early-life share of lifetime views. The Posts view already
 * runs it across the 12 most-recent Notes. This module runs it across the WHOLE
 * published catalogue (capped for scale) and cross-references the B5 evergreen
 * flag (inc/post-evergreen.php) to surface REFRESH CANDIDATES — posts the data
 * says are cooling that the editor has NOT marked evergreen.
 *
 * At scale the per-path series is read with ONE grouped query over the durable
 * daily rollup (not N per-path queries); everything else is pure + unit-tested.
 * The subject/cohort age-aligned comparison stays in analytics-posts.php — this is
 * the catalogue-wide shape census, a different question.
 *
 * @package signal-and-noise-tools
 * @since 8.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-posts.php'; // decay/velocity/by-dol primitives.

const SN_POSTS_LIFECYCLE_MAX = 200;  // catalogue scan ceiling (matches the health stale-scan cap).
const SN_POSTS_LIFECYCLE_TTL = 21600; // 6h transient — a whole-catalogue read, refreshed a few times a day.

/* ─────────────────────────────── pure logic ─────────────────────────────── */

/**
 * Classify one post's lifecycle: its decay shape plus whether it's a refresh
 * candidate. A candidate is a post the data says is COOLING that the editor has
 * NOT flagged evergreen — the actionable intersection of A4 and B5. Spikes
 * (front-loaded, their moment passed) and evergreen shapes are never candidates.
 *
 * @param array<int,int> $by_dol       [day_of_life => views]
 * @param int            $early_days   Early-life window (SN_POSTS_DECAY_DAYS).
 * @param bool           $is_evergreen Editor's _sn_evergreen flag.
 * @return array{decay:string,refresh_candidate:bool}
 */
function sn_analytics_lifecycle_classify( $by_dol, $early_days, $is_evergreen ) {
	$decay     = sn_analytics_posts_decay( $by_dol, $early_days );
	$candidate = ( 'cooling' === $decay ) && ! $is_evergreen;
	return array(
		'decay'             => $decay,
		'refresh_candidate' => $candidate,
	);
}

/**
 * Build one lifecycle row per post from the batched per-path series. Pure: the
 * caller supplies posts (with the evergreen flag resolved) and the path→series
 * map from the single grouped query. Lifetime is summed from the series, so no
 * separate per-path lifetime query is needed.
 *
 * @param array $posts          [{id,title,permalink,path,publish_ts,modified_ts,evergreen}]
 * @param array $series_by_path [path => [{day:'Y-m-d',views:int}]]
 * @param int   $now            Unix "now" (age reference).
 * @return array<int,array<string,mixed>>
 */
function sn_analytics_posts_lifecycle_rows( $posts, $series_by_path, $now ) {
	$rows = array();
	foreach ( (array) $posts as $p ) {
		$path      = (string) ( $p['path'] ?? '' );
		$series    = ( '' !== $path && isset( $series_by_path[ $path ] ) ) ? (array) $series_by_path[ $path ] : array();
		$publish   = (int) ( $p['publish_ts'] ?? 0 );
		$by_dol    = sn_analytics_posts_daily_by_dol( $series, $publish );
		$lifetime  = 0;
		foreach ( $by_dol as $v ) {
			$lifetime += (int) $v;
		}
		$age       = ( $publish > 0 ) ? (int) floor( ( (int) $now - $publish ) / DAY_IN_SECONDS ) : 0;
		$evergreen = ! empty( $p['evergreen'] );
		$cls       = sn_analytics_lifecycle_classify( $by_dol, SN_POSTS_DECAY_DAYS, $evergreen );

		$rows[] = array(
			'id'                => (int) ( $p['id'] ?? 0 ),
			'title'             => (string) ( $p['title'] ?? '' ),
			'permalink'         => (string) ( $p['permalink'] ?? '' ),
			'age'               => $age,
			'lifetime'          => $lifetime,
			'per_day'           => $age > 0 ? round( $lifetime / ( $age + 1 ), 1 ) : (float) $lifetime,
			'decay'             => $cls['decay'],
			'evergreen'         => $evergreen,
			'refresh_candidate' => $cls['refresh_candidate'],
			'modified_ts'       => (int) ( $p['modified_ts'] ?? 0 ),
		);
	}
	return $rows;
}

/**
 * Roll the rows up into a shape census + refresh-candidate total. The empty-shape
 * ('' — no traffic yet) rows bucket as 'unknown'.
 *
 * @param array $rows Lifecycle rows.
 * @return array{counts:array{spike:int,cooling:int,evergreen:int,unknown:int},refresh_candidates:int,total:int}
 */
function sn_analytics_lifecycle_summary( $rows ) {
	$counts     = array( 'spike' => 0, 'cooling' => 0, 'evergreen' => 0, 'unknown' => 0 );
	$candidates = 0;
	foreach ( (array) $rows as $r ) {
		$decay = (string) ( $r['decay'] ?? '' );
		$key   = isset( $counts[ $decay ] ) ? $decay : 'unknown';
		++$counts[ $key ];
		if ( ! empty( $r['refresh_candidate'] ) ) {
			++$candidates;
		}
	}
	return array(
		'counts'             => $counts,
		'refresh_candidates' => $candidates,
		'total'             => count( (array) $rows ),
	);
}

/**
 * Ordering for the leaderboard: refresh candidates first (the actionable set),
 * then by lifetime views descending. Stable via a decorate-sort-undecorate so
 * equal keys keep input order.
 *
 * @param array $rows Lifecycle rows.
 * @return array Re-ordered rows.
 */
function sn_analytics_lifecycle_sort( $rows ) {
	$rows = array_values( (array) $rows );
	$idx  = array();
	foreach ( $rows as $i => $r ) {
		$idx[ $i ] = $r;
	}
	uasort(
		$idx,
		function ( $a, $b ) use ( $rows ) {
			$ac = ! empty( $a['refresh_candidate'] ) ? 1 : 0;
			$bc = ! empty( $b['refresh_candidate'] ) ? 1 : 0;
			if ( $ac !== $bc ) {
				return $bc <=> $ac; // candidates first
			}
			return (int) ( $b['lifetime'] ?? 0 ) <=> (int) ( $a['lifetime'] ?? 0 );
		}
	);
	return array_values( $idx );
}

/* ───────────────────────── orchestration (impure) ───────────────────────── */

/**
 * All published post/pages (capped) with the fields the lifecycle rows need,
 * including the resolved evergreen flag.
 *
 * @param int $limit Scan ceiling.
 * @return array<int,array<string,mixed>>
 */
function sn_analytics_posts_lifecycle_catalogue( $limit = SN_POSTS_LIFECYCLE_MAX ) {
	$q = new WP_Query( array(
		'post_type'           => array( 'post' ),
		'post_status'         => 'publish',
		'posts_per_page'      => max( 1, (int) $limit ),
		'orderby'             => 'date',
		'order'               => 'DESC',
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	) );
	$out = array();
	foreach ( (array) $q->posts as $p ) {
		$out[] = array(
			'id'          => (int) $p->ID,
			'title'       => get_the_title( $p->ID ),
			'permalink'   => (string) get_permalink( $p->ID ),
			'path'        => sn_analytics_post_path( $p->ID ),
			'publish_ts'  => (int) get_post_time( 'U', true, $p->ID ),
			'modified_ts' => (int) get_post_modified_time( 'U', true, $p->ID ),
			'evergreen'   => function_exists( 'sn_post_is_evergreen' ) ? sn_post_is_evergreen( $p->ID ) : false,
		);
	}
	return $out;
}

/**
 * ONE grouped read of the durable daily rollup for a set of paths — the "at
 * scale" query that replaces N per-path series reads.
 *
 * @param string[] $paths Stored paths.
 * @return array<string,array<int,array{day:string,views:int}>> path => series
 */
function sn_analytics_paths_daily_series( $paths ) {
	global $wpdb;
	$paths = array_values( array_unique( array_filter( array_map( 'strval', (array) $paths ) ) ) );
	if ( empty( $paths ) ) {
		return array();
	}
	$table        = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;
	$placeholders = implode( ', ', array_fill( 0, count( $paths ), '%s' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a fixed %s list; $paths bound below.
	$sql  = "SELECT path, day, SUM(views) AS views FROM {$table}
			 WHERE class = 'human' AND path IN ({$placeholders})
			 GROUP BY path, day ORDER BY path ASC, day ASC";
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $paths ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$out  = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $r ) {
			$out[ (string) $r['path'] ][] = array( 'day' => (string) $r['day'], 'views' => (int) $r['views'] );
		}
	}
	return $out;
}

/**
 * The full lifecycle bundle: ordered rows + shape census. Transient-cached (a
 * whole-catalogue read). Null when there are no published posts.
 *
 * @param int $limit Scan ceiling.
 * @return array{rows:array,summary:array,generated:int}|null
 */
function sn_analytics_posts_lifecycle( $limit = SN_POSTS_LIFECYCLE_MAX ) {
	$catalogue = sn_analytics_posts_lifecycle_catalogue( $limit );
	if ( empty( $catalogue ) ) {
		return null;
	}

	$ids = wp_list_pluck( $catalogue, 'id' );
	// Fold the evergreen flags into the key so ticking a post's "Evergreen" box (the
	// B5 editorial override this whole arc exists to honour) busts this bundle
	// immediately — otherwise a freshly-flagged post would keep reading as a refresh
	// candidate for up to the 6h TTL, on the exact same-day traffic.
	$evergreen_sig = implode( '', array_map(
		function ( $p ) {
			return ! empty( $p['evergreen'] ) ? '1' : '0';
		},
		$catalogue
	) );
	$cache_key = 'sn_posts_lifecycle_' . md5( implode( ',', $ids ) . '|' . $evergreen_sig . '|' . gmdate( 'Y-m-d' ) );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$paths    = array_filter( wp_list_pluck( $catalogue, 'path' ) );
	$series   = sn_analytics_paths_daily_series( $paths );
	$rows     = sn_analytics_posts_lifecycle_rows( $catalogue, $series, time() );
	$bundle   = array(
		'rows'      => sn_analytics_lifecycle_sort( $rows ),
		'summary'   => sn_analytics_lifecycle_summary( $rows ),
		'generated' => time(),
	);
	set_transient( $cache_key, $bundle, SN_POSTS_LIFECYCLE_TTL );
	return $bundle;
}
