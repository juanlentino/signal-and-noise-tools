<?php
/**
 * Share echoes (inc/share-echoes.php): the wording and the Shares list.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** "40 min, read": the gap from the share to the visit, and whether it was a read. PURE. */
function sn_share_echo_follow_text( $gap_s, $read ) {
	$m   = (int) round( (int) $gap_s / 60 );
	$gap = $m < 1 ? 'under a minute' : ( $m < 120 ? $m . ' min' : round( $m / 60 ) . ' h' );
	return $gap . ', ' . ( $read ? 'read' : 'not read' );
}

/** "after a share via WhatsApp, 40 min, read"; '' for no share. PURE. */
function sn_share_echo_text( $share ) {
	return is_array( $share ) ? 'after a share via ' . $share['app'] . ', ' . sn_share_echo_follow_text( $share['gap_s'], ! empty( $share['read'] ) ) : '';
}

/**
 * The Shares list: each echo, newest first, with every human visit that
 * followed it (the visits it is the latest share for). A share no one opened
 * yet is listed too, with no visits. PURE.
 *
 * @param array $echoes   sn_share_echoes_rows() rows.
 * @param array $sessions Human session rows.
 * @return array<int,array{at:int,path:string,family:string,app:string,visits:array}>
 */
function sn_share_echoes_list( array $echoes, array $sessions ) {
	$list = array();
	foreach ( $echoes as $i => $e ) {
		$list[ $i ] = array( 'at' => $e['ts'], 'path' => $e['path'], 'family' => $e['family'], 'app' => SN_SHARE_ECHO_FAMILIES[ $e['family'] ], 'visits' => array() );
	}
	foreach ( $sessions as $s ) {
		$start = (int) strtotime( (string) ( $s['start'] ?? '' ) );
		$hit   = sn_share_echo_for( $echoes, sn_share_echo_session_key( $s ), $start );
		if ( null === $hit ) {
			continue;
		}
		foreach ( $echoes as $i => $e ) {
			if ( $e['ts'] === $hit['at'] && $e['family'] === $hit['family'] && $e['key'] === sn_share_echo_session_key( $s ) ) {
				$list[ $i ]['visits'][] = array( 'start' => (string) $s['start'], 'gap_s' => $hit['gap_s'], 'read' => sn_share_echo_session_read( $s ) );
				break;
			}
		}
	}
	return array_reverse( array_values( $list ) );
}
