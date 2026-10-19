<?php
/**
 * Signal & Noise Tools: the index_gap watch. A published note or page Google
 * has not indexed SNT_GSC_INDEX_WATCH_DAYS after it went live.
 *
 * Why 14: a new note is inspected on its own at day 3 and day 10
 * (SNT_GSC_INSPECT_ONE_DAYS), and the weekly run looks again within seven
 * days of that. A note still out at day 14 has had Google's own second look
 * and a full weekly pass, and is no longer "give it a few days".
 *
 * Reads the stored coverage map only, so the brief and sn-status{watches}
 * never spend inspection quota. Clears on its own when the weekly map reads
 * the page indexed. The URL Inspection API cannot request indexing, so the
 * note hands the owner a direct inspection link for each path and says to
 * click Request indexing there.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_GSC_INDEX_WATCH_DAYS = 14;

/** A direct Search Console URL Inspection link. PURE. */
function snt_gsc_inspect_link( $property, $url ) {
	return 'https://search.google.com/search-console/inspect?resource_id=' . rawurlencode( (string) $property ) . '&id=' . rawurlencode( (string) $url );
}

/** Is this entry a published note or page Google has not indexed (and not by noindex on purpose)? PURE. */
function snt_gsc_index_gap_candidate( $e ) {
	return is_array( $e ) && ! isset( $e['error'] ) && 'post' === ( $e['kind'] ?? 'post' ) && false === ( $e['indexed'] ?? null )
		&& 0 !== stripos( (string) ( $e['coverage_state'] ?? '' ), 'excluded by' ) && (int) ( $e['post_id'] ?? 0 ) > 0;
}

/**
 * Candidates published at least $days ago, oldest first. PURE.
 *
 * @param array $entries   Coverage map entries, keyed by join path.
 * @param array $published post_id => publish unix time.
 * @param int   $now
 * @param int   $days
 * @return array<int,array{path:string,url:string,state:string,days:int}>
 */
function snt_gsc_index_stuck( $entries, $published, $now, $days ) {
	$out = array();
	foreach ( (array) $entries as $path => $e ) {
		$at = snt_gsc_index_gap_candidate( $e ) ? (int) ( $published[ (int) $e['post_id'] ] ?? 0 ) : 0;
		if ( $at <= 0 || ( $now - $at ) < (int) $days * DAY_IN_SECONDS ) {
			continue;
		}
		$out[] = array( 'path' => (string) $path, 'url' => (string) ( $e['url'] ?? '' ), 'state' => (string) $e['coverage_state'], 'days' => (int) floor( ( $now - $at ) / DAY_IN_SECONDS ) );
	}
	usort( $out, static function ( $a, $b ) {
		return $b['days'] <=> $a['days'];
	} );
	return $out;
}

/**
 * Ripe while a published note or page sits unindexed past the line. Trailing
 * parameters are test seams; false/null read the site.
 *
 * @param array            $watch     The watch row (unused).
 * @param int              $now
 * @param array|null|false $data      Coverage map; false reads it.
 * @param array|null       $published post_id => publish time; null reads it.
 * @param string|null      $property  Search Console property; null reads it.
 * @param array|null       $bing      Bing answers by path; null reads them.
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_index_gap( $watch, $now, $data = false, $published = null, $property = null, $bing = null ) {
	unset( $watch );
	if ( false === $data ) {
		$data = function_exists( 'snt_gsc_coverage_data' ) ? snt_gsc_coverage_data() : null;
	}
	if ( ! is_array( $data ) ) {
		return array( 'ripe' => false, 'note' => 'coverage not synced yet' );
	}
	$entries = (array) ( $data['entries'] ?? array() );
	if ( null === $published ) {
		$published = array();
		foreach ( $entries as $e ) {
			if ( snt_gsc_index_gap_candidate( $e ) && function_exists( 'get_post_time' ) ) {
				$published[ (int) $e['post_id'] ] = (int) get_post_time( 'U', true, (int) $e['post_id'] );
			}
		}
	}
	$property = null === $property ? ( function_exists( 'sn_setting' ) ? (string) sn_setting( 'search_console.property', '' ) : '' ) : (string) $property;
	$bing     = null === $bing ? ( function_exists( 'sn_bing_url_info_data' ) ? sn_bing_url_info_data() : array() ) : (array) $bing;
	$stuck    = snt_gsc_index_stuck( $entries, $published, (int) $now, SNT_GSC_INDEX_WATCH_DAYS );
	if ( array() === $stuck ) {
		$young = 0;
		foreach ( $entries as $e ) {
			$young += snt_gsc_index_gap_candidate( $e ) ? 1 : 0;
		}
		return array( 'ripe' => false, 'note' => 'none past ' . SNT_GSC_INDEX_WATCH_DAYS . ' days' . ( $young ? sprintf( '; %d newer still waiting', $young ) : '' ) );
	}
	$parts = array();
	foreach ( $stuck as $r ) {
		$b       = function_exists( 'sn_bing_url_info_label' ) ? sn_bing_url_info_label( $bing[ $r['path'] ] ?? null ) : null;
		$parts[] = sprintf( '%s (%s, %d days since publish; %s): %s', $r['path'], $r['state'], $r['days'], null === $b ? 'Bing not read yet' : $b, snt_gsc_inspect_link( $property, $r['url'] ) );
	}
	return array(
		'ripe' => true,
		'note' => sprintf( '%d published notes or pages not indexed after %d days: ', count( $stuck ), SNT_GSC_INDEX_WATCH_DAYS ) . implode( ' | ', $parts )
			. '. The API cannot request indexing: open each link and click Request indexing.',
	);
}
