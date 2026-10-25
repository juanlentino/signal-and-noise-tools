<?php
/**
 * Signal & Noise Tools: SEO, the requests that stay reachable but out of the
 * index: every feed, and MailPoet's subscription page.
 *
 * Search Console, 2026-10. /?mailpoet_page=subscriptions served 200, indexable,
 * with a self-canonical carrying the query string. That canonical was this
 * plugin's own, printed from get_permalink() of the mailpoet_page post. Since
 * wave 2 (2026-10-24) core's rel_canonical() prints singular canonicals and
 * sn_seo_canonical_url() returns none for this page; the robots half rides
 * sn_seo_robots_directives(), which sn_seo_wp_robots() hands to core's
 * wp_robots() (seo-head-parts.php).
 *
 * Feeds keep working and keep their <link rel="alternate"> tags; the header
 * only asks search engines not to list the feed itself as a result.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is this request MailPoet's subscription or unsubscribe page? Only when the
 * query resolved to its post type (/?mailpoet_page=subscriptions does, live:
 * body class single-mailpoet_page). The bare parameter is not enough: any URL
 * with ?mailpoet_page= appended would otherwise go noindex and lose its
 * canonical, a variant anyone could link to.
 *
 * @return bool
 */
function sn_seo_is_mailpoet_request() {
	return function_exists( 'is_singular' ) && is_singular( 'mailpoet_page' );
}

/**
 * The X-Robots-Tag header line this request carries, or '' for none.
 *
 * @return string
 */
function sn_seo_robots_header() {
	if ( function_exists( 'is_feed' ) && is_feed() ) {
		return 'X-Robots-Tag: noindex, follow';
	}
	return sn_seo_is_mailpoet_request() ? 'X-Robots-Tag: noindex' : '';
}

/**
 * wp_headers filter: send it with core's headers. WP::send_headers() applies
 * wp_headers after the query (is_feed() is answered) and before it answers a
 * feed's conditional GET with a 304 and exits, so a 304 carries it too; a
 * template_redirect sender never ran on a 304.
 *
 * @param array $headers Core's headers.
 * @return array
 */
function sn_seo_robots_header_filter( $headers ) {
	$line = sn_seo_robots_header();
	if ( '' !== $line ) {
		list( $name, $value ) = explode( ': ', $line, 2 );
		$headers[ $name ]     = $value;
	}
	return $headers;
}
add_filter( 'wp_headers', 'sn_seo_robots_header_filter' );
