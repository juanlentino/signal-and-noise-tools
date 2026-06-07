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
 * SearchAction: WebSite emits a SearchAction targeting the on-site search at
 * `/notes/?s=` (theme v9.8.0 Notes-scoped search) so Google can surface a
 * sitelinks search box. (Pre-v4.8.1 this was skipped — there was no search route.)
 *
 * Skipped vs The SEO Framework's emission:
 *   - WebPage on non-post singulars — marginal value, omit.
 *
 * NOTE: BreadcrumbList JSON-LD IS emitted from here (sn_schema_breadcrumb_list
 * below). The earlier v2.0.0 docblock claimed WP 7.0's native Breadcrumbs
 * block would emit its own structured data so we could drop this — that
 * claim was false. Verified 2026-05-20 against Gutenberg trunk: the native
 * core/breadcrumbs block emits visual <nav><ol> HTML only, no JSON-LD. So
 * this function remains load-bearing for SERP breadcrumb rich results.
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
	return (array) sn_setting( 'social.same_as', array() );
}

/**
 * Build the Person schema (author + publisher).
 */
function sn_schema_person() {
	$home    = home_url( '/' );
	$same_as = (array) apply_filters( 'sn_schema_same_as', sn_schema_default_same_as() );
	$name    = sn_setting( 'identity.person_name', get_bloginfo( 'name' ) );

	$person = array(
		'@type'      => 'Person',
		'@id'        => $home . '#/schema/Person',
		'name'       => $name,
		'url'        => $home,
		'sameAs'     => array_values( $same_as ),
		'jobTitle'   => sn_setting( 'identity.job_title', 'Music Producer' ),
		'knowsAbout' => (array) sn_setting(
			'identity.knows_about',
			array(
				'Music Production',
				'Audio Engineering',
				'Provenance',
				'Music Industry',
			)
		),
	);

	// v4.8.1: author image as an ImageObject. Read the configured OG image
	// DIRECTLY (no sn_og_image_url filter) — Person is the cross-URL-stable
	// author+publisher entity (@id-referenced by WebSite.publisher and
	// Article.author/publisher), so its image must NOT vary per article. The
	// sn_og_image_url filter is per-post by design (og-card-generator.php
	// returns the article's featured image/card on singular views); applying it
	// here would leak the current post's image onto the stable Person entity.
	// Article.image elsewhere KEEPS that per-post filter — only Person bypasses
	// it. Fall back to the site icon; the fixed @id doubles as the publisher
	// logo reference for any future @id consumer.
	$img = (string) sn_setting( 'og.default_image_url', '' );
	if ( '' === $img && function_exists( 'get_site_icon_url' ) ) {
		$img = (string) get_site_icon_url( 512 );
	}
	if ( '' !== $img ) {
		$person['image'] = array(
			'@type' => 'ImageObject',
			'@id'   => $home . '#/schema/PersonImage',
			'url'   => $img,
		);
	}

	return $person;
}

/**
 * Build the WebSite schema.
 */
function sn_schema_website() {
	$home   = home_url( '/' );
	$locale = sn_setting( 'identity.locale', 'en_US' );

	$schema = array(
		'@type'       => 'WebSite',
		'@id'         => $home . '#/schema/WebSite',
		'url'         => $home,
		'name'        => sn_setting( 'identity.site_name', get_bloginfo( 'name' ) ),
		'description' => sn_setting( 'identity.site_description', get_bloginfo( 'description' ) ),
		'inLanguage'  => str_replace( '_', '-', $locale ),
		'publisher'   => array(
			'@id' => $home . '#/schema/Person',
		),
	);

	// v4.8.1: on-site search at /notes/?s= (theme v9.8.0 Notes-scoped search).
	// The SearchAction lets Google surface a sitelinks search box for the site.
	$schema['potentialAction'] = array(
		'@type'       => 'SearchAction',
		'target'      => array(
			'@type'       => 'EntryPoint',
			'urlTemplate' => home_url( '/notes/' ) . '?s={search_term_string}',
		),
		'query-input' => 'required name=search_term_string',
	);

	return $schema;
}

/**
 * Resolve the Article schema description with the v1.10.0 fallback
 * chain — per-post _sn_meta_description override wins over excerpt.
 *
 * Separate helper because seo-schema.php reads $post->post_excerpt
 * directly (independent of sn_seo_meta_for_current_view() in seo.php).
 * Both callsites need the same fallback logic.
 *
 * @param WP_Post $post Post being rendered.
 * @return string Description string (may be empty).
 */
