<?php
/**
 * THE one "counted human" rule every human reader routes through.
 *
 * Human = the network did not class the hit bot/suspect (blob7 = 'human') AND
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

// Measured 2026-09 on sn_pageviews (28 days): ONE human-classed visitor-day
// (Chrome macOS, 2026-09-22) made 258 of the 497 human page views; the next
// highest were 20, 12, 12, 9, 7. No reader does 50 pages in a day.
const SNT_ANALYTICS_VDAY_PV_CAP = 50;
// Widest reader window: AE keeps ~90 days; the north star reads 12 weeks.
const SNT_ANALYTICS_VDAY_WINDOW_DAYS = 92;
const SNT_ANALYTICS_VDAY_LIST_MAX    = 500;
// 19.4.1: versioned key, so the upgrade drops a failed read cached by 19.4.0.
const SNT_ANALYTICS_VDAY_CACHE_KEY   = 'sn_analytics_overcap_vdays_v2';

/**
 * AE SQL: visitor-days over the page-view cap in the trailing window. Analytics
 * Engine takes only column names or aliases in GROUP BY, so the day is
 * selected as an alias and grouped and filtered by alias (verified live
 * 2026-09-27; `GROUP BY index1, toDate(timestamp)` is refused).
 *
 * @return string
 */
function sn_analytics_overcap_sql() {
	$days = (int) SNT_ANALYTICS_VDAY_WINDOW_DAYS;
	$cap  = (int) SNT_ANALYTICS_VDAY_PV_CAP;
	$max  = (int) SNT_ANALYTICS_VDAY_LIST_MAX;
	return implode( ' ', array(
		'SELECT index1 AS vid, toDate(timestamp) AS d, sum(_sample_interval) AS views',
		'FROM ' . ( defined( 'SN_ANALYTICS_DATASET' ) ? SN_ANALYTICS_DATASET : 'sn_pageviews' ),
		"WHERE blob1 = 'pv' AND timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY)",
		'GROUP BY vid, d',
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
 * The SQL condition for one traffic class under the rule. PURE.
 * human/suspect: the class filter minus over-cap visitor-days.
 * bot: the class filter plus over-cap visitor-days.
 *
 * @param string $class  Traffic class (anything unknown reads as human).
 * @param array  $hashes Over-cap hashes.
 * @return string
 */
function sn_analytics_counted_condition( $class, array $hashes ) {
	$class  = in_array( $class, array( 'human', 'suspect', 'bot' ), true ) ? $class : 'human';
	$hashes = sn_analytics_valid_vday_hashes( $hashes );
	if ( 'bot' === $class && array() !== $hashes ) {
		return "(blob7 = 'bot' OR index1 IN ('" . implode( "','", $hashes ) . "'))";
	}
	return "blob7 = '{$class}'" . sn_analytics_overcap_and( $hashes );
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
 * Make a re-roll REPLACE the days it read instead of only upserting over them.
 * An upsert never removes a stored key the fresh result no longer has, so a
 * visitor the human rule newly excludes left their paths behind (measured
 * 2026-09-27: 139 stored 7-day human views against ~62 in AE). A failed or
 * row-cap-truncated read ($complete false) only upserts, as before: deleting on
 * it would blank history. The caller judges completeness right after its own
 * query, because the truncation verdict describes the last query only. Deletes
 * the window's days (narrowed by $scope, e.g. one dim or role) and runs $write
 * in one transaction, rolled back when the write leaves a database error.
 * ponytail: the error check sees the last chunk's query only; a per-chunk
 * verdict from the upserts if a mid-batch failure is ever measured.
 *
 * @param bool     $complete The read was not null and not truncated.
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
	if ( false !== $gone ) {
		$write();
	}
	$wpdb->query( false !== $gone && '' === (string) $wpdb->last_error ? 'COMMIT' : 'ROLLBACK' );
}
