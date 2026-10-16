<?php
/**
 * SN Traffic Lanes: the beacon sensor (public page views plus sn_owner_hits).
 *
 * The public read groups by site-local day, visitor-day and the house's own
 * read-time class expression (sn_analytics_class_select(), the expression
 * the daily table groups by), so `human` here is the dashboard's human by
 * construction: class human, visitor-day not over the cap. The bot-signal
 * score is the house weights (sn_bot_signals_score()) per site-local
 * visitor-day, from the same rows; the UTC per-day store is not read.
 *
 * Precedence per row, first match wins: stored bot (ua_list), an RSS server
 * event (feed_server, lane human), read-time suspect (network), over-cap
 * visitor-day (over_cap), score at the threshold (bot_score), else human
 * (remainder). Owner rows come only from sn_owner_hits, by identity code.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The day column and the window bounds for the trailing complete days: from
 * the start of the day `SN_LANES_WINDOW_DAYS` ago to the start of today, in
 * the zone. PURE.
 *
 * @param string $tz IANA zone, '' for UTC.
 * @return array{0:string,1:string} [ day column, 'timestamp >= ... AND timestamp < ...' ], the WHERE's opening shape every house builder uses.
 */
function sn_lanes_window_sql( $tz ) {
	list( $day_col, $lower ) = sn_analytics_rollup_window_exprs( SN_LANES_WINDOW_DAYS, $tz );
	$tz    = ( '' !== $tz && preg_match( '#^[A-Za-z0-9_/+-]+$#', (string) $tz ) ) ? (string) $tz : '';
	$upper = '' !== $tz ? "toStartOfInterval(now(), INTERVAL '1' DAY, '{$tz}')" : 'toStartOfDay(now())';
	return array( $day_col, "timestamp >= {$lower} AND timestamp < {$upper}" );
}

/**
 * The complete days a run may write, oldest first: the window without today.
 * The oldest is named from a clock 5 minutes ahead, the newest from one 5
 * minutes behind, so a clock skew around midnight can only drop a boundary
 * day, never write a day the read did not cover whole. PURE given $now.
 *
 * @param string   $tz  Zone ('' = UTC).
 * @param int|null $now Unix time.
 * @return string[]
 */
function sn_lanes_complete_days( $tz, $now = null ) {
	$now = null === $now ? time() : (int) $now;
	try {
		$zone = new DateTimeZone( '' !== (string) $tz ? (string) $tz : 'UTC' );
	} catch ( Exception $e ) {
		return array();
	}
	$day = static fn( $ts, $ago ) => ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $zone )->modify( '-' . (int) $ago . ' days' )->format( 'Y-m-d' );
	$out = array();
	for ( $d = $day( $now + 300, SN_LANES_WINDOW_DAYS ), $last = $day( $now - 300, 1 ); $d <= $last; $d = gmdate( 'Y-m-d', strtotime( $d . ' 12:00:00 UTC' ) + 86400 ) ) {
		$out[] = $d;
	}
	return $out;
}

/**
 * The RSS tracker's event name, charset-guarded for interpolation.
 *
 * @return string
 */
function sn_lanes_feed_event() {
	$s    = function_exists( 'get_option' ) ? get_option( defined( 'SN_RSS_TRACKER_SETTINGS_OPT' ) ? SN_RSS_TRACKER_SETTINGS_OPT : 'sn_rss_tracker_settings', array() ) : array();
	$name = is_array( $s ) && isset( $s['event_name'] ) ? (string) $s['event_name'] : 'RSS Feed Request';
	return (string) preg_replace( '/[^A-Za-z0-9 _-]/', '', $name );
}

/**
 * Public page views per site-local day, visitor-day, read-time class and
 * feed flag, with the signal bits. PURE.
 *
 * @param string $source Dataset.
 * @param string $range  One side of the split, or ''.
 * @param string $tz     Zone.
 * @return string
 */
function sn_lanes_public_sql( $source, $range, $tz ) {
	list( $day_col, $window ) = sn_lanes_window_sql( $tz );
	$range = sn_analytics_split_range_ok( $range ) ? (string) $range : '';
	$ce    = sn_analytics_col( 'blob16', $source );
	$cols  = array( "{$day_col} AS day", 'index1 AS vid', sn_analytics_class_select() . ' AS class', "if(blob1 = 'ce' AND {$ce} = '" . sn_lanes_feed_event() . "', 1, 0) AS feed", "sum(if(blob1 = 'pv', _sample_interval, 0)) AS views" );
	foreach ( SNT_BOT_SIGNAL_BITS as $bit => $name ) {
		$cols[] = "max(bitAnd(toUInt32(double8), {$bit})) AS {$name}";
	}
	return 'SELECT ' . implode( ', ', $cols ) . ' FROM ' . $source . ' WHERE ' . $window . $range . sn_analytics_excluded_path_sql() . ' GROUP BY day, vid, class, feed';
}

