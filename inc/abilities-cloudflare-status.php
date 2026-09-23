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
	// 17.8.1 (#1002): the edge rollup's 5xx rows, independent of the monitor record.
	$errors = function_exists( 'sn_edge_errors_reading' ) ? sn_edge_errors_reading( 7 ) : null;
	$record = function_exists( 'sn_cf_monitor_read' ) ? sn_cf_monitor_read() : null;
	if ( ! is_array( $record ) ) {
		return array( 'state' => 'never_run', 'fetched_at' => null, 'configured' => null, 'token' => null, 'zone' => null, 'firewall' => null, 'posture' => null, 'errors_5xx' => $errors );
	}
	// 15.4.0: the posture record rides along; null when it has never been read.
	$posture = function_exists( 'sn_cf_posture_read' ) ? sn_cf_posture_read() : null;
	return array(
		'state'      => empty( $record['configured'] ) ? 'unconfigured' : 'recorded',
		'fetched_at' => (int) $record['fetched_at'],
		'configured' => (bool) $record['configured'],
		'token'      => $record['token'],
		'zone'       => $record['zone'],
		'firewall'   => $record['firewall'],
		'posture'    => is_array( $posture ) ? array( 'fetched_at' => (int) ( $posture['fetched_at'] ?? 0 ), 'settings' => $posture['settings'] ?? null, 'dnssec' => $posture['dnssec'] ?? null, 'rules' => $posture['rules'] ?? null ) : null,
		'errors_5xx' => $errors,
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/cloudflare-status', array(
		'label'               => 'Cloudflare Status',
		'description'         => 'The Cloudflare monitor\'s last stored reading: the API token\'s status and expiry (GET /user/tokens/verify), the zone\'s last seven days from GraphQL httpRequests1dGroups (requests, cached share, bytes, threats, 4xx/5xx), and the firewall\'s last 24 hours from firewallEventsAdaptiveGroups, or from the raw firewallEventsAdaptive grouped locally when the plan lacks the grouped one (`dataset: raw`; events by action, top rules). READ `needs_permission: true` AS A GAP, never as zero: for the zone reading the token lacks Zone › Analytics › Read; for the firewall the zone\'s plan lacks both datasets, and no grant changes that. `state: never_run` means the daily monitor has not stored anything yet. Cloudflare publishes no rate-limit headers, which is why this reading exists instead of a quota row. Since 15.4.0 `posture` carries the daily REST reads of what the edge is SET TO: zone settings (SSL mode, min TLS, Always Use HTTPS, Development Mode and readings), DNSSEC status, and the custom WAF rules by name, action and enabled; each read says `needs_permission` with the scope it lacks (Zone Settings Read, DNS Read, Zone WAF Read). Since 17.8.1 `errors_5xx` names the last seven days of 5xx by path and by responder, from the daily edge rollup. Read-only; never fetches, never purges.',
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
				'firewall'   => array( 'type' => array( 'object', 'null' ), 'description' => 'available, needs_permission, error, events, by_action{}, top_rules[], dataset (groups|raw), truncated, groups_refused.' ),
				'posture'    => array( 'type' => array( 'object', 'null' ), 'description' => 'fetched_at; settings{available,needs_permission,error,values{id:value}}; dnssec{available,needs_permission,error,status}; rules{available,needs_permission,error,rules[{id,description,action,enabled,expression}]}.' ),
				'errors_5xx' => array( 'type' => array( 'object', 'null' ), 'description' => 'Since 17.8.1, from the daily edge rollup (not the monitor): the last 7 days of 5xx, from, to, total, paths[{value,requests}] (which URLs failed) and sources[{value,label,requests}] (who answered: `edge=503 origin=503` is the origin failing, `origin=-` is Cloudflare or a Worker answering by itself).' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ),
		),
	) );
} );
