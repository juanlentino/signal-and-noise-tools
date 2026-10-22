<?php
/**
 * Signal & Noise Tools: seo-schema.php, the Article, WebPage and
 * ProfessionalService nodes.
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
 * Build the Article schema for the current singular post, or pillar page.
 * Returns null elsewhere.
 */
function sn_schema_article() {
	// 15.7.0: a pillar page (`_sn_pillar` meta) is an essay and gets an Article
	// too. The bridge's get-citation reads the page's own Article; without one
	// the site's most citable pages answered "not a note", and search engines
	// saw a plain WebPage for a 5,000-word essay.
	if ( ! is_singular( 'post' ) && ! sn_schema_is_pillar_page() ) {
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
		// 15.9.2: WHEN THE PROSE LAST CHANGED, not when the row was last saved.
		// A title-tag override on every note (2026-09-17) bumped post_modified
		// on all 43 and told Google every note changed that day. The provenance
		// commit is the honest clock (the stale-posts check's since 11.11.8);
		// post_modified only for a post with no commit.
		'dateModified'     => sn_schema_date_modified( $post ),
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

	// 15.9.2: what the headers already say, in the schema an answer engine
	// reads: the notes are free to read, and the machine license is the RSL
	// file the edge links as rel="license". Research register; a reader or a
	// model can cite without guessing at terms.
	$article['isAccessibleForFree'] = true;
	$article['license']             = home_url( '/license.xml' );

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

	// v11.7.0 (R5): the provenance pointer. A signed subject's Article node
	// carries its ledger uid as a standard PropertyValue identifier, so a
	// structured-data consumer can join the page to its record without
	// parsing the verification manifest. Guarded: unsigned posts (and any
	// load order without the pointers module) leave the key absent.
	if ( function_exists( 'sn_prov_machine_pointers_identifier' ) ) {
		$sn_prov_ident = sn_prov_machine_pointers_identifier( (int) $post->ID );
		if ( null !== $sn_prov_ident ) {
			$article['identifier'] = $sn_prov_ident;
		}
	}

	// 15.11.0: the DOI. A production DOI joins the identifier list as its own
	// PropertyValue (propertyID "DOI", the schema.org convention) and the
	// doi.org URL rides sameAs, so a citation manager, Scholar and the
	// bridge's get-citation all read one thing. A sandbox DOI never lands.
	$sn_doi = function_exists( 'sn_zenodo_doi_for' ) ? sn_zenodo_doi_for( (int) $post->ID ) : '';
	if ( '' !== $sn_doi ) {
		$sn_doi_ident = array( '@type' => 'PropertyValue', 'propertyID' => 'DOI', 'value' => $sn_doi );
		if ( isset( $article['identifier'] ) ) {
			$article['identifier'] = isset( $article['identifier']['@type'] ) ? array( $article['identifier'], $sn_doi_ident ) : array_merge( (array) $article['identifier'], array( $sn_doi_ident ) );
		} else {
			$article['identifier'] = $sn_doi_ident;
		}
		$article['sameAs'] = 'https://doi.org/' . $sn_doi;
	}

	// wordCount — strip shortcodes + tags before counting words.
	$word_count = snt_word_count( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) ); // v10.24.0: Unicode-safe — str_word_count published wrong schema.org numbers for digit-bearing prose.
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
	// v10.0.0: the virtual-route branch is retired with the sn_seo_route_meta
	// seam — every former virtual route is a real Page, so the singular branch
	// below builds its WebPage.

	if ( ! is_singular() ) {
		return null;
	}
	// v13.109.22 (#1224): /notes is both is_singular() (a real Page) and
	// is_page('notes') — sn_schema_collection_page() also builds for it and
	// mints '@id' => the /notes/ URL. Without this gate the two builders
	// emit two graph nodes sharing one @id. CollectionPage owns that @id.
	if ( is_page( 'notes' ) ) {
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
		'inLanguage' => function_exists( 'sn_lang_schema_language' ) ? sn_lang_schema_language() : str_replace( '_', '-', sn_setting( 'identity.locale', 'en_US' ) ),
		'isPartOf'   => array(
			'@id' => home_url( '/' ) . '#/schema/WebSite',
		),
	);

	// v10.62.1: the breadcrumb REFERENCE must track the breadcrumb NODE.
	// sn_schema_breadcrumb_list() deliberately returns null on the front page
	// (no useful trail), but this key was emitted unconditionally — a dangling
	// @id reference Google resolves to an empty breadcrumb and flags as
	// 'Missing field "itemListElement"' (GSC, first detected 2026-05-18, the
	// homepage its one affected item). Same gate as the node builder, so the
	// two can never disagree again.
	if ( ! is_front_page() ) {
		$webpage['breadcrumb'] = array(
			'@id' => $permalink . '#breadcrumb',
		);
	}

	if ( '' !== $description ) {
		$webpage['description'] = $description;
	}

	// 2026-10-22: pages carried no dateModified. The same clock the Article
	// and the sitemap's lastmod read (the provenance commit, else post_modified),
	// so all three name one date. Absent when the post has no real time.
	$modified = sn_schema_date_modified( $post );
	if ( is_string( $modified ) && '' !== $modified ) {
		$webpage['dateModified'] = $modified;
	}

	// Identity pages (about / resume / services / uses) are ProfilePages whose mainEntity is
	// the Person — so search + answer engines read them as "the page about this person"
	// rather than a generic WebPage (additions A1; uses is a sibling of about, v9.20.0).
	// Filterable slug list.
	$profile_slugs = (array) apply_filters( 'sn_schema_profile_page_slugs', array( 'about', 'resume', 'services', 'uses' ) );
	$slug          = function_exists( 'sn_lang_effective_slug' ) ? sn_lang_effective_slug( $post ) : ( isset( $post->post_name ) ? (string) $post->post_name : '' );
	if ( in_array( $slug, $profile_slugs, true ) ) {
		$webpage['@type']      = 'ProfilePage';
		$webpage['mainEntity'] = array( '@id' => home_url( '/' ) . '#/schema/Person' );
	}

	return $webpage;
}

