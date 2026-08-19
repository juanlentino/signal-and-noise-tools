<?php
/**
 * Signal & Noise — Dashboard console host.
 *
 * The Dashboard tab is a metabox host. This file owns the once-per-request
 * data snapshot every box reads from.
 *
 * WHY A SNAPSHOT: box callbacks run in the order the USER dragged them into.
 * If each box fetched its own data, dragging a box would change how many
 * outbound probes fire — behaviour coupled to layout. One memoised fetch makes
 * that impossible.
 *
 * @package SignalNoiseTools
 * @since 11.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the Dashboard boxes need, gathered once.
 *
 * probe_budget stays at 1: a cold page load must not fan out five live HTTP
 * calls (v11.11.4). A worker that is merely cold reads as `warming`, which
 * sn_dash_zone_fleet() treats as pending rather than unknown.
 *
 * @since 11.29.0
 * @return array<string,mixed>
 */
function snt_dashboard_snapshot() {
	static $snap = null;
	if ( null !== $snap ) {
		return $snap;
	}

	$theme  = function_exists( 'snt_deploy_status_for' ) ? snt_deploy_status_for( 'theme' ) : array();
	$plugin = function_exists( 'snt_deploy_status_for' ) ? snt_deploy_status_for( 'plugin' ) : array();

	$runs = function_exists( 'snt_deploy_history_merged' ) && defined( 'SNT_DEPLOY_REPOS' )
		? snt_deploy_history_merged( array_values( SNT_DEPLOY_REPOS ), 5 )
		: array();

	$last_deploy_ago = function_exists( 'snt_dashboard_last_deploy_label' )
		? snt_dashboard_last_deploy_label( $runs )
		: '';

	$workers = function_exists( 'snt_deploy_workers_status' )
		? snt_deploy_workers_status( array( 'probe_budget' => 1 ) )
		: array();

	$cards = function_exists( 'snt_dashboard_glance_cards' )
		? snt_dashboard_glance_cards( $theme, $plugin, $runs, $last_deploy_ago )
		: array();

	$overrides = function_exists( 'snt_dashboard_override_post_types' )
		? get_posts( array(
			'post_type'      => snt_dashboard_override_post_types(),
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		) )
		: array();

	$snap = array(
		'theme'           => $theme,
		'plugin'          => $plugin,
		'runs'            => $runs,
		'last_deploy_ago' => $last_deploy_ago,
		'workers'         => $workers,
		'cards'           => $cards,
		'override_count'  => is_array( $overrides ) ? count( $overrides ) : 0,
		'measurement'     => function_exists( 'snt_dashboard_measurement_data' ) ? snt_dashboard_measurement_data() : array(),
	);

	return $snap;
}

/**
 * The six Dashboard boxes, in registration order.
 *
 * Registration order is only the DEFAULT — core persists the user's own order
 * in `meta-box-order_{page}` and applies it on top.
 *
 * @since 11.29.0
 * @return array<int,array{id:string,title:string,callback:string,context:string}>
 */
function snt_dash_boxes() {
	return array(
		array(
			'id'       => 'sn-dash-systems',
			'title'    => __( 'Systems', 'signal-and-noise-tools' ),
			'callback' => 'snt_dash_box_systems',
			'context'  => 'normal',
		),
		array(
			'id'       => 'sn-dash-fleet',
			'title'    => __( 'Fleet', 'signal-and-noise-tools' ),
			'callback' => 'snt_dash_box_fleet',
			'context'  => 'normal',
		),
		array(
			'id'       => 'sn-dash-traffic',
			'title'    => __( 'Traffic', 'signal-and-noise-tools' ),
			'callback' => 'snt_dash_box_traffic',
			'context'  => 'normal',
		),
		array(
			'id'       => 'sn-dash-glance',
			'title'    => __( 'At a glance', 'signal-and-noise-tools' ),
			'callback' => 'snt_dash_box_glance',
			'context'  => 'side',
		),
		array(
			'id'       => 'sn-dash-maintenance',
			'title'    => __( 'Maintenance', 'signal-and-noise-tools' ),
			'callback' => 'snt_dash_box_maintenance',
			'context'  => 'side',
		),
		array(
			'id'       => 'sn-dash-diagnostics',
			'title'    => __( 'Diagnostics', 'signal-and-noise-tools' ),
			'callback' => 'snt_dash_box_diagnostics',
			'context'  => 'side',
		),
	);
}

/**
 * Register the boxes for one screen. MUST run on `load-{$hook}`.
 *
 * TIMING, verified against wp-admin/admin.php: `load-{$page_hook}` fires, THEN
 * admin-header.php renders Screen Options, THEN the page callback runs. If we
 * registered inside the renderer, $wp_meta_boxes would be empty when Screen
 * Options draws and the show/hide checkboxes would silently never appear —
 * no error, just a feature that quietly does not exist.
 *
 * THE TAB GATE: every SN tab shares the screen id, and Screen Options is
 * per-screen. WP_Screen::render_meta_boxes_preferences() early-returns unless
 * $wp_meta_boxes[$screen->id] is set, so registering only on the Dashboard tab
 * leaves the panel empty elsewhere by construction rather than by a second
 * guard we would have to maintain.
 *
 * @since 11.29.0
 * @param string $hook_suffix The screen id to register against.
 * @return void
 */
function snt_dash_boxes_register( $hook_suffix ) {
	if ( ! function_exists( 'sn_admin_page_active_tab' ) || 'dashboard' !== sn_admin_page_active_tab() ) {
		return;
	}

	add_screen_option(
		'layout_columns',
		array(
			'max'     => 2,
			'default' => 2,
		)
	);

	foreach ( snt_dash_boxes() as $box ) {
		add_meta_box( $box['id'], $box['title'], $box['callback'], $hook_suffix, $box['context'] );
	}
}
