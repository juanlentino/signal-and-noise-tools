<?php
/**
 * THE one "counted human" rule every human reader routes through.
 *
 * Human = the row is not a stored bot and the worker's network lists read it
 * human, decided at READ time from stored fields (inc/analytics-network-terms.php),
 * so a list change applies to all retained history; AND
 * the visitor-day did not exceed SNT_ANALYTICS_VDAY_PV_CAP page views. index1
 * is a daily-rotating visitor hash, so a hash IS a visitor-day: one list of
 * over-cap hashes, read over the widest window any reader uses, excludes the
 * right rows from any narrower window too (a hash outside a window matches
 * nothing there). Over-cap visitor-days read as automated whatever their class.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-network-terms.php';
require_once __DIR__ . '/analytics-referrer-kind.php'; // the ONE internal-click rule (worker 1.23.0)

// Measured 2026-09 on sn_pageviews (28 days): ONE human-classed visitor-day
// (Chrome macOS, 2026-09-22) made 258 of the 497 human page views; the next
// highest were 20, 12, 12, 9, 7. No reader does 50 pages in a day.
const SNT_ANALYTICS_VDAY_PV_CAP = 50;
// Widest reader window: AE keeps ~90 days; the north star reads 12 weeks.
const SNT_ANALYTICS_VDAY_WINDOW_DAYS = 92;
// 300 (was 400; 400 measured 9,572 chars, too thin) keeps the longest statement (read-time class + NOT IN list) under AE's
// 10,000-character cap; sn_analytics_sql_too_long() fails closed past it.
const SNT_ANALYTICS_VDAY_LIST_MAX    = 300;
// Versioned key: 19.4.1 dropped a failed read cached by 19.4.0; v3 drops a list read with the UTC-split grouping.
const SNT_ANALYTICS_VDAY_CACHE_KEY   = 'sn_analytics_overcap_vdays_v3';

/**
 * AE SQL: visitor-days over the page-view cap in the trailing window. The hash
 * alone is the visitor-day: the worker rotates it at America/New_York
 * midnight, so grouping by the UTC date as well split one visitor-day in two
 * and let it slip under the cap (measured 2026-09-27: 65 views read as 18 + 47).
 * AE takes only column names or aliases in GROUP BY and HAVING.
 *
 * @return string
 */
function sn_analytics_overcap_sql() {
	$days = (int) SNT_ANALYTICS_VDAY_WINDOW_DAYS;
	$cap  = (int) SNT_ANALYTICS_VDAY_PV_CAP;
	$max  = (int) SNT_ANALYTICS_VDAY_LIST_MAX;
	return implode( ' ', array(
		'SELECT index1 AS vid, sum(_sample_interval) AS views',
		'FROM ' . ( defined( 'SN_ANALYTICS_DATASET' ) ? SN_ANALYTICS_DATASET : 'sn_pageviews' ),
		"WHERE blob1 = 'pv' AND timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY)",
		'GROUP BY vid',
		"HAVING views > {$cap}",
		"LIMIT {$max}",
	) );
}

/**
 * Keep only well-formed visitor hashes (hex). The list comes from our own
 * dataset but is interpolated into SQL, so it is treated as untrusted. PURE.
 *
 * @param array $hashes Candidate hashes.
 * @return string[]
 */
function sn_analytics_valid_vday_hashes( array $hashes ) {
	$out = array();
	foreach ( $hashes as $h ) {
		$h = strtolower( (string) $h );
		if ( 1 === preg_match( '/^[0-9a-f]{8,64}$/', $h ) ) {
			$out[ $h ] = true;
		}
	}
	return array_keys( $out );
}

/**
 * The over-cap visitor-days, cached for an hour. A failed read returns an empty
 * list with ok=false (cached five minutes) so the UI can say the cap was NOT
 * applied, instead of silently counting everything.
 *
 * @return array{hashes:string[],ok:bool,truncated:bool}
 */
