<?php
/**
 * Signal & Noise Tools — JSON-LD structured data (schema.org).
 *
 * Emits a single @graph JSON-LD script in <head> with three schemas:
 *
 *   - WebSite      — every page; publisher references the Person
 *   - Person       — every page; name + url + sameAs (social profiles)
 *   - Article      — singular posts; headline, dates, author, image,
 *                    mainEntityOfPage. (BlogPosting would also be fine
 *                    but Article is the broader supertype.)
 *
 * Skipped vs The SEO Framework's emission:
 *   - BreadcrumbList — WordPress 7.0 ships a native Breadcrumbs block,
 *     use that instead of duplicating in JSON-LD.
 *   - SearchAction — site has no /search/{term} route.
 *   - WebPage on non-post singulars — marginal value, omit.
 *
 * Social profile URLs (sameAs) are filterable via `sn_schema_same_as`
 * for future configurability.
 *
 * Added in v1.7.0 (Phase 11 absorption, 2026-05-16).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default social profile URLs (sameAs). Filterable.
 */
function sn_schema_default_same_as() {
	return array(
		'https://x.com/juan_lentino',
		'https://instagram.com/juan_lentino',
		'https://linkedin.com/in/juanlentino',
	);
}

/**
 * Build the Person schema (author + publisher).
 */
function sn_schema_person() {
	$home    = home_url( '/' );
	$same_as = (array) apply_filters( 'sn_schema_same_as', sn_schema_default_same_as() );

	return array(
		'@type'   => 'Person',
		'@id'     => $home . '#/schema/Person',
		'name'    => 'Juan Lentino',
		'url'     => $home,
		'sameAs'  => array_values( $same_as ),
	);
}

/**
 * Build the WebSite schema.
 */
function sn_schema_website() {
	$home = home_url( '/' );

	return array(
		'@type'       => 'WebSite',
		'@id'         => $home . '#/schema/WebSite',
		'url'         => $home,
		'name'        => 'Juan Lentino',
		'description' => 'Music Production & Creative Strategy',
		'inLanguage'  => 'en-US',
		'publisher'   => array(
			'@id' => $home . '#/schema/Person',
		),
	);
}

/**
 * Build the Article schema for the current singular post.
 * Returns null if not on a singular post.
 */
function sn_schema_article() {
	if ( ! is_singular( 'post' ) ) {
		return null;
	}
	$post = get_queried_object();
	if ( ! $post ) {
		return null;
	}

	$permalink = get_permalink( $post );
	$title     = wp_strip_all_tags( get_the_title( $post ) );
	$excerpt   = wp_strip_all_tags( (string) $post->post_excerpt );

	$image_url = (string) apply_filters( 'sn_og_image_url', '' );
	$image_dim = (array) apply_filters( 'sn_og_image_dimensions', array( 1200, 630 ), $image_url );

	$article = array(
		'@type'            => 'Article',
		'@id'              => $permalink . '#article',
		'headline'         => $title,
		'datePublished'    => get_post_time( 'c', true, $post ),
		'dateModified'     => get_post_modified_time( 'c', true, $post ),
		'mainEntityOfPage' => $permalink,
		'inLanguage'       => 'en-US',
		'author'           => array(
			'@id' => home_url( '/' ) . '#/schema/Person',
		),
		'publisher'        => array(
			'@id' => home_url( '/' ) . '#/schema/Person',
		),
	);

	if ( $excerpt ) {
		$article['description'] = $excerpt;
	}

	if ( $image_url ) {
		$article['image'] = array(
			'@type'  => 'ImageObject',
			'url'    => $image_url,
			'width'  => (int) ( $image_dim[0] ?? 1200 ),
			'height' => (int) ( $image_dim[1] ?? 630 ),
		);
	}

	return $article;
}

/**
 * Emit the @graph JSON-LD script in <head>.
 *
 * Single script tag carries all schemas as a connected graph
 * (preferred by Google's structured-data tooling over multiple
 * disjoint scripts).
 */
add_action( 'wp_head', function() {
	// Only emit on front page, /notes, /provenance, and any singular content.
	if ( ! is_front_page() && ! is_home() && ! is_singular() ) {
		return;
	}

	$graph = array(
		sn_schema_person(),
		sn_schema_website(),
	);

	$article = sn_schema_article();
	if ( $article ) {
		$graph[] = $article;
	}

	$payload = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}, 5 );
