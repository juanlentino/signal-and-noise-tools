<?php
/**
 * Signal & Noise Tools — AI prepopulation at publish.
 *
 * On the FIRST transition into published/scheduled, schedule a one-off
 * cron event that auto-fills the meta description, excerpt, and OG card
 * title — but ONLY when each field is empty (never overwrites manual
 * content). Deferred via wp_schedule_single_event so the publish request
 * is never blocked by AI latency.
 *
 * The engine calls the SN *_impl() functions DIRECTLY, not the ability
 * wrappers: the abilities' permission_callback checks current_user_can
 * ('edit_post'), but WP-Cron has no logged-in user, so the ability layer
 * would reject prepop. The impls are the canonical pure functions both the
 * ability and the (deprecated) REST handler delegate to, and they gate
 * only on AI availability.
 *
 * A prepop wp_update_post() (for the excerpt) re-fires save_post, but
 * sn_post_settings_save() early-returns when its nonce is absent (cron has
 * no $_POST), so it cannot wipe SN meta — no re-entrancy guard needed
 * (verified inc/post-settings.php:189-191).
 *
 * @package SignalNoiseTools
 * @since 4.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimum body words before prepop will spend AI calls. Filterable.
 */
if ( ! defined( 'SNT_PREPOP_MIN_WORDS' ) ) {
	define( 'SNT_PREPOP_MIN_WORDS', 50 );
}

/**
 * Schedule prepop on the first transition into publish/scheduled.
 *
 * Mirrors the sn_webhook_on_transition guard: fire only when ENTERING a
 * public/scheduled state from a non-public one (never on re-saves or the
 * future->publish auto-publish, which the empty-only guard covers anyway).
 *
 * @param string  $new_status
 * @param string  $old_status
 * @param WP_Post $post
 */
function snt_prepop_on_transition( $new_status, $old_status, $post ) {
	$public_now    = in_array( $new_status, array( 'publish', 'future' ), true );
	$public_before = in_array( $old_status, array( 'publish', 'future' ), true );
	if ( ! $public_now || $public_before ) {
		return;
	}
	$allowed = apply_filters( 'sn_webhook_post_types', array( 'post', 'page' ) );
	if ( ! in_array( $post->post_type, $allowed, true ) ) {
		return;
	}
	wp_schedule_single_event( time(), 'snt_prepop_event', array( (int) $post->ID ) );
}
add_action( 'transition_post_status', 'snt_prepop_on_transition', 10, 3 );

/**
 * Cron callback: generate + persist the empty fields.
 *
 * @param int $post_id
 */
function snt_run_prepop( $post_id ) {
	$post_id = (int) $post_id;

	if ( ! function_exists( 'snt_ai_is_available' ) || ! snt_ai_is_available() ) {
		return;
	}
	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
		return;
	}

	$min   = (int) apply_filters( 'snt_prepop_min_words', SNT_PREPOP_MIN_WORDS );
	$words = str_word_count( wp_strip_all_tags( (string) $post->post_content ) );
	if ( $words < $min ) {
		return;
	}

	// 1. Meta description (post meta — written here).
	if ( '' === (string) get_post_meta( $post_id, '_sn_meta_description', true )
		&& function_exists( 'snt_ai_meta_desc_impl' ) ) {
		$res = snt_ai_meta_desc_impl( $post_id, true );
		if ( is_array( $res ) && ! empty( $res['description'] ) ) {
			update_post_meta( $post_id, '_sn_meta_description', sanitize_textarea_field( $res['description'] ) );
			update_post_meta( $post_id, '_sn_autogen_meta_description', '1' );
		}
	}

	// 2. OG card title (impl self-persists the meta + rebuilds the PNG).
	if ( '' === (string) get_post_meta( $post_id, '_sn_og_card_title', true )
		&& function_exists( 'snt_ai_og_card_title_impl' ) ) {
		$res = snt_ai_og_card_title_impl( $post_id );
		if ( is_array( $res ) && ! empty( $res['title'] ) ) {
			update_post_meta( $post_id, '_sn_autogen_og_card_title', '1' );
		}
	}

	// 3. Excerpt (core post_excerpt field — wp_update_post last; it is the
	//    only re-entrancy point, and sn_post_settings_save no-ops without a
	//    nonce). Re-read the post in case the OG step changed it.
	$post = get_post( $post_id );
	if ( $post && '' === (string) $post->post_excerpt
		&& function_exists( 'snt_ai_excerpt_impl' ) ) {
		$res = snt_ai_excerpt_impl( $post_id, true );
		if ( is_array( $res ) && ! empty( $res['excerpt'] ) ) {
			wp_update_post( array(
				'ID'           => $post_id,
				'post_excerpt' => sanitize_textarea_field( $res['excerpt'] ),
			) );
			update_post_meta( $post_id, '_sn_autogen_excerpt', '1' );
		}
	}
}
add_action( 'snt_prepop_event', 'snt_run_prepop', 10, 1 );

/**
 * Map of sentinel meta key → human label for the notice.
 *
 * @return array<string,string>
 */
function sn_prepop_fields() {
	return array(
		'_sn_autogen_meta_description' => 'meta description',
		'_sn_autogen_excerpt'          => 'excerpt',
		'_sn_autogen_og_card_title'    => 'OG card title',
	);
}

/**
 * Render the consolidated "auto-generated at publish" notice at the top of
 * the SN meta box, listing only the fields whose sentinel is set. Called
 * from sn_post_settings_render(). Emits nothing when no sentinel is set.
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

/**
 * Clear all prepop sentinels for a post (the notice shows once, then clears
 * on the next editor save or an explicit dismiss). Called from
 * sn_post_settings_save() and the dismiss REST route.
 *
 * @param int $post_id
 */
function sn_prepop_clear_sentinels( $post_id ) {
	foreach ( array_keys( sn_prepop_fields() ) as $sentinel ) {
		delete_post_meta( (int) $post_id, $sentinel );
	}
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
	wp_register_script(
		'snt-prepop-notice',
		plugins_url( 'assets/prepop-notice.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch' ),
		SNT_VERSION,
		true
	);
	wp_localize_script( 'snt-prepop-notice', 'sntPrepopNotice', array(
		'restPath' => '/signal-noise/v1/prepop/dismiss',
	) );
	wp_enqueue_script( 'snt-prepop-notice' );
} );
