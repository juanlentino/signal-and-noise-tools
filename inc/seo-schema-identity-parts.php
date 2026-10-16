<?php
/**
 * Signal & Noise Tools: seo-schema.php, the Person, credentials and WebSite
 * nodes, plus the article description, pillar and dateModified helpers.
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
 * Default social profile URLs (sameAs). Filterable.
 */
function sn_schema_default_same_as() {
	return (array) sn_setting( 'social.same_as', array() );
}

/**
 * Build the Person schema (author + publisher).
 */
function sn_schema_person() {
	$home = home_url( '/' );

	// v9.2.2: the /about page publicly displays an ORCID iD — the authoritative
	// identifier for the research the page describes (the Provenance papers).
	// sameAs is the link search + answer engines use to reconcile this Person with
	// that profile, so the visible identifier must live in the structured data too;
	// otherwise it exists only as page text, invisible to the entity graph. Merged
	// into the sameAs list BEFORE the sn_schema_same_as filter (so it stays fully
	// overridable) with a dedupe guard so a configured/duplicate entry is not doubled.
	$same_as = (array) sn_schema_default_same_as();
	$orcid   = 'https://orcid.org/0009-0006-8151-5920';
	if ( ! in_array( $orcid, $same_as, true ) ) {
		$same_as[] = $orcid;
	}
	$same_as = (array) apply_filters( 'sn_schema_same_as', $same_as );

	$name = sn_setting( 'identity.person_name', get_bloginfo( 'name' ) );

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
		// v6.24.0: declare real pixel dimensions when measurable (matches the
		// Article.image treatment) — omitted rather than guessed when unknown.
		$person_dims = function_exists( 'sn_seo_image_dimensions' ) ? sn_seo_image_dimensions( $img ) : null;
		if ( null !== $person_dims ) {
			$person['image']['width']  = $person_dims[0];
			$person['image']['height'] = $person_dims[1];
		}
	}

	// Credentials graph (occupation / education / awards / memberships / worksFor).
	// Filterable code-config — these facts are stable and verbatim from /about + /resume,
	// so they live in a filter default, not a settings-tab field (no admin UI to drift,
	// no sn_settings_save clobber surface).
	$person = array_merge( $person, sn_schema_person_credentials() );

	return $person;
}

/**
 * Stable identity credentials merged into the Person node, sourced verbatim from
 * the live /about + /resume copy. Filterable so the owner can override without code.
 *
 * @return array<string,mixed> hasOccupation / alumniOf / award / memberOf / worksFor
 */
function sn_schema_person_credentials() {
	return (array) apply_filters(
		'sn_schema_person_credentials',
		array(
			'hasOccupation' => array(
				array( '@type' => 'Occupation', 'name' => 'Music Producer' ),
				array( '@type' => 'Occupation', 'name' => 'Audio Engineer' ),
				array( '@type' => 'Occupation', 'name' => 'Creative Strategist' ),
			),
			'alumniOf'      => array(
				array( '@type' => 'CollegeOrUniversity', 'name' => 'Full Sail University' ),
				array( '@type' => 'CollegeOrUniversity', 'name' => 'Westcliff University' ),
			),
			'award'         => array(
				'Valedictorian, Full Sail University',
				'Advanced Achiever Award, Full Sail University',
			),
			'memberOf'      => array(
				array( '@type' => 'Organization', 'name' => 'The Recording Academy' ),
				array( '@type' => 'Organization', 'name' => 'The Latin Recording Academy' ),
			),
			'worksFor'      => array(
				'@type'        => 'Organization',
				'name'         => 'Panacea',
				'description'  => 'Recording studio',
				'foundingDate' => '2016',
			),
			// v9.4.0: the /about ORCID as a formal identifier (PropertyValue),
			// the schema.org best-practice form ORCID's own JSON-LD serves,
			// alongside the sameAs link added in v9.2.2. @id stays the site anchor.
			'identifier'    => array(
				'@type'      => 'PropertyValue',
				'propertyID' => 'ORCID',
				'value'      => 'https://orcid.org/0009-0006-8151-5920',
			),
		)
	);
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
 * Is the queried object a pillar page? `_sn_pillar` meta, seeded by
 * inc/pillar-meta-seed.php and read by the site map the same way.
 *
 * @return bool
 */
function sn_schema_is_pillar_page() {
	if ( ! is_singular( 'page' ) ) {
		return false;
	}
	$page = get_queried_object();
	return $page && '1' === (string) get_post_meta( (int) $page->ID, '_sn_pillar', true );
}

/**
 * The Article's dateModified: the provenance commit when there is one.
 *
 * @since 15.9.2
 * @param WP_Post $post Post object.
 * @return string ISO 8601, UTC.
 */
function sn_schema_date_modified( $post ) {
	$meta   = defined( 'SN_PROV_LAST_COMMIT_META' ) ? SN_PROV_LAST_COMMIT_META : '_sn_prov_last_commit_gmt';
	$commit = (string) get_post_meta( $post->ID, $meta, true );
	if ( '' !== $commit ) {
		$ts = strtotime( $commit . ' UTC' );
		if ( false !== $ts && $ts > 0 ) {
			return gmdate( 'c', $ts );
		}
	}
	return get_post_modified_time( 'c', true, $post );
}

