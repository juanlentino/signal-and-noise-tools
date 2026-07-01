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
 * DISMISS REST ROUTE + NOTICE JS
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function () {
	register_rest_route( 'signal-noise/v1', '/prepop/dismiss', array(
		'methods'             => 'POST',
		'callback'            => 'snt_prepop_dismiss_rest_handler',
		'permission_callback' => 'snt_prepop_dismiss_rest_permission',
		'args'                => array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
		),
	) );
} );

/**
 * @param WP_REST_Request $request
 * @return bool
 */
function snt_prepop_dismiss_rest_permission( $request ) {
	return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
}

/**
 * @param WP_REST_Request $request
 * @return array
 */
function snt_prepop_dismiss_rest_handler( $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	sn_prepop_clear_sentinels( $post_id );
	return rest_ensure_response( array( 'ok' => true ) );
}

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
		array( 'wp-api-fetch' ),
		SNT_VERSION,
		true
	);
	wp_localize_script( 'snt-prepop-notice', 'sntPrepopNotice', array(
		// v6.55.0: point the dismiss JS at the signal-noise/prepop-dismiss
		// ability run-path so the legacy /prepop/dismiss route becomes caller-free.
		'restPath' => '/wp-abilities/v1/abilities/signal-noise/prepop-dismiss/run',
	) );
	wp_enqueue_script( 'snt-prepop-notice' );
} );