function sn_analytics_overcap_vdays() {
	$fail = array( 'hashes' => array(), 'ok' => false, 'truncated' => false );
	if ( ! function_exists( 'get_transient' ) || ! function_exists( 'sn_analytics_query' ) ) {
		return $fail;
	}
	$cached = get_transient( SNT_ANALYTICS_VDAY_CACHE_KEY );
	if ( is_array( $cached ) && isset( $cached['hashes'], $cached['ok'] ) ) {
		return $cached;
	}
	$rows = sn_analytics_query( sn_analytics_overcap_sql() );
	if ( ! is_array( $rows ) ) {
		error_log( '[sn-analytics] over-cap visitor-day read failed; the page-view cap is not applied' );
		set_transient( SNT_ANALYTICS_VDAY_CACHE_KEY, $fail, 5 * 60 );
		return $fail;
	}
	$out = array(
		'hashes'    => sn_analytics_valid_vday_hashes( array_column( $rows, 'vid' ) ),
		'ok'        => true,
		'truncated' => count( $rows ) >= SNT_ANALYTICS_VDAY_LIST_MAX,
	);
	set_transient( SNT_ANALYTICS_VDAY_CACHE_KEY, $out, 3600 );
	return $out;
}

/**
 * ' AND index1 NOT IN (...)' for the given hashes, or '' when none. PURE.
 *
 * @param array $hashes Over-cap hashes.
 * @return string
 */
function sn_analytics_overcap_and( array $hashes ) {
	$hashes = sn_analytics_valid_vday_hashes( $hashes );
	return array() === $hashes ? '' : " AND index1 NOT IN ('" . implode( "','", $hashes ) . "')";
}

/**
 * The SQL condition for one traffic class under the rule, decided at read time
 * (a stored 'bot' is final: it comes only from the worker's UA list). PURE.
 * human/suspect: the read-time class minus over-cap visitor-days.
 * bot: the stored bot class plus over-cap visitor-days.
 *
 * @param string $class  Traffic class (anything unknown reads as human).
 * @param array  $hashes Over-cap hashes.
 * @return string
 */
function sn_analytics_counted_condition( $class, array $hashes ) {
	$class  = in_array( $class, array( 'human', 'suspect', 'bot' ), true ) ? $class : 'human';
	$hashes = sn_analytics_valid_vday_hashes( $hashes );
	if ( 'bot' === $class ) {
		return array() === $hashes ? "blob7 = 'bot'" : "(blob7 = 'bot' OR index1 IN ('" . implode( "','", $hashes ) . "'))";
	}
	$net = sn_analytics_network_human_sql();
	return "(blob7 != 'bot' AND " . ( 'human' === $class ? "({$net})" : "NOT ({$net})" ) . ')' . sn_analytics_overcap_and( $hashes );
}

/**
 * The live condition for a class (reads the cached over-cap list).
 *
 * @param string $class Traffic class.
 * @return string
 */
function sn_analytics_class_where( $class = 'human' ) {
	return sn_analytics_counted_condition( $class, sn_analytics_overcap_vdays()['hashes'] );
}

/**
 * The live exclusion for builders that group by class in PHP.
 *
 * @return string
 */
function sn_analytics_overcap_where() {
	return sn_analytics_overcap_and( sn_analytics_overcap_vdays()['hashes'] );
}

/**
 * What the Overview says about the rule for a window: the over-cap count (and
 * whether the list read at all) plus engaged visitor-days from the durable
 * session rollup (human only; NULL days were not measured).
 *
 * @param string $from  Y-m-d.
 * @param string $to    Y-m-d.
 * @param string $class Traffic class.
 * @return array{excluded:int,ok:bool,truncated:bool,engaged:?int,measured:int,days:int,class:string}
 */
function sn_analytics_human_rule_reading( $from, $to, $class ) {
	$list = sn_analytics_overcap_vdays();
	$out  = array(
		'excluded'  => count( $list['hashes'] ),
		'ok'        => (bool) $list['ok'],
		'truncated' => (bool) $list['truncated'],
		'engaged'   => null,
		'measured'  => 0,
		'days'      => 0,
		'class'     => (string) $class,
	);
	$rows = ( 'human' === $class && function_exists( 'sn_session_rollup_read' ) ) ? sn_session_rollup_read( $from, $to, 'human' ) : null;
	foreach ( (array) $rows as $r ) {
		++$out['days'];
		if ( null !== ( $r['engaged'] ?? null ) ) {
			++$out['measured'];
			$out['engaged'] = (int) $out['engaged'] + (int) $r['engaged'];
		}
	}
	return $out;
}

