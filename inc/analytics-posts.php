<?php
/**
 * Signal & Noise — Posts (lifecycle) analytics data layer.
 *
 * The unit of analysis is the POST and its AGE/lifetime, not a dimension slice —
 * the one axis no other analytics view covers (Content/Geography/Technology/
 * Engagement are all "audience over a date range, sliced by a dimension"). Every
 * figure is read from the DURABLE per-path daily rollup (wp_sn_analytics_daily,
 * forever-retained, sample-corrected views) with a `WHERE path = %s` predicate —
 * no Analytics Engine call, no sampling, no retention ceiling.
 *
 * Pure helpers (cumulative-by-day-of-life, median, velocity, decay, rank) carry
 * the age-alignment math and are unit-tested in tests/analytics-posts.php.
 *
 * @package signal-and-noise-tools
 * @since 6.39.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Classification windows + thresholds (named, not magic).
const SN_POSTS_RECENT_LIMIT  = 12;   // cohort size for the baseline + leaderboard.
const SN_POSTS_VELOCITY_DAYS = 2;    // "launch window" ≈ first 48h (daily granularity).
const SN_POSTS_DECAY_DAYS    = 7;    // early-life window for the sustained/spike split.
const SN_POSTS_SPIKE_SHARE   = 0.8;  // ≥ this share of lifetime views in week 1 → spike.
const SN_POSTS_SUSTAINED_SHARE = 0.5; // ≤ this share → sustained (a long tail, not a spike).
const SN_POSTS_BUNDLE_TTL    = 900;  // 15-min transient cache for the N-read bundle.

/* ───────────────────────── pure age-alignment math ───────────────────────── */

/**
 * A post's canonical stored path — the same string the beacon records as blob2
 * (location.pathname; pretty permalinks carry a trailing slash). '' when unknown.
 *
 * @param int $id Post ID.
 * @return string Path, or '' if the permalink can't be resolved.
 */
function sn_analytics_post_path( $id ) {
	$url = get_permalink( (int) $id );
	if ( ! is_string( $url ) || '' === $url ) {
		return '';
	}
	$path = wp_parse_url( $url, PHP_URL_PATH );
	return is_string( $path ) ? $path : '';
}

/**
 * Re-index a calendar-day series to day-of-life (publish day = 0). Days before
 * publish are dropped; gap days are simply absent (not zero-filled).
 *
 * @param array $series     [{day:'Y-m-d', views:int}] ascending.
 * @param int   $publish_ts Unix ts of the publish moment (floored to its UTC day).
 * @return array<int,int> [day_of_life => views]
 */
function sn_analytics_posts_daily_by_dol( $series, $publish_ts ) {
	$pub_day = strtotime( gmdate( 'Y-m-d', (int) $publish_ts ) . ' 00:00:00 UTC' );
	$out     = array();
	foreach ( (array) $series as $row ) {
		$day_ts = strtotime( (string) ( $row['day'] ?? '' ) . ' 00:00:00 UTC' );
		if ( false === $day_ts ) {
			continue;
		}
		$dol = (int) round( ( $day_ts - $pub_day ) / DAY_IN_SECONDS );
		if ( $dol < 0 ) {
			continue;
		}
		$out[ $dol ] = ( $out[ $dol ] ?? 0 ) + (int) ( $row['views'] ?? 0 );
	}
	return $out;
}

/**
 * Cumulative views through day-of-life $age (inclusive). Reads past the last
 * recorded day just return the lifetime-so-far.
 *
 * @param array<int,int> $by_dol [day_of_life => views]
 * @param int            $age
 * @return int
 */
function sn_analytics_posts_cumulative_at( $by_dol, $age ) {
	$sum = 0;
	foreach ( (array) $by_dol as $dol => $views ) {
		if ( (int) $dol >= 0 && (int) $dol <= (int) $age ) {
			$sum += (int) $views;
		}
	}
	return $sum;
}

/**
 * Median of a numeric list. Empty → 0 (the caller reads 0 as "no baseline").
 *
 * @param array $vals
 * @return int|float
 */
function sn_analytics_median( $vals ) {
	$vals = array_values( array_map( 'floatval', (array) $vals ) );
	$n    = count( $vals );
	if ( 0 === $n ) {
		return 0;
	}
	sort( $vals, SORT_NUMERIC );
	$mid = intdiv( $n, 2 );
	if ( 1 === $n % 2 ) {
		$v = $vals[ $mid ];
		return ( $v === floor( $v ) ) ? (int) $v : $v; // keep ints integral.
	}
	return ( $vals[ $mid - 1 ] + $vals[ $mid ] ) / 2;
}

/**
 * Launch velocity — views in the first $n days of life.
 *
 * @param array<int,int> $by_dol
 * @param int            $n
 * @return int
 */
function sn_analytics_posts_velocity( $by_dol, $n ) {
	$sum = 0;
	foreach ( (array) $by_dol as $dol => $views ) {
		if ( (int) $dol >= 0 && (int) $dol < (int) $n ) {
			$sum += (int) $views;
		}
	}
	return $sum;
}

