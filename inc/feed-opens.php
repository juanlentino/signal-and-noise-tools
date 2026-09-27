<?php
/**
 * Feed reach: "opened in a feed reader", a floor. Each notes feed item ends
 * with a 1x1 image at /wp-json/signal-noise/v1/feed-open?p=ID (the note id is
 * the only parameter). A fetch records (day, note, hashed user agent) in one
 * option; the same UA opening the same note on the same day counts once. No
 * cookie, no IP, nothing personal is stored, and bot user agents are dropped
 * by the RSS tracker's own classifier (sn_rss_tracker_is_bot()).
 *
 * A floor, never a total: many readers block remote images, and image-proxying
 * readers (Feedly, Inoreader) fetch once for all their users. It is kept apart
 * from the north star, which counts on-site reads only; it shows beside it as
 * an input (inc/north-star-reading.php) and on Monitoring › RSS.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_FEED_OPENS_OPT     = 'snt_feed_opens';
const SNT_FEED_OPENS_DAYS    = 90;
const SNT_FEED_OPENS_PER_DAY = 500; // distinct UAs kept per note per day; bounds a UA-spraying flood.
const SNT_FEED_OPENS_GIF     = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

/**
 * The pixel URL for a note: the note id and nothing else.
 *
 * @param int $post_id Note id.
 * @return string
 */
function snt_feed_open_pixel_url( $post_id ) {
	return add_query_arg( 'p', (int) $post_id, rest_url( 'signal-noise/v1/feed-open' ) );
}

/**
 * Record one open into a store. PURE.
 *
 * @param array  $store   {Y-m-d: {post_id: {ua_hash: 1}}}.
 * @param int    $post_id Note id.
 * @param string $ua_hash Hashed user agent.
 * @param int    $now     Epoch seconds.
 * @return array The new store, pruned to SNT_FEED_OPENS_DAYS.
 */
function snt_feed_opens_record( array $store, $post_id, $ua_hash, $now ) {
	$day = gmdate( 'Y-m-d', $now );
	$pid = (string) (int) $post_id;
	$set = $store[ $day ][ $pid ] ?? array();
	if ( count( $set ) < SNT_FEED_OPENS_PER_DAY ) { // keyed by hash: a repeat open overwrites, never adds
		$set[ $ua_hash ] = 1;
	}
	$store[ $day ][ $pid ] = $set;
	$cut = gmdate( 'Y-m-d', $now - ( SNT_FEED_OPENS_DAYS - 1 ) * DAY_IN_SECONDS );
	return array_filter( $store, static fn( $d ) => $d >= $cut, ARRAY_FILTER_USE_KEY );
}

/**
 * Opens over the last $days days, total and per note. PURE.
 *
 * @param array $store Store.
 * @param int   $days  Window.
 * @param int   $now   Epoch seconds.
 * @return array{total:int, notes:array<int,int>}
 */
function snt_feed_opens_window( array $store, $days, $now ) {
	$cut   = gmdate( 'Y-m-d', $now - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
	$notes = array();
	foreach ( $store as $day => $per ) {
		if ( $day < $cut ) {
			continue;
		}
		foreach ( (array) $per as $pid => $set ) {
			$notes[ (int) $pid ] = ( $notes[ (int) $pid ] ?? 0 ) + count( (array) $set );
		}
	}
	arsort( $notes );
	return array( 'total' => array_sum( $notes ), 'notes' => $notes );
}

/**
 * Opens in the last $days days, read from the stored option.
 *
 * @param int $days Window.
 * @return array{total:int, notes:array<int,int>}
 */
function snt_feed_opens_stats( $days = 7 ) {
	return snt_feed_opens_window( (array) get_option( SNT_FEED_OPENS_OPT, array() ), $days, time() );
}

/**
 * REST handler: record a human open of a published note, then answer the GIF.
 *
 * @param WP_REST_Request $req Request.
 */
function snt_feed_open_serve( $req ) {
	$pid = absint( $req->get_param( 'p' ) );
	$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- hashed, never stored raw.
	$bot = function_exists( 'sn_rss_tracker_is_bot' ) ? sn_rss_tracker_is_bot( $ua ) : ( '' === $ua );
	if ( $pid && ! $bot && 'post' === get_post_type( $pid ) && 'publish' === get_post_status( $pid ) ) {
		// ponytail: read-modify-write on one option; two opens in the same instant can lose one,
		// which only lowers a floor. Move to a table if opens ever reach hundreds a minute.
		$store = snt_feed_opens_record( (array) get_option( SNT_FEED_OPENS_OPT, array() ), $pid, substr( hash( 'sha256', $ua ), 0, 16 ), time() );
		update_option( SNT_FEED_OPENS_OPT, $store, false );
	}
	nocache_headers();
	header( 'Cache-Control: no-store, private, max-age=0' );
	header( 'Content-Type: image/gif' );
	echo base64_decode( SNT_FEED_OPENS_GIF ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a fixed 1x1 GIF.
	exit;
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'signal-noise/v1',
			'/feed-open',
			array(
				'methods'             => 'GET',
				'callback'            => 'snt_feed_open_serve',
				'permission_callback' => '__return_true',
				'args'                => array( 'p' => array( 'type' => 'integer', 'required' => true ) ),
			)
		);
	}
);
