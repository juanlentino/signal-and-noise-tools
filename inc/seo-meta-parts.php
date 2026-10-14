<?php
/**
 * Signal & Noise Tools: SEO, the meta resolvers (the paged var, the singular title and description, the description store, the per-view meta, the tags glossary).
 *
 * Split from inc/seo.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO: Output meta description tag.
 *
 * Notes index (`/notes`) and the Provenance pillar (`/provenance`) get
 * dedicated copy — for everything else we fall back to the post excerpt
 * (set by the editor when publishing).
 */
/**
 * The current /notes listing page number (≥1). Reads the main-query 'paged'
 * var — reliable for a non-front Page like /notes/ (WP only reassigns
 * paged→page for the static front page) — with a raw $_GET fallback as
 * defensive belt-and-suspenders for the documented is_page('notes') routing
 * ambiguity. v5.1.0.
 */
function sn_seo_current_paged() {
	$paged = (int) get_query_var( 'paged' );
	if ( $paged < 1 && isset( $_GET['paged'] ) ) {
		$paged = (int) $_GET['paged']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public page-number, no state change
	}
	return max( 1, $paged );
}

/**
 * Resolve the active page's effective title + description for SEO meta.
 * Returns [ $title, $description, $url ] — any field may be empty string.
 *
 * The dedicated /notes and /provenance entries take precedence over the
 * generic excerpt fallback so the index pages get curated copy instead
 * of WP's auto-excerpt of an empty Page body.
 */

/**
 * Resolve the singular page title for <title>/og:title/twitter:title.
 *
 * Precedence: _sn_seo_title per-page override → sn_seo_singular_title theme
 * fallback (v9.3.0 seam, defaults '') → get_the_title(). The " — {site_name}"
 * suffix is appended for every singular EXCEPT posts (15.9.1): a note's title
 * tag is the aphorism and its plain-words subtitle, nothing else.
 * Named + pure so the chain is CLI-testable independent of document_title_parts.
 *
 * @since 9.3.0
 * @param WP_Post|object $post
 * @return string
 */
function sn_seo_resolve_singular_title( $post ) {
	$written  = function_exists( 'sn_post_settings_get_seo_title' )
		? (string) sn_post_settings_get_seo_title( $post->ID )
		: '';
	$override = $written;
	if ( '' === $override ) {
		$override = (string) apply_filters( 'sn_seo_singular_title', '', $post );
	}
	$base = ( '' !== $override )
		? wp_strip_all_tags( $override )
		: wp_strip_all_tags( get_the_title( $post ) );
	// 15.9.1: a NOTE's title tag carries no site-name suffix. Since 2022
	// Google paints the site name on its own line from the WebSite schema
	// (which every page emits), and the notes' titles are now "Aphorism:
	// plain words" (median 84 characters with the suffix, display cut near
	// 60): the suffix was the part that got cut, and it said nothing the
	// schema does not.
	// 16.1.2: neither does an OVERRIDE on any type. A written search title
	// is the whole title; the pillars' overrides ran 73 to 91 characters
	// with the suffix and Bing flagged every one (past 70). A page WITHOUT
	// an override keeps the "Page — Site" shape, and so does a title the
	// theme's route filter supplies; the 404 too.
	if ( '' !== $written || ( isset( $post->post_type ) && 'post' === (string) $post->post_type ) ) {
		return $base;
	}
	$site = sn_setting( 'identity.site_name', get_bloginfo( 'name' ) );
	return $base . ' — ' . $site;
}

/**
 * Resolve the singular meta description for <meta name="description">,
 * og:description, and twitter:description.
 *
 * Precedence: _sn_meta_description per-post override → post_excerpt (stripped) →
 * sn_seo_singular_description theme-route fallback (v6.24.0 seam, defaults '').
 * Mirrors pre-v9.7.0 behavior exactly — the override is returned RAW (the meta
 * box stores plain text; unlike the title path, which strips it), only the
 * excerpt is stripped, and an excerpt that is empty() (including the literal
 * '0') falls through to the filter. Named + pure so the chain is CLI-testable
 * independent of the wp_head emission, and so the Analytics descriptionless-
 * Pages recommendation can reuse the identical resolution.
 *
 * @since 9.7.0
 * @param WP_Post|object $post
 * @return string
 */
