<?php
/**
 * Signal & Noise Tools: SEO, delivery (the singular Last-Modified and 304, the feed ETag and conditional GET).
 *
 * Split from inc/seo.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Last-Modified header + If-Modified-Since 304 handling on singulars.
 *
 * TSF emits Last-Modified itself when active; gate keeps us dormant
 * until TSF deactivates. Returns 304 Not Modified when the request's
 * If-Modified-Since timestamp is at-or-after the post's modified time
 * — saves crawl budget for Google and friends.
 *
 * Hooked at template_redirect (after the query is set, before output
 * starts) so we can still send headers + exit cleanly.
 *
 * Added in v2.0.0 (Phase 13 TSF cutover).
 */
/**
 * Effective Last-Modified timestamp for a singular's 304 validator.
 *
 * post_modified only advances when wp_update_post() touches the post row.
 * The update-post-surfaces write door (meta_description / og_card_title /
 * seo_title / focus_keyword) writes post meta directly and never calls
 * wp_update_post(), so it stamps '_sn_head_touched' on every write; take
 * the max of the two so a head-only edit still busts a stale 304 (#1224).
 *
 * Pure function (CLI-testable).
 *
 * @param WP_Post|object $post
 * @return int GMT unix timestamp, or 0 if neither value is known.
 */
function sn_seo_singular_last_modified_gmt( $post ) {
	$modified_gmt = (int) get_post_modified_time( 'U', true, $post );
	$head_touched = (int) get_post_meta( $post->ID, '_sn_head_touched', true );
	return max( $modified_gmt, $head_touched );
}

/**
 * When the rendered markup last changed for reasons a post row cannot show: a
 * theme or plugin update, a Styles save, a manual purge. Each runs the purge
 * chain, which records the time BEFORE it purges (sn_seo_record_render_change()
 * in inc/seo-validators.php). The theme's sn_last_purge_report is stamped at
 * the END of the chain, after the re-warm, so it only answers until the first
 * recorded flush. 0 if unknown.
 *
 * @return int GMT unix timestamp.
 */
function sn_seo_render_changed_gmt() {
	$recorded = (int) get_option( SN_SEO_RENDER_CHANGED_OPT, 0 );
	if ( $recorded > 0 ) {
		return $recorded;
	}
	$report = get_option( 'sn_last_purge_report', array() );
	return is_array( $report ) ? (int) ( $report['time'] ?? 0 ) : 0;
}

/**
 * Whether a conditional request may be answered 304. PURE.
 *
 * 20.9.0: the content date alone is not enough. The edge revalidates a cached
 * page with the Last-Modified it stored, which is the post's own date; a code
 * update does not move that date, so the old test answered 304 and the edge
 * kept serving the old markup, renewed, for as long as the post went
 * unedited. A validator older than the last render change gets the page.
 *
 * @param int $client_since   The request's If-Modified-Since, as a timestamp.
 * @param int $modified_gmt   The content's effective modified time.
 * @param int $render_changed sn_seo_render_changed_gmt().
 * @return bool
 */
function sn_seo_singular_not_modified( $client_since, $modified_gmt, $render_changed ) {
	$client_since = (int) $client_since;
	return $client_since > 0 && $client_since >= (int) $modified_gmt && $client_since >= (int) $render_changed;
}

add_action( 'template_redirect', function() {
	if ( function_exists( 'the_seo_framework' ) ) {
		return;
	}

	if ( ! is_singular() ) {
		return;
	}

	$post = get_queried_object();
	if ( ! $post ) {
		return;
	}

	// The later of the content date and the last render change, or no
	// validator at all on a computed page (2026.10 live audit, P1-1).
	$modified_gmt = sn_seo_singular_validator_gmt( sn_seo_singular_last_modified_gmt( $post ), sn_seo_render_changed_gmt(), sn_seo_is_computed_content( (string) $post->post_content ) );
	if ( ! $modified_gmt ) {
		return;
	}

	$modified_http = gmdate( 'D, d M Y H:i:s', $modified_gmt ) . ' GMT';
	header( 'Last-Modified: ' . $modified_http );

	if ( ! empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
		$client_since = strtotime( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) );
		if ( sn_seo_singular_not_modified( (int) $client_since, $modified_gmt, sn_seo_render_changed_gmt() ) ) {
			// SERVER_PROTOCOL is normally set by the front-end web server,
			// but allowlist defensively — a manipulated value here would
			// be the protocol portion of an HTTP response status line, so
			// CRLF injection could enable response splitting. The status
			// code arg to header() handles the actual status; protocol
			// is just the prefix string.
			$raw_protocol = isset( $_SERVER['SERVER_PROTOCOL'] ) ? wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) : '';
			$protocol     = in_array( $raw_protocol, array( 'HTTP/1.0', 'HTTP/1.1', 'HTTP/2', 'HTTP/2.0', 'HTTP/3' ), true )
				? $raw_protocol
				: 'HTTP/1.1';
			header( $protocol . ' 304 Not Modified', true, 304 );
			exit;
		}
	}
}, 10 );