/**
 * The rollup window every *_run_rollup() reads: trailing `days` (lower bound)
 * and, for the history recompute only, `until` days ago (exclusive upper
 * bound). Default is the nightly's trailing window with no upper bound, so
 * the nightly SQL is unchanged. Pass an array to set, false to reset.
 *
 * @param array|false|null $set Window to set, false to reset, null to read.
 * @return array{days:int,until:int}
 */
function sn_analytics_rollup_window( $set = null ) {
	static $w = null;
	if ( is_array( $set ) ) {
		$w = array( 'days' => max( 1, (int) $set['days'] ), 'until' => max( 0, (int) $set['until'] ) );
	} elseif ( false === $set ) {
		$w = null;
	}
	return $w ?? array( 'days' => defined( 'SN_ANALYTICS_ROLLUP_WINDOW_DAYS' ) ? (int) SN_ANALYTICS_ROLLUP_WINDOW_DAYS : 7, 'until' => 0 );
}

/**
 * ' AND timestamp < <start of the day `until` days ago>' or '' when unbounded.
 * The expression is the same family as the rollup's own lower bound
 * (sn_analytics_rollup_window_exprs), zoned when $tz is given. PURE per window.
 *
 * @param string $tz Optional IANA zone (UTC builders pass none).
 * @return string
 */
function sn_analytics_window_upper( $tz = '' ) {
	$until = (int) sn_analytics_rollup_window()['until'];
	if ( $until <= 0 ) {
		return '';
	}
	$tz = ( '' !== $tz && preg_match( '#^[A-Za-z0-9_/+-]+$#', (string) $tz ) ) ? (string) $tz : '';
	return ' AND timestamp < ' . ( '' !== $tz
		? "toStartOfInterval(now(), INTERVAL '1' DAY, '{$tz}') - INTERVAL '{$until}' DAY"
		: "toStartOfDay(now() - INTERVAL '{$until}' DAY)" );
}

/**
 * The day keys the current rollup window reads COMPLETELY, keyed the way the
 * writer keys them: site-local days for the zone the read actually ran with,
 * UTC days for ''. The SQL floors the lower bound to a whole day and the upper
 * bound (recompute only) to a day start, so every day from `days` ago through
 * `until + 1` ago (through today when unbounded) is read in full: there is no
 * partial boundary day to protect. PHP's clock can disagree with AE's around
 * midnight, so the oldest day is named from a clock 5 minutes AHEAD and the
 * newest from one 5 minutes BEHIND: a skew can only drop a boundary day from
 * the list, never add a day the read did not cover.
 *
 * @param string $tz The zone the read ran with ('' = UTC).
 * @return string[] Y-m-d days, oldest first; [] for an invalid zone.
 */
function sn_analytics_rollup_window_days( $tz = '' ) {
	$w = sn_analytics_rollup_window();
	try {
		$zone = new DateTimeZone( '' !== (string) $tz ? (string) $tz : 'UTC' );
	} catch ( Exception $e ) {
		return array();
	}
	$day   = static function ( $ts, $ago ) use ( $zone ) {
		return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $zone )->modify( '-' . (int) $ago . ' days' )->format( 'Y-m-d' );
	};
	$first = $day( time() + 300, $w['days'] );
	$last  = $day( time() - 300, $w['until'] > 0 ? $w['until'] + 1 : 0 );
	$out   = array();
	for ( $d = $first; $d <= $last; $d = gmdate( 'Y-m-d', strtotime( $d . ' 12:00:00 UTC' ) + 86400 ) ) {
		$out[] = $d;
	}
	return $out;
}

/**
 * Whether any upsert chunk failed since the last reset (a day replace resets it
 * before its write and rolls back when it is set).
 *
 * @param bool|null $set true marks a failure, false resets, null reads.
 * @return bool
 */