function sn_seo_resolve_singular_description( $post ) {
	$override = function_exists( 'sn_post_settings_get_description' )
		? sn_post_settings_get_description( $post->ID )
		: '';
	if ( '' !== $override ) {
		return $override;
	}
	if ( ! empty( $post->post_excerpt ) ) {
		return wp_strip_all_tags( $post->post_excerpt );
	}
	return (string) apply_filters( 'sn_seo_singular_description', '', $post );
}

/**
 * The seo_copy.* settings key whose value SHIPS as this Page's meta
 * description, or '' when the Page takes its description from the post itself
 * (override -> excerpt -> filter).
 *
 * THE route table, in one place. sn_seo_description_for_post() below reads it
 * for the value; sn_validate's meta_description resolver reads it to decide
 * WHICH store to grade. Before v13.109.3 the table existed only inside
 * sn_seo_description_for_post(), so sn_validate had no way to ask the question
 * and graded _sn_meta_description on every post — including the three routes
 * that never emit it. Measured on /provenance 2026-09-10: the post meta row
 * held 175 chars while the page shipped 83 from settings, so the char_range
 * warning was scoring a string no crawler could ever see.
 *
 * A fourth route-served Page is added HERE, once.
 *
 * @since 13.109.3
 * @param object|null $post A Page post object ( ->ID, ->post_name ).
 * @return string Settings key, or '' when the post itself is the source.
 */
function sn_seo_description_setting_key( $post ) {
	if ( ! is_object( $post ) ) {
		return '';
	}
	$front = (int) get_option( 'page_on_front' );
	$id    = (int) ( $post->ID ?? 0 );
	$slug  = (string) ( $post->post_name ?? '' );

	if ( $front && $id === $front ) {
		return 'seo_copy.home_description';
	}
	if ( 'notes' === $slug ) {
		return 'seo_copy.notes_description';
	}
	if ( 'provenance' === $slug ) {
		return 'seo_copy.provenance_description';
	}
	return '';
}

/**
 * The meta description sn_seo_meta_for_current_view() WOULD emit for a given
 * Page post — mirrors that function's per-route description branches but keyed
 * on the post's identity (not conditional tags), so a caller can evaluate an
 * arbitrary Page outside its own request. Used by the Analytics
 * descriptionless-Pages recommendation, which walks every published Page.
 *
 * The route branches themselves now live in sn_seo_description_setting_key()
 * above, so this reads the table rather than restating it; keep that table in
 * sync with sn_seo_meta_for_current_view() — the seo-description-for-post test
 * pins each branch through both functions.
 *
 * @param object|null $post A Page post object ( ->ID, ->post_name, ->post_excerpt ).
 * @return string
 */
function sn_seo_description_for_post( $post ) {
	if ( ! is_object( $post ) ) {
		return '';
	}
	$key = sn_seo_description_setting_key( $post );
	if ( '' !== $key ) {
		return (string) sn_setting( $key, '' );
	}
	return sn_seo_resolve_singular_description( $post );
}

