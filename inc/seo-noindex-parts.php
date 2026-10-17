<?php
/**
 * Signal & Noise Tools: SEO, the requests that stay reachable but out of the
 * index: every feed, and MailPoet's subscription page.
 *
 * Search Console, 2026-10. /?mailpoet_page=subscriptions served 200, indexable,
 * with a self-canonical carrying the query string. That canonical was this
 * plugin's own: core's rel_canonical is removed (seo-head-parts.php), and the
 * singular branch of sn_seo_meta_for_current_view() printed get_permalink() of
 * the mailpoet_page post. The robots half rides sn_seo_robots_directives(),
 * not core's wp_robots filter: the plugin removes wp_robots() and prints its
 * own meta, so a wp_robots filter never runs here.
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
 * Is this request MailPoet's subscription or unsubscribe page? Either the query
 * resolved to its post type, or the request carries the parameter at all.
 *
 * @return bool
 */
function sn_seo_is_mailpoet_request() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only presence check, nothing is processed.
	if ( isset( $_GET['mailpoet_page'] ) ) {
		return true;
	}
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
 * Send it. template_redirect, where is_feed() is already answered (it is not
 * yet at send_headers) and nothing has been printed; priority 1 so it goes out
 * before the feed conditional-GET handler can 304 and exit at priority 10.
 *
 * @return void
 */
function sn_seo_send_robots_header() {
	$line = sn_seo_robots_header();
	if ( '' !== $line && ! headers_sent() ) {
		header( $line );
	}
}
add_action( 'template_redirect', 'sn_seo_send_robots_header', 1 );
