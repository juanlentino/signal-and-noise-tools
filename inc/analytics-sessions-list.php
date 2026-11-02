<?php
/**
 * Signal & Noise Tools: sessions one by one (owner brief "MCP full analytics
 * access", 2026-11-01).
 *
 * The Sessions tab's unit (a within-day visit split at the idle gap, with at
 * least one page view: sn_sessionize(), sn_pageview_visits()) listed one row
 * per session: start and end in site time, place, device, the source it
 * arrived from, the pages in order with time and scroll on each, class,
 * returning (a second or later session of the same visitor-day: no visitor is
 * recognized on another day) and the link code of a coded résumé link. The
 * Sessions tab's list and both MCP doors read this one function, so they
 * agree row for row. No visitor code, IP address or user agent is returned.
 * Paths pass the rows read's rule: the site's own content or (unmatched).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_SESSIONS_PER_PAGE = 50;
const SN_ANALYTICS_SESSIONS_MAX_PAGE = 200;

/**
 * One session's row. PURE given the source labels.
 *
 * @param array  $events    Its events, in time order (sn_sessionize()).
 * @param bool   $returning A second or later session of the same visitor-day.
 * @param string $class     The class the sessions were read for.
 * @param array  $real      Canonical content paths (snt_arows_real_paths()).
 * @param array  $cfg       sn_analytics_session_config(): the tab's engaged thresholds.
 * @return array
 */
function sn_analytics_session_row( array $events, $returning, $class, array $real, array $cfg ) {
	// A beacon in the same second as its page view comes after it, whatever order the read returned.
	usort( $events, static fn( $a, $b ) => (int) $a['ts'] <=> (int) $b['ts'] ?: ( 'pv' === ( $b['ev'] ?? '' ) ) <=> ( 'pv' === ( $a['ev'] ?? '' ) ) );
	$sum   = sn_visit_summary( $events, $cfg['engaged_scroll'], $cfg['engaged_ms'] );
	$pages = array();
	$last  = array(); // path => its latest view: a beacon belongs to the view it follows.
	$first = null;
	$code  = '';
	foreach ( $events as $e ) {
		$p  = (string) ( $e['path'] ?? '' );
		$ev = (string) ( $e['ev'] ?? '' );
		if ( 'pv' === $ev ) {
			$first      = $first ?? $e;
			$code       = '' === $code && function_exists( 'sn_link_code_from_path' ) ? sn_link_code_from_path( sn_analytics_canonical_path( $p ) ) : $code;
			$last[ $p ] = count( $pages );
			$pages[]    = array( 'path' => snt_arows_clean( 'path', $p, $real ), 'time_ms' => null, 'scroll_pct' => null );
		} elseif ( isset( $last[ $p ] ) && 'tm' === $ev ) {
			$pages[ $last[ $p ] ]['time_ms'] = (int) $pages[ $last[ $p ] ]['time_ms'] + (int) ( $e['dwell'] ?? 0 ); // deltas: they add up.
		} elseif ( isset( $last[ $p ] ) && 'sc' === $ev ) {
			$pages[ $last[ $p ] ]['scroll_pct'] = max( (int) $pages[ $last[ $p ] ]['scroll_pct'], (int) ( $e['scroll'] ?? 0 ) );
		}
	}
	$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	$when = static fn( $ts ) => ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz )->format( 'Y-m-d\TH:i:sP' );
	$start = (int) ( $events[0]['ts'] ?? 0 );
	return array(
		'start'     => $when( $start ),
		'end'       => $when( $start + (int) $sum['duration'] ),
		'duration_s' => (int) $sum['duration'],
		'pageviews' => (int) $sum['pageviews'],
		'entry'     => $pages ? $pages[0]['path'] : '',
		'exit'      => $pages ? $pages[ count( $pages ) - 1 ]['path'] : '',
		'pages'     => $pages,
		'source'    => sn_analytics_session_source( (string) ( $first['ref'] ?? '' ) ),
		// The rows read's values: (unknown) when blank, so the two reads join.
		'country'   => snt_arows_clean( 'country', (string) ( $first['country'] ?? '' ), $real ),
		'region'    => snt_arows_clean( 'region', (string) ( $first['region'] ?? '' ), $real ),
		'city'      => snt_arows_clean( 'city', (string) ( $first['city'] ?? '' ), $real ),
		'device'    => snt_arows_clean( 'device', (string) ( $first['device'] ?? '' ), $real ),
		'class'     => (string) $class,
		'returning' => (bool) $returning,
		'link_code' => $code,
		'engaged'   => (bool) $sum['engaged'],
		// Event names are the beacon's, not the site's, and reach a model's context:
		// identifier-shaped names only (the site's own are share_copy, verify,
		// contact-<alias>, resume_lane_music); a sentence reads (invalid).
		'goals'     => array_values( array_map( static fn( $g ) => preg_match( '#^[A-Za-z0-9_.:-]{1,64}$#', (string) $g ) ? (string) $g : '(invalid)', $sum['goals'] ) ),
	);
}

/**
 * A session's source as the doors may show it: (direct), (internal), a known
 * brand label, or a clean hostname. The referrer host is visitor-written and
 * lands in a model's context, so anything else reads (invalid), as the live
 * readers' label does (sn_analytics_live_source_label()). PURE given the rules.
 *
 * @param string $ref The first page view's stored referrer.
 * @return string
 */
