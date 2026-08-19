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
