<?php
/**
 * Signal & Noise Tools: the indexing guard reads the site chrome (2026-10-22).
 *
 * The link graph reads post bodies only, so pages the header nav or footer
 * meta-nav link from EVERY page read as unlinked. This renders the theme's
 * header and footer parts through core (get_block_template() + do_blocks(),
 * never HTTP), reads hrefs with WP_HTML_Tag_Processor, and caches the paths
 * keyed on stylesheet, version and the parts' content. If rendering throws
 * (cron), the block markup is the fallback: navigation-link urls, a
 * navigation ref's links, and the plugin blocks (meta-nav) via render_block().
 *
 * Spanish: the theme's lang-chrome swaps header hrefs to the Spanish twin on
 * a Spanish page, and rendering that needs a Spanish request. So a page whose
 * English counterpart (_sn_en_counterpart) is chrome-linked counts as
 * chrome-linked. The footer's pages have no Spanish twins, so no over-count.
 * Notes never borrow the chrome: they are judged on body links only.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/ml-link-isolation.php'; // snt_ml_link_target_path(), snt_ml_path_key().

const SN_INDEXING_CHROME_PARTS = array( 'header', 'footer' );
const SN_INDEXING_CHROME_CACHE = 'sn_indexing_chrome_paths';

/**
 * Internal paths an HTML fragment links to, in lookup form, sorted, unique.
 *
 * @param string      $html Rendered markup.
 * @param string|null $host Site host.
 * @return string[]
 */
function sn_indexing_chrome_paths_from_html( $html, $host = null ) {
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || '' === (string) $html ) {
		return array();
	}
	$tags  = new WP_HTML_Tag_Processor( (string) $html );
	$paths = array();
	while ( $tags->next_tag( 'A' ) ) {
		$href = $tags->get_attribute( 'href' );
		$path = is_string( $href ) ? snt_ml_link_target_path( $href, $host ) : '';
		if ( '' !== $path ) {
			$paths[ $path ] = true;
		}
	}
	$paths = array_keys( $paths );
	sort( $paths );
	return $paths;
}

/**
 * The cache key: theme stylesheet, theme version and the parts' content. PURE.
 *
 * @param string   $stylesheet Theme stylesheet.
 * @param string   $version    Theme version.
 * @param string[] $contents   The parts' block markup.
 * @return string
 */
function sn_indexing_chrome_key( $stylesheet, $version, $contents ) {
	return md5( $stylesheet . '|' . $version . '|' . md5( implode( "\0", (array) $contents ) ) );
}

/**
 * The fallback markup: links the blocks name in their attributes, plus the
 * render of a navigation ref and of the plugin-rendered blocks.
 *
 * @param array $blocks parse_blocks() output.
 * @return string
 */
function sn_indexing_chrome_fallback_html( $blocks ) {
	$html = '';
	foreach ( (array) $blocks as $b ) {
		$name = (string) ( $b['blockName'] ?? '' );
		if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) && ! empty( $b['attrs']['url'] ) ) {
			$html .= '<a href="' . esc_url( (string) $b['attrs']['url'] ) . '"></a>';
		} elseif ( 'core/navigation' === $name && ! empty( $b['attrs']['ref'] ) && function_exists( 'get_post' ) ) {
			$nav   = get_post( (int) $b['attrs']['ref'] );
			$html .= $nav ? sn_indexing_chrome_fallback_html( parse_blocks( (string) $nav->post_content ) ) : '';
		} elseif ( 0 === strpos( $name, 'signal-noise/' ) ) {
			$html .= (string) render_block( $b );
		}
		$html .= sn_indexing_chrome_fallback_html( $b['innerBlocks'] ?? array() );
	}
	return $html;
}

/**
 * The chrome-linked paths, live: rendered once per change of theme or parts.
 * An empty set is never cached, so a failed read is retried, not pinned.
 *
 * @return string[]
 */
function sn_indexing_chrome_paths() {
	if ( ! function_exists( 'get_block_template' ) || ! function_exists( 'do_blocks' ) ) {
		return array();
	}
	$contents = array();
	foreach ( SN_INDEXING_CHROME_PARTS as $slug ) {
		$part              = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template_part' );
		$contents[ $slug ] = $part ? (string) $part->content : '';
	}
	if ( '' === implode( '', $contents ) ) {
		return array();
	}
	$key    = sn_indexing_chrome_key( get_stylesheet(), (string) wp_get_theme()->get( 'Version' ), $contents );
	$cached = get_transient( SN_INDEXING_CHROME_CACHE );
	if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === $key && ! empty( $cached['paths'] ) ) {
		return (array) $cached['paths'];
	}
	$level = ob_get_level();
	ob_start(); // a block that echoes still lands in the markup.
	$html = '';
	try {
		foreach ( $contents as $content ) {
			$html .= do_blocks( $content );
		}
		$html .= (string) ob_get_contents();
	} catch ( Throwable $e ) {
		$html = '';
	}
	while ( ob_get_level() > $level ) {
		ob_end_clean();
	}
	$paths = sn_indexing_chrome_paths_from_html( $html );
	if ( array() === $paths ) {
		$paths = sn_indexing_chrome_paths_from_html( sn_indexing_chrome_fallback_html( parse_blocks( implode( '', $contents ) ) ) );
	}
	if ( array() !== $paths ) {
		set_transient( SN_INDEXING_CHROME_CACHE, array( 'key' => $key, 'paths' => $paths ), WEEK_IN_SECONDS );
	}
	return $paths;
}

/**
 * The chrome set widened by the Spanish twins of chrome-linked pages. PURE.
 *
 * @param string[] $chrome   Lookup paths.
 * @param array    $subjects Rows with path and en_path.
 * @return string[]
 */
function sn_indexing_chrome_expand( $chrome, $subjects ) {
	$set = array_fill_keys( array_map( 'snt_ml_path_key', (array) $chrome ), true );
	if ( array() === $set ) {
		return array();
	}
	foreach ( (array) $subjects as $s ) {
		if ( '' !== (string) ( $s['en_path'] ?? '' ) && isset( $set[ snt_ml_path_key( $s['en_path'] ) ] ) ) {
			$set[ snt_ml_path_key( $s['path'] ) ] = true;
		}
	}
	$out = array_keys( $set );
	sort( $out );
	return $out;
}

/**
 * Is this path (or its English counterpart) in the chrome set? PURE.
 *
 * @param string[] $chrome  Lookup paths.
 * @param string   $path    Join path.
 * @param string   $en_path English counterpart's join path, or ''.
 * @return bool
 */
function sn_indexing_chrome_has( $chrome, $path, $en_path = '' ) {
	foreach ( array_filter( array( (string) $path, (string) $en_path ) ) as $p ) {
		if ( in_array( snt_ml_path_key( $p ), (array) $chrome, true ) ) {
			return true;
		}
	}
	return false;
}

if ( function_exists( 'add_action' ) ) {
	foreach ( array( 'switch_theme', 'upgrader_process_complete', 'customize_save_after', 'save_post_wp_template_part', 'save_post_wp_navigation' ) as $sn_chrome_hook ) {
		add_action( $sn_chrome_hook, static function () {
			delete_transient( SN_INDEXING_CHROME_CACHE );
		} );
	}
	unset( $sn_chrome_hook );
}