function sn_analytics_session_source( $ref ) {
	$src = sn_analytics_canonical_source( (string) $ref );
	if ( in_array( $src, array( '(direct)', '(internal)' ), true ) || in_array( $src, array_column( sn_analytics_source_rules(), 'label' ), true ) ) {
		return $src;
	}
	return strlen( $src ) <= 253 && preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $src ) ? $src : '(invalid)';
}

/**
 * Raw rows to session rows, newest first. PURE given the labels.
 *
 * @param array  $rows  sn_analytics_session_sql() rows.
 * @param string $class Traffic class.
 * @param array  $real  Canonical content paths.
 * @param array  $cfg   sn_analytics_session_config(): the idle gap and the engaged thresholds.
 * @param int    $since    Keep sessions that started at or after this instant (0 keeps all).
 * @param int    $until    ... and before this one (0 keeps all).
 * @param bool   $with_vid Keep the visitor-day code as `_vid`, for a caller that counts visitor-days and drops it (inc/analytics-rows-sessions.php); never in an answer.
 * @return array
 */
function sn_analytics_sessions_build( array $rows, $class, array $real, array $cfg, $since = 0, $until = 0, $with_vid = false ) {
	$by_vid = array();
	foreach ( sn_sessionize( $rows, $cfg['gap_sec'] ) as $events ) {
		if ( sn_pageview_visits( array( sn_visit_summary( $events ) ) ) ) {
			$by_vid[ (string) $events[0]['vid'] ][] = $events;
		}
	}
	$out = array();
	foreach ( $by_vid as $sessions ) {
		usort( $sessions, static fn( $a, $b ) => (int) $a[0]['ts'] <=> (int) $b[0]['ts'] );
		foreach ( $sessions as $i => $events ) {
			if ( (int) $events[0]['ts'] >= (int) $since && ( 0 === (int) $until || (int) $events[0]['ts'] < (int) $until ) ) {
				$out[] = array( 'ts' => (int) $events[0]['ts'] ) + sn_analytics_session_row( $events, $i > 0, $class, $real, $cfg ) + ( $with_vid ? array( '_vid' => (string) $events[0]['vid'] ) : array() );
			}
		}
	}
	usort( $out, static fn( $a, $b ) => $b['ts'] <=> $a['ts'] ?: strcmp( $a['entry'], $b['entry'] ) );
	return array_map( static fn( $r ) => array_diff_key( $r, array( 'ts' => 1 ) ), $out );
}

/**
 * The UTC instants of the whole visitor-days [$t_lo, $t_hi) touches: from the
 * rotation zone's midnight on or before $t_lo to the one after $t_hi - 1. PURE.
 *
 * @param int $t_lo Unix time, inclusive.
 * @param int $t_hi Unix time, exclusive.
 * @return array{0:string,1:string}
 */
function sn_analytics_visitor_day_bounds( $t_lo, $t_hi ) {
	$zone  = new DateTimeZone( defined( 'SN_ANALYTICS_SPLIT_TZ' ) ? SN_ANALYTICS_SPLIT_TZ : 'UTC' );
	$utc   = new DateTimeZone( 'UTC' );
	$start = ( new DateTimeImmutable( '@' . (int) $t_lo ) )->setTimezone( $zone )->setTime( 0, 0 );
	$end   = ( new DateTimeImmutable( '@' . max( (int) $t_lo, (int) $t_hi - 1 ) ) )->setTimezone( $zone )->setTime( 0, 0 )->modify( '+1 day' );
	return array( $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
}

/**
 * The sessions that START in [$lo, $hi) for a class: rows plus a cap flag, or
 * null on a failed read. A visitor-day is a day in the analytics worker's
 * rotation zone (SN_ROTATE_TZ, SN_ANALYTICS_SPLIT_TZ: the visitor code rotates
 * at midnight there, not at UTC midnight), so the read takes the whole
 * visitor-days the window touches: a session is never cut at the window's
 * edge, and returning counts every earlier session of the same visitor-day,
 * inside the window or not. (Reading whole UTC days missed the evening's
 * earlier sessions for a window opened between 00:00 UTC and New York
 * midnight, and cut a session that straddled 00:00 UTC.)
 *
 * @param string $lo       UTC instant, inclusive.
 * @param string $hi       UTC instant, exclusive.
 * @param string $class    Traffic class.
 * @param bool   $with_vid See sn_analytics_sessions_build().
 * @return array{rows:array,capped:bool}|null
 */
function sn_analytics_sessions_read( $lo, $hi, $class, $with_vid = false ) {
	$cfg  = sn_analytics_session_config();
	$t_lo = strtotime( $lo . ' UTC' );
	$t_hi = strtotime( $hi . ' UTC' );
	$read = sn_analytics_visitor_day_bounds( $t_lo, $t_hi );
	$day  = substr( $read[0], 0, 10 );
	$sets = sn_analytics_stitched_rows( static fn( $s, $r ) => sn_analytics_session_sql( $day, $day, $class, $cfg['row_cap'], $s, $r, $read ), $day, $read[1] );
	if ( null === $sets ) {
		return null;
	}
	$real = function_exists( 'snt_arows_real_paths' ) ? snt_arows_real_paths() : array( '/' => true );
	return array(
		'rows'   => sn_analytics_sessions_build( array_merge( array(), ...$sets ), $class, $real, $cfg, $t_lo, $t_hi, $with_vid ),
		'capped' => array() !== array_filter( $sets, static fn( $s ) => count( $s ) >= $cfg['row_cap'] ),
	);
}
