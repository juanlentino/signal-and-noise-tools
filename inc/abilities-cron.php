<?php
/**
 * Signal & Noise Tools — Abilities API: WP-Cron event dashboard.
 *
 * Four abilities wrapping the v3.0.0 Cron Dashboard module:
 *   - signal-noise/list-cron-events       (read; sn_only filter)
 *   - signal-noise/get-cron-event          (read; identified by hook + args_signature)
 *   - signal-noise/get-cron-history        (read; firing history from snt_cron_history)
 *   - signal-noise/unschedule-cron-event   (destructive; refuses SN-owned hooks)
 *
 * Categories: list/get/history are 'diagnostics'; unschedule is 'maintenance'.
 * File-level grouping is by feature (cron dashboard) rather than category so
 * the impl wrappers stay co-located with their registrations.
 *
 * Extracted from inc/abilities-registration.php by the v4.1.3 split (B-11).
 *
 * @package SignalNoiseTools
 * @since 4.1.3 (registrations from 3.0.0 + 3.1.0 + 3.2.0)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/list-cron-events', array(
		'label'               => 'List Cron Events',
		'description'         => 'Returns all scheduled WP-Cron events with next-run, recurrence, last-fired, args, has_handler flag, and is_sn_owned flag. Pass sn_only=true to filter to the SN-owned hooks (e.g. the RSS subscriber-prune hook).',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_list_cron_events',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'sn_only' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'If true, filter to the SN-owned hooks only.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'hook'           => array( 'type' => 'string' ),
					'args_signature' => array( 'type' => 'string' ),
					'next_run_ts'    => array( 'type' => 'integer' ),
					'schedule'       => array( 'type' => array( 'string', 'boolean' ) ),
					'interval_s'     => array( 'type' => array( 'integer', 'null' ) ),
					'args'           => array( 'type' => 'array' ),
					'last_fired_ts'  => array( 'type' => array( 'integer', 'null' ) ),
					'has_handler'    => array( 'type' => 'boolean' ),
					'is_sn_owned'    => array( 'type' => 'boolean' ),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'        => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-cron-event', array(
		'label'               => 'Get Cron Event Details',
		'description'         => 'Returns details for a single scheduled cron event identified by hook + args_signature. Returns null if no match. `args_signature` is the md5 hash returned by signal-noise/list-cron-events. Use that ability first to discover signatures.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_cron_event',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'hook', 'args_signature' ),
			'properties'           => array(
				'hook'           => array(
					'type'        => 'string',
					'description' => 'The cron hook name.',
					'minLength'   => 1,
					'examples'    => array( 'sn_analytics_rollup_daily', 'sn_rss_tracker_daily_prune', 'wp_scheduled_delete' ),
				),
				'args_signature' => array(
					'type'        => 'string',
					'description' => 'The md5 args signature from list-cron-events.',
					'minLength'   => 1,
					'examples'    => array( '', '3a8e965b27c4c1d4b8a9' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'hook'           => array( 'type' => 'string' ),
				'args_signature' => array( 'type' => 'string' ),
				'next_run_ts'    => array( 'type' => 'integer' ),
				'schedule'       => array( 'type' => array( 'string', 'boolean' ) ),
				'interval_s'     => array( 'type' => array( 'integer', 'null' ) ),
				'args'           => array( 'type' => 'array' ),
				'last_fired_ts'  => array( 'type' => array( 'integer', 'null' ) ),
				'has_handler'    => array( 'type' => 'boolean' ),
				'is_sn_owned'    => array( 'type' => 'boolean' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'        => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-cron-history', array(
		'label'               => 'Get Cron Firing History',
		'description'         => 'Returns the most recent N firings of a cron hook with elapsed time, success/failure, and any error message. Backed by the snt_cron_history table populated since plugin v3.2.0; retention is a rolling 30 days OR 1000 rows per hook, whichever is shorter.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_cron_history',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'hook' ),
			'properties'           => array(
				'hook'  => array(
					'type'        => 'string',
					'description' => 'The cron hook name to look up history for.',
					'minLength'   => 1,
					'examples'    => array( 'sn_analytics_rollup_daily', 'sn_rss_tracker_daily_prune' ),
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => 'Maximum rows to return (1–100, default 10, newest first).',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
					'examples'    => array( 20, 50 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'id'             => array( 'type' => 'integer' ),
					'hook'           => array( 'type' => 'string' ),
					'args_signature' => array( 'type' => 'string' ),
					'fired_at'       => array( 'type' => 'string', 'description' => 'UTC datetime "Y-m-d H:i:s".' ),
					'fired_at_ts'    => array( 'type' => 'integer', 'description' => 'Unix timestamp of fired_at.' ),
					'elapsed_ms'     => array( 'type' => array( 'integer', 'null' ) ),
					'success'        => array( 'type' => 'boolean' ),
					'error_message'  => array( 'type' => array( 'string', 'null' ) ),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'        => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );

	wp_register_ability( 'signal-noise/unschedule-cron-event', array(
		'label'               => 'Unschedule cron event',
		'description'         => "Permanently removes a scheduled WP-Cron event (single OR recurring) by hook + args. Signal & Noise's own LIVE recurring hooks (analytics + edge rollups, audit + cron-history prune, insights, narration, uptime heartbeat, discography, RSS) are refused with a clear error so the dashboard's data pipeline can't be killed by one call; retired or orphaned hooks stay removable (that is the point of cleanup). The matching event is identified by exact args match — pass [] for events scheduled without args. Returns the count cleared (0 if no match). Useful for pruning orphaned cron events left by uninstalled plugins.",
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_unschedule_cron_event',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'hook' ),
			'properties'           => array(
				'hook' => array(
					'type'        => 'string',
					'description' => 'The cron hook name to unschedule.',
					'minLength'   => 1,
					'examples'    => array( 'orphaned_plugin_cron_hook', 'wp_scheduled_delete' ),
				),
				'args' => array(
					'type'        => 'array',
					'description' => 'Optional args array — must match the scheduled signature exactly. Pass [] for events scheduled without args.',
					'default'     => array(),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'hook'    => array( 'type' => 'string' ),
				'args'    => array( 'type' => 'array' ),
				'cleared' => array( 'type' => 'integer', 'description' => 'Number of events removed; 0 if no match.' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive'     => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );

	wp_register_ability( 'signal-noise/run-cron-event', array(
		'label'               => 'Run a scheduled cron event now',
		'description'         => 'Synchronously dispatches the named cron event by calling do_action() on its hook. DESTRUCTIVE — runs the hook callbacks immediately. Refuses SN-internal hooks (sn_*) for safety; use the dedicated abilities for those.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_run_cron_event',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'hook' ),
			'properties'           => array(
				'hook' => array(
					'type'        => 'string',
					'description' => 'WP cron hook name to dispatch.',
					'minLength'   => 1,
				),
				'args' => array(
					'type'        => 'array',
					'description' => 'Args to pass to do_action(). Default: empty array.',
					'default'     => array(),
				),
			),
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
				'destructive' => true,
				'idempotent'  => false,
			),
		),
	) );
} );

/**
 * Ability execute callback: signal-noise/list-cron-events.
 * Thin wrapper around snt_cron_get_events_impl() with sn_only filter passthrough.
 * @since 3.7.5
 */