function sn_analytics_rollup_chunk_failed( $set = null ) {
	static $failed = false;
	if ( null !== $set ) {
		$failed = (bool) $set;
	}
	return $failed;
}

/**
 * Make a re-roll REPLACE the days it read instead of only upserting over them.
 * An upsert never removes a stored key the fresh result no longer has, so a
 * visitor the human rule newly excludes left their paths behind (measured
 * 2026-09-27: 139 stored 7-day human views against ~62 in AE). A failed, empty
 * or row-cap-truncated read ($complete false) only upserts, as before: deleting
 * on it would blank history. The caller judges completeness right after its own
 * query, because the truncation verdict describes the last query only. Deletes
 * the window's days (narrowed by $scope, e.g. one dim or role) and runs $write
 * in one transaction, rolled back when the write leaves a database error.
 * Every upsert chunk reports its own failure (sn_analytics_rollup_chunk_failed),
 * so a failed FIRST chunk rolls back as surely as a failed last one.
 *
 * @param bool     $complete The read was not null, not empty, not truncated.
 * @param string   $table    Table name without prefix.
 * @param string   $tz       The zone the read ran with ('' = UTC days).
 * @param callable $write    Writes the fresh rows.
 * @param array    $scope    Optional column => value (or list of values) the delete is limited to.
 */
function sn_analytics_rollup_replace( $complete, $table, $tz, callable $write, array $scope = array() ) {
	global $wpdb;
	$days = $complete ? sn_analytics_rollup_window_days( $tz ) : array();
	if ( empty( $days ) || in_array( array(), $scope, true ) || ! is_object( $wpdb ) ) {
		$write();
		return;
	}
	$sql  = "DELETE FROM {$wpdb->prefix}{$table} WHERE day IN (" . implode( ',', array_fill( 0, count( $days ), '%s' ) ) . ')';
	$args = $days;
	foreach ( $scope as $col => $vals ) {
		$vals = array_map( 'strval', (array) $vals );
		$sql .= ' AND ' . preg_replace( '/[^a-z_]/', '', (string) $col ) . ' IN (' . implode( ',', array_fill( 0, count( $vals ), '%s' ) ) . ')';
		$args = array_merge( $args, $vals );
	}
	$wpdb->query( 'START TRANSACTION' );
	// phpcs:ignore WordPress.DB.PreparedSQL -- table is prefix + a plugin constant, the column is charset-stripped, every value is bound.
	$gone = $wpdb->query( $wpdb->prepare( $sql, $args ) );
	sn_analytics_rollup_chunk_failed( false );
	if ( false !== $gone ) {
		$write();
	}
	if ( false !== $gone && ! sn_analytics_rollup_chunk_failed() && '' === (string) $wpdb->last_error ) {
		$wpdb->query( 'COMMIT' );
		return;
	}
	$wpdb->query( 'ROLLBACK' );
	error_log( '[sn-analytics] re-roll of ' . $table . ' rolled back after a failed write chunk, the stored days are unchanged. ' . (string) $wpdb->last_error );
}

/**
 * Shared window expressions for the two rollup queries: the day-bucket column
 * and the floored lower bound. Extracted so the gated pageview_visits query
 * (P0.1 Fallback A) buckets and floors IDENTICALLY to the main query — the
 * PHP-side merge joins on (day, path, class), so a drift here would silently
 * mis-key the merge.
 *
 * @param int    $days Trailing window in days (floored to >= 1).
 * @param string $tz   Optional IANA zone (charset-guarded; invalid → UTC path).
 * @return array{0:string,1:string} [ $day_col, $lower ].
 */
