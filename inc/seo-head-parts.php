<?php
/**
 * Signal & Noise Tools: SEO, the head emission (the core canonical removal, the canonical, robots and description tags, the document title).
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
 * Remove WP core's rel_canonical to avoid emitting two canonical tags.
 *
 * WP core's wp-includes/link-template.php registers rel_canonical()
 * on wp_head at priority 10. It fires on singular views (which
 * includes static front pages). Until Phase 13, TSF was the suppressor;
 * now that TSF is gone, our seo.php canonical at priority 1 would
 * race-emit alongside WP core's at priority 10, producing two
 * <link rel="canonical"> tags per page (caught in v2.0.1 cutover
 * verification).
 *
 * Gated on TSF — keeps the removal scoped to "when SN owns canonical
 * emission" so accidental TSF reactivation doesn't double-remove.
 *
 * Added in v2.0.2.
 */
add_action( 'init', function() {
	if ( ! function_exists( 'the_seo_framework' ) ) {
		remove_action( 'wp_head', 'rel_canonical' );
		// wp_robots() (WP 5.7+) is hooked on wp_head priority 1 and emits
		// a competing <meta name="robots"> tag when blog_public=0
		// ("Discourage search engines" in Settings > Reading). Today our
		// production site has blog_public=1 so this is harmless, but a
		// staging clone or accidental toggle would cause two robots tags
		// with conflicting directives. Belt-and-suspenders removal so the
		// plugin owns robots emission unconditionally. v2.0.4 hardening.
		remove_action( 'wp_head', 'wp_robots', 1 );
	}
}, 1 );

/**
 * SEO: Canonical URL.
 *
 * Emits <link rel="canonical"> on the front page, /notes, /provenance,
 * and any singular post/page. Migrated from The SEO Framework in
 * plugin v1.6.0 (Phase 10).
 */
add_action( 'wp_head', function() {
	// v4.4.3: TSF coexistence defense-in-depth. If TSF is ever reactivated,
	// let it own canonical emission; our emitter stands down. The init hook
	// already gates the rel_canonical() REMOVAL on TSF absence, but the
	// emitter itself was unconditional — would produce a duplicate tag.
	if ( function_exists( 'the_seo_framework' ) ) {
		return;
	}

	list( , , $url ) = sn_seo_meta_for_current_view();

	// v1.10.2+: per-post _sn_canonical_url override wins for singulars.
	// Use case: republished/syndicated content where the canonical lives
	// at the original publisher's URL.
	if ( is_singular() && function_exists( 'sn_post_settings_get_canonical_url' ) ) {
		$post = get_queried_object();
		if ( $post ) {
			$override = sn_post_settings_get_canonical_url( $post->ID );
			if ( '' !== $override ) {
				$url = $override;
			}
		}
	}

	if ( $url ) {
		echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
	}
}, 1 );

/**
 * SEO: Robots meta.
 *
 * Mirrors The SEO Framework's default "no restrictions" robots meta.
 * Honors per-post override flags for singulars (set via the post
 * settings meta box, added in v1.10.0 for noindex / v1.10.2 for
 * noarchive + noimageindex):
 *   _sn_noindex       — adds 'noindex'      (v12.12.0: no longer implies nofollow)
 *   _sn_nofollow      — adds 'nofollow'     (v12.12.0, standalone)
 *   _sn_noarchive     — adds 'noarchive'    (no cached copy)
 *   _sn_noimageindex  — adds 'noimageindex' (no Google Images)
 */
/**
 * Build the robots-meta directive list. Pure apart from its WP context reads,
 * so it is fixture-testable (tests/seo-robots.php).
 *
 * v9.88.0: the list passes through the `sn_seo_robots_directives` filter. The
 * plugin removes core's wp_robots and emits this tag itself, so a theme-side
 * `wp_robots` filter is DEAD CODE (the theme's v10.51.0 search-noindex hooked
 * exactly that and was live-verified inert). This is the seam a theme uses to
 * add directives for the routes it owns. Listener returns are normalized: a
 * non-array is ignored, duplicates collapse, the result stays list-shaped.
 *
 * @return string[]
 */
