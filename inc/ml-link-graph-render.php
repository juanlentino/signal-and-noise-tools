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
 * The style and script queues, so a render's front-end enqueues can be undone.
 *
 * @return array<string,object>
 */
function snt_ml_render_deps() {
	$deps = array();
	foreach ( array( 'wp_styles', 'wp_scripts' ) as $fn ) {
		if ( function_exists( $fn ) ) {
			$deps[ $fn ] = $fn();
		}
	}
	return $deps;
}

/**
 * One body rendered the way the_content renders it, or null when it threw.
 * Everything it touches is put back in finally: the HTTP short-circuit, the
 * global post, the output buffers, and the style and script queues (a
 * maturity shortcode enqueues a front-end stylesheet that must not print in
 * admin).
 *
 * @param object $post WP_Post-like.
 * @return string|null
 */
function snt_ml_render_body( $post ) {
	$prev    = $GLOBALS['post'] ?? null;
	$no_http = static function () {
		return new WP_Error( 'snt_link_graph_render', 'No HTTP while reading rendered links.' );
	};
	$deps   = snt_ml_render_deps();
	$queues = array();
	foreach ( $deps as $fn => $d ) {
		$queues[ $fn ] = array( (array) $d->queue, (array) ( $d->to_do ?? array() ), (array) ( $d->done ?? array() ) );
	}
	add_filter( 'pre_http_request', $no_http, 1 );
	$level = ob_get_level();
	ob_start(); // a block that echoes still lands in the markup.
	try {
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		setup_postdata( $post );
		$html = do_shortcode( do_blocks( (string) ( $post->post_content ?? '' ) ) ) . (string) ob_get_contents();
	} catch ( Throwable $e ) {
		$html = null;
	} finally {
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		remove_filter( 'pre_http_request', $no_http, 1 );
		foreach ( $deps as $fn => $d ) {
			$d->queue = $queues[ $fn ][0];
			if ( property_exists( $d, 'to_do' ) ) {
				$d->to_do = $queues[ $fn ][1];
				$d->done  = $queues[ $fn ][2];
			}
		}
		$GLOBALS['post'] = $prev; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the caller's post, back.
		if ( is_object( $prev ) ) {
			setup_postdata( $prev );
		}
	}
	return $html;
}

/** Seconds one build may spend rendering; the rest read raw until the next call. */
const SNT_ML_RENDER_BUDGET = 15.0;
/** Renders between transient writes, so a build cut short keeps its progress. */
const SNT_ML_RENDER_PERSIST_EVERY = 20;

/**
 * Rendered outbound paths per post id; null where the render failed or the
 * time budget ran out (the graph then parses that raw body). A failure is
 * never cached, so it is retried. Empty array when rendering is unavailable.
 *
 * @param array         $posts  WP_Post-like objects.
 * @param string        $host   Site host.
 * @param float         $budget Seconds allowed for rendering.
 * @param callable|null $clock  Test seam: returns seconds; microtime(true) by default.
 * @return array<int,string[]|null>
 */
function snt_ml_rendered_links( $posts, $host, $budget = SNT_ML_RENDER_BUDGET, $clock = null ) {
	$stats = array( 'rendered' => 0, 'cached' => 0, 'failed' => 0, 'ms' => 0, 'budget_hit' => false, 'remaining' => 0 );
	$GLOBALS['snt_ml_render_stats'] = $stats;
	if ( ! function_exists( 'do_blocks' ) || ! function_exists( 'do_shortcode' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return array();
	}
	$clock    = is_callable( $clock ) ? $clock : static function () {
		return microtime( true );
	};
	$t0       = microtime( true );
	$deadline = (float) $clock() + (float) $budget;
	$version  = ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '' ) . '|' . get_stylesheet() . '|' . (string) wp_get_theme()->get( 'Version' );
	$cache    = get_transient( SNT_ML_RENDER_CACHE );
	$cache    = is_array( $cache ) && ( $cache['v'] ?? '' ) === $version ? $cache : array( 'v' => $version, 'posts' => array() );
	$out      = array();
	$unsaved  = 0;
	foreach ( (array) $posts as $post ) {
		$id  = (int) $post->ID;
		$key = (string) ( $post->post_modified_gmt ?? '' );
		$hit = $cache['posts'][ $id ] ?? null;
		if ( is_array( $hit ) && $hit['k'] === $key ) {
			++$stats['cached'];
			$out[ $id ] = $hit['p'];
			continue;
		}
		if ( $stats['budget_hit'] || (float) $clock() >= $deadline ) {
			$stats['budget_hit'] = true;
			++$stats['remaining'];
			$out[ $id ] = null;
			continue;
		}
		$html       = snt_ml_render_body( $post );
		$out[ $id ] = null === $html ? null : snt_ml_paths_from_html( $html, $host );
		if ( null === $html ) {
			++$stats['failed'];
			continue;
		}
		++$stats['rendered'];
		$cache['posts'][ $id ] = array( 'k' => $key, 'p' => $out[ $id ] );
		if ( ++$unsaved >= SNT_ML_RENDER_PERSIST_EVERY ) {
			set_transient( SNT_ML_RENDER_CACHE, $cache, WEEK_IN_SECONDS );
			$unsaved = 0;
		}
	}
	if ( $unsaved > 0 ) {
		set_transient( SNT_ML_RENDER_CACHE, $cache, WEEK_IN_SECONDS );
	}
	$stats['ms']                    = (int) round( ( microtime( true ) - $t0 ) * 1000 );
	$GLOBALS['snt_ml_render_stats'] = $stats;
	return $out;
}

/**
 * The last build's render cost: rendered, cached, failed, ms, and whether the
 * time budget cut it short (budget_hit; remaining read raw this time).
 *
 * @return array{rendered:int,cached:int,failed:int,ms:int,budget_hit:bool,remaining:int}
 */
function snt_ml_render_stats() {
	return $GLOBALS['snt_ml_render_stats'] ?? array( 'rendered' => 0, 'cached' => 0, 'failed' => 0, 'ms' => 0, 'budget_hit' => false, 'remaining' => 0 );
}

if ( function_exists( 'add_action' ) ) {
	foreach ( array( 'switch_theme', 'upgrader_process_complete' ) as $snt_render_hook ) {
		add_action( $snt_render_hook, static function () {
			delete_transient( SNT_ML_RENDER_CACHE );
		} );
	}
	unset( $snt_render_hook );
}
