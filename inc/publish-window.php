<?php
/**
 * Signal & Noise Tools: publish windows, computed on demand.
 *
 * `_sn_published_at` (unix seconds) is written once, when a note ('post')
 * first moves to publish, from time() at that transition: the real go-live
 * moment, scheduled (WP-Cron's wp_publish_post(), however late it runs) or
 * manual. Updates never touch it, and a republish keeps it, unless the
 * post's date is moved past the stored moment: then the note relaunched and
 * the stamp is reset (to time() on a transition to publish, to the new date
 * when a published note's date is edited in place). No backfill: a note
 * published before this existed has no window and the report says so.
 *
 * The figures are read from Analytics Engine when a report asks: the window
 * [published_at, published_at + length) and everything after it, each split
 * by lane. Once the window has closed its figures are cached in
 * `sn_publish_window_cache` (not autoloaded, bounded), and only the
 * after-window half is read again. The remote door is not touched.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/publish-window-parts.php';

/**
 * Stamp the go-live moment on a transition to publish.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       The post.
 * @return void
 */
function sn_publish_window_on_transition( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status || ! is_object( $post ) || 'post' !== ( $post->post_type ?? '' ) ) {
		return;
	}
	$stored = get_post_meta( (int) $post->ID, SN_PUBLISH_WINDOW_META, true );
	if ( sn_publish_window_should_stamp( $stored, (int) strtotime( (string) ( $post->post_date_gmt ?? '' ) . ' UTC' ) ) ) {
		update_post_meta( (int) $post->ID, SN_PUBLISH_WINDOW_META, time() );
	}
}
add_action( 'transition_post_status', 'sn_publish_window_on_transition', 10, 3 );

/**
 * A published note whose date is moved past its stamp, in place, relaunched.
 *
 * @param int     $post_id Post id.
 * @param WP_Post $after   After the update.
 * @param WP_Post $before  Before it.
 * @return void
 */
function sn_publish_window_on_update( $post_id, $after, $before ) {
	if ( ! is_object( $after ) || ! is_object( $before ) || 'post' !== ( $after->post_type ?? '' ) || 'publish' !== ( $after->post_status ?? '' ) || 'publish' !== ( $before->post_status ?? '' ) ) {
		return;
	}
	$date = (int) strtotime( (string) ( $after->post_date_gmt ?? '' ) . ' UTC' );
	if ( (string) ( $after->post_date_gmt ?? '' ) === (string) ( $before->post_date_gmt ?? '' ) ) {
		return;
	}
	$stored = get_post_meta( (int) $post_id, SN_PUBLISH_WINDOW_META, true );
	if ( is_numeric( $stored ) && (int) $stored > 0 && $date > (int) $stored ) {
		update_post_meta( (int) $post_id, SN_PUBLISH_WINDOW_META, $date );
	}
}
add_action( 'post_updated', 'sn_publish_window_on_update', 10, 3 );

/**
 * The window length in minutes (Measurement › Analytics › Setup).
 *
 * @return int
 */
function sn_publish_window_minutes() {
	return sn_publish_window_clamp( function_exists( 'sn_setting' ) ? sn_setting( 'analytics.publish_window_minutes', SN_PUBLISH_WINDOW_DEFAULT ) : SN_PUBLISH_WINDOW_DEFAULT );
}

/**
 * One note's publish window, read on demand.
 *
 * @param int      $post_id Note id.
 * @param int|null $now     Unix time (tests).
 * @return array{state:string,published_at:int|null,minutes:int,ends_at:int|null,minutes_left:int|null,window:array|null,after:array|null,sources:array|null,cached:bool}
 *         state: none (no stamp), open, closed, unread (a read failed).
 */
