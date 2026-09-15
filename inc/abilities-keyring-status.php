<?php
/**
 * Signal & Noise Tools — `signal-noise/keyring-status`: every credential's
 * SOURCE and last VERDICT on the read door, as the `keyring` section of
 * sn-status. Never a value, never a probe; reads what the keyring resolves
 * and what "Verify all" stored.
 *
 * @package SignalNoiseTools
 * @since 15.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param mixed $input Unused.
 * @return array<string,mixed>
 */
function snt_ability_keyring_status( $input = null ) {
	unset( $input );
	if ( ! function_exists( 'sn_keyring' ) ) {
		return array( 'state' => 'unavailable', 'rows' => array(), 'verified_at' => null );
	}
	$verdicts = function_exists( 'sn_keyring_verdicts' ) ? sn_keyring_verdicts() : array();
	$rows     = array();
	$at       = 0;
	foreach ( sn_keyring() as $id => $row ) {
		$v      = isset( $verdicts[ $id ] ) && is_array( $verdicts[ $id ] ) ? $verdicts[ $id ] : null;
		$at     = max( $at, (int) ( $v['at'] ?? 0 ) );
		$rows[] = array(
			'id'      => (string) $id,
			'group'   => (string) $row['group'],
			'source'  => sn_keyring_source( $id ),
			'set'     => '' !== sn_credential( $id ),
			'derives' => 'site' === ( $row['derive'] ?? '' ),
			'verdict' => is_array( $v ) ? (string) ( $v['status'] ?? '' ) : null,
			'detail'  => is_array( $v ) ? (string) ( $v['detail'] ?? '' ) : null,
		);
	}
	return array( 'state' => $at > 0 ? 'verified' : 'never_verified', 'rows' => $rows, 'verified_at' => $at > 0 ? $at : null );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/keyring-status', array(
		'label'               => 'Keyring Status',
		'description'         => 'Every credential the plugin holds (Connections › Credentials), one row each: its id and group, where its value comes from (`source`: constant | site | option | empty), whether it is set, whether it derives from the site secret, and the last "Verify all" verdict with its sentence (ok | refused | error | unset | none). NEVER a value. `state: never_verified` means Verify all has not run. A `refused` sensor row names which side differs; a `none` verdict means the row has no probe and the tab that uses it is the witness. Read-only; never probes.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_keyring_status',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'state'       => array( 'type' => 'string', 'enum' => array( 'unavailable', 'never_verified', 'verified' ) ),
				'verified_at' => array( 'type' => array( 'integer', 'null' ) ),
				'rows'        => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'description' => 'id, group, source, set, derives, verdict, detail; never a value.' ) ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array( 'readonly' => true, 'idempotent' => true, 'open_world_hint' => false ),
		),
	) );
} );
