<?php
/**
 * Signal & Noise Tools — pre-publish mistake gate (client-side, no AI).
 *
 * Enqueues a small editor script that registers a PluginPrePublishPanel
 * (from @wordpress/editor) listing *advisory* warnings before a post is
 * published — the kind of mistakes that are silent until weeks later:
 *
 *   - "noindex" is left ON (post & page)
 *   - the SN meta description is empty (post & page)
 *   - the post has zero tags (post only)
 *
 * Advisory only: it never calls `lockPostSaving`, so the author can publish
 * regardless. It's a nudge, not a wall.
 *
 * Why this file is pure-enqueue (mirror of inc/command-palette.php): the
 * panel is built entirely client-side from `wp.data.select('core/editor')`
 * — there is no PHP render path. We just register the JS that calls
 *   wp.plugins.registerPlugin( 'snt-pre-publish-gate', { render } )
 * with the right dependency set so wp-plugins / wp-editor are present.
 *
 * Verified against (2026-06-07):
 *   - WordPress/gutenberg packages/editor/src/components/index.js
 *     → `export { default as PluginPrePublishPanel } from './plugin-pre-publish-panel';`
 *   - .../plugin-pre-publish-panel/index.js → props { children, className,
 *     title, initialOpen, icon }; children render in a <PanelBody>.
 *   - .../store/selectors.js → getCurrentPostType / getEditedPostAttribute
 *     ('meta' merges saved + edits; 'tags' is an array of term ids).
 *
 * Gated on:
 *   - the post.php / post-new.php editor screens (hook suffix) — the only
 *     screens that load the block editor for a single post/page
 *   - current_user_can( 'edit_posts' ) — anyone who can reach the editor
 *
 * Deliberately NOT gated on snt_ai_is_available(): this gate is 100%
 * client-side, makes zero AI/network calls, and must work even when the
 * AI key is absent or the model is unreachable.
 *
 * @package SignalNoiseTools
 * @since 4.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
	// Only the single-post/page block editor screens.
	if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
		return;
	}

	// Anyone who can edit posts can reach this editor; show them the gate.
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	wp_register_script(
		'snt-pre-publish-gate',
		plugins_url( 'assets/pre-publish-gate.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-plugins', 'wp-editor', 'wp-data', 'wp-element', 'wp-i18n' ),
		SNT_VERSION,
		true
	);

	wp_enqueue_script( 'snt-pre-publish-gate' );

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'snt-pre-publish-gate', 'signal-noise-tools' );
	}
} );
