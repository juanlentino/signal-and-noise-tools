<?php
/**
 * Signal & Noise Tools: SEO, delivery (the singular Last-Modified and 304).
 *
 * Split from inc/seo.php (house line cap, 2026-10-14). The file header there
 * describes the module. Loaded by require_once at the end of that file, in the
 * order the code always ran.
 *
 * A feed's conditional GET is core's: WP::send_headers() sends its
 * Last-Modified and ETag and answers its 304. Wave 2 (2026-10-24) deleted the
 * plugin's second feed validator; the last render change reaches core through
 * sn_seo_feed_lastpostmodified() (inc/seo-validators.php). Core has no
 * validator for a singular, so that one stays here.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Last-Modified header + If-Modified-Since 304 handling on singulars.
 *
 * Returns 304 Not Modified when the request's
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

