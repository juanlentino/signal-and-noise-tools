<?php
/**
 * Signal & Noise Tools — Sitemap refinements.
 *
 * Filters WP core's built-in sitemap (`/wp-sitemap.xml`, available
 * since WP 5.5) to honor the v1.10.0+ per-post SEO overrides:
 *
 *   _sn_noindex = '1'       → post excluded from sitemap entirely
 *   _sn_canonical_url != '' → post excluded (canonical lives elsewhere,
 *                             so this post shouldn't appear as a
 *                             discoverable URL in OUR sitemap)
 *
 * Both rules implemented via meta_query on the `wp_sitemaps_posts_query_args`
 * filter — applies before WP core runs the post-listing query for each
 * sitemap chunk.
 *
 * IMPORTANT: while The SEO Framework (TSF) is active, this filter is
 * effectively dormant — TSF deregisters WP core's sitemap and serves
 * its own at `/sitemap.xml`. Our filter only takes effect once TSF
 * is deactivated (Phase 13 cutover, queued for v2.0.0). The filter is
 * registered unconditionally because doing so is cheap (~no overhead
 * when the hook never fires) and avoids coordination logic with TSF.
 *
 * Added in v1.11.0 (2026-05-16).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exclude per-post-overridden posts from WP core's sitemap output.
 *
 * @param array  $args      WP_Query args for the sitemap post listing.
 * @param string $post_type Post type slug for this sitemap chunk.
 * @return array Modified args with an additional meta_query clause.
 */
add_filter( 'wp_sitemaps_posts_query_args', function( $args, $post_type ) {
	// Only restrict the post types we explicitly support overrides on
	// (matches inc/post-settings.php's SN_POST_SETTINGS_POST_TYPES).
	if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
		return $args;
	}

	$existing_meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] )
		? $args['meta_query']
		: array();

	// Combine our exclusion with any pre-existing meta_query via AND.
	$sn_clause = array(
		'relation' => 'AND',
		// Exclude posts with _sn_noindex = '1' (meta exists OR is absent).
		array(
			'relation' => 'OR',
			array(
				'key'     => '_sn_noindex',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_sn_noindex',
				'value'   => '1',
				'compare' => '!=',
			),
		),
		// Exclude posts with a non-empty _sn_canonical_url (canonical
		// points elsewhere — this post shouldn't appear as the canonical
		// URL in OUR sitemap).
		array(
			'relation' => 'OR',
			array(
				'key'     => '_sn_canonical_url',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_sn_canonical_url',
				'value'   => '',
				'compare' => '=',
			),
		),
	);

	if ( ! empty( $existing_meta_query ) ) {
		$args['meta_query'] = array(
			'relation' => 'AND',
			$existing_meta_query,
			$sn_clause,
		);
	} else {
		$args['meta_query'] = $sn_clause;
	}

	return $args;
}, 10, 2 );

// v4.8.1: a single-author Notes site needs no author sitemap, and tag/category
// term-archives are thin/duplicate-y — drop them from the sitemap index.
add_filter(
	'wp_sitemaps_add_provider',
	function ( $provider, $name ) {
		return ( 'users' === $name ) ? false : $provider;
	},
	10,
	2
);
/**
 * 15.10.0: a tag archive with this many notes is a topical hub, not a thin
 * page. Every tag carries an owner-written description (the archive paints
 * it as the intro and the meta description), so a tag with three notes is a
 * real page about "music metadata" or "AI disclosure". Below the line it is
 * a list of one or two links and stays out of the sitemap, with noindex.
 */
const SN_SITEMAP_TAG_MIN_NOTES = 3;

/**
 * Is a tag a hub (in the sitemap, indexable) by its note count? PURE.
 *
 * @since 15.10.0
 * @param int $count Published notes carrying the tag.
 * @return bool
 */
function sn_sitemap_tag_is_hub( $count ) {
	return (int) $count >= SN_SITEMAP_TAG_MIN_NOTES;
}

/**
 * The term ids of tags BELOW the hub line, for the taxonomy provider's
 * `exclude`. Reads the live counts; the rule itself is sn_sitemap_tag_is_hub().
 *
 * @since 15.10.0
 * @return int[]
 */
function sn_sitemap_thin_tag_ids() {
	if ( ! function_exists( 'get_terms' ) ) {
		return array();
	}
	$terms = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false, 'fields' => 'all' ) );
	if ( ! is_array( $terms ) ) {
		return array();
	}
	$thin = array();
	foreach ( $terms as $term ) {
		if ( is_object( $term ) && ! sn_sitemap_tag_is_hub( (int) ( $term->count ?? 0 ) ) ) {
			$thin[] = (int) $term->term_id;
		}
	}
	return $thin;
}

add_filter(
	'wp_sitemaps_taxonomies',
	function ( $taxonomies ) {
		// 15.10.0: post_tag STAYS (the hubs); category goes (one category, a
		// duplicate of /notes/). Until now both were dropped, and Google's URL
		// Inspection read every tag archive as "URL is unknown to Google"
		// although each note links four of them: discovery needed the sitemap.
		unset( $taxonomies['category'] );
		return $taxonomies;
	}
);

add_filter(
	'wp_sitemaps_taxonomies_query_args',
	function ( $args, $taxonomy ) {
		if ( 'post_tag' !== $taxonomy ) {
			return $args;
		}
		$thin = sn_sitemap_thin_tag_ids();
		if ( array() !== $thin ) {
			$args['exclude'] = array_values( array_unique( array_merge( (array) ( $args['exclude'] ?? array() ), $thin ) ) );
		}
		return $args;
	},
	10,
	2
);

/**
 * v13.66.0: per-URL <lastmod>.
 *
 * WP core's sitemap lists URLs with no dates. Google uses <lastmod> to decide
 * what to recrawl — when it is accurate and consistent — and the first URL
 * Inspection reading (v13.63.0) showed 13 of 37 notes not indexed with the
 * sitemap fetched 185 times in a month: Google could find them and declined.
 * Without lastmod every note looks equally stale. This emits the post's
 * modified time, GMT, W3C format, and emits NOTHING for a post without a real
 * modified time — a fabricated date would be the inconsistency Google
 * documents as the reason to ignore the field.
 *
 * @param string $modified_gmt post_modified_gmt ('Y-m-d H:i:s', GMT).
 * @return string|null ISO 8601 with a +00:00 offset, or null.
 */
function sn_sitemap_lastmod( $modified_gmt ) {
	$modified_gmt = trim( (string) $modified_gmt );
	if ( '' === $modified_gmt || 0 === strpos( $modified_gmt, '0000-00-00' ) ) {
		return null;
	}
	$dt = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $modified_gmt, new DateTimeZone( 'UTC' ) );
	if ( false === $dt || $dt->format( 'Y-m-d H:i:s' ) !== $modified_gmt ) {
		return null; // Not a real timestamp: emit nothing rather than a guess.
	}
	return $dt->format( 'Y-m-d\TH:i:s+00:00' );
}

add_filter(
	'wp_sitemaps_posts_entry',
	function ( $entry, $post ) {
		$lastmod = sn_sitemap_lastmod( is_object( $post ) ? (string) ( $post->post_modified_gmt ?? '' ) : '' );
		if ( null !== $lastmod && is_array( $entry ) ) {
			$entry['lastmod'] = $lastmod;
		}
		return $entry;
	},
	10,
	2
);