function sn_schema_article_description( $post ) {
	$override = function_exists( 'sn_post_settings_get_description' )
		? sn_post_settings_get_description( $post->ID )
		: '';
	if ( '' !== $override ) {
		return $override;
	}
	if ( ! empty( $post->post_excerpt ) ) {
		return wp_strip_all_tags( $post->post_excerpt );
	}
	return '';
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

	$permalink   = get_permalink( $post );
	$title       = wp_strip_all_tags( get_the_title( $post ) );
	$description = sn_schema_article_description( $post );

	// Seed the filter with the same default OG image URL that seo.php uses,
	// so any filter listener that augments-rather-than-replaces produces
	// consistent behavior between the JSON-LD Article and the OG meta tag.
	$default_og = sn_setting( 'og.default_image_url', '' );
	$image_url  = (string) apply_filters( 'sn_og_image_url', $default_og );
	$image_dim  = (array) apply_filters( 'sn_og_image_dimensions', array( 1200, 630 ), $image_url );

	$article = array(
		'@type'            => 'Article',
		'@id'              => $permalink . '#article',
		'headline'         => $title,
		'datePublished'    => get_post_time( 'c', true, $post ),
		'dateModified'     => get_post_modified_time( 'c', true, $post ),
		'mainEntityOfPage' => $permalink,
		// v4.4.3 (Bug-E1): read from Identity locale setting (same as WebSite
		// schema on line 83 and WebPage schema on line 194). Hardcoded 'en-US'
		// would diverge if the locale setting is ever changed.
		'inLanguage'       => str_replace( '_', '-', sn_setting( 'identity.locale', 'en_US' ) ),
		'author'           => array(
			'@id' => home_url( '/' ) . '#/schema/Person',
		),
		'publisher'        => array(
			'@id' => home_url( '/' ) . '#/schema/Person',
		),
	);

	if ( '' !== $description ) {
		$article['description'] = $description;
	}

	if ( $image_url ) {
		$article['image'] = array(
			'@type'  => 'ImageObject',
			'url'    => $image_url,
			'width'  => (int) ( $image_dim[0] ?? 1200 ),
			'height' => (int) ( $image_dim[1] ?? 630 ),
		);
	}

	// v4.8.1: structured-data enrichment. Each addition is guarded so a
	// missing source leaves the key absent rather than emitting an empty value.

	// wordCount — strip shortcodes + tags before counting words.
	$word_count = str_word_count( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
	if ( $word_count > 0 ) {
		$article['wordCount'] = $word_count;
	}

	// timeRequired — ISO-8601 duration from the cached reading time. Reuse the
	// theme/plugin reading-time helper; do NOT recompute.
	if ( function_exists( 'sn_get_reading_time' ) ) {
		$article['timeRequired'] = 'PT' . (int) sn_get_reading_time( $post ) . 'M';
	}

	// keywords — comma-joined post_tag names.
	$tags = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );
	if ( is_array( $tags ) && ! empty( $tags ) && ! is_wp_error( $tags ) ) {
		$article['keywords'] = implode( ', ', $tags );
	}

	// articleSection — first category name.
	$cats = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'names' ) );
	if ( is_array( $cats ) && ! empty( $cats ) && ! is_wp_error( $cats ) ) {
		$article['articleSection'] = (string) $cats[0];
	}

	return $article;
}

/**
 * Build the WebPage schema for the current singular view.
 * Returns null if not on a singular (caller should use CollectionPage or skip).
 *
 * Added in v2.0.0 (Phase 13 TSF cutover).
 */
function sn_schema_webpage() {
	if ( ! is_singular() ) {
		return null;
	}
	$post = get_queried_object();
	if ( ! $post ) {
		return null;
	}

	$permalink   = get_permalink( $post );
	$name        = wp_strip_all_tags( get_the_title( $post ) );
	$description = sn_schema_article_description( $post );

	$webpage = array(
		'@type'      => 'WebPage',
		'@id'        => $permalink,
		'url'        => $permalink,
		'name'       => $name,
		'inLanguage' => str_replace( '_', '-', sn_setting( 'identity.locale', 'en_US' ) ),
		'isPartOf'   => array(
			'@id' => home_url( '/' ) . '#/schema/WebSite',
		),
		'breadcrumb' => array(
			'@id' => $permalink . '#breadcrumb',
		),
	);

	if ( '' !== $description ) {
		$webpage['description'] = $description;
	}

	return $webpage;
}

