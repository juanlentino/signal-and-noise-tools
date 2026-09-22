<?php
/**
 * Signal & Noise Tools -- signal-noise/zenodo-status (readonly ability).
 *
 * One call answers "do the documents carry DOIs": the environment, whether a
 * token is set, the ledger's states counted, and the rows that are not
 * minted, with their reasons. Read-only: it never deposits. The deposit runs
 * from the anchor confirmation, the hourly pass and the Connections › Zenodo
 * leaf; an MCP write for it would be an sn-apply change type, deliberately
 * not built until asked for (docs/zenodo-doi-design.md).
 *
 * @package SignalNoiseTools
 * @since 15.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shape the status. PURE.
 *
 * @since 15.11.0
 * @param string $env     'sandbox' | 'production'.
 * @param bool   $enabled A token is set for that environment.
 * @param array  $rows    sn_zenodo_ledger() rows.
 * @return array
 */
function sn_zenodo_status_shape( $env, $enabled, array $rows ) {
	$counts  = array();
	$missing = array();
	foreach ( $rows as $r ) {
		$state = (string) ( $r['state'] ?? 'unknown' );
		$counts[ $state ] = ( $counts[ $state ] ?? 0 ) + 1;
		if ( 'minted' !== $state ) {
			$missing[] = array(
				'id'    => (int) ( $r['id'] ?? 0 ),
				'title' => (string) ( $r['title'] ?? '' ),
				'state' => $state,
				'error' => (string) ( $r['error'] ?? '' ),
			);
		}
	}
	ksort( $counts );
	return array(
		'environment' => (string) $env,
		'enabled'     => (bool) $enabled,
		'total'       => count( $rows ),
		'minted'      => (int) ( $counts['minted'] ?? 0 ),
		'counts'      => $counts,
		'missing'     => $missing,
	);
}

/**
 * Register the ability.
 *
 * @since 15.11.0
 */
function snt_abilities_zenodo_register() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/zenodo-status', array(
		'label'               => 'Zenodo DOI status',
		'description'         => 'Whether the site\'s signed documents (notes and pillar essays) carry Zenodo DOIs: the environment (sandbox or production), whether a token is set, every document\'s state counted (minted, ready, anchor-pending, sandbox, or a failed deposit with its error), and the rows not yet minted. Read-only; deposits run from the anchor confirmation and the hourly pass.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_zenodo_status',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'environment' => array( 'type' => 'string' ),
				'enabled'     => array( 'type' => 'boolean' ),
				'total'       => array( 'type' => 'integer' ),
				'minted'      => array( 'type' => 'integer' ),
				'counts'      => array( 'type' => 'object' ),
				'missing'     => array( 'type' => 'array' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			'annotations'  => array(
				'readonly'        => true,
				'destructive'     => false,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );
}
add_action( 'wp_abilities_api_init', 'snt_abilities_zenodo_register' );

/**
 * Ability execute callback.
 *
 * @since 15.11.0
 */
function snt_ability_zenodo_status( $input = null ) {
	unset( $input );
	return sn_zenodo_status_shape( sn_zenodo_env(), sn_zenodo_is_enabled(), sn_zenodo_ledger() );
}
