<?php
/**
 * Signal & Noise Tools: admin-bar.php, the menu builder, the AJAX
 * registration and the four handlers.
 *
 * Split from inc/admin-bar.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add the parent "S&N" menu and its quick-action submenu items.
 * Priority 100 so we land after WP core's nodes (themes, plugins,
 * comments). Visible on both admin and front-end when the admin bar
 * is shown.
 */
add_action( 'admin_bar_menu', function( $admin_bar ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$admin_bar->add_node( array(
		'id'    => 'sn-quick',
		'title' => '<span class="ab-label">S&amp;N</span>',
		'href'  => admin_url( 'admin.php?page=sn-theme-options' ),
		'meta'  => array(
			'title' => 'Signal & Noise: quick actions',
		),
	) );

	foreach ( sn_admin_bar_items() as $node_id => $item ) {
		// Conditional items (e.g., Cloudflare only when configured, or the
		// contextual Regen OG Card which is only shown for a single post).
		// A guard that returns falsy hides the item. The post ID a contextual
		// guard resolves is carried to the JS via the script config (see
		// sn_admin_bar_print_script) rather than a DOM attribute — WP core's
		// admin bar renderer doesn't pass arbitrary data-* attrs through.
		if ( ! empty( $item['guard'] ) && is_callable( $item['guard'] ) && ! call_user_func( $item['guard'] ) ) {
			continue;
		}

		$admin_bar->add_node( array(
			'id'     => $node_id,
			'parent' => 'sn-quick',
			'title'  => $item['label'],
			// href = '#' so right-click "open in new tab" doesn't fire
			// the action twice. JS preventDefaults the left click.
			'href'   => '#',
			'meta'   => array(
				'class' => 'sn-quick-action',
			),
		) );
	}

	// Separator-style item linking back to the full dashboard for
	// anything not exposed as a quick action.
	$admin_bar->add_node( array(
		'id'     => 'sn-quick-dashboard',
		'parent' => 'sn-quick',
		'title'  => '⚙ Open S&N Home',
		'href'   => admin_url( 'admin.php?page=sn-theme-options' ),
	) );
}, 100 );

/**
 * Register one wp_ajax handler per quick action. All require
 * `manage_options` and verify the per-action nonce. Returns JSON with
 * a `message` field used by the JS to populate the toast.
 *
 * Registered only on `wp_ajax_*` (admin-side) — these are admin
 * actions, not public API. Signed-out users get a 0 response from
 * WP's `_nopriv_` non-handler.
 */
add_action( 'init', function() {
	$handlers = array(
		'sn_quick_force_update_check' => 'sn_handle_quick_force_update_check',
		'sn_quick_scan_patterns'      => 'sn_handle_quick_scan_patterns',
		'sn_quick_clear_overrides'    => 'sn_handle_quick_clear_overrides',
		'sn_quick_regen_og_card'      => 'sn_handle_quick_regen_og_card',
);
	foreach ( $handlers as $action => $callback ) {
		add_action( 'wp_ajax_' . $action, $callback );
	}
} );

function sn_handle_quick_force_update_check() {
	check_ajax_referer( 'sn_quick_force_update_check' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
	}
	// Same work signal-noise/get-deploy-status does with force_refresh=true
	// (and the removed force-check-updates wrapper before it): both route
	// through snt_cmd_impl_force_check(), which busts the GitHub tag caches
	// + WP's update_themes/update_plugins transients. Call the same impl so
	// the admin-bar action and the ability stay behaviorally identical.
	if ( ! function_exists( 'snt_cmd_impl_force_check' ) ) {
		wp_send_json_error( array( 'message' => 'Force-check helper unavailable.' ), 500 );
	}
	snt_cmd_impl_force_check();
	wp_send_json_success( array(
		'message' => 'Update check forced: see Dashboard › Updates.',
	) );
}

function sn_handle_quick_scan_patterns() {
	check_ajax_referer( 'sn_quick_scan_patterns' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
	}
	if ( ! function_exists( 'snt_pattern_adoption_run_scan' ) ) {
		wp_send_json_error( array( 'message' => 'Pattern-adoption scanner unavailable.' ), 500 );
	}
	// snt_pattern_adoption_run_scan() returns an ENVELOPE:
	// array( 'candidates' => [...], 'counts' => [...], 'scanned_at' => int ).
	// Surface count( $result['candidates'] ) — NOT count( $result ), which
	// would always be 3 (the envelope's key count). This exact off-by-envelope
	// bug was caught in the v4.6.0 pattern-adoption-scan ability.
	$result = snt_pattern_adoption_run_scan();
	$count  = ( is_array( $result ) && isset( $result['candidates'] ) && is_array( $result['candidates'] ) )
		? count( $result['candidates'] )
		: 0;
	wp_send_json_success( array(
		'message' => sprintf(
			'Pattern scan complete. %d candidate%s.',
			$count,
			1 === $count ? '' : 's'
		),
	) );
}

function sn_handle_quick_regen_og_card() {
	check_ajax_referer( 'sn_quick_regen_og_card' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
	}
	// Nonce verified above by check_ajax_referer.
	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		wp_send_json_error( array( 'message' => 'No post in context.' ), 400 );
	}
	// Per-post capability — manage_options alone is not enough to regen a
	// card for an arbitrary post; the user must be able to edit THIS post.
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => 'You cannot edit this post.' ), 403 );
	}
	// Same work the signal-noise/regenerate-og-card ability does: its
	// execute_callback (snt_ability_regenerate_og_card) calls
	// sn_generate_og_card( $post_id ) (returns bool). Call the same impl so
	// the admin-bar action and the ability stay behaviorally identical.
	if ( ! function_exists( 'sn_generate_og_card' ) ) {
		wp_send_json_error( array( 'message' => 'OG card generator not available.' ), 500 );
	}
	if ( ! sn_generate_og_card( $post_id ) ) {
		wp_send_json_error( array(
			'message' => 'OG card regeneration failed (check that GD + theme fonts are available).',
		), 500 );
	}
	wp_send_json_success( array( 'message' => 'OG card regenerated for this post.' ) );
}

function sn_handle_quick_clear_overrides() {
	check_ajax_referer( 'sn_quick_clear_overrides' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
	}
	// Dispatched via the sn_clear_template_overrides_result filter
	// contract — theme module template-maintenance.php owns the
	// implementation. #1228: apply_filters() with no listener returns the
	// default 0, which is indistinguishable from "cleared zero", so error
	// here via has_filter() rather than reporting a false success.
	if ( ! has_filter( 'sn_clear_template_overrides_result' ) ) {
		wp_send_json_error( array( 'message' => 'Template-override clearing unavailable.' ), 500 );
	}
	$count = (int) apply_filters( 'sn_clear_template_overrides_result', 0 );
	wp_send_json_success( array(
		'message' => $count . ' DB override(s) cleared.',
	) );
}