/**
 * ProfessionalService + OfferCatalog for the /services hire-me page. Provider is
 * @id-referenced to the Person so the offerings attach to the same entity (additions
 * A2). Returns null off /services. Offerings filterable; sourced from the live page.
 *
 * @return array<string,mixed>|null
 */
function sn_schema_professional_service() {
	if ( ! ( function_exists( 'sn_lang_is_page' ) ? sn_lang_is_page( 'services' ) : is_page( 'services' ) ) ) {
		return null;
	}
	$home      = home_url( '/' );
	$offerings = array_values( (array) apply_filters(
		'sn_schema_service_offerings',
		array(
			'Production',
			'Mixing',
			'Songwriting',
			'Mastering',
			'Operations & AI Strategy',
			'Artist & Producer Development',
		)
	) );
	$items = array();
	foreach ( $offerings as $i => $name ) {
		$items[] = array(
			'@type'       => 'Offer',
			'position'    => $i + 1,
			'itemOffered' => array( '@type' => 'Service', 'name' => (string) $name ),
		);
	}
	$queried = get_queried_object();
	return array(
		'@type'           => 'ProfessionalService',
		'@id'             => $home . '#/schema/ProfessionalService',
		'name'            => sn_setting( 'identity.person_name', get_bloginfo( 'name' ) ),
		'url'             => $queried ? get_permalink( $queried ) : $home . 'services/',
		'provider'        => array( '@id' => $home . '#/schema/Person' ),
		'hasOfferCatalog' => array(
			'@type'           => 'OfferCatalog',
			'name'            => 'Services',
			'itemListElement' => $items,
		),
	);
}