/**
 * Owner-routed page views per site-local day, visitor-day, identity and agent. PURE.
 *
 * @param string $tz Zone.
 * @return string
 */
function sn_lanes_owner_sql( $tz ) {
	list( $day_col, $window ) = sn_lanes_window_sql( $tz );
	return "SELECT {$day_col} AS day, index1 AS vid, toUInt32(double14) AS identity, toUInt32(double15) AS agent, sum(if(blob1 = 'pv', _sample_interval, 0)) AS views FROM " . SN_LANES_OWNER_DATASET
		. ' WHERE ' . $window . sn_analytics_excluded_path_sql() . ' GROUP BY day, vid, identity, agent';
}

/**
 * The first site-local day a public second-generation row carries a rule
 * version (double16 >= 1): the beacon's lanes_since. PURE.
 *
 * @param string $tz Zone.
 * @return string
 */
function sn_lanes_beacon_since_sql( $tz ) {
	list( $day_col ) = sn_analytics_rollup_window_exprs( 1, $tz );
	return "SELECT {$day_col} AS day, count() AS n FROM sn_pageviews_v2 WHERE timestamp >= toStartOfDay(now() - INTERVAL '" . (int) SNT_ANALYTICS_VDAY_WINDOW_DAYS . "' DAY) AND double16 >= 1 GROUP BY day ORDER BY day ASC LIMIT 1";
}

/**
 * Add one row's figures to a lane cell. PURE.
 *
 * @param array  $out    Day => key => cell.
 * @param string $day    Day.
 * @param array  $key    [ lane, reason, agent ].
 * @param string $vid    Visitor-day.
 * @param int    $views  Page views.
 * @return array
 */
function sn_lanes_beacon_add( array $out, $day, array $key, $vid, $views ) {
	$k = implode( '|', $key );
	$c = $out[ $day ][ $k ] ?? array( 'lane' => $key[0], 'reason' => $key[1], 'agent' => $key[2], 'hits' => 0, 'pv' => array(), 'all' => array() );
	$c['hits']       += $views;
	$c['all'][ $vid ] = true;
	if ( $views > 0 ) {
		$c['pv'][ $vid ] = true;
	}
	$out[ $day ][ $k ] = $c;
	return $out;
}

/**
 * The beacon lanes from the two reads. Every input row lands in exactly one
 * cell; views sum to the input per day. PURE.
 *
 * @param array    $public sn_lanes_public_sql() rows.
 * @param array    $owner  sn_lanes_owner_sql() rows.
 * @param string[] $hashes Over-cap visitor-days.
 * @return array<string,array<int,array>> Day => lane rows {lane, reason, agent, family, purpose, hits, visits, visitor_days}.
 */
function sn_lanes_beacon_derive( array $public, array $owner, array $hashes ) {
	$over = array_flip( sn_analytics_valid_vday_hashes( $hashes ) );
	$bits = array();
	foreach ( $public as $r ) {
		$r = (array) $r;
		$k = substr( (string) ( $r['day'] ?? '' ), 0, 10 ) . '|' . strtolower( (string) ( $r['vid'] ?? '' ) );
		foreach ( SNT_BOT_SIGNAL_BITS as $name ) {
			$bits[ $k ][ $name ] = max( (float) ( $bits[ $k ][ $name ] ?? 0 ), (float) ( $r[ $name ] ?? 0 ) );
		}
	}
	$out = array();
	foreach ( $public as $r ) {
		$r     = (array) $r;
		$day   = substr( (string) ( $r['day'] ?? '' ), 0, 10 );
		$vid   = strtolower( (string) ( $r['vid'] ?? '' ) );
		$class = (string) ( $r['class'] ?? '' );
		if ( 'bot' === $class ) {
			$key = array( 'suspected_automation', 'ua_list' );
		} elseif ( (int) ( $r['feed'] ?? 0 ) > 0 ) {
			$key = array( 'human', 'feed_server' );
		} elseif ( 'human' !== $class ) {
			$key = array( 'suspected_automation', 'network' );
		} elseif ( isset( $over[ $vid ] ) ) {
			$key = array( 'suspected_automation', 'over_cap' );
		} elseif ( sn_bot_signals_score( $bits[ $day . '|' . $vid ] ?? array() ) >= SNT_BOT_SIGNAL_THRESHOLD ) {
			$key = array( 'suspected_automation', 'bot_score' );
		} else {
			$key = array( 'human', 'remainder' );
		}
		$out = sn_lanes_beacon_add( $out, $day, array( $key[0], $key[1], '' ), $vid, (int) round( (float) ( $r['views'] ?? 0 ) ) );
	}
	foreach ( $owner as $r ) {
		$r   = (array) $r;
		$id  = (int) ( $r['identity'] ?? 0 );
		$key = SN_LANES_IDENTITY[ $id ] ?? array( 'unverified_owner', 'unknown_code' );
		$out = sn_lanes_beacon_add( $out, substr( (string) ( $r['day'] ?? '' ), 0, 10 ), array( $key[0], $key[1], sn_lanes_agent_label( $r['agent'] ?? 0 ) ), strtolower( (string) ( $r['vid'] ?? '' ) ), (int) round( (float) ( $r['views'] ?? 0 ) ) );
	}
	$rows = array();
	foreach ( $out as $day => $cells ) {
		foreach ( $cells as $c ) {
			$rows[ $day ][] = array( 'lane' => $c['lane'], 'reason' => $c['reason'], 'agent' => $c['agent'], 'family' => '', 'purpose' => '', 'hits' => $c['hits'], 'visits' => count( $c['pv'] ), 'visitor_days' => count( $c['all'] ) );
		}
	}
	return $rows;
}