function sn_seo_robots_directives() {
	$directives = array();

	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post ) {
			// noindex — v12.12.0 emits it ALONE. From v1.6.0 it also forced
			// nofollow, which welded together two unrelated questions: whether
			// a page belongs in the index, and whether the links leaving it
			// should carry signal. A demo page pointing at the product it
			// demos wants the first and not the second, and had no way to say
			// so. noindex+nofollow is still reachable via the standalone flag
			// below — it just has to be asked for now.
			$noindex = function_exists( 'sn_post_settings_get_noindex' )
				? sn_post_settings_get_noindex( $post->ID )
				: ( '1' === (string) get_post_meta( $post->ID, '_sn_noindex', true ) );
			if ( $noindex ) {
				$directives[] = 'noindex';
			}

			// nofollow + noarchive + noimageindex — standalone flags
			// (noarchive/noimageindex v1.10.2, nofollow v12.12.0). Layer on
			// top of (or independent of) noindex.
			$nofollow = function_exists( 'sn_post_settings_get_nofollow' )
				? sn_post_settings_get_nofollow( $post->ID )
				: ( '1' === (string) get_post_meta( $post->ID, '_sn_nofollow', true ) );
			if ( $nofollow ) {
				$directives[] = 'nofollow';
			}

			$noarchive = function_exists( 'sn_post_settings_get_noarchive' )
				? sn_post_settings_get_noarchive( $post->ID )
				: ( '1' === (string) get_post_meta( $post->ID, '_sn_noarchive', true ) );
			if ( $noarchive ) {
				$directives[] = 'noarchive';
			}
			$noimageindex = function_exists( 'sn_post_settings_get_noimageindex' )
				? sn_post_settings_get_noimageindex( $post->ID )
				: ( '1' === (string) get_post_meta( $post->ID, '_sn_noimageindex', true ) );
			if ( $noimageindex ) {
				$directives[] = 'noimageindex';
			}
		}
	}

	// Always-present permissive defaults (mirrors TSF's "no restrictions").
	$directives[] = 'max-snippet:-1';
	$directives[] = 'max-image-preview:large';
	$directives[] = 'max-video-preview:-1';

	// 15.10.0: a tag archive below the hub line (fewer than three notes) is
	// a list of one or two links; it stays out of the index and the sitemap
	// alike (inc/sitemap.php owns the line). A hub tag is indexable, and its
	// description is the page.
	if ( function_exists( 'is_tag' ) && is_tag() && function_exists( 'sn_sitemap_tag_is_hub' ) ) {
		$term = get_queried_object();
		if ( $term && isset( $term->count ) && ! sn_sitemap_tag_is_hub( (int) $term->count ) ) {
			$directives[] = 'noindex';
		}
	}

	$filtered = apply_filters( 'sn_seo_robots_directives', $directives );
	if ( ! is_array( $filtered ) ) {
		$filtered = $directives;
	}
	$clean = array();
	foreach ( $filtered as $directive ) {
		if ( is_scalar( $directive ) && '' !== (string) $directive ) {
			$clean[] = (string) $directive;
		}
	}
	return array_values( array_unique( $clean ) );
}

add_action( 'wp_head', function() {
	// v4.4.3: TSF coexistence defense-in-depth. TSF owns robots meta while
	// active; our emitter defers. The init hook removes wp_robots() when TSF
	// is inactive, but that removal only covers WP core's hook — without this
	// gate, TSF + our emitter would both fire if TSF were reactivated.
	if ( function_exists( 'the_seo_framework' ) ) {
		return;
	}

	echo '<meta name="robots" content="' . esc_attr( implode( ',', sn_seo_robots_directives() ) ) . '">' . "\n";
}, 1 );

/**
 * SEO: Meta description tag.
 */
add_action( 'wp_head', function() {
	// v4.4.3: TSF coexistence defense-in-depth. TSF owns meta description
	// while active; our emitter defers. Without this gate, reactivating TSF
	// would produce a duplicate meta description tag in <head>.
	if ( function_exists( 'the_seo_framework' ) ) {
		return;
	}

	list( , $description, ) = sn_seo_meta_for_current_view();
	if ( $description ) {
		echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
	}
}, 2 );

/**
 * Title tag format — WP-native emission via document_title_parts filter.
 *
 * Cooperates with WP core's _wp_render_title_tag() (active because the
 * theme declares add_theme_support('title-tag') as of theme v8.5.5).
 * Splits our pre-built "Page Name — Site Name" format from
 * sn_seo_meta_for_current_view() into the title/site parts WP expects.
 *
 * Gated on TSF: while TSF is active, TSF emits the <title> itself and
 * we let it. The instant TSF is deactivated, this filter takes over.
 *
 * Added in plugin v2.0.0 (Phase 13 TSF cutover).
 */
add_filter( 'document_title_parts', function( $parts ) {
	if ( function_exists( 'the_seo_framework' ) ) {
		return $parts;
	}

	list( $title, , ) = sn_seo_meta_for_current_view();
	if ( '' === $title ) {
		// 404s carry no view meta, and WP's fallback joins with its default
		// hyphen — the one title on the site that broke the em-dash
		// convention ("Page not found - …"). Keep the "Page — Site" shape.
		if ( is_404() ) {
			return array( 'title' => 'Page not found — ' . get_bloginfo( 'name' ) );
		}
		return $parts;
	}

	// Our title from sn_seo_meta_for_current_view() is already in
	// "Page Name — Site Name" final format. WP joins all non-empty
	// $parts (title + site + tagline + page) with a separator, so we
	// must REPLACE the whole array — not just add to it — otherwise
	// WP appends the site tagline (= bloginfo('description')) and the
	// rendered <title> becomes "X — Y — Z" with three segments.
	return array( 'title' => $title );
}, 10, 1 );

