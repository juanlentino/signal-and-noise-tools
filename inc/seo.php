<?php
/**
 * Signal & Noise — SEO + analytics delivery.
 *
 * - Meta description on front page and singular posts
 * - Breeze excludes so our perf-critical bundles aren't re-ordered by
 *   the Breeze cache plugin
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO: Suppress The SEO Framework's Open Graph / Facebook / Twitter emission.
 *
 * Why: our own wp_head OG emission (below) and TSF's both emit `og:image`
 * and `twitter:image` meta tags. The result is duplicate conflicting tags
 * in <head> — TSF emits the site icon as fallback; we emit the generated
 * /sn-og/post-*.png card. Crawler parsing of duplicates is undefined.
 *
 * This filter removes TSF from those generator pools, leaving our wp_head
 * emission as the single source of truth for OG/Twitter meta.
 *
 * Why not deactivate TSF entirely: TSF still owns canonical URLs, robots
 * meta, JSON-LD schemas, and a few fields we don't yet emit (og:locale,
 * og:image:width/height, article:published_time, twitter:site/creator).
 * Those move to our seo.php in Phase 10+11 of the absorption roadmap;
 * full TSF deactivation lands in Phase 13.
 *
 * Added in plugin v1.4.1 (2026-05-16, Phase 6 diagnostic outcome).
 */
add_filter( 'the_seo_framework_meta_generator_pools', function( $pools ) {
	return array_diff( (array) $pools, array( 'Open_Graph', 'Facebook', 'Twitter' ) );
} );

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
 * suffix is always appended, matching pre-v9.3.0 behavior for every singular.
 * Named + pure so the chain is CLI-testable independent of document_title_parts.
 *
 * @since 9.3.0
 * @param WP_Post|object $post
 * @return string
 */
