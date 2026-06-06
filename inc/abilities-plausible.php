<?php
/**
 * Signal & Noise Tools — Abilities API: Plausible analytics.
 *
 * Three abilities backing the Plausible REST surface (added v4.6.0 to close
 * the gap identified in the v5.0.0 scope audit — these REST routes had no
 * Ability replacement before this file):
 *   - signal-noise/get-plausible-stats        (read; dashboard breakdown)
 *   - signal-noise/get-plausible-realtime     (read; current visitor count)
 *   - signal-noise/test-plausible-connection  (read; API ping for setup)
 *
 * Category 'diagnostics' — these are read-only reporting actions, not
 * mutations. AI agents pull these to summarize site traffic.
 *
 * Impl functions live in inc/plausible-api.php. This file is a thin
 * wrapper that adapts the impl returns to the Ability output_schema.
 *
 * @package SignalNoiseTools
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/get-plausible-stats', array(
		'label'               => 'Get Plausible dashboard stats',
		'description'         => 'Returns the dashboard breakdown (pageviews, unique visitors, top pages, top sources) from the configured Plausible Analytics account. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_plausible_stats',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'   => array( 'type' => 'boolean' ),
				'data' => array( 'type' => array( 'object', 'null' ) ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-plausible-realtime', array(
		'label'               => 'Get Plausible realtime visitor count',
		'description'         => 'Returns the current realtime visitor count from Plausible. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_plausible_realtime',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'visitors' => array( 'type' => 'integer', 'minimum' => 0 ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/test-plausible-connection', array(
		'label'               => 'Test Plausible API connection',
		'description'         => 'Pings the Plausible API with the configured token. Returns ok/error for setup diagnostics. Read-only; no side effects.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_test_plausible_connection',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );
} );

/**
 * Ability execute_callback for signal-noise/get-plausible-stats.
 *
 * Delegates to sn_plausible_dashboard_data() in inc/plausible-api.php.
 *
 * @param mixed $input Ignored — controller accepts null per show_in_rest pattern.
 * @return array{ok:bool,data:?array}
 */
function snt_ability_get_plausible_stats( $input ) {
	if ( ! function_exists( 'sn_plausible_dashboard_data' ) ) {
		return array( 'ok' => false, 'data' => null );
	}
	$data = sn_plausible_dashboard_data();
	return array( 'ok' => is_array( $data ), 'data' => is_array( $data ) ? $data : null );
}

/**
 * Ability execute_callback for signal-noise/get-plausible-realtime.
 *
 * @param mixed $input Ignored.
 * @return array{ok:bool,visitors:int}
 */
function snt_ability_get_plausible_realtime( $input ) {
	if ( ! function_exists( 'sn_plausible_realtime' ) ) {
		return array( 'ok' => false, 'visitors' => 0 );
	}
	$count = sn_plausible_realtime();
	return array( 'ok' => is_int( $count ), 'visitors' => is_int( $count ) ? $count : 0 );
}

/**
 * Ability execute_callback for signal-noise/test-plausible-connection.
 *
 * Uses sn_plausible_last_error() — the existing inc/plausible-api.php helper
 * that captures the most recent API error transient.
 *
 * @param mixed $input Ignored.
 * @return array{ok:bool,message:string}
 */
function snt_ability_test_plausible_connection( $input ) {
	if ( ! function_exists( 'sn_plausible_realtime' ) || ! function_exists( 'sn_plausible_last_error' ) ) {
		return array( 'ok' => false, 'message' => 'Plausible module not loaded.' );
	}
	// Force a fresh call to surface API health.
	$res = sn_plausible_realtime();
	$err = sn_plausible_last_error();
	if ( is_int( $res ) && empty( $err ) ) {
		return array( 'ok' => true, 'message' => 'Plausible API reachable.' );
	}
	$msg = is_array( $err ) && ! empty( $err['message'] ) ? $err['message'] : 'Unknown error.';
	return array( 'ok' => false, 'message' => $msg );
}