/**
 * Decay classification from the early-life share of lifetime views.
 * '' when there is no data; else 'spike' | 'cooling' | 'sustained'.
 *
 * @param array<int,int> $by_dol
 * @param int            $early_days
 * @return string
 */
function sn_analytics_posts_decay( $by_dol, $early_days ) {
	$total = 0;
	foreach ( (array) $by_dol as $views ) {
		$total += (int) $views;
	}
	if ( $total <= 0 ) {
		return '';
	}
	$early = sn_analytics_posts_velocity( $by_dol, $early_days );
	$share = $early / $total;
	if ( $share >= SN_POSTS_SPIKE_SHARE ) {
		return 'spike';
	}
	if ( $share <= SN_POSTS_SUSTAINED_SHARE ) {
		return 'sustained';
	}
	return 'cooling';
}

/**
 * The subject's 1-based rank among {subject} ∪ cohort (higher value = better
 * rank). Ties resolve to the better rank (strictly-greater count + 1).
 *
 * @param int|float       $subject
 * @param array<int,mixed> $cohort
 * @return array{rank:int,of:int}
 */
function sn_analytics_posts_rank( $subject, $cohort ) {
	$cohort  = array_values( array_map( 'floatval', (array) $cohort ) );
	$greater = 0;
	foreach ( $cohort as $v ) {
		if ( $v > (float) $subject ) {
			++$greater;
		}
	}
	return array( 'rank' => $greater + 1, 'of' => count( $cohort ) + 1 );
}

/* ───────────────────────── durable-rollup accessors ──────────────────────── */

/**
 * Daily human views for one path over [from,to] — a `WHERE path = %s` clone of
 * sn_analytics_top_paths. views only (sample-corrected); visits is a raw estimate
 * and is deliberately not surfaced as a count by this view.
 *
 * @param string $path
 * @param string $from YYYY-MM-DD
 * @param string $to   YYYY-MM-DD
 * @return array<int,array{day:string,views:int}>
 */
function sn_analytics_path_daily_series( $path, $from, $to ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT day, SUM(views) AS views
		 FROM {$table}
		 WHERE path = %s AND class = 'human' AND day >= %s AND day <= %s
		 GROUP BY day ORDER BY day ASC",
		(string) $path,
		(string) $from,
		(string) $to
	), ARRAY_A );
	$out = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $r ) {
			$out[] = array( 'day' => (string) $r['day'], 'views' => (int) $r['views'] );
		}
	}
	return $out;
}

/**
 * Lifetime human views for one path (all days).
 *
 * @param string $path
 * @return int
 */
function sn_analytics_path_lifetime( $path ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(views) FROM {$table} WHERE path = %s AND class = 'human'",
		(string) $path
	) );
}

/**
 * Views and visits for ONE path over an inclusive day window, from the
 * durable daily table, human class.
 *
 * The rollup stores paths VERBATIM: '/notes/foo' and '/notes/foo/' are two
 * rows (the 2026-08-19 finding). Both spellings are summed here, so a note
 * never under-counts because its permalink carries a trailing slash.
 *
 * `site_rows` is the number of (day, path) rows of ANY path in the window:
 * the caller separates "no analytics were collected in this window" (0) from
 * "this note had no views" (views 0 with site_rows > 0). The table never
 * stores a zero row, so absence IS zero once the window has rows at all.
 * A COUNT(*) that could not be read is NULL, never 0: a failed read must not
 * become the positive statement "no analytics in this window".
 *
 * `visits` sums per-day distinct visitor-days -- visitor-days, not unique
 * visitors, the same unit sn_analytics_top_paths() reports.
 *
 * @param string $path Site-relative path (either spelling).
 * @param string $from 'YYYY-MM-DD' inclusive.
 * @param string $to   'YYYY-MM-DD' inclusive.
 * @return array{views:int,visits:int,days:int,site_rows:int|null}|null Null on a
 *                                                                      refused input or a failed
 *                                                                      per-path read; site_rows null
 *                                                                      when only the count failed.
 */
function sn_analytics_path_window( $path, $from, $to ) {
	global $wpdb;
	$path = (string) $path;
	if ( '' === $path || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ) {
		return null;
	}
	$canon = function_exists( 'sn_analytics_canonical_path' ) ? sn_analytics_canonical_path( $path ) : rtrim( $path, '/' );
	if ( '' === $canon ) {
		$canon = '/';
	}
	$slashed = '/' === $canon ? '/' : $canon . '/';
	$table   = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT SUM(views) AS views, SUM(visits) AS visits, COUNT(DISTINCT day) AS days
		 FROM {$table}
		 WHERE path IN ( %s, %s ) AND class = 'human' AND day >= %s AND day <= %s",
		$canon,
		$slashed,
		(string) $from,
		(string) $to
	), ARRAY_A );
	if ( ! is_array( $row ) ) {
		return null;
	}
	$site = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE class = 'human' AND day >= %s AND day <= %s",
		(string) $from,
		(string) $to
	) );
	return array(
		'views'     => (int) ( $row['views'] ?? 0 ),
		'visits'    => (int) ( $row['visits'] ?? 0 ),
		'days'      => (int) ( $row['days'] ?? 0 ),
		'site_rows' => null === $site ? null : (int) $site,
	);
}

