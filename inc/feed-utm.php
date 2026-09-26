<?php
/**
 * Feed click-throughs become visible: each item's link in the RSS and Atom
 * feeds carries `utm_source=rss&utm_medium=feed`, so a reader who clicks
 * through from a feed reader lands as a campaign visit the edge worker
 * already records (inc/analytics-utm.php). Reading inside the feed reader
 * stays invisible; that is the reader's app, not the site.
 *
 * Only the item LINK is tagged. The GUID (get_the_guid) is untouched, so feed
 * readers keep recognising items they have already seen; the comments feed
 * is left alone.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_FEED_UTM = array( 'utm_source' => 'rss', 'utm_medium' => 'feed' );

/**
 * Tag a URL with the feed UTM, keeping any query it has and never tagging
 * twice. PURE.
 *
 * @param string $url Item permalink.
 * @return string
 */
function snt_feed_utm_url( $url ) {
	$url = (string) $url;
	if ( '' === $url || false !== strpos( $url, 'utm_source=' ) ) {
		return $url;
	}
	$hash = '';
	$pos  = strpos( $url, '#' );
	if ( false !== $pos ) {
		$hash = substr( $url, $pos );
		$url  = substr( $url, 0, $pos );
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( SNT_FEED_UTM ) . $hash;
}

add_filter(
	'the_permalink_rss',
	static function ( $url ) {
		return ( function_exists( 'is_comment_feed' ) && is_comment_feed() ) ? $url : snt_feed_utm_url( $url );
	}
);