function sn_publish_window_report( $post_id, $now = null ) {
	$now     = null === $now ? time() : (int) $now;
	$stamp   = (int) get_post_meta( (int) $post_id, SN_PUBLISH_WINDOW_META, true );
	$minutes = sn_publish_window_minutes();
	$out     = array( 'state' => 'none', 'published_at' => null, 'minutes' => $minutes, 'ends_at' => null, 'minutes_left' => null, 'window' => null, 'after' => null, 'sources' => null, 'cached' => false );
	if ( $stamp < 1 ) {
		return $out;
	}
	$cache = get_option( SN_PUBLISH_WINDOW_CACHE, array() );
	$cache = is_array( $cache ) ? $cache : array();
	$hit   = $cache[ (int) $post_id ] ?? null;
	// A cached window is used only for the same stamp and the same length.
	$hit   = is_array( $hit ) && (int) ( $hit['published_at'] ?? 0 ) === $stamp && (int) ( $hit['minutes'] ?? 0 ) === $minutes ? $hit : null;
	$end   = $stamp + 60 * $minutes;
	$open  = $now < $end;
	$out   = array_merge( $out, array( 'state' => $open ? 'open' : 'closed', 'published_at' => $stamp, 'ends_at' => $end, 'minutes_left' => $open ? (int) ceil( ( $end - $now ) / 60 ) : null ) );
	$read  = sn_publish_window_read( sn_analytics_post_path_or_empty( $post_id ), null !== $hit ? $end : $stamp, $end, null === $hit );
	if ( null === $read ) {
		$out['state'] = 'unread';
		return $out;
	}
	$out['after']   = $read['after'];
	$out['window']  = null !== $hit ? $hit['window'] : $read['window'];
	$out['sources'] = null !== $hit ? $hit['sources'] : $read['sources'];
	$out['cached']  = null !== $hit;
	if ( ! $open && null === $hit ) {
		update_option( SN_PUBLISH_WINDOW_CACHE, sn_publish_window_cache_put( $cache, (int) $post_id, array( 'published_at' => $stamp, 'minutes' => $minutes, 'window' => $out['window'], 'sources' => $out['sources'] ), $now ), false );
	}
	return $out;
}

/**
 * A note's URL path, or ''.
 *
 * @param int $post_id Note id.
 * @return string
 */
function sn_analytics_post_path_or_empty( $post_id ) {
	return function_exists( 'sn_analytics_post_path' ) ? (string) sn_analytics_post_path( (int) $post_id ) : (string) wp_parse_url( (string) get_permalink( (int) $post_id ), PHP_URL_PATH );
}

/**
 * The reads: the lanes per part from `$from`, and the window's sources when
 * asked. Null when any read failed or the path is not readable.
 *
 * @param string $path         Note path.
 * @param int    $from         First instant.
 * @param int    $end          Window end.
 * @param bool   $with_sources Read the window's sources too.
 * @return array{window:array,after:array,sources:array|null}|null
 */
function sn_publish_window_read( $path, $from, $end, $with_sources ) {
	$scope = sn_publish_window_scope( $path, $from, $end );
	if ( null === $scope || ! function_exists( 'sn_lanes_public_sql' ) || ! function_exists( 'sn_analytics_query' ) ) {
		return null;
	}
	$tz     = function_exists( 'sn_analytics_site_tz_name' ) ? sn_analytics_site_tz_name() : '';
	$source = sn_analytics_source( gmdate( 'Y-m-d', (int) $from - 86400 ) );
	$public = sn_analytics_query( sn_lanes_public_sql( $source, '', $tz, $scope ) );
	$owner  = '' !== sn_lanes_since( 'beacon' ) ? sn_analytics_query( sn_lanes_owner_sql( $tz, $scope ) ) : array();
	if ( ! is_array( $public ) || ! is_array( $owner ) ) {
		return null;
	}
	$parts   = sn_publish_window_collapse( sn_lanes_beacon_derive( $public, $owner, sn_analytics_overcap_vdays()['hashes'] ) );
	$sources = null;
	if ( $with_sources ) {
		$rows = sn_analytics_query( sn_publish_window_sources_sql( $source, $path, $from, $end ) );
		if ( ! is_array( $rows ) ) {
			return null;
		}
		$sources = function_exists( 'sn_analytics_live_sources_from_rows' ) ? sn_analytics_live_sources_from_rows( $rows ) : array();
	}
	return array( 'window' => $parts['window'], 'after' => $parts['after'], 'sources' => $sources );
}