function sn_analytics_rollup_window_exprs( $days, $tz = '' ) {
	$days = max( 1, (int) $days );
	// Bucket each row by the SITE-LOCAL calendar day when a named IANA zone is
	// available (v9.26.4), so the durable "day" matches the site's day — and the live
	// "views today" measured in the same zone — instead of a UTC day that rolls
	// mid-evening for western zones (the 8pm-ET reset). AE's formatDateTime() and
	// toStartOfInterval() take an optional timezone arg (added 2025-11-12). The zone
	// is charset-guarded before interpolation as defence in depth; the caller already
	// validates it via sn_analytics_site_tz_name(). Empty/invalid → the UTC path.
	$tz      = ( '' !== $tz && preg_match( '#^[A-Za-z0-9_/+-]+$#', (string) $tz ) ) ? (string) $tz : '';
	$day_col = '' !== $tz
		? "formatDateTime(timestamp, '%Y-%m-%d', '{$tz}')"
		: "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d')";
	// Floor the lower bound to a COMPLETE calendar day — LOCAL when zoned
	// (toStartOfInterval with the zone), UTC otherwise. A bare `now() - INTERVAL`
	// instant would aggregate the boundary day as a partial slice, and the UPSERT
	// would clobber its previously-complete row — silently corrupting the durable
	// forever-table. Flooring keeps every re-roll genuinely idempotent, and it is
	// what lets a re-roll DELETE its days first: sn_analytics_rollup_window_days()
	// names exactly the whole days this floor reads.
	$lower   = '' !== $tz
		? "toStartOfInterval(now(), INTERVAL '1' DAY, '{$tz}') - INTERVAL '{$days}' DAY"
		: "toStartOfDay(now() - INTERVAL '{$days}' DAY)";

	return array( $day_col, $lower );
}

/**
 * Is this an admin/login path that should never be counted as a human pageview?
 *
 * The front-end beacon (theme) only enqueues on wp_enqueue_scripts, so it can't
 * fire in wp-admin or on wp-login.php — any such path in the pipeline is noise
 * (a stray/forged beacon, a cache edge case), never a real visit. This is the
 * ingestion-side half of the invariant the retired Plausible importer enforced;
 * the collector Worker enforces the same rule at the edge. Boundary-aware so a
 * legitimate front-end slug like `/wp-admin-guide/` is NOT swept up.
 *
 * @param string $path Request path (already query/hash-stripped upstream).
 * @return bool
 */
function sn_analytics_is_excluded_path( $path ) {
	$path = (string) $path;
	if ( '/wp-admin' === $path || 0 === strpos( $path, '/wp-admin/' ) || 0 === strpos( $path, '/wp-login.php' ) ) {
		return true;
	}
	// 17.5.1: the beacon's path is client-supplied and a replayed one planted
	// /wp-content/uploads/sn-css/$h as a pageview. No theme page lives under
	// these prefixes or ends in a file extension; the edge (analytics worker
	// 1.21.4, isExcludedPath) drops the same set, this is the rollup's twin.
	foreach ( array( '/wp-content/', '/wp-includes/', '/wp-json/' ) as $prefix ) {
		if ( 0 === strpos( $path, $prefix ) ) {
			return true;
		}
	}
	$last = (string) substr( $path, (int) strrpos( $path, '/' ) + 1 );
	return 1 === preg_match( '/\.[a-z0-9]{1,8}$/i', $last );
}

/**
 * sn_analytics_is_excluded_path() as an AE WHERE clause over blob2, for the
 * rollups that do not see the path in PHP (the dims rollup groups by a
 * dimension, so its rows carry no path to test). Without it the country,
 * device and source tables counted the asset and admin "pageviews" the daily
 * table drops: 291 views against 235 over the same 30 days (2026-10-02).
 *
 * Built only from shapes live AE already accepts (ILIKE inside NOT (...), as
 * the read-time class does). AE has no regex, so the file-extension rule is
 * "a dot anywhere in the path": no page on this site has one, and the pin in
 * tests/analytics-excluded-path-sql.php checks the two rules agree on real
 * paths. ILIKE is case-insensitive where the PHP prefixes are not; a mixed-case
 * /WP-ADMIN is dropped here too, which only ever removes junk.
 *
 * @return string " AND NOT (...)", leading space included.
 */
function sn_analytics_excluded_path_sql() {
	$like = array( '/wp-admin', '/wp-admin/%', '/wp-login.php%', '/wp-content/%', '/wp-includes/%', '/wp-json/%', '%.%' );
	return ' AND NOT (' . implode( ' OR ', array_map( static fn( $p ) => "blob2 ILIKE '{$p}'", $like ) ) . ')';
}
