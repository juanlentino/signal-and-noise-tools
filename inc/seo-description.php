<?php
/**
 * Signal & Noise Tools — shared singular meta-description resolver.
 *
 * Extracted from inc/seo.php in v9.3.0 so the <head> emitter and the Analytics
 * Intelligence "descriptionless route" recommendation resolve a page's meta
 * description through ONE precedence, with no drift:
 *   per-post override -> post excerpt -> sn_seo_singular_description filter
 * (the companion theme supplies copy for template-driven Pages that carry no
 * excerpt: /about, /contact, /colophon, /music, /services).
 *
 * @package SignalNoiseTools
 * @since 9.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the meta description for a singular post/page. Returns '' when nothing
 * resolves — i.e. the route ships descriptionless (the signal the recommendation
 * flags).
 *
 * @param object|null $post A post/page object with ID + post_excerpt.
 * @return string
 */
function sn_seo_resolve_singular_description( $post ) {
	if ( ! is_object( $post ) ) {
		return '';
	}
	$override = function_exists( 'sn_post_settings_get_description' )
		? (string) sn_post_settings_get_description( $post->ID )
		: '';
	if ( '' !== $override ) {
		return $override;
	}
	if ( ! empty( $post->post_excerpt ) ) {
		return wp_strip_all_tags( $post->post_excerpt );
	}
	return (string) apply_filters( 'sn_seo_singular_description', '', $post );
}
