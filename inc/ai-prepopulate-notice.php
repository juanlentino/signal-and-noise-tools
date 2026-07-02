<?php
/**
 * Signal & Noise Tools — AI prepopulation notice + dismiss surface.
 *
 * The "auto-generated at publish" notice rendered inside the SN meta box, its
 * dismiss REST route, and the dismiss JS enqueue. The sentinel state it reads
 * and clears is owned by inc/ai-prepopulate.php (sn_prepop_fields /
 * sn_prepop_clear_sentinels), which is loaded first.
 *
 * @package SignalNoiseTools
 * @since 4.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the consolidated "auto-generated at publish" notice at the top of the
 * SN meta box, listing only the fields whose sentinel is set. Called from
 * sn_post_settings_render(). Emits nothing when no sentinel is set.
 *
 * @param WP_Post $post
 */
function sn_prepop_render_notice( $post ) {
	$labels = array();
	foreach ( sn_prepop_fields() as $sentinel => $label ) {
		if ( '1' === (string) get_post_meta( $post->ID, $sentinel, true ) ) {
			$labels[] = $label;
		}
	}
	if ( empty( $labels ) ) {
		return;
	}
	echo '<div class="sn-prepop-notice notice notice-info" data-post="' . (int) $post->ID . '">';
	echo '<p>' . esc_html( 'Auto-generated when you published: ' . implode( ', ', $labels ) . '.' );
	echo ' <button type="button" class="button-link sn-prepop-dismiss">' . esc_html( 'Dismiss' ) . '</button></p>';
	echo '</div>';
}

/* ════════════════════════════════════════════════════════════════════════
 * NOTICE JS
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'admin_enqueue_scripts', function ( $hook_suffix ) {
	if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	if ( ! function_exists( 'snt_ai_is_available' ) || ! snt_ai_is_available() ) {
		return;
	}
	wp_register_script(
		'snt-prepop-notice',
		plugins_url( 'assets/prepop-notice.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch', 'snt-ability-run' ),
		SNT_VERSION,
		true
	);
	wp_localize_script( 'snt-prepop-notice', 'sntPrepopNotice', array(
		// v7.7.2: restPath removed — the JS dispatches through the shared
		// sntAbilityRun runner (slug-only; hardcoded run paths are guarded
		// against by tests/ability-run-client.php).
	) );
	wp_enqueue_script( 'snt-prepop-notice' );
} );
