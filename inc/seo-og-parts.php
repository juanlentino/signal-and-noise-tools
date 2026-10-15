<?php
/**
 * Signal & Noise Tools: SEO, Open Graph and Twitter (the image alt, local path and dimensions, the head-view gate, the emitter, the article meta).
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
 * Resolve the alt text for og:image:alt / twitter:image:alt.
 *
 * Fallback chain (v4.8.1): the featured image's _wp_attachment_image_alt on
 * singular views → the page $title → the configured site name. Extracted as a
 * named, side-effect-free helper so the chain is CLI-testable independent of
 * the wp_head emission (which can't be exercised headlessly).
 *
 * @since 4.8.1
 * @param string $title The OG/Twitter title for the current view.
 * @return string Alt text (may still be empty if every source is empty).
 */
function sn_seo_og_image_alt( $title ) {
	$alt = '';
	if ( is_singular() ) {
		$pid = get_post_thumbnail_id( get_queried_object() );
		if ( $pid ) {
			$alt = trim( (string) get_post_meta( $pid, '_wp_attachment_image_alt', true ) );
		}
	}
	if ( '' === $alt ) {
		// v4.8.1: on singular, use the BARE post title — image alt should
		// describe the subject, not the SERP title string. $title here carries
		// the " — Site Name" suffix from sn_seo_meta_for_current_view(); the
		// bare get_the_title() does not. Non-singular views (front page, blog
		// index) have no single queried post, so keep the passed $title.
		$alt = is_singular()
			? wp_strip_all_tags( get_the_title( get_queried_object() ) )
			: (string) $title;
	}
	if ( '' === $alt ) {
		$alt = (string) sn_setting( 'identity.site_name', get_bloginfo( 'name' ) );
	}
	return $alt;
}

/**
 * Map a same-host upload URL to its filesystem path, or '' if not local.
 *
 * Strips a query string (cache-buster) first. Only resolves URLs under
 * content_url() so an off-site image never triggers a filesystem read.
 *
 * @since 6.24.0
 * @param string $url Image URL (may carry ?v=… cache-buster).
 * @return string Absolute path, or '' when the URL isn't a local upload.
 */
function sn_seo_local_image_path( $url ) {
	$url = strtok( (string) $url, '?' );
	$base_url = content_url();
	$base_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '';
	if ( '' === $base_dir || 0 !== strpos( $url, $base_url ) ) {
		return '';
	}
	return $base_dir . substr( $url, strlen( $base_url ) );
}

/**
 * Actual pixel dimensions of an og:image / Person.image URL, or null if unknown.
 *
 * Generated /sn-og/ cards are a known constant (SN_OG_WIDTH×SN_OG_HEIGHT) — no
 * filesystem hit. Other local uploads are measured with getimagesize() (cached
 * per request). Returns null for remote/unreadable images so callers can fall
 * back rather than declaring a wrong size — the live bug this fixes was every
 * page declaring og:image:width=1000 for cards that are actually 1200 wide.
 *
 * @since 6.24.0
 * @param string $url Image URL.
 * @return array{0:int,1:int}|null [width, height] or null.
 */
function sn_seo_image_dimensions( $url ) {
	$url = (string) $url;
	if ( defined( 'SN_OG_DIRNAME' ) && defined( 'SN_OG_WIDTH' ) && defined( 'SN_OG_HEIGHT' )
		&& false !== strpos( $url, '/' . SN_OG_DIRNAME . '/' ) ) {
		return array( (int) SN_OG_WIDTH, (int) SN_OG_HEIGHT );
	}
	static $cache = array();
	if ( array_key_exists( $url, $cache ) ) {
		return $cache[ $url ];
	}
	$dims = null;
	$path = sn_seo_local_image_path( $url );
	if ( '' !== $path && is_readable( $path ) ) {
		$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a corrupt/odd file must degrade to null, not warn.
		if ( is_array( $size ) && (int) $size[0] > 0 && (int) $size[1] > 0 ) {
			$dims = array( (int) $size[0], (int) $size[1] );
		}
	}
	$cache[ $url ] = $dims;
	return $dims;
}

/**
 * A tag view: the /notes/tags/ glossary (a theme route) or a /tag/<slug>/
 * archive. Both are collections with a canonical and a description.
 *
 * @return bool
 */
function sn_seo_is_tag_view() {
	return ( function_exists( 'sn_notes_is_tags_request' ) && sn_notes_is_tags_request() )
		|| ( function_exists( 'is_tag' ) && is_tag() );
}

/**
 * The views that carry Open Graph and JSON-LD: the front page, the notes
 * index, every singular and (2026.10 live audit, P2-5) the tag views. One
 * gate for both emitters (here and inc/seo-schema.php).
 *
 * @return bool
 */
function sn_seo_head_view_wanted() {
	return is_front_page() || is_home() || is_singular() || sn_seo_is_tag_view();
}

/**
 * Open Graph + Twitter card meta.
 *
 * Emitted on the front page and any singular post/page (including the
 * Notes index when wired as the WP Posts page). The OG image defaults
 * to the existing site logo and is filterable via `sn_og_image_url` so
 * a future per-post or per-route image can be plugged in without
 * touching theme code.
 */