/**
 * Run the public read: one dataset, or both halves of the split. A partial
 * read never writes: the answer is then 'read_failed' or 'truncated'.
 *
 * @param string $tz Zone.
 * @return array|string Rows, or the outcome that stopped the read.
 */
function sn_lanes_public_rows( $tz ) {
	$from   = sn_analytics_trailing_from( SN_LANES_WINDOW_DAYS );
	$halves = sn_analytics_stitch( $from );
	$parts  = null === $halves ? array( array( sn_analytics_source( $from ), '' ) ) : array(
		array( $halves['legacy'], " AND timestamp < toDateTime('{$halves['at']}')" ),
		array( $halves['v2'], " AND timestamp >= toDateTime('{$halves['at']}')" ),
	);
	$rows = array();
	foreach ( $parts as $p ) {
		$got = sn_lanes_read( sn_lanes_public_sql( $p[0], $p[1], $tz ) );
		if ( ! is_array( $got ) ) {
			return $got;
		}
		$rows = array_merge( $rows, $got );
	}
	return $rows;
}

/**
 * One AE read: rows, or 'read_failed' / 'truncated'.
 *
 * @param string $sql Statement.
 * @return array|string
 */
function sn_lanes_read( $sql ) {
	$got = sn_analytics_query( $sql );
	if ( ! is_array( $got ) ) {
		return 'read_failed';
	}
	return function_exists( 'sn_analytics_last_result_truncated' ) && sn_analytics_last_result_truncated() ? 'truncated' : $got;
}

/**
 * The beacon half of the nightly pass.
 *
 * @return bool Whether days were written.
 */
function sn_lanes_beacon_run() {
	$tz     = function_exists( 'sn_analytics_site_tz_name' ) ? sn_analytics_site_tz_name() : '';
	$public = sn_lanes_public_rows( $tz );
	if ( ! is_array( $public ) || array() === $public ) {
		error_log( '[sn-lanes] beacon: the public read failed, was truncated or was empty; the stored days are kept' );
		return sn_lanes_last_run( 'beacon', is_array( $public ) ? 'empty' : $public );
	}
	if ( '' === sn_lanes_since( 'beacon' ) ) {
		$first = sn_analytics_query( sn_lanes_beacon_since_sql( $tz ) );
		if ( is_array( $first ) && isset( $first[0]['day'] ) ) {
			sn_lanes_since( 'beacon', substr( (string) $first[0]['day'], 0, 10 ) );
		}
	}
	// Until a public row carries a rule version the analytics Worker has not
	// shipped owner routing, and sn_owner_hits may not exist: it is not read.
	// From then on, an owner read that fails or truncates skips the run.
	$owner = array();
	if ( '' !== sn_lanes_since( 'beacon' ) ) {
		$owner = sn_lanes_read( sn_lanes_owner_sql( $tz ) );
		if ( ! is_array( $owner ) ) {
			error_log( '[sn-lanes] beacon: the owner read ' . $owner . '; the stored days are kept' );
			return sn_lanes_last_run( 'beacon', $owner );
		}
	}
	$derived = sn_lanes_beacon_derive( $public, $owner, sn_analytics_overcap_vdays()['hashes'] );
	$ok      = sn_lanes_store_days( 'beacon', sn_lanes_complete_days( $tz ), $derived );
	return sn_lanes_last_run( 'beacon', $ok ? 'wrote' : 'not_written' );
}
