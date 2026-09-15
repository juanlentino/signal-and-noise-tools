<?php
/**
 * Signal & Noise Tools — `signal-noise/cloudflare-status`: the Cloudflare
 * monitor's stored record on the read door, as the `cloudflare` section of
 * sn-status. Never fetches; reads what inc/cloudflare-monitor.php stored.
 *
 * @package SignalNoiseTools
 * @since 14.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param mixed $input Unused.
 * @return array<string,mixed>
 */
function snt_ability_cloudflare_status( $input = null ) {
	unset( $input );
	$record = function_exists( 'sn_cf_monitor_read' ) ? sn_cf_monitor_read() : null;
	if ( ! is_array( $record ) ) {
		return array( 'state' => 'never_run', 'fetched_at' => null, 'configured' => null, 'token' => null, 'zone' => null, 'firewall' => null );
	}
	return array(
		'state'      => empty( $record['configured'] ) ? 'unconfigured' : 'recorded',
		'fetched_at' => (int) $record['fetched_at'],
		'configured' => (bool) $record['configured'],
		'token'      => $record['token'],
		'zone'       => $record['zone'],
		'firewall'   => $record['firewall'],
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/cloudflare-status', array(
		'label'               => 'Cloudflare Status',
		'description'         => 'The Cloudflare monitor\'s last stored reading: the API token\'s status and expiry (GET /user/tokens/verify), the zone\'s last seven days from GraphQL httpRequests1dGroups (requests, cached share, bytes, threats, 4xx/5xx), and the firewall\'s last 24 hours from firewallEventsAdaptiveGroups (events by action, top rules). READ `needs_permission: true` AS A GAP, never as zero: the token lacks Zone › Analytics › Read and the reading was not made. `state: never_run` means the daily monitor has not stored anything yet. Cloudflare publishes no rate-limit headers, which is why this reading exists instead of a quota row. Read-only; never fetches, never purges.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_cloudflare_status',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'state'      => array( 'type' => 'string', 'enum' => array( 'never_run', 'unconfigured', 'recorded' ) ),
				'fetched_at' => array( 'type' => array( 'integer', 'null' ) ),
				'configured' => array( 'type' => array( 'boolean', 'null' ) ),
				'token'      => array( 'type' => array( 'object', 'null' ), 'description' => 'verified, status (active|expired|disabled|invalid|unreachable), expires_on, not_before, error.' ),
				'zone'       => array( 'type' => array( 'object', 'null' ), 'description' => 'available, needs_permission, error, days[], totals{requests,cached,bytes,cached_bytes,threats,status_4xx,status_5xx,cache_share}.' ),
				'firewall'   => array( 'type' => array( 'object', 'null' ), 'description' => 'available, needs_permission, error, events, by_action{}, top_rules[].' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array( 'readonly' => true, 'idempotent' => true, 'open_world_hint' => false ),
		),
	) );
} );