function sn_seo_resolve_singular_title( $post ) {
	$override = function_exists( 'sn_post_settings_get_seo_title' )
		? sn_post_settings_get_seo_title( $post->ID )
		: '';
	if ( '' === $override ) {
		$override = (string) apply_filters( 'sn_seo_singular_title', '', $post );
	}
	$base = ( '' !== $override )
		? wp_strip_all_tags( $override )
		: wp_strip_all_tags( get_the_title( $post ) );
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
 * The meta description sn_seo_meta_for_current_view() WOULD emit for a given
 * Page post — mirrors that function's per-route description branches but keyed
 * on the post's identity (not conditional tags), so a caller can evaluate an
 * arbitrary Page outside its own request. Used by the Analytics
 * descriptionless-Pages recommendation, which walks every published Page.
 *
 * The front page, /notes, and /provenance take their description from SEO
 * settings (seo_copy.*_description), NOT the Page excerpt; every other Page uses
 * sn_seo_resolve_singular_description(). Keep the route branches in sync with
 * sn_seo_meta_for_current_view() above — the seo-description-for-post test pins
 * each branch.
 *
 * @param object|null $post A Page post object ( ->ID, ->post_name, ->post_excerpt ).
 * @return string
 */
function sn_seo_description_for_post( $post ) {
	if ( ! is_object( $post ) ) {
		return '';
	}
	$front = (int) get_option( 'page_on_front' );
	$id    = (int) ( $post->ID ?? 0 );
	$slug  = (string) ( $post->post_name ?? '' );

	if ( $front && $id === $front ) {
		return (string) sn_setting( 'seo_copy.home_description', '' );
	}
	if ( 'notes' === $slug ) {
		return (string) sn_setting( 'seo_copy.notes_description', '' );
	}
	if ( 'provenance' === $slug ) {
		return (string) sn_setting( 'seo_copy.provenance_description', '' );
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
 * Open Graph + Twitter card meta.
 *
 * Emitted on the front page and any singular post/page (including the
 * Notes index when wired as the WP Posts page). The OG image defaults
 * to the existing site logo and is filterable via `sn_og_image_url` so
 * a future per-post or per-route image can be plugged in without
 * touching theme code.
 */
add_action( 'wp_head', function() {
	// v10.0.0: the sn_seo_route_meta virtual-route seam is retired — the
	// pages-to-CMS flip made every former virtual route a real Page, so
	// is_singular() covers them.
	if ( ! is_front_page() && ! is_home() && ! is_singular() ) {
		return;
	}

	list( $title, $description, $url ) = sn_seo_meta_for_current_view();
	if ( ! $title && ! $description ) {
		return;
	}

	$is_article = is_singular( 'post' );
	$default_og = sn_setting( 'og.default_image_url', '' );
	$og_image   = apply_filters( 'sn_og_image_url', $default_og );

	$locale = sn_setting( 'identity.locale', 'en_US' );
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

/**
 * Prevent Breeze from deferring the block navigation script.
 */
add_filter( 'breeze_exclude_js', function( $excluded ) {
	$excluded[] = 'wp-block-navigation-view';
	$excluded[] = 'wp-block-navigation';
	$excluded[] = 'signal-noise-sticky-header';
	return $excluded;
} );

/**
 * Prevent Breeze from minifying critical inline CSS.
 */
add_filter( 'breeze_exclude_css', function( $excluded ) {
	$excluded[] = 'critical.css';
	return $excluded;
} );

/**
 * Last-Modified header + If-Modified-Since 304 handling on singulars.
 *
 * TSF emits Last-Modified itself when active; gate keeps us dormant
 * until TSF deactivates. Returns 304 Not Modified when the request's
 * If-Modified-Since timestamp is at-or-after the post's modified time
 * — saves crawl budget for Google and friends.
 *
 * Hooked at template_redirect (after the query is set, before output
 * starts) so we can still send headers + exit cleanly.
 *
 * Added in v2.0.0 (Phase 13 TSF cutover).
 */
add_action( 'template_redirect', function() {
	if ( function_exists( 'the_seo_framework' ) ) {
		return;
	}

	if ( ! is_singular() ) {
		return;
	}

	$post = get_queried_object();
	if ( ! $post ) {
		return;
	}

	$modified_gmt = (int) get_post_modified_time( 'U', true, $post );
	if ( ! $modified_gmt ) {
		return;
	}

	$modified_http = gmdate( 'D, d M Y H:i:s', $modified_gmt ) . ' GMT';
	header( 'Last-Modified: ' . $modified_http );

	if ( ! empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
		$client_since = strtotime( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) );
		if ( $client_since && $client_since >= $modified_gmt ) {
			// SERVER_PROTOCOL is normally set by the front-end web server,
			// but allowlist defensively — a manipulated value here would
			// be the protocol portion of an HTTP response status line, so
			// CRLF injection could enable response splitting. The status
			// code arg to header() handles the actual status; protocol
			// is just the prefix string.
			$raw_protocol = isset( $_SERVER['SERVER_PROTOCOL'] ) ? wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) : '';
			$protocol     = in_array( $raw_protocol, array( 'HTTP/1.0', 'HTTP/1.1', 'HTTP/2', 'HTTP/2.0', 'HTTP/3' ), true )
				? $raw_protocol
				: 'HTTP/1.1';
			header( $protocol . ' 304 Not Modified', true, 304 );
			exit;
		}
	}
}, 10 );

/**
 * Compute the ETag for an RSS feed request, unique per feed.
 *
 * Pure function (CLI-testable). The ETag must vary per FEED, not just per build
 * timestamp: two distinct feeds (e.g. /feed/ and /notes/feed/) can share a
 * build timestamp, and an ETag built from the timestamp alone would collide —
 * a reader caching one feed could get a 304 false-positive against the other.
 *
 * We fold a stable feed identity into the hash:
 *   - WP_Term       (taxonomy/term archive feed) → "{taxonomy}:{term_id}"
 *   - WP_Post_Type  (post-type archive feed)      → "{post_type}:{name}"
 *   - anything else (main /feed/)                  → "" (empty identity)
 *
 * Inputs are ONLY the build timestamp + feed identity — nothing
 * request-varying — so the ETag is STABLE for a given feed across requests
 * (a strong validator the client can match on If-None-Match).
 *
 * @since 4.8.1
 * @param string $last_modified_http RFC-1123 feed build date (the Last-Modified value).
 * @param mixed  $queried_object     get_queried_object() for the request (WP_Term|WP_Post_Type|null).
 * @return string Quoted strong ETag.
 */
function sn_seo_feed_etag( $last_modified_http, $queried_object ) {
	$feed_id = '';
	if ( $queried_object instanceof WP_Term ) {
		$feed_id = $queried_object->taxonomy . ':' . $queried_object->term_id;
	} elseif ( $queried_object instanceof WP_Post_Type ) {
		$feed_id = 'post_type:' . $queried_object->name;
	}
	return '"' . md5( $last_modified_http . '|' . $feed_id ) . '"';
}

/**
 * Decide the conditional-GET response for an RSS feed request.
 *
 * Pure function (no header/exit side effects) so the decision logic is
 * CLI-testable. Returns:
 *   - null                        when there's no usable validator ($modified_gmt 0)
 *   - array( 'status' => 304 )    when the client's validator is still fresh
 *   - array( 'status' => 200,
 *            'headers' => [...] ) otherwise (Last-Modified + ETag to emit)
 *
 * ETag takes precedence over If-Modified-Since (a strong validator match is
 * definitive). IMS uses >= so an exactly-equal timestamp is treated as fresh.
 *
 * @since 4.8.1
 * @param int    $modified_gmt      Feed build timestamp (Unix, GMT). 0 = unknown.
 * @param string $if_modified_since Raw HTTP_IF_MODIFIED_SINCE value ('' if none).
 * @param string $if_none_match     Raw HTTP_IF_NONE_MATCH value ('' if none).
 * @param string $etag              The computed ETag (quoted) for this feed.
 * @return array|null
 */
function sn_seo_feed_conditional_response( $modified_gmt, $if_modified_since, $if_none_match, $etag ) {
	if ( ! $modified_gmt ) {
		return null;
	}
	if ( $if_none_match && trim( $if_none_match ) === $etag ) {
		return array( 'status' => 304 );
	}
	if ( $if_modified_since && strtotime( $if_modified_since ) >= $modified_gmt ) {
		return array( 'status' => 304 );
	}
	$http = gmdate( 'D, d M Y H:i:s', $modified_gmt ) . ' GMT';
	return array(
		'status'  => 200,
		'headers' => array(
			'Last-Modified' => $http,
			'ETag'          => $etag,
		),
	);
}

/**
 * Conditional-GET (Last-Modified + ETag + 304) for RSS feeds.
 *
 * Mirrors the singular handler above but for feed requests, so well-behaved
 * feed readers (and Google's feed crawler) can skip re-downloading an
 * unchanged feed — saving bandwidth + crawl budget.
 *
 * Hooked at priority 10 so it runs AFTER sn_rss_tracker_capture (priority 1):
 * the tracker must record the hit before we short-circuit with a 304/exit.
 * Gated dormant while TSF is active (it owns feed headers then). Uses
 * get_feed_build_date() so category feeds like /notes/feed/ get an accurate
 * per-feed timestamp.
 *
 * Added in v4.8.1.
 */
add_action( 'template_redirect', function() {
	if ( function_exists( 'the_seo_framework' ) ) {
		return;
	}
	if ( ! is_feed() ) {
		return;
	}

	$ts = (int) get_feed_build_date( 'U' );
	if ( ! $ts ) {
		return;
	}

	$last_modified_http = gmdate( 'D, d M Y H:i:s', $ts ) . ' GMT';
	// v4.8.1: fold feed identity into the ETag so two feeds sharing a build
	// timestamp (e.g. /feed/ and /notes/feed/) don't collide on the same
	// validator. See sn_seo_feed_etag().
	$etag               = sn_seo_feed_etag( $last_modified_http, get_queried_object() );

	$ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : '';
	$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) : '';

	$response = sn_seo_feed_conditional_response( $ts, (string) $ims, (string) $inm, $etag );
	if ( null === $response ) {
		return;
	}

	if ( 304 === $response['status'] ) {
		// SERVER_PROTOCOL allowlist — same CRLF-injection defense as the
		// singular handler above (a manipulated value would prefix the
		// status line, enabling response splitting).
		$raw_protocol = isset( $_SERVER['SERVER_PROTOCOL'] ) ? wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) : '';
		$protocol     = in_array( $raw_protocol, array( 'HTTP/1.0', 'HTTP/1.1', 'HTTP/2', 'HTTP/2.0', 'HTTP/3' ), true )
			? $raw_protocol
			: 'HTTP/1.1';
		header( $protocol . ' 304 Not Modified', true, 304 );
		exit;
	}

	foreach ( $response['headers'] as $name => $value ) {
		header( $name . ': ' . $value );
	}
}, 10 );
