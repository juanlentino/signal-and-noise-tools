<?php
/**
 * Signal & Noise Tools: the dates the page and feed validators send.
 *
 * 2026.10 live audit. A validator that never moves keeps a reader on a stale
 * copy: Cloudflare answers the browser's If-Modified-Since itself, so a page
 * whose Last-Modified is the post's own date was renewed by 304s for weeks
 * after a deploy, and the feed's build date ignored a scheduled note going
 * live (publishing a scheduled post does not touch post_modified).
 *
 * Pure helpers here; the handlers in inc/seo.php call them.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcodes whose output is computed at render time (live counts, scores),
 * so the post's own dates say nothing about when the page last changed.
 *
 * @return string[]
 */
function sn_seo_computed_shortcodes() {
	$maturity = function_exists( 'sn_maturity_layout_shortcodes' ) ? sn_maturity_layout_shortcodes() : array();
	return array_merge( array( 'sn_public_stats', 'sn_maturity_roadmap' ), $maturity );
}

/**
 * Does this content render computed data? PURE apart from has_shortcode().
 *
 * @param string $content Post content.
 * @return bool
 */
function sn_seo_is_computed_content( $content ) {
	foreach ( sn_seo_computed_shortcodes() as $tag ) {
		if ( has_shortcode( (string) $content, $tag ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The Last-Modified a singular sends. PURE.
 *
 * The later of the content's date and the last render change (a deploy, a
 * Styles save, a purge: sn_seo_render_changed_gmt()), so a cache revalidating
 * with the stored date gets the new markup. A computed page sends none (0):
 * its numbers change with no post edit, so no date can vouch for a copy.
 *
 * @param int  $modified_gmt   sn_seo_singular_last_modified_gmt().
 * @param int  $render_changed sn_seo_render_changed_gmt().
 * @param bool $computed       sn_seo_is_computed_content().
 * @return int GMT timestamp, or 0 to send no validator.
 */
function sn_seo_singular_validator_gmt( $modified_gmt, $render_changed, $computed ) {
	if ( $computed || (int) $modified_gmt <= 0 ) {
		return 0;
	}
	return max( (int) $modified_gmt, (int) $render_changed );
}

/**
 * A feed's build time: the newest publish or edit among its items. PURE.
 *
 * Core's get_feed_build_date() reads post_modified_gmt only, and a scheduled
 * post that goes live keeps the modified time of its last edit (measured: a
 * note edited Oct 1 and published Oct 11 left the feed dated Oct 6).
 *
 * @param array $posts Feed items (objects with post_date_gmt, post_modified_gmt).
 * @return int GMT timestamp, 0 when no item carries a usable date.
 */
function sn_seo_feed_build_gmt( $posts ) {
	$max = 0;
	foreach ( (array) $posts as $post ) {
		foreach ( array( 'post_date_gmt', 'post_modified_gmt' ) as $field ) {
			$raw = is_object( $post ) && isset( $post->$field ) ? (string) $post->$field : '';
			if ( '' === $raw || 0 === strpos( $raw, '0000-00-00' ) ) {
				continue;
			}
			$ts = strtotime( $raw . ' UTC' );
			if ( false !== $ts && $ts > $max ) {
				$max = $ts;
			}
		}
	}
	return $max;
}

/**
 * get_feed_build_date filter: fold publish dates into the build date, so the
 * RSS lastBuildDate and the feed's Last-Modified and ETag (inc/seo.php) move
 * when a scheduled note goes live. Comment feeds keep core's value.
 *
 * @param string|int|false $value  Core's build date, in $format.
 * @param string           $format The requested date format.
 * @return string|int|false
 */
function sn_seo_feed_build_date_filter( $value, $format ) {
	global $wp_query;
	if ( empty( $wp_query ) || ! is_object( $wp_query ) || empty( $wp_query->posts ) ) {
		return $value;
	}
	if ( method_exists( $wp_query, 'is_comment_feed' ) && $wp_query->is_comment_feed() ) {
		return $value;
	}
	$ts = sn_seo_feed_build_gmt( $wp_query->posts );
	return $ts > 0 ? gmdate( (string) $format, $ts ) : $value;
}
add_filter( 'get_feed_build_date', 'sn_seo_feed_build_date_filter', 10, 2 );