function sn_seo_meta_for_current_view() {
	// v10.0.0: the virtual-route precedence branch is gone with the
	// sn_seo_route_meta seam — every former virtual route is a real Page, so
	// the conditionals below resolve them.
	$title       = '';
	$description = '';
	$url         = '';

	if ( is_front_page() ) {
		$title       = sn_setting( 'seo_copy.home_title', '' );
		$description = sn_setting( 'seo_copy.home_description', '' );
		$url         = home_url( '/' );
	} elseif ( function_exists( 'sn_notes_is_tags_request' ) && sn_notes_is_tags_request() ) {
		// 16.1.2: the /notes/tags/ glossary is a theme route WordPress sees
		// as a 404 the theme clears, so it fell through every branch here and
		// served no canonical and no description (Bing, 2026-09-18). The
		// theme owns the <title>; this supplies what was missing.
		$title       = '';
		$description = sn_seo_tags_glossary_description();
		$url         = home_url( '/notes/tags/' );
	} elseif ( is_page( 'notes' ) || is_home() ) {
		$title       = sn_setting( 'seo_copy.notes_title', '' );
		$description = sn_setting( 'seo_copy.notes_description', '' );
		$url         = home_url( '/notes/' );
		// v5.1.0: paged pages self-canonical (do NOT collapse to /notes/).
		// Flows to both <link rel="canonical"> and og:url, which read $url.
		$paged = sn_seo_current_paged();
		if ( $paged > 1 ) {
			$url = add_query_arg( 'paged', $paged, $url );
		}
	} elseif ( function_exists( 'is_tag' ) && is_tag() ) {
		// v13.14.0: tag archives had NO branch here at all, so they fell
		// through every conditional and the canonical emitter (which reads
		// only $url) printed nothing. Measured on the live site: 23 indexable
		// tag archives with no canonical, and /tag/provenance/ and
		// /tag/provenance/page/2/ serving DIFFERENT notes under an identical
		// <title> with no canonical on either — the duplicate-content shape
		// the /notes/ branch fixed for itself back in v5.1.0 and tags never
		// got.
		//
		// TITLE IS DELIBERATELY LEFT EMPTY. The document_title_parts filter
		// below returns WP's own parts untouched when this is '', and WP's
		// default is already the correct "Notes — <Tag> — Juan Lentino".
		// Setting a title here would REPLACE that string, so the safe fix
		// supplies only what is actually missing.
		//
		// The description is the term's own, never a fabricated one: an
		// unwritten tag description stays empty and this view keeps emitting
		// no og:description, exactly as it does today. When the description is
		// written, the og block lights up on its own.
		$term = get_queried_object();
		if ( $term && isset( $term->term_id ) ) {
			$description = trim( wp_strip_all_tags( (string) term_description( $term ) ) );
			$link        = get_term_link( $term );
			if ( ! is_wp_error( $link ) ) {
				$url   = (string) $link;
				$paged = sn_seo_current_paged();
				if ( $paged > 1 ) {
					// Pretty pagination: /tag/<slug>/page/N/ is the URL that
					// actually serves, so the canonical must be that and not
					// a ?paged= variant of it.
					$url = trailingslashit( $url ) . 'page/' . $paged . '/';
				}
			}
		}
	} elseif ( is_page( 'provenance' ) ) {
		$title       = sn_setting( 'seo_copy.provenance_title', '' );
		$description = sn_setting( 'seo_copy.provenance_description', '' );
		$url         = home_url( '/provenance/' );
	} elseif ( is_singular() ) {
		$post  = get_queried_object();
		// v9.3.0: per-page _sn_seo_title override → sn_seo_singular_title theme
		// fallback → derived title, all with the site-name suffix.
		$title = $post ? sn_seo_resolve_singular_title( $post ) : '';
		// v9.7.0: per-post _sn_meta_description override → post_excerpt →
		// sn_seo_singular_description theme-route filter (for template-driven
		// Pages like /about, /contact, /colophon, /music that carry no excerpt).
		// Extracted into sn_seo_resolve_singular_description() so this path and
		// the Analytics descriptionless-Pages recommendation share one source of
		// truth — mirrors how the title above delegates to its resolver.
		$description = $post ? sn_seo_resolve_singular_description( $post ) : '';
		$url         = $post ? get_permalink( $post ) : '';
	}

	return array( $title, $description, $url );
}

/**
 * The /notes/tags/ glossary's description, from the live tag count. PURE
 * given the count; the count is every non-empty tag.
 *
 * @since 16.1.2
 * @param int|null $count Injected for tests; null reads the taxonomy.
 * @return string
 */
function sn_seo_tags_glossary_description( $count = null ) {
	if ( null === $count ) {
		$count = function_exists( 'wp_count_terms' ) ? wp_count_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => true ) ) : 0;
		$count = is_wp_error( $count ) ? 0 : (int) $count;
	}
	$count = (int) $count;
	if ( $count < 1 ) {
		return 'Every tag on the notes, with what it gathers and how many notes carry it.';
	}
	return sprintf( 'The %d tags on the notes, each with what it gathers and how many notes carry it.', $count );
}