function snt_ability_list_cron_events( $input ) {
	if ( ! function_exists( 'snt_cron_get_events_impl' ) ) {
		return new WP_Error( 'snt_cron_unavailable', 'Cron dashboard module not loaded.', array( 'status' => 500 ) );
	}
	$sn_only = is_array( $input ) && ! empty( $input['sn_only'] );
	return snt_cron_get_events_impl( $sn_only );
}

/**
 * Ability execute callback: signal-noise/get-cron-event.
 * Thin wrapper around snt_cron_get_event_impl().
 * @since 3.7.5
 */
function snt_ability_get_cron_event( $input ) {
	if ( ! function_exists( 'snt_cron_get_event_impl' ) ) {
		return new WP_Error( 'snt_cron_unavailable', 'Cron dashboard module not loaded.', array( 'status' => 500 ) );
	}
	return snt_cron_get_event_impl(
		(string) $input['hook'],
		(string) $input['args_signature']
	);
}

/**
 * Ability execute callback for signal-noise/get-cron-history.
 * Thin wrapper around snt_cron_history_for_hook().
 *
 * @since 3.2.0
 */
function snt_ability_get_cron_history( $input ) {
	if ( ! function_exists( 'snt_cron_history_for_hook' ) ) {
		return new WP_Error( 'snt_cron_unavailable', 'Cron history module not loaded.', array( 'status' => 500 ) );
	}
	$hook  = isset( $input['hook'] ) ? (string) $input['hook'] : '';
	$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
	return snt_cron_history_for_hook( $hook, $limit );
}

