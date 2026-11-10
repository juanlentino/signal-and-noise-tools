<?php
/**
 * Signal & Noise Tools: trims on the official AI plugin, where one of its
 * features would touch a signed note without a person reading the result, or
 * advertise a second copy of something this site already serves.
 *
 * Owner rule (restated 2026-11-09): S&N complements and enhances what core and
 * the official plugins do. Each trim here is the smallest one: the feature
 * stays, one surface of it goes. The four whole-feature locks, with their
 * reasons, are in inc/ai-ai-dedupe.php. Read at ai/ai 1.5.0.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content Summarization keeps its editor button (a person reads the summary
 * before saving). Its bulk action writes model text into up to 100 selected
 * posts with no review; on a published note each save is a provenance version
 * minted outside sn-apply. The menu entry is removed and a request for it is
 * left unhandled.
 *
 * @param array<string,string> $actions Bulk actions.
 * @return array<string,string>
 */
function snt_ai_no_bulk_summary( $actions ) {
	if ( is_array( $actions ) ) {
		unset( $actions['wpai_generate_summary'] );
	}
	return $actions;
}

/**
 * Unhook one method of an official AI plugin object from a hook. The plugin
 * keeps its instances private, so the callback is found by class and method.
 *
 * @param string $hook   Hook name.
 * @param string $class  Fully qualified class of the object.
 * @param string $method Method name.
 * @return int|null The priority it was hooked at, null when it was not found.
 */
function snt_ai_unhook_official( $hook, $class, $method ) {
	$h = $GLOBALS['wp_filter'][ $hook ] ?? null;
	foreach ( is_object( $h ) ? (array) $h->callbacks : array() as $priority => $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$fn = $cb['function'] ?? null;
			if ( is_array( $fn ) && is_object( $fn[0] ) && $fn[0] instanceof $class && $method === $fn[1] ) {
				remove_filter( $hook, $fn, $priority );
				return (int) $priority;
			}
		}
	}
	return null;
}

// Priority 20: after the AI plugin registered its two bulk hooks on this screen (10).
add_action( 'load-edit.php', function () {
	$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing, as the AI plugin does.
	$class = 'WordPress\\AI\\Experiments\\Summarization\\Summarization';
	add_filter( "bulk_actions-edit-{$type}", 'snt_ai_no_bulk_summary', 999 );
	snt_ai_unhook_official( "handle_bulk_actions-edit-{$type}", $class, 'handle_bulk_action' ); // a crafted request goes nowhere.
}, 20 );

/**
 * A note already advertises its Markdown: the theme's `.md` alternate, served
 * at the edge with the provenance record. Markdown Feeds prints a second
 * per-post alternate to a different document (`?output_format=markdown`) from
 * the same method that prints the feed link, with no filter between them. On a
 * note, that method is unhooked and the feed link alone is printed in its
 * place. Pages, which have no `.md`, keep both. The document itself stays
 * reachable; it is only no longer advertised.
 */
function snt_ai_notes_one_markdown_alternate() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	$priority = snt_ai_unhook_official( 'wp_head', 'WordPress\\AI\\Experiments\\Markdown_Feeds\\Markdown_Feeds', 'add_discovery_links' );
	if ( null !== $priority ) {
		add_action( 'wp_head', 'snt_ai_markdown_feed_link', $priority );
	}
}
add_action( 'wp_head', 'snt_ai_notes_one_markdown_alternate', 0 );

/** The feed link, as Markdown Feeds prints it. */
function snt_ai_markdown_feed_link() {
	printf(
		'<link rel="alternate" type="text/markdown" title="%s" href="%s" />' . "\n",
		/* translators: %s: site name. */
		esc_attr( sprintf( __( '%s Markdown Feed', 'signal-and-noise-tools' ), get_bloginfo( 'name' ) ) ),
		esc_url( get_feed_link( 'markdown' ) )
	);
}
