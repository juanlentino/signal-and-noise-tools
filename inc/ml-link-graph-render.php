<?php
/**
 * Signal & Noise: the link graph reads RENDERED bodies (2026-10-22).
 *
 * Raw post_content misses every link a dynamic block or a shortcode prints:
 * the /maturity hub's index cards, the pillar list on /provenance, any
 * server-rendered list. Each source body is rendered through core the way
 * the_content does it (do_blocks(), then do_shortcode()), with the post set
 * up as the global post, and its hrefs read with WP_HTML_Tag_Processor.
 * While rendering, pre_http_request short-circuits, so a block can never
 * reach the network from here. A render that throws falls back to the raw
 * body for that post only.
 *
 * Cached in one transient, per post, keyed on post_modified_gmt plus the
 * plugin and theme versions; cleared on a theme switch or an update.
 * ponytail: a body whose rendered links depend on OTHER posts (the maturity
 * index resolves page URLs) refreshes when it is edited, on an update, or
 * when the week-long transient lapses; key on a corpus hash if that matters.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_ML_RENDER_CACHE = 'snt_ml_rendered_links';

/**
 * Internal paths an HTML fragment links to, in lookup form, sorted, unique.
 *
 * @param string      $html Markup.
 * @param string|null $host Site host.
 * @return string[]
 */
function snt_ml_paths_from_html( $html, $host = null ) {
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
 * One body rendered the way the_content renders it, or null when it threw.
 *
 * @param object $post WP_Post-like.
 * @return string|null
 */
function snt_ml_render_body( $post ) {
	$prev    = $GLOBALS['post'] ?? null;
	$no_http = static function () {
		return new WP_Error( 'snt_link_graph_render', 'No HTTP while reading rendered links.' );
	};
	add_filter( 'pre_http_request', $no_http, 1 );
	$level = ob_get_level();
	ob_start(); // a block that echoes still lands in the markup.
	try {
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		setup_postdata( $post );
		$html = do_shortcode( do_blocks( (string) ( $post->post_content ?? '' ) ) ) . (string) ob_get_contents();
	} catch ( Throwable $e ) {
		$html = null;
	}
	while ( ob_get_level() > $level ) {
		ob_end_clean();
	}
	remove_filter( 'pre_http_request', $no_http, 1 );
	$GLOBALS['post'] = $prev; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the caller's post, back.
	if ( is_object( $prev ) ) {
		setup_postdata( $prev );
	}
	return $html;
}

/**
 * Rendered outbound paths per post id, null where the render failed (the
 * graph then parses the raw body). Empty array when rendering is unavailable.
 *
 * @param array  $posts WP_Post-like objects.
 * @param string $host  Site host.
 * @return array<int,string[]|null>
 */
function snt_ml_rendered_links( $posts, $host ) {
	$GLOBALS['snt_ml_render_stats'] = array( 'rendered' => 0, 'cached' => 0, 'failed' => 0, 'ms' => 0 );
	if ( ! function_exists( 'do_blocks' ) || ! function_exists( 'do_shortcode' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return array();
	}
	$t0      = microtime( true );
	$version = ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '' ) . '|' . get_stylesheet() . '|' . (string) wp_get_theme()->get( 'Version' );
	$cache   = get_transient( SNT_ML_RENDER_CACHE );
	$cache   = is_array( $cache ) && ( $cache['v'] ?? '' ) === $version ? $cache : array( 'v' => $version, 'posts' => array() );
	$out     = array();
	$dirty   = false;
	foreach ( (array) $posts as $post ) {
		$id  = (int) $post->ID;
		$key = (string) ( $post->post_modified_gmt ?? '' );
		$hit = $cache['posts'][ $id ] ?? null;
		if ( is_array( $hit ) && $hit['k'] === $key ) {
			++$GLOBALS['snt_ml_render_stats']['cached'];
			$out[ $id ] = $hit['p'];
			continue;
		}
		$html  = snt_ml_render_body( $post );
		$paths = null === $html ? null : snt_ml_paths_from_html( $html, $host );
		++$GLOBALS['snt_ml_render_stats'][ null === $html ? 'failed' : 'rendered' ];
		$cache['posts'][ $id ] = array( 'k' => $key, 'p' => $paths );
		$out[ $id ]            = $paths;
		$dirty                 = true;
	}
	if ( $dirty ) {
		set_transient( SNT_ML_RENDER_CACHE, $cache, WEEK_IN_SECONDS );
	}
	$GLOBALS['snt_ml_render_stats']['ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
	return $out;
}

/**
 * The last build's render cost: rendered, cached, failed, ms.
 *
 * @return array{rendered:int,cached:int,failed:int,ms:int}
 */
function snt_ml_render_stats() {
	return $GLOBALS['snt_ml_render_stats'] ?? array( 'rendered' => 0, 'cached' => 0, 'failed' => 0, 'ms' => 0 );
}

if ( function_exists( 'add_action' ) ) {
	foreach ( array( 'switch_theme', 'upgrader_process_complete' ) as $snt_render_hook ) {
		add_action( $snt_render_hook, static function () {
			delete_transient( SNT_ML_RENDER_CACHE );
		} );
	}
	unset( $snt_render_hook );
}
