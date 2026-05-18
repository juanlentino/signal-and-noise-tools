<?php
/**
 * Signal & Noise — SEO + analytics delivery.
 *
 * - Meta description on front page and singular posts
 * - Google Tag (gtag.js) delayed until first user interaction
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
 * Resolve the active page's effective title + description for SEO meta.
 * Returns [ $title, $description, $url ] — any field may be empty string.
 *
 * The dedicated /notes and /provenance entries take precedence over the
 * generic excerpt fallback so the index pages get curated copy instead
 * of WP's auto-excerpt of an empty Page body.
 */
function sn_seo_meta_for_current_view() {
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
	} elseif ( is_page( 'provenance' ) ) {
		$title       = sn_setting( 'seo_copy.provenance_title', '' );
		$description = sn_setting( 'seo_copy.provenance_description', '' );
		$url         = home_url( '/provenance/' );
	} elseif ( is_singular() ) {
		$post  = get_queried_object();
		$title = $post ? wp_strip_all_tags( get_the_title( $post ) ) . ' — ' . sn_setting( 'identity.site_name', get_bloginfo( 'name' ) ) : '';
		if ( $post ) {
			// v1.10.0+: per-post _sn_meta_description override wins over
			// the excerpt. Empty override falls through to excerpt.
			$override = function_exists( 'sn_post_settings_get_description' )
				? sn_post_settings_get_description( $post->ID )
				: '';
			if ( '' !== $override ) {
				$description = $override;
			} elseif ( ! empty( $post->post_excerpt ) ) {
				$description = wp_strip_all_tags( $post->post_excerpt );
			}
		}
		$url = $post ? get_permalink( $post ) : '';
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
 *   _sn_noindex       — adds 'noindex,nofollow'
 *   _sn_noarchive     — adds 'noarchive'    (no cached copy)
 *   _sn_noimageindex  — adds 'noimageindex' (no Google Images)
 */
add_action( 'wp_head', function() {
	$directives = array();

	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post ) {
			// noindex — kept as the v1.6.0 semantic (also implies nofollow).
			$noindex = function_exists( 'sn_post_settings_get_noindex' )
				? sn_post_settings_get_noindex( $post->ID )
				: ( '1' === (string) get_post_meta( $post->ID, '_sn_noindex', true ) );
			if ( $noindex ) {
				$directives[] = 'noindex';
				$directives[] = 'nofollow';
			}

			// noarchive + noimageindex — v1.10.2 standalone flags. Layer
			// on top of (or independent of) noindex.
			if ( function_exists( 'sn_post_settings_get_noarchive' ) && sn_post_settings_get_noarchive( $post->ID ) ) {
				$directives[] = 'noarchive';
			}
			if ( function_exists( 'sn_post_settings_get_noimageindex' ) && sn_post_settings_get_noimageindex( $post->ID ) ) {
				$directives[] = 'noimageindex';
			}
		}
	}

	// Always-present permissive defaults (mirrors TSF's "no restrictions").
	$directives[] = 'max-snippet:-1';
	$directives[] = 'max-image-preview:large';
	$directives[] = 'max-video-preview:-1';

	echo '<meta name="robots" content="' . esc_attr( implode( ',', $directives ) ) . '">' . "\n";
}, 1 );

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
 * Open Graph + Twitter card meta.
 *
 * Emitted on the front page and any singular post/page (including the
 * Notes index when wired as the WP Posts page). The OG image defaults
 * to the existing site logo and is filterable via `sn_og_image_url` so
 * a future per-post or per-route image can be plugged in without
 * touching theme code.
 */
add_action( 'wp_head', function() {
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
		// Dimensions filterable; defaults match our generated /sn-og/ cards (1200x630).
		// Site-icon fallback would be 512x512 but the discrepancy is harmless for crawlers.
		$dims = (array) apply_filters(
			'sn_og_image_dimensions',
			array(
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

	// Article published/modified times on singular posts.
	if ( $is_article ) {
		$post = get_queried_object();
		if ( $post ) {
			$published = get_post_time( 'c', true, $post );
			$modified  = get_post_modified_time( 'c', true, $post );
			if ( $published ) {
				echo '<meta property="article:published_time" content="' . esc_attr( $published ) . '">' . "\n";
			}
			if ( $modified ) {
				echo '<meta property="article:modified_time" content="' . esc_attr( $modified ) . '">' . "\n";
			}
		}
	}
}, 3 );

/**
 * Analytics: Delay Google Tag (gtag.js) until first user interaction.
 * Eliminates 147 KiB from initial page load. Analytics still fires for
 * any user who scrolls, clicks, or touches — only bots and instant
 * bounces are missed, which aren't useful data anyway.
 */
add_action( 'wp_head', function() {
	?>
	<script>
	(function(){var d=!1;function g(){if(!d){d=!0;var s=document.createElement('script');s.src='https://www.googletagmanager.com/gtag/js?id=GT-NMC3GVL';s.async=!0;document.head.appendChild(s);s.onload=function(){window.dataLayer=window.dataLayer||[];function t(){dataLayer.push(arguments)}t('js',new Date());t('config','GT-NMC3GVL')}}}['scroll','click','touchstart','keydown'].forEach(function(e){document.addEventListener(e,g,{once:!0,passive:!0})});setTimeout(g,5000)})();
	</script>
	<?php
}, 10 );

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
