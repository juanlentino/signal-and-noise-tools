<?php
/**
 * Signal & Noise Tools: the indexing guard reads the site chrome and the
 * templates (2026-10-22).
 *
 * The link graph reads post bodies, so links the theme prints around them
 * were invisible. Three sets, cached together:
 *
 *   chrome    the header and footer parts, RENDERED through core
 *             (get_block_template() + do_blocks(), never HTTP), hrefs read
 *             with WP_HTML_Tag_Processor: links from every page. If rendering
 *             throws (cron), the parts' block markup is the fallback.
 *   template  the single (notes) and page templates, outside core/post-content:
 *             links from every indexed page of that type.
 *   home      the front-page template (else home) outside core/post-content:
 *             a link from the home page.
 *
 * Templates are read from their block markup, not rendered: single.html holds
 * per-note blocks (related notes, cited-by) whose links differ on every note,
 * so a render without a note would count links that are not on every note.
 * Their own template parts are walked (header and footer are the chrome).
 *
 * Spanish: the theme's lang-chrome swaps header hrefs to the Spanish twin on
 * a Spanish page, and rendering that needs a Spanish request. So a page whose
 * English counterpart (_sn_en_counterpart) is chrome-linked counts as
 * chrome-linked. The footer's pages have no Spanish twins, so no over-count.
 *
 * Keyed on stylesheet, theme version and every read part and template's
 * content; cleared on a theme switch, an update, a Customizer save, and a
 * template, part or navigation save. An empty read is never cached.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/ml-link-isolation.php'; // snt_ml_paths_from_html(), snt_ml_path_key().

const SN_INDEXING_CHROME_PARTS = array( 'header', 'footer' );
const SN_INDEXING_CHROME_CACHE = 'sn_indexing_chrome_paths';

/**
 * The cache key: theme stylesheet, theme version and the content read. PURE.
 *
 * @param string   $stylesheet Theme stylesheet.
 * @param string   $version    Theme version.
 * @param string[] $contents   Block markup.
 * @return string
 */
function sn_indexing_chrome_key( $stylesheet, $version, $contents ) {
	return md5( $stylesheet . '|' . $version . '|' . md5( implode( "\0", (array) $contents ) ) );
}

/**
 * Block markup of the active theme's template or part, or ''.
 *
 * @param string $slug Slug.
 * @param string $type wp_template or wp_template_part.
 * @return string
 */
function sn_indexing_theme_markup( $slug, $type ) {
	$t = get_block_template( get_stylesheet() . '//' . $slug, $type );
	return $t ? (string) $t->content : '';
}

/**
 * Static links in block markup: each block's own HTML and navigation-link
 * urls, never core/post-content, header or footer parts; nested parts are
 * walked; with $render, plugin blocks render (the meta-nav).
 *
 * @param array $blocks parse_blocks() output.
 * @param bool  $render Render signal-noise/* blocks.
 * @param int   $depth  Nesting guard.
 * @return string
 */
function sn_indexing_blocks_html( $blocks, $render = false, $depth = 0 ) {
	$html = '';
	foreach ( (array) $blocks as $b ) {
		$name = (string) ( $b['blockName'] ?? '' );
		$slug = (string) ( $b['attrs']['slug'] ?? '' );
		if ( 'core/post-content' === $name || $depth > 3 ) {
			continue;
		}
		if ( 'core/template-part' === $name ) {
			$html .= in_array( $slug, SN_INDEXING_CHROME_PARTS, true ) ? '' : sn_indexing_blocks_html( parse_blocks( sn_indexing_theme_markup( $slug, 'wp_template_part' ) ), $render, $depth + 1 );
			continue;
		}
		if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) && ! empty( $b['attrs']['url'] ) ) {
			$html .= '<a href="' . esc_url( (string) $b['attrs']['url'] ) . '"></a>';
		} elseif ( 'core/navigation' === $name && ! empty( $b['attrs']['ref'] ) && function_exists( 'get_post' ) ) {
			$nav   = get_post( (int) $b['attrs']['ref'] );
			$html .= $nav ? sn_indexing_blocks_html( parse_blocks( (string) $nav->post_content ), $render, $depth + 1 ) : '';
		} elseif ( $render && 0 === strpos( $name, 'signal-noise/' ) ) {
			$html .= (string) render_block( $b );
		}
		$html .= (string) ( $b['innerHTML'] ?? '' ) . sn_indexing_blocks_html( $b['innerBlocks'] ?? array(), $render, $depth );
	}
	return $html;
}

