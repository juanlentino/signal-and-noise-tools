<?php
/**
 * Signal & Noise — editor UI enqueue for the ML candidate buttons (v10.19.0).
 *
 * Mounts assets/ml-candidates-ui.js on the post editor, which injects two
 * deterministic-kernel surfaces into the Signal & Noise meta box:
 *   - "Suggest keywords" beside the focus-keyword field: ranked TF-IDF
 *     candidates as clickable chips; a click FILLS the input (the human
 *     still saves — the kernel computes, a person decides).
 *   - "Suggest links": related-but-unlinked notes with resolved permalinks
 *     and copy buttons; nothing touches the body.
 *
 * Deliberately NOT routed through snt_ai_enqueue_editor_script(): that helper
 * gates on snt_ai_is_available(), and these buttons are pure kernel — they
 * must appear even where the AI client is absent. Structure otherwise mirrors
 * it exactly (post edit screens only, edit_posts, the shared dep set).
 * Additional gate: post type 'post' only — the ML corpus is posts; the
 * meta box also renders on pages, where these buttons would only 404.
 *
 * Transport: window.sntAbilityRun (annotation-correct verbs, no hardcoded
 * paths — tests/ability-run-client.php's transport guard applies).
 *
 * @package SignalNoiseTools @since 10.19.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the candidates UI on post-edit screens for posts.
 *
 * @param string $hook_suffix The admin_enqueue_scripts hook suffix.
 * @return void
 */
function snt_ml_candidates_ui_enqueue( $hook_suffix ) {
	if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
		return;
	}
	// The ML corpus is posts. Absent typenow fails closed.
	$typenow = isset( $GLOBALS['typenow'] ) ? (string) $GLOBALS['typenow'] : '';
	if ( 'post' !== $typenow ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	wp_register_script(
		'snt-ml-candidates-ui',
		plugins_url( 'assets/ml-candidates-ui.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch', 'wp-i18n', 'snt-status', 'snt-ability-run' ),
		SNT_VERSION,
		true
	);
	wp_enqueue_script( 'snt-ml-candidates-ui' );

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'snt-ml-candidates-ui', 'signal-and-noise-tools' );
	}
}
add_action( 'admin_enqueue_scripts', 'snt_ml_candidates_ui_enqueue' );