/**
 * The most recent published posts (the cohort): id/title/permalink/path/publish_ts.
 *
 * @param int $limit
 * @return array<int,array{id:int,title:string,permalink:string,path:string,publish_ts:int}>
 */
function sn_analytics_posts_recent( $limit = SN_POSTS_RECENT_LIMIT ) {
	$q = new WP_Query( array(
		'post_type'           => 'post',
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
			'id'         => (int) $p->ID,
			'title'      => get_the_title( $p->ID ),
			'permalink'  => (string) get_permalink( $p->ID ),
			'path'       => sn_analytics_post_path( $p->ID ),
			'publish_ts' => (int) get_post_time( 'U', true, $p->ID ),
		);
	}
	return $out;
}

/**
 * The full age-aligned bundle the Posts view renders: the subject (newest post)
 * verdict, the cohort baseline trajectory, the leaderboard, velocity, decay.
 * Transient-cached (N per-path reads) keyed on the cohort id-set + day.
 *
 * @param int $limit Cohort size.
 * @return array|null Bundle, or null when no published post / no analytics config.
 */
function sn_analytics_posts_bundle( $limit = SN_POSTS_RECENT_LIMIT ) {
	$posts = sn_analytics_posts_recent( $limit );
	if ( empty( $posts ) ) {
		return null;
	}

	$ids       = wp_list_pluck( $posts, 'id' );
	$cache_key = 'sn_posts_bundle_' . md5( implode( ',', $ids ) . '|' . gmdate( 'Y-m-d' ) );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$now   = time();
	$today = gmdate( 'Y-m-d', $now );
	$rows  = array();

	foreach ( $posts as $p ) {
		$age = ( $p['publish_ts'] > 0 )
			? (int) floor( ( $now - $p['publish_ts'] ) / DAY_IN_SECONDS )
			: 0;
		$from   = gmdate( 'Y-m-d', $p['publish_ts'] > 0 ? $p['publish_ts'] : $now );
		$series = '' !== $p['path'] ? sn_analytics_path_daily_series( $p['path'], $from, $today ) : array();
		$by_dol = sn_analytics_posts_daily_by_dol( $series, $p['publish_ts'] );
		$life   = '' !== $p['path'] ? sn_analytics_path_lifetime( $p['path'] ) : 0;

		$rows[] = array(
			'id'        => $p['id'],
			'title'     => $p['title'],
			'permalink' => $p['permalink'],
			'age'       => $age,
			'by_dol'    => $by_dol,
			'lifetime'  => $life,
			'per_day'   => $age > 0 ? round( $life / ( $age + 1 ), 1 ) : (float) $life,
			'velocity'  => sn_analytics_posts_velocity( $by_dol, SN_POSTS_VELOCITY_DAYS ),
			'decay'     => sn_analytics_posts_decay( $by_dol, SN_POSTS_DECAY_DAYS ),
		);
	}

	$bundle = array(
		'subject'     => sn_analytics_posts_subject( $rows ),
		'leaderboard' => $rows,
		'generated'   => $now,
	);
	set_transient( $cache_key, $bundle, SN_POSTS_BUNDLE_TTL );
	return $bundle;
}

/**
 * The "did it land" verdict for the newest post (rows[0]) against the cohort at
 * its CURRENT age — the load-bearing age-aligned comparison.
 *
 * @param array $rows Leaderboard rows (rows[0] = newest).
 * @return array Subject summary with cohort verdict + rank.
 */
function sn_analytics_posts_subject( $rows ) {
	$s   = $rows[0];
	$age = (int) $s['age'];

	$subject_at_age = sn_analytics_posts_cumulative_at( $s['by_dol'], $age );

	// Cohort = the OTHER posts that have lived at least as long, measured at the
	// SAME age (day-7 vs cohort-at-day-7) so the comparison is apples-to-apples.
	$cohort = array();
	foreach ( array_slice( $rows, 1 ) as $r ) {
		if ( (int) $r['age'] >= $age ) {
			$cohort[] = sn_analytics_posts_cumulative_at( $r['by_dol'], $age );
		}
	}
	$median  = sn_analytics_median( $cohort );
	$delta   = function_exists( 'sn_analytics_delta' )
		? sn_analytics_delta( $subject_at_age, $median )
		: array( 'pct' => null, 'dir' => 'flat' );
	$rank    = sn_analytics_posts_rank( $subject_at_age, $cohort );

	return array(
		'id'        => $s['id'],
		'title'     => $s['title'],
		'permalink' => $s['permalink'],
		'age'       => $age,
		'views'     => $subject_at_age,
		'lifetime'  => (int) $s['lifetime'],
		'median'    => $median,
		'delta'     => $delta,
		'rank'      => $rank,
		'by_dol'    => $s['by_dol'],
		'has_data'  => $subject_at_age > 0 || ! empty( $cohort ),
	);
}