/**
 * Build the CollectionPage schema for /notes archive views.
 * Returns null if not on a CollectionPage-appropriate view.
 *
 * Added in v2.0.0 (Phase 13 TSF cutover).
 */
function sn_schema_collection_page() {
	if ( ! is_home() && ! is_page( 'notes' ) ) {
		return null;
	}

	$url  = home_url( '/notes/' );
	$name = sn_setting( 'seo_copy.notes_title', 'Notes' );

	$schema = array(
		'@type'      => 'CollectionPage',
		'@id'        => $url,
		'url'        => $url,
		'name'       => $name,
		'inLanguage' => str_replace( '_', '-', sn_setting( 'identity.locale', 'en_US' ) ),
		'isPartOf'   => array(
			'@id' => home_url( '/' ) . '#/schema/WebSite',
		),
		'breadcrumb' => array(
			'@id' => $url . '#breadcrumb',
		),
	);

	// v4.8.1: enumerate the most recent published Notes as an ItemList so the
	// CollectionPage carries its contents (better SERP understanding of /notes).
	$recent = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'suppress_filters' => false,
		)
	);

	$elements = array();
	$position = 1;
	foreach ( (array) $recent as $recent_post ) {
		$elements[] = array(
			'@type'    => 'ListItem',
			'position' => $position,
			'url'      => get_permalink( $recent_post ),
			'name'     => wp_strip_all_tags( get_the_title( $recent_post ) ),
		);
		++$position;
	}

	if ( ! empty( $elements ) ) {
		$schema['mainEntity'] = array(
			'@type'           => 'ItemList',
			'itemListElement' => $elements,
		);
	}

	return $schema;
}

/**
 * Build the BreadcrumbList schema for the current view.
 * Returns null on the front page (no useful trail).
 *
 * Trail order: Home → (parent page chain if any) → current page.
 * For singular posts: Home → Post Title (no post-type archive in the trail).
 *
 * Added in v2.0.0 (Phase 13 TSF cutover). The original docblock claimed
 * this would be removed once WP 7.0's native Breadcrumbs block landed —
 * inspection of Gutenberg trunk on 2026-05-20 showed the native block
 * emits visual <nav><ol> only, no JSON-LD. So this stays.
 */
function sn_schema_breadcrumb_list() {
	if ( is_front_page() ) {
		return null;
	}

	$home  = home_url( '/' );
	$items = array(
		array(
			'@type'    => 'ListItem',
			'position' => 1,
			'item'     => $home,
			'name'     => sn_setting( 'identity.site_name', get_bloginfo( 'name' ) ),
		),
	);

	$position = 2;
	$base_id  = '';

	if ( is_singular() ) {
		$post    = get_queried_object();
		$base_id = $post ? get_permalink( $post ) : '';

		if ( $post && $post->post_parent ) {
			$ancestors = array_reverse( get_post_ancestors( $post ) );
			foreach ( $ancestors as $ancestor_id ) {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $position++,
					'item'     => get_permalink( $ancestor_id ),
					'name'     => wp_strip_all_tags( get_the_title( $ancestor_id ) ),
				);
			}
		}

		if ( $post ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				// `item` is required on every ListItem per Google Rich Results
				// spec — without it the breadcrumb is suppressed in SERPs.
				'item'     => get_permalink( $post ),
				'name'     => wp_strip_all_tags( get_the_title( $post ) ),
			);
		}
	} elseif ( is_home() || is_page( 'notes' ) ) {
		$base_id = home_url( '/notes/' );
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position,
			'item'     => $base_id,
			'name'     => sn_setting( 'seo_copy.notes_title', 'Notes' ),
		);
	} else {
		return null;
	}

	return array(
		'@type'           => 'BreadcrumbList',
		'@id'             => $base_id . '#breadcrumb',
		'itemListElement' => $items,
	);
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

	// v2.0.0 (Phase 13 TSF cutover): when TSF is inactive, also emit
	// WebPage/CollectionPage and BreadcrumbList. These replace TSF's
	// equivalent schema emission. Gate keeps them dormant while TSF
	// is active to avoid duplicate JSON-LD entries on rollback.
	if ( ! function_exists( 'the_seo_framework' ) ) {
		$webpage = sn_schema_webpage();
		if ( $webpage ) {
			$graph[] = $webpage;
		}

		$collection = sn_schema_collection_page();
		if ( $collection ) {
			$graph[] = $collection;
		}

		$breadcrumb = sn_schema_breadcrumb_list();
		if ( $breadcrumb ) {
			$graph[] = $breadcrumb;
		}
	}

	$payload = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}, 5 );
