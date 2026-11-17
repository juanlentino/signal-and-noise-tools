<?php
/**
 * Link scans after a share (owner brief 2026-11-17). When a coded link is
 * sent in a LinkedIn message, LinkedIn's verified preview bot fetches it (a
 * share echo, inc/share-echoes.php) and, within a minute, a browser on
 * Microsoft's network opens it and runs the page: LinkedIn checking the link,
 * not the recipient (2026-11-17, /r/angela: verified fetches 15:46:54 to
 * 15:47:01 UTC, the browser at 15:47:48 from AS8075, 6 seconds, class
 * suspect).
 *
 * The browser itself proves nothing (Microsoft's network also carries this
 * site's own smoke tests), so it is recognized only by that verified fetch:
 * a suspect-class session on the same /r/ code, one page view, starting
 * within 60 seconds after the app's verified fetch. Joined by code and time
 * only, never by visitor code or place. Such a session is taken out of the
 * suspect sessions list and counted apart; the share itself is already the
 * echo. Class totals elsewhere are unchanged.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How long after the verified fetch the app's scan can start, seconds. */
const SN_SHARE_ECHO_SCAN_WINDOW = 60;

/** Apps whose link scan has been seen on this site (verified fetch, then a browser). */
const SN_SHARE_ECHO_SCAN_FAMILIES = array( 'linkedin' );

/**
 * The app that scanned this session's link, or null. PURE.
 *
 * @param array $echoes sn_share_echoes_rows() rows (verified where the family requires it), oldest first.
 * @param array $s      A session row.
 * @return string|null The app's name.
 */
function sn_share_echo_link_scan( array $echoes, array $s ) {
	$code  = (string) ( $s['link_code'] ?? '' );
	$start = (int) strtotime( (string) ( $s['start'] ?? '' ) );
	if ( '' === $code || $start <= 0 || (int) ( $s['pageviews'] ?? 0 ) > 1 ) {
		return null;
	}
	foreach ( $echoes as $e ) {
		$gap = $start - (int) $e['ts'];
		if ( 'r:' . $code === $e['key'] && in_array( $e['family'], SN_SHARE_ECHO_SCAN_FAMILIES, true ) && $gap >= 0 && $gap <= SN_SHARE_ECHO_SCAN_WINDOW ) {
			return SN_SHARE_ECHO_FAMILIES[ $e['family'] ];
		}
	}
	return null;
}

/**
 * Split link scans out of a suspect sessions read. PURE over its inputs.
 *
 * @param array      $rows   Session rows.
 * @param array|null $echoes Echo rows; null when they could not be read.
 * @return array{rows:array,scans:int|null}
 */
function sn_share_echo_split_scans( array $rows, $echoes ) {
	if ( null === $echoes ) {
		return array( 'rows' => $rows, 'scans' => null );
	}
	$kept = array_values( array_filter( $rows, static fn( $s ) => null === sn_share_echo_link_scan( $echoes, $s ) ) );
	return array( 'rows' => $kept, 'scans' => count( $rows ) - count( $kept ) );
}

/**
 * The split for a sessions read: suspect only; other classes keep every row
 * and read null.
 *
 * @param array  $rows  Session rows.
 * @param string $class The class read.
 * @return array{rows:array,scans:int|null}
 */
function sn_share_echo_scans_on_sessions( array $rows, $class ) {
	if ( 'suspect' !== $class || array() === $rows || ! function_exists( 'sn_share_echoes_read' ) ) {
		return array( 'rows' => $rows, 'scans' => 'suspect' === $class && array() === $rows ? 0 : null );
	}
	$oldest = min( array_map( static fn( $s ) => (int) strtotime( (string) ( $s['start'] ?? '' ) ), $rows ) );
	$days   = (int) ceil( ( sn_analytics_clock() - $oldest + SN_SHARE_ECHO_SCAN_WINDOW ) / 86400 ) + 1;
	return sn_share_echo_split_scans( $rows, sn_share_echoes_read( $days ) );
}
