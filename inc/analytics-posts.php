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
 * Pure helpers (day-of-life bucketing, velocity, decay) carry
 * the age-alignment math and are unit-tested in tests/analytics-posts.php.
 *
 * @package signal-and-noise-tools
 * @since 6.39.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Classification windows + thresholds (named, not magic).
const SN_POSTS_DECAY_DAYS    = 7;    // early-life window for the sustained/spike split.
const SN_POSTS_SPIKE_SHARE   = 0.8;  // ≥ this share of lifetime views in week 1 → spike.
const SN_POSTS_SUSTAINED_SHARE = 0.5; // ≤ this share → sustained (a long tail, not a spike).

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
 * The SITE-LOCAL calendar day of a timestamp, 'Y-m-d' — the same day the
 * rollup keys its `day` column on (schema v4, inc/analytics-rollup.php).
 *
 * A note published 21:30 ET is 01:30 UTC the next day; its launch-evening rows
 * sit under the local day, and a UTC publish day read them as dol -1 and
 * dropped them (#1200). Standalone (no wp_date) this is the UTC day.
 *
 * @param int $ts Unix timestamp.
 * @return string
 */
function sn_analytics_posts_local_day( $ts ) {
	return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d', (int) $ts ) : gmdate( 'Y-m-d', (int) $ts );
}

/**
 * Age of a post in whole SITE-LOCAL calendar days — the unit `by_dol` is keyed
 * in, so "views at age N" and the leaderboard's day-N bucket agree. A 24h
 * count read a note published yesterday evening as age 0 while its rows sat
 * under day 1, and the hero said "no recorded views yet" (#1200).
 *
 * @param int $publish_ts Publish moment (0 = unknown -> age 0).
 * @param int $now        The reference moment.
 * @return int
 */
function sn_analytics_posts_age( $publish_ts, $now ) {
	$publish_ts = (int) $publish_ts;
	if ( $publish_ts <= 0 ) {
		return 0;
	}
	$pub = strtotime( sn_analytics_posts_local_day( $publish_ts ) . ' 00:00:00 UTC' );
	$cur = strtotime( sn_analytics_posts_local_day( (int) $now ) . ' 00:00:00 UTC' );
	return max( 0, (int) round( ( $cur - $pub ) / DAY_IN_SECONDS ) );
}

/**
 * Re-index a calendar-day series to day-of-life (publish day = 0). Days before
 * publish are dropped; gap days are simply absent (not zero-filled).
 *
 * @param array $series     [{day:'Y-m-d', views:int}] ascending.
 * @param int   $publish_ts Unix ts of the publish moment (floored to its SITE-LOCAL day).
 * @return array<int,int> [day_of_life => views]
 */
function sn_analytics_posts_daily_by_dol( $series, $publish_ts ) {
	$pub_day = strtotime( sn_analytics_posts_local_day( (int) $publish_ts ) . ' 00:00:00 UTC' );
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

/* ───────────────────────── durable-rollup accessors ──────────────────────── */

/**
 * Both stored spellings of one path: canonical (no trailing slash) and slashed.
 *
 * The rollup stores paths VERBATIM (the 2026-08-19 finding), and the callers
 * of these accessors hand over whichever spelling they hold — the permalink's
 * slashed form, or sn_analytics_top_paths()'s canonical form. A `WHERE path =`
 * on either alone reads a pretty-permalink note as empty (#1199); every
 * per-path read here binds both.
 *
 * @param string $path Either spelling.
 * @return array{0:string,1:string} [canonical, slashed]; the root is ['/', '/'].
 */
function sn_analytics_path_spellings( $path ) {
	$path  = (string) $path;
	$canon = function_exists( 'sn_analytics_canonical_path' ) ? sn_analytics_canonical_path( $path ) : rtrim( $path, '/' );
	if ( '' === $canon ) {
		$canon = '/';
	}
	return array( $canon, '/' === $canon ? '/' : $canon . '/' );
}

/**
 * Daily human views for one path over [from,to] — a per-path clone of
 * sn_analytics_top_paths (both spellings, see sn_analytics_path_spellings()).
 * views only (sample-corrected); visits is a raw estimate
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
	list( $canon, $slashed ) = sn_analytics_path_spellings( $path );
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT day, SUM(views) AS views
		 FROM {$table}
		 WHERE path IN ( %s, %s ) AND class = 'human' AND day >= %s AND day <= %s
		 GROUP BY day ORDER BY day ASC",
		$canon,
		$slashed,
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
	list( $canon, $slashed ) = sn_analytics_path_spellings( $path );
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(views) FROM {$table} WHERE path IN ( %s, %s ) AND class = 'human'",
		$canon,
		$slashed
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
	list( $canon, $slashed ) = sn_analytics_path_spellings( $path );
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

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

