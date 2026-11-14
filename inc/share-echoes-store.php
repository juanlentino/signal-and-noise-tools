<?php
/**
 * Share echoes (inc/share-echoes.php): the read from the rights-signals worker
 * and the durable counts. The per-event rows live as long as Analytics Engine
 * keeps them (about 90 days); per site day, app and page counts are kept in
 * one option, refreshed with the nightly rollup.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_SHARE_ECHOES_OPTION = 'sn_share_echoes_by_day';
const SN_SHARE_ECHOES_KEEP   = 400;

/**
 * The echoes of the last $days days, validated and collapsed, oldest first.
 * Null when the read failed, or when the worker answered rows that are not
 * echoes (a worker before 1.39.0 answers an unknown view with its aggregate).
 *
 * @param int $days 1 to 90.
 * @return array|null
 */
function sn_share_echoes_read( $days = 14 ) {
	if ( ! function_exists( 'snt_mr_fetch' ) ) {
		return null;
	}
	$res = snt_mr_fetch( max( 1, min( 90, (int) $days ) ), 'echoes' );
	if ( empty( $res['ok'] ) ) {
		return null;
	}
	$raw  = (array) ( $res['rows'] ?? array() );
	$rows = sn_share_echoes_rows( $raw );
	if ( array() !== $raw && array() === $rows ) {
		error_log( '[sn-analytics] share echoes: the worker answered rows that are not echoes (is rights-signals 1.39.0 deployed?)' );
		return null;
	}
	return $rows;
}

/**
 * Echoes per site day, app and page, over the days the rows cover. PURE.
 *
 * @param array    $rows sn_share_echoes_rows() rows.
 * @param string[] $days The site days the read covered completely.
 * @param string   $tz   The site's zone ('' = UTC).
 * @return array<string,array<string,array<string,int>>> day => family => path => echoes.
 */
function sn_share_echoes_fold( array $rows, array $days, $tz = '' ) {
	$zone = new DateTimeZone( '' !== $tz ? $tz : 'UTC' );
	$out  = array_fill_keys( $days, array() );
	foreach ( $rows as $r ) {
		$day = ( new DateTimeImmutable( '@' . (int) $r['ts'] ) )->setTimezone( $zone )->format( 'Y-m-d' );
		if ( array_key_exists( $day, $out ) ) {
			$out[ $day ][ $r['family'] ][ $r['path'] ] = (int) ( $out[ $day ][ $r['family'] ][ $r['path'] ] ?? 0 ) + 1;
		}
	}
	return $out;
}

/** Nightly: the last week's echoes into the durable counts. */
function sn_share_echoes_refresh() {
	$rows = sn_share_echoes_read( 8 );
	if ( null === $rows || ! function_exists( 'sn_analytics_reads_today' ) ) {
		return;
	}
	$today = sn_analytics_reads_today();
	$days  = array();
	for ( $i = 6; $i >= 1; $i-- ) { // complete site days only; today is still filling.
		$days[] = sn_analytics_reads_shift( $today, -$i );
	}
	$stored = get_option( SN_SHARE_ECHOES_OPTION, array() );
	$out    = array_merge( is_array( $stored ) ? $stored : array(), sn_share_echoes_fold( $rows, $days, sn_analytics_site_tz_name() ) );
	ksort( $out );
	update_option( SN_SHARE_ECHOES_OPTION, array_slice( $out, -SN_SHARE_ECHOES_KEEP, null, true ), false );
}
if ( defined( 'SN_ANALYTICS_ROLLUP_DAILY_HOOK' ) ) {
	add_action( SN_ANALYTICS_ROLLUP_DAILY_HOOK, 'sn_share_echoes_refresh', 22 );
}

/**
 * The share annotation on a sessions read: human sessions only, and only
 * when the echoes read; otherwise each session carries share null.
 *
 * @param array  $sessions Session rows.
 * @param string $class    The class they were read for.
 * @return array
 */
function sn_share_echoes_on_sessions( array $sessions, $class ) {
	// Far enough back for the oldest session's 72 hours.
	$oldest = array() === $sessions ? 0 : min( array_map( static fn( $s ) => (int) strtotime( (string) ( $s['start'] ?? '' ) ), $sessions ) );
	$days   = (int) ceil( ( sn_analytics_clock() - $oldest + SN_SHARE_ECHO_WINDOW ) / 86400 ) + 1;
	$echoes = 'human' === $class && array() !== $sessions ? sn_share_echoes_read( $days ) : null;
	if ( null === $echoes ) {
		return array_map( static fn( $s ) => $s + array( 'share' => null ), $sessions );
	}
	return sn_share_echo_annotate( $sessions, $echoes );
}