/**
 * Compute the ETag for an RSS feed request, unique per feed.
 *
 * Pure function (CLI-testable). The ETag must vary per FEED, not just per build
 * timestamp: two distinct feeds (e.g. /feed/ and /notes/feed/) can share a
 * build timestamp, and an ETag built from the timestamp alone would collide —
 * a reader caching one feed could get a 304 false-positive against the other.
 *
 * We fold a stable feed identity into the hash:
 *   - WP_Term       (taxonomy/term archive feed) → "{taxonomy}:{term_id}"
 *   - WP_Post_Type  (post-type archive feed)      → "{post_type}:{name}"
 *   - anything else (main /feed/)                  → "" (empty identity)
 *
 * Inputs are ONLY the build timestamp + feed identity — nothing
 * request-varying — so the ETag is STABLE for a given feed across requests
 * (a strong validator the client can match on If-None-Match).
 *
 * @since 4.8.1
 * @param string $last_modified_http RFC-1123 feed build date (the Last-Modified value).
 * @param mixed  $queried_object     get_queried_object() for the request (WP_Term|WP_Post_Type|null).
 * @return string Quoted strong ETag.
 */
function sn_seo_feed_etag( $last_modified_http, $queried_object ) {
	$feed_id = '';
	if ( $queried_object instanceof WP_Term ) {
		$feed_id = $queried_object->taxonomy . ':' . $queried_object->term_id;
	} elseif ( $queried_object instanceof WP_Post_Type ) {
		$feed_id = 'post_type:' . $queried_object->name;
	}
	return '"' . md5( $last_modified_http . '|' . $feed_id ) . '"';
}

/**
 * Decide the conditional-GET response for an RSS feed request.
 *
 * Pure function (no header/exit side effects) so the decision logic is
 * CLI-testable. Returns:
 *   - null                        when there's no usable validator ($modified_gmt 0)
 *   - array( 'status' => 304 )    when the client's validator is still fresh
 *   - array( 'status' => 200,
 *            'headers' => [...] ) otherwise (Last-Modified + ETag to emit)
 *
 * ETag takes precedence over If-Modified-Since (a strong validator match is
 * definitive). IMS uses >= so an exactly-equal timestamp is treated as fresh.
 *
 * @since 4.8.1
 * @param int    $modified_gmt      Feed build timestamp (Unix, GMT). 0 = unknown.
 * @param string $if_modified_since Raw HTTP_IF_MODIFIED_SINCE value ('' if none).
 * @param string $if_none_match     Raw HTTP_IF_NONE_MATCH value ('' if none).
 * @param string $etag              The computed ETag (quoted) for this feed.
 * @return array|null
 */
function sn_seo_feed_conditional_response( $modified_gmt, $if_modified_since, $if_none_match, $etag ) {
	if ( ! $modified_gmt ) {
		return null;
	}
	if ( $if_none_match && trim( $if_none_match ) === $etag ) {
		return array( 'status' => 304 );
	}
	if ( $if_modified_since && strtotime( $if_modified_since ) >= $modified_gmt ) {
		return array( 'status' => 304 );
	}
	$http = gmdate( 'D, d M Y H:i:s', $modified_gmt ) . ' GMT';
	return array(
		'status'  => 200,
		'headers' => array(
			'Last-Modified' => $http,
			'ETag'          => $etag,
		),
	);
}

/**
 * Conditional-GET (Last-Modified + ETag + 304) for RSS feeds.
 *
 * Mirrors the singular handler above but for feed requests, so well-behaved
 * feed readers (and Google's feed crawler) can skip re-downloading an
 * unchanged feed — saving bandwidth + crawl budget.
 *
 * Hooked at priority 10 so it runs AFTER sn_rss_tracker_capture (priority 1):
 * the tracker must record the hit before we short-circuit with a 304/exit.
 * Gated dormant while TSF is active (it owns feed headers then). Uses
 * get_feed_build_date() so category feeds like /notes/feed/ get an accurate
 * per-feed timestamp.
 *
 * Added in v4.8.1.
 */
add_action( 'template_redirect', function() {
	if ( function_exists( 'the_seo_framework' ) ) {
		return;
	}
	if ( ! is_feed() ) {
		return;
	}

	$ts = (int) get_feed_build_date( 'U' );
	if ( ! $ts ) {
		return;
	}

	$last_modified_http = gmdate( 'D, d M Y H:i:s', $ts ) . ' GMT';
	// v4.8.1: fold feed identity into the ETag so two feeds sharing a build
	// timestamp (e.g. /feed/ and /notes/feed/) don't collide on the same
	// validator. See sn_seo_feed_etag().
	$etag               = sn_seo_feed_etag( $last_modified_http, get_queried_object() );

	$ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : '';
	$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) : '';

	$response = sn_seo_feed_conditional_response( $ts, (string) $ims, (string) $inm, $etag );
	if ( null === $response ) {
		return;
	}

	if ( 304 === $response['status'] ) {
		// SERVER_PROTOCOL allowlist — same CRLF-injection defense as the
		// singular handler above (a manipulated value would prefix the
		// status line, enabling response splitting).
		$raw_protocol = isset( $_SERVER['SERVER_PROTOCOL'] ) ? wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) : '';
		$protocol     = in_array( $raw_protocol, array( 'HTTP/1.0', 'HTTP/1.1', 'HTTP/2', 'HTTP/2.0', 'HTTP/3' ), true )
			? $raw_protocol
			: 'HTTP/1.1';
		header( $protocol . ' 304 Not Modified', true, 304 );
		exit;
	}

	foreach ( $response['headers'] as $name => $value ) {
		header( $name . ': ' . $value );
	}
}, 10 );
