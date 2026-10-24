<?php
/**
 * Signal & Noise Tools: SEO, the head emission (the canonical, what core's
 * robots meta and canonical are fed, the description tag, the document title).
 *
 * Split from inc/seo.php (house line cap, 2026-10-14). The file header there
 * describes the module. Loaded by require_once at the end of that file, in the
 * order the code always ran.
 *
 * Wave 2 (2026-10-24): core prints the robots meta (wp_robots(), wp_head:1)
 * and the singular canonical (rel_canonical(), wp_head:10) again. The plugin
 * removed both from v2.0.2 and printed its own; it now feeds them through the
 * wp_robots and get_canonical_url filters, so core's blog_public, search,
 * embed and replytocom noindex and its <!--nextpage--> canonical all run.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO: Canonical URL on the views core prints none for: the posts page,
 * /notes/tags/ and tag archives, each with its paged self-canonical. A
 * singular view is rel_canonical()'s, fed by sn_seo_canonical_url() below.
 */
add_action( 'wp_head', function() {
	if ( is_singular() ) {
		return;
	}

	list( , , $url ) = sn_seo_meta_for_current_view();
	if ( $url ) {
		echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
	}
}, 1 );

/**
 * get_canonical_url filter: what core's rel_canonical() prints for a post.
 *
 *   - the _sn_canonical_url override (v1.10.2: republished content whose
 *     canonical lives at the original publisher);
 *   - nothing on MailPoet's subscription page (inc/seo-noindex-parts.php);
 *   - on the /notes/ Page, each page of the list self-canonicals with
 *     ?paged=N (v5.1.0), a query var core's canonical does not read.
 *
 * Anything else keeps core's URL, pagination and comment pages included.
 *
 * @param string  $url  Core's canonical URL.
 * @param WP_Post $post The post.
 * @return string
 */
function sn_seo_canonical_url( $url, $post ) {
	$override = function_exists( 'sn_post_settings_get_canonical_url' ) ? (string) sn_post_settings_get_canonical_url( $post->ID ) : '';
	if ( '' !== $override ) {
		return $override;
	}
	if ( 'mailpoet_page' === $post->post_type ) {
		return '';
	}
	if ( (int) $post->ID === (int) get_queried_object_id() && is_page( 'notes' ) && sn_seo_current_paged() > 1 ) {
		list( , , $url ) = sn_seo_meta_for_current_view();
	}
	return $url;
}
add_filter( 'get_canonical_url', 'sn_seo_canonical_url', 10, 2 );

/**
 * SEO: Robots meta.
 *
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
 * v9.88.0: the list passes through the `sn_seo_robots_directives` filter, the
 * seam the theme uses to add directives for the routes it owns (its notes
 * search noindex). It stays the seam: sn_seo_wp_robots() hands the list to
 * core's wp_robots filter, so a listener here still reaches the printed tag.
 * Listener returns are normalized: a non-array is ignored, duplicates
 * collapse, the result stays list-shaped.
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

	// Always-present permissive defaults ("no restrictions").
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

	// 2026-10: MailPoet's subscription page stays out of the index
	// (inc/seo-noindex-parts.php sends the matching header).
	if ( function_exists( 'sn_seo_is_mailpoet_request' ) && sn_seo_is_mailpoet_request() ) {
		$directives[] = 'noindex';
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

/**
 * wp_robots filter: fold the directive list into core's map, after core's own
 * callbacks (priority 10). 'max-snippet:-1' becomes 'max-snippet' => '-1', a
 * bare 'noindex' becomes 'noindex' => true.
 *
 * Only inside wp_head: core also calls wp_robots() on login_head, embed_head
 * and from wp_die(), where the query may not exist (core strips its own
 * query-dependent callbacks there) and the plugin never printed anything.
 *
 * @param array $robots Core's directives.
 * @return array
 */
function sn_seo_wp_robots( $robots ) {
	if ( ! doing_action( 'wp_head' ) ) {
		return $robots;
	}
	$robots = (array) $robots;
	foreach ( sn_seo_robots_directives() as $directive ) {
		$parts               = explode( ':', $directive, 2 );
		$robots[ $parts[0] ] = $parts[1] ?? true;
	}
	// One answer on links: a nofollow (the post flag, core on a private site)
	// outranks the follow core and the theme pair with a search noindex.
	if ( ! empty( $robots['nofollow'] ) ) {
		unset( $robots['follow'] );
	}
	return $robots;
}
add_filter( 'wp_robots', 'sn_seo_wp_robots', 20 );

/**
 * SEO: Meta description tag.
 */
add_action( 'wp_head', function() {
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
 * Added in plugin v2.0.0 (Phase 13 TSF cutover).
 */
add_filter( 'document_title_parts', function( $parts ) {
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
