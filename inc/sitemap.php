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
 * Since 2026-10-22 both rules (and the plugin's own noindex routes, /verify)
 * come from sn_post_is_indexable() (inc/indexable.php), applied as
 * post__not_in on the `wp_sitemaps_posts_query_args` filter.
 *
 * The SEO Framework (TSF) has been inactive on the live site since the
 * v2.0.0 (Phase 13) cutover, so core's sitemap is the one served and these
 * filters are live. While TSF was active it served its own sitemap at
 * `/sitemap.xml` and these filters lay dormant. They are registered
 * unconditionally: that costs nothing when the hook never fires and needs
 * no coordination with TSF.
 *
 * Added in v1.11.0 (2026-05-16).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/indexable.php';

/**
 * Published ids of one type the indexable predicate refuses. 2026-10-22: the
 * sitemap used its own meta_query (noindex, canonical) and so listed /verify,
 * which the virtual route serves noindex. Now it asks sn_post_is_indexable(),
 * the same question the URL Inspection targets and IndexNow ask.
 *
 * @param string $post_type 'post' or 'page'.
 * @return int[]
 */
function sn_sitemap_excluded_ids( $post_type ) {
	if ( ! function_exists( 'get_posts' ) ) {
		return array();
	}
	$ids = (array) get_posts( array( 'post_type' => (string) $post_type, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
	return array_values( array_filter( array_map( 'intval', $ids ), static function ( $id ) {
		return ! sn_post_is_indexable( $id );
	} ) );
}

add_filter( 'wp_sitemaps_posts_query_args', function( $args, $post_type ) {
	if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
		return $args;
	}
	$excluded = sn_sitemap_excluded_ids( $post_type );
	if ( array() !== $excluded ) {
		$args['post__not_in'] = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $args['post__not_in'] ?? array() ) ), $excluded ) ) );
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
// MailPoet registers `mailpoet_page` as a public post type (its subscription
// and unsubscribe page), so core listed wp-sitemap-posts-mailpoet_page-1.xml
// in the index: a utility page no search engine should be handed.
add_filter(
	'wp_sitemaps_post_types',
	function ( $post_types ) {
		unset( $post_types['mailpoet_page'] );
		return $post_types;
	}
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
 * Core's sitemap already carries a date: WP_Sitemaps_Posts::get_url_list()
 * sets `lastmod` on every post entry from post_modified_gmt, formatted with
 * wp_date() in the site timezone, before the wp_sitemaps_posts_entry filter
 * runs. Google uses <lastmod> to decide what to recrawl when it is accurate
 * and consistent, and the first URL Inspection reading (v13.63.0) showed 13
 * of 37 notes not indexed with the sitemap fetched 185 times in a month:
 * Google could find them and declined. This function formats the date the
 * filter below writes over core's: GMT, W3C format, +00:00, so the sitemap
 * and the JSON-LD agree. It returns null for a value that is not a real time,
 * and the filter then removes core's lastmod, so the entry carries no date
 * rather than one that disagrees with the schema.
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
		return null; // Not a real timestamp: no guess. The filter then emits no lastmod.
	}
	return $dt->format( 'Y-m-d\TH:i:s+00:00' );
}

/**
 * The lastmod for one post: the content-change clock the JSON-LD dateModified
 * reads (sn_schema_date_modified(): the provenance commit, else
 * post_modified), so the sitemap and the schema name one date. 2026-10-22:
 * the sitemap read post_modified_gmt alone and said 2026-09-24 where the
 * Article said 2026-09-14. Null when neither is a real time; the filter
 * below then removes the lastmod core put in the entry.
 *
 * @param object $post WP_Post-like.
 * @return string|null
 */
function sn_sitemap_lastmod_for( $post ) {
	if ( ! is_object( $post ) ) {
		return null;
	}
	$c = function_exists( 'sn_schema_date_modified' ) ? sn_schema_date_modified( $post ) : false;
	$t = is_string( $c ) && '' !== $c ? strtotime( $c ) : false;
	if ( false !== $t && $t > 0 ) {
		return gmdate( 'Y-m-d\TH:i:s+00:00', $t );
	}
	return sn_sitemap_lastmod( (string) ( $post->post_modified_gmt ?? '' ) );
}

add_filter(
	'wp_sitemaps_posts_entry',
	function ( $entry, $post ) {
		$lastmod = sn_sitemap_lastmod_for( $post );
		if ( ! is_array( $entry ) ) {
			return $entry;
		}
		if ( null !== $lastmod ) {
			$entry['lastmod'] = $lastmod;
		} else {
			unset( $entry['lastmod'] ); // No real date: emit none, not core's.
		}
		return $entry;
	},
	10,
	2
);