/**
 * Ability execute callback for signal-noise/unschedule-cron-event.
 * Thin wrapper around snt_cron_unschedule_event_impl() so the dispatch
 * layer (ability) doesn't duplicate the impl's safety checks.
 *
 * @since 3.1.0
 */
function snt_ability_unschedule_cron_event( $input ) {
	if ( ! function_exists( 'snt_cron_unschedule_event_impl' ) ) {
		return new WP_Error( 'snt_cron_unavailable', 'Cron dashboard module not loaded.', array( 'status' => 500 ) );
	}
	$hook = isset( $input['hook'] ) ? (string) $input['hook'] : '';
	$args = isset( $input['args'] ) && is_array( $input['args'] ) ? $input['args'] : array();
	return snt_cron_unschedule_event_impl( $hook, $args );
}

/**
 * Ability execute_callback for signal-noise/run-cron-event.
 *
 * Delegates to snt_cron_run_event_impl() (inc/cron-dashboard.php), which
 * carries the proven safety guards: manage_options gate, non-empty-string
 * check, has_action() orphan pre-flight (WP_Error snt_cron_no_handler),
 * DOING_CRON spoof, Throwable catch, last-fired tracking, and a history
 * record. This wrapper adds only the sn_* pre-filter — SN-namespaced hooks
 * have dedicated abilities (purge-all-caches, force-check-updates, etc.)
 * with proper input schemas, so this generic runner refuses them.
 *
 * Hook names are matched VERBATIM (no sanitize_key — that would mangle
 * mixed-case/namespaced hooks). The impl's array return is collapsed to
 * the {ok,message} output_schema; the impl's WP_Error paths (orphan /
 * forbidden / invalid) pass through unchanged with their status + message.
 *
 * @param array $input { hook: string, args?: array }
 * @return array{ok:bool,message:string}|WP_Error
 */
function snt_ability_run_cron_event( $input ) {
	$hook = isset( $input['hook'] ) ? trim( (string) $input['hook'] ) : '';
	$args = isset( $input['args'] ) && is_array( $input['args'] ) ? $input['args'] : array();

	if ( '' === $hook ) {
		return new WP_Error( 'snt_invalid_hook', 'Missing or empty hook name.', array( 'status' => 422 ) );
	}

	if ( str_starts_with( $hook, 'sn_' ) ) {
		return new WP_Error(
			'snt_sn_hook_refused',
			'SN-internal hooks (sn_*) are not dispatchable via this ability — use the dedicated abilities for those actions.',
			array( 'status' => 422 )
		);
	}

	if ( ! function_exists( 'snt_cron_run_event_impl' ) ) {
		return new WP_Error( 'snt_impl_missing', 'Cron runner not available.', array( 'status' => 500 ) );
	}

	$r = snt_cron_run_event_impl( $hook, $args );
	if ( is_wp_error( $r ) ) {
		return $r; // orphan/forbidden/invalid — carries status + message.
	}

	$ok = ! empty( $r['success'] );
	return array(
		'ok'      => $ok,
		'message' => $ok
			? sprintf( 'Dispatched %s.', $hook )
			: ( 'Dispatch failed: ' . ( ! empty( $r['error'] ) ? $r['error'] : 'unknown error' ) ),
	);
}
