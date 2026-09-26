<?php
/**
 * The north star's return layer, wired: DOI downloads from Zenodo's public
 * records search (by the author ORCID, 25 a page, no token) and inquiries
 * from the forms plugin's entries.
 *
 * Zenodo reports lifetime totals, so a daily cron keeps one snapshot a day
 * and the weekly figure is the difference against the snapshot a week back.
 * Until a week of history exists the reading says "all time", never a guess.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_NSM_ZENODO_HOOK = 'snt_nsm_zenodo_daily';
const SNT_NSM_ZENODO_OPT  = 'snt_nsm_zenodo_snapshots';
const SNT_NSM_ZENODO_KEEP = 40; // days of snapshots kept.

/**
 * Sum lifetime stats over every record by the author. Null when any page
 * fails: a partial sum would read as a drop.
 *
 * @return array{downloads:int,views:int,records:int}|null
 */
function snt_nsm_zenodo_fetch() {
	$orcid = defined( 'SN_ZENODO_ORCID' ) ? SN_ZENODO_ORCID : '';
	if ( '' === $orcid ) {
		return null;
	}
	$sum   = array( 'downloads' => 0, 'views' => 0, 'records' => 0 );
	$total = null;
	for ( $page = 1; $page <= 20 && ( null === $total || $sum['records'] < $total ); $page++ ) {
		$url  = 'https://zenodo.org/api/records?size=25&page=' . $page . '&q=' . rawurlencode( 'creators.orcid:"' . $orcid . '"' );
		$resp = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : 'dev' ) . ' north-star' ) ) );
		$body = is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ? null : json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || ! isset( $body['hits']['hits'] ) ) {
			return null;
		}
		$total = (int) ( $body['hits']['total'] ?? 0 );
		$hits  = (array) $body['hits']['hits'];
		if ( array() === $hits ) {
			break;
		}
		foreach ( $hits as $h ) {
			$sum['downloads'] += (int) ( $h['stats']['unique_downloads'] ?? 0 );
			$sum['views']     += (int) ( $h['stats']['unique_views'] ?? 0 );
			++$sum['records'];
		}
	}
	return $sum;
}

/**
 * Cron: record today's snapshot, trimmed to the last SNT_NSM_ZENODO_KEEP days.
 */
function snt_nsm_zenodo_snapshot() {
	$sum = snt_nsm_zenodo_fetch();
	if ( null === $sum ) {
		return;
	}
	$snaps                     = (array) get_option( SNT_NSM_ZENODO_OPT, array() );
	$snaps[ gmdate( 'Y-m-d' ) ] = $sum;
	ksort( $snaps );
	update_option( SNT_NSM_ZENODO_OPT, array_slice( $snaps, -SNT_NSM_ZENODO_KEEP, null, true ), false );
}
add_action( SNT_NSM_ZENODO_HOOK, 'snt_nsm_zenodo_snapshot' );
add_action(
	'init',
	static function () {
		if ( ! wp_next_scheduled( SNT_NSM_ZENODO_HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', SNT_NSM_ZENODO_HOOK );
		}
	}
);

/**
 * The downloads reading from snapshots. PURE.
 *
 * @param array  $snaps date => {downloads, views}.
 * @param string $today Y-m-d.
 * @return array{value:int|null,window:string,pending?:string}
 */
function snt_nsm_zenodo_reading( array $snaps, $today ) {
	if ( array() === $snaps ) {
		return array( 'value' => null, 'window' => '', 'pending' => __( 'First Zenodo snapshot runs tonight.', 'signal-and-noise-tools' ) );
	}
	ksort( $snaps );
	$latest = end( $snaps );
	$back   = gmdate( 'Y-m-d', strtotime( $today . ' 00:00:00 UTC' ) - 7 * 86400 );
	if ( isset( $snaps[ $back ] ) ) {
		return array( 'value' => max( 0, (int) $latest['downloads'] - (int) $snaps[ $back ]['downloads'] ), 'window' => '7d' );
	}
	return array( 'value' => (int) $latest['downloads'], 'window' => 'all time' );
}

/**
 * Inquiries: form entries the owner can act on (read or unread; never spam,
 * partial or trash) since an epoch. Null when the forms plugin is absent.
 *
 * @param int $since Epoch seconds.
 * @return int|null
 */
function snt_nsm_inquiries_since( $since ) {
	if ( ! defined( 'ALLTFO_ENTRY_TYPE' ) || ! defined( 'ALLTFO_STATUS_UNREAD' ) || ! defined( 'ALLTFO_STATUS_READ' ) ) {
		return null;
	}
	return count(
		get_posts(
			array(
				'post_type'      => ALLTFO_ENTRY_TYPE,
				'post_status'    => array( ALLTFO_STATUS_UNREAD, ALLTFO_STATUS_READ ),
				'date_query'     => array( array( 'after' => gmdate( 'Y-m-d H:i:s', $since ), 'column' => 'post_date_gmt' ) ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		)
	);
}
