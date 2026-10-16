<?php
/**
 * Signal & Noise Tools: seo-schema.php, the CollectionPage, tag CollectionPage
 * and BreadcrumbList nodes, and the wp_head emitter.
 *
 * Split from inc/seo-schema.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the CollectionPage schema for /notes archive views.
 * Returns null if not on a CollectionPage-appropriate view.
 *
 * Added in v2.0.0 (Phase 13 TSF cutover).
 */
function sn_schema_collection_page() {
	if ( function_exists( 'sn_seo_is_tag_view' ) && sn_seo_is_tag_view() ) {
		return sn_schema_tag_collection_page();
	}
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
		$permalink = get_permalink( $recent_post );
		// v6.24.0: each entry is an Article node carrying the SAME @id the
		// single-note page mints (`<permalink>#article`), so Google reconciles
		// the list contents with the full Article entities across pages instead
		// of reading a bare URL list. headline+datePublished keep the inline
		// node useful without duplicating the full single-page payload.
		$elements[] = array(
			'@type'    => 'ListItem',
			'position' => $position,
			'item'     => array(
				'@type'         => 'Article',
				'@id'           => $permalink . '#article',
				'url'           => $permalink,
				'headline'      => wp_strip_all_tags( get_the_title( $recent_post ) ),
				'datePublished' => get_post_time( 'c', true, $recent_post ),
			),
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
 * The CollectionPage for a tag view (/notes/tags/ or /tag/<slug>/), at the
 * view's canonical, named by the page's own title (2026.10 live audit, P2-5).
 *
 * @return array|null
 */
function sn_schema_tag_collection_page() {
	list( , $description, $url ) = sn_seo_meta_for_current_view();
	if ( '' === (string) $url ) {
		return null;
	}
	$schema = array(
		'@type'      => 'CollectionPage',
		'@id'        => $url,
		'url'        => $url,
		'name'       => function_exists( 'wp_get_document_title' ) ? (string) wp_get_document_title() : '',
		'inLanguage' => str_replace( '_', '-', sn_setting( 'identity.locale', 'en_US' ) ),
		'isPartOf'   => array( '@id' => home_url( '/' ) . '#/schema/WebSite' ),
		'breadcrumb' => array( '@id' => $url . '#breadcrumb' ),
	);
	if ( '' !== (string) $description ) {
		$schema['description'] = (string) $description;
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
	} elseif ( function_exists( 'sn_seo_is_tag_view' ) && sn_seo_is_tag_view() ) {
		list( , , $base_id ) = sn_seo_meta_for_current_view();
		if ( '' === (string) $base_id ) {
			return null;
		}
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position++,
			'item'     => home_url( '/notes/' ),
			'name'     => sn_setting( 'seo_copy.notes_title', 'Notes' ),
		);
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position,
			'item'     => $base_id,
			// The raw term name: single_term_title() texturizes (& to &#038;).
			'name'     => ( is_tag() && isset( get_queried_object()->name ) ) ? (string) get_queried_object()->name : 'Tags',
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
	// Front page, /notes, any singular, and the tag views (sn_seo_head_view_wanted()).
	if ( ! sn_seo_head_view_wanted() ) {
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

		$service = sn_schema_professional_service();
		if ( $service ) {
			$graph[] = $service;
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

	// JSON_HEX_TAG escapes < and > to < / > so a literal </script> in
	// any string field (e.g. an admin-set identity field — term names are already
	// core-sanitized) can never break out of the <script> block. Behaviorally
	// transparent to JSON-LD consumers; matches command-palette.php's encoder.
	echo '<script type="application/ld+json">' . wp_json_encode( $payload, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}, 5 );