add_action( 'wp_head', function() {
	if ( ! sn_seo_head_view_wanted() ) {
		return;
	}

	list( $title, $description, $url ) = sn_seo_meta_for_current_view();
	// The tag views leave the view title empty so WordPress (or the theme, for
	// /notes/tags/) owns the <title>; the card takes that same title.
	if ( ! $title && sn_seo_is_tag_view() && function_exists( 'wp_get_document_title' ) ) {
		$title = (string) wp_get_document_title();
	}
	if ( ! $title && ! $description ) {
		return;
	}

	$is_article = is_singular( 'post' );
	$default_og = sn_setting( 'og.default_image_url', '' );
	$og_image   = apply_filters( 'sn_og_image_url', $default_og );

	$locale = function_exists( 'sn_lang_og_locale' ) ? sn_lang_og_locale() : sn_setting( 'identity.locale', 'en_US' );
	echo '<meta property="og:type" content="' . ( $is_article ? 'article' : 'website' ) . '">' . "\n";
	echo '<meta property="og:locale" content="' . esc_attr( $locale ) . '">' . "\n";
	if ( $title ) {
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
	}
	if ( $description ) {
		echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
		echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
	}
	if ( $url ) {
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	}
	$site_name = sn_setting( 'identity.site_name', get_bloginfo( 'name' ) );
	echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '">' . "\n";
	if ( $og_image ) {
		// v6.24.0: declare the image's ACTUAL pixel size. Generated /sn-og/ cards
		// resolve to the generator's constant; other local uploads are measured.
		// Falls back to the og.card_* setting only when the size can't be read —
		// previously the setting was used unconditionally and drifted (cards are
		// 1200 wide but the stored setting declared 1000). Still filterable.
		$measured = sn_seo_image_dimensions( $og_image );
		$dims     = (array) apply_filters(
			'sn_og_image_dimensions',
			null !== $measured ? $measured : array(
				sn_setting( 'og.card_width', 1200 ),
				sn_setting( 'og.card_height', 630 ),
			),
			$og_image
		);
		echo '<meta property="og:image" content="' . esc_url( $og_image ) . '">' . "\n";
		echo '<meta property="og:image:width" content="' . (int) ( $dims[0] ?? 1200 ) . '">' . "\n";
		echo '<meta property="og:image:height" content="' . (int) ( $dims[1] ?? 630 ) . '">' . "\n";
		echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
		echo '<meta name="twitter:image" content="' . esc_url( $og_image ) . '">' . "\n";
		// v4.8.1: accessible alt text for the social card image.
		$alt = sn_seo_og_image_alt( $title );
		if ( '' !== $alt ) {
			echo '<meta property="og:image:alt" content="' . esc_attr( $alt ) . '">' . "\n";
			echo '<meta name="twitter:image:alt" content="' . esc_attr( $alt ) . '">' . "\n";
		}
	} else {
		echo '<meta name="twitter:card" content="summary">' . "\n";
	}

	// Twitter handle attribution (filterable so future config UI can change).
	$twitter_handle = (string) apply_filters(
		'sn_twitter_handle',
		sn_setting( 'social.twitter_handle', '' )
	);
	if ( $twitter_handle ) {
		echo '<meta name="twitter:site" content="' . esc_attr( $twitter_handle ) . '">' . "\n";
		echo '<meta name="twitter:creator" content="' . esc_attr( $twitter_handle ) . '">' . "\n";
	}

	// Article OG metadata on singular posts (published/modified/author/section/tag).
	if ( $is_article ) {
		$post = get_queried_object();
		if ( $post ) {
			sn_seo_article_meta( $post );
		}
	}
}, 3 );

/**
 * Emit the article:* Open Graph metadata for a single post. Named (not inline
 * in the wp_head closure) so it is unit-testable — mirrors why
 * sn_seo_og_image_alt() was extracted.
 *
 * article:author points at the site identity URL (home_url('/')), which is
 * exactly Person.url and the entity Article.author/@id resolves to in the
 * JSON-LD (inc/seo-schema.php) — so the OG profile pointer and the structured
 * data agree. Filterable via sn_og_article_author_url for a future dedicated
 * profile route. article:section + article:tag mirror the JSON-LD's
 * articleSection (first category) + keywords (post tags) EXACTLY — same source
 * terms, but emitted as one repeated <meta> per tag per the OGP spec (the
 * JSON-LD comma-joins them; OG must not).
 *
 * @param WP_Post|object $post The queried single post.
 */
function sn_seo_article_meta( $post ) {
	$published = get_post_time( 'c', true, $post );
	$modified  = get_post_modified_time( 'c', true, $post );
	if ( $published ) {
		echo '<meta property="article:published_time" content="' . esc_attr( $published ) . '">' . "\n";
	}
	if ( $modified ) {
		echo '<meta property="article:modified_time" content="' . esc_attr( $modified ) . '">' . "\n";
	}

	// article:author → the canonical site identity (= JSON-LD Person.url / author @id).
	$author_url = (string) apply_filters( 'sn_og_article_author_url', home_url( '/' ), $post );
	if ( '' !== $author_url ) {
		echo '<meta property="article:author" content="' . esc_url( $author_url ) . '">' . "\n";
	}

	// article:section → first category name (mirrors JSON-LD articleSection; no
	// Yoast/custom primary logic exists — plain $cats[0], matched exactly).
	$cats = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'names' ) );
	if ( is_array( $cats ) && ! is_wp_error( $cats ) && ! empty( $cats ) ) {
		echo '<meta property="article:section" content="' . esc_attr( (string) $cats[0] ) . '">' . "\n";
	}

	// article:tag → one <meta> per post tag (mirrors JSON-LD keywords, but
	// repeated metas per OGP, NOT a single comma-joined value).
	$tags = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );
	if ( is_array( $tags ) && ! is_wp_error( $tags ) && ! empty( $tags ) ) {
		foreach ( $tags as $tag_name ) {
			echo '<meta property="article:tag" content="' . esc_attr( (string) $tag_name ) . '">' . "\n";
		}
	}
}