/**
 * The header and footer rendered through core; '' when rendering throws.
 *
 * @param string[] $parts Block markup.
 * @return string
 */
function sn_indexing_chrome_render( $parts ) {
	$level = ob_get_level();
	ob_start(); // a block that echoes still lands in the markup.
	try {
		$html = '';
		foreach ( $parts as $content ) {
			$html .= do_blocks( $content );
		}
		$html .= (string) ob_get_contents();
	} catch ( Throwable $e ) {
		$html = '';
	}
	while ( ob_get_level() > $level ) {
		ob_end_clean();
	}
	return $html;
}

/**
 * The site's links outside post bodies, live and cached.
 *
 * @return array{chrome:string[],template:array{post:string[],page:string[]},home:string[]}
 */
function sn_indexing_site_links() {
	$empty = array( 'chrome' => array(), 'template' => array( 'post' => array(), 'page' => array() ), 'home' => array() );
	if ( ! function_exists( 'get_block_template' ) || ! function_exists( 'do_blocks' ) ) {
		return $empty;
	}
	$parts = array();
	foreach ( SN_INDEXING_CHROME_PARTS as $slug ) {
		$parts[ $slug ] = sn_indexing_theme_markup( $slug, 'wp_template_part' );
	}
	$front = sn_indexing_theme_markup( 'front-page', 'wp_template' );
	$tpl   = array(
		'post' => sn_indexing_theme_markup( 'single', 'wp_template' ),
		'page' => sn_indexing_theme_markup( 'page', 'wp_template' ),
		'home' => '' !== $front ? $front : sn_indexing_theme_markup( 'home', 'wp_template' ),
	);
	if ( '' === implode( '', $parts ) . implode( '', $tpl ) ) {
		return $empty;
	}
	$key    = sn_indexing_chrome_key( get_stylesheet(), (string) wp_get_theme()->get( 'Version' ), array_merge( array_values( $parts ), array_values( $tpl ) ) );
	$cached = get_transient( SN_INDEXING_CHROME_CACHE );
	if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === $key && is_array( $cached['links'] ?? null ) ) {
		return $cached['links'];
	}
	$chrome = snt_ml_paths_from_html( sn_indexing_chrome_render( $parts ) );
	if ( array() === $chrome ) {
		$chrome = snt_ml_paths_from_html( sn_indexing_blocks_html( parse_blocks( implode( '', $parts ) ), true ) );
	}
	$read  = static function ( $markup ) {
		return '' === $markup ? array() : snt_ml_paths_from_html( sn_indexing_blocks_html( parse_blocks( $markup ) ) );
	};
	$links = array(
		'chrome'   => $chrome,
		'template' => array( 'post' => $read( $tpl['post'] ), 'page' => $read( $tpl['page'] ) ),
		'home'     => $read( $tpl['home'] ),
	);
	if ( $links !== $empty ) {
		set_transient( SN_INDEXING_CHROME_CACHE, array( 'key' => $key, 'links' => $links ), WEEK_IN_SECONDS );
	}
	return $links;
}

if ( function_exists( 'add_action' ) ) {
	foreach ( array( 'switch_theme', 'upgrader_process_complete', 'customize_save_after', 'save_post_wp_template', 'save_post_wp_template_part', 'save_post_wp_navigation' ) as $sn_chrome_hook ) {
		add_action( $sn_chrome_hook, static function () {
			delete_transient( SN_INDEXING_CHROME_CACHE );
		} );
	}
	unset( $sn_chrome_hook );
}

require_once __DIR__ . '/search-console-indexing-site.php'; // the pure side: who links a path from outside the body.
