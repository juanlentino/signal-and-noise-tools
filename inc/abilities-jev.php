<?php
/**
 * Signal & Noise Tools — `signal-noise/jev-notes`: the stored Jev pass.
 *
 * Read-only over the option the daily pass writes; never calls Jev.
 *
 * @since 16.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** PURE. */
function sn_jev_status_shape( $data, $ready ) {
	if ( ! is_array( $data ) ) {
		return array( 'ok' => true, 'source' => 'typesafe-jev', 'ready' => (bool) $ready, 'synced' => false, 'synced_at' => 0, 'model' => '', 'judged' => 0, 'unsure' => 0, 'findings' => array(), 'usage' => null, 'last_error' => '', 'note' => $ready ? 'A key is stored; the first daily pass has not run.' : 'No TypeSafe key in the keyring.' );
	}
	$j = function_exists( 'sn_health_jev_notes_judge' ) ? sn_health_jev_notes_judge( $data['notes'] ?? array() ) : array( 'findings' => array(), 'unsure' => 0, 'judged' => 0 );
	return array(
		'ok'         => true,
		'source'     => 'typesafe-jev',
		'ready'      => (bool) $ready,
		'synced'     => true,
		'synced_at'  => (int) $data['synced_at'],
		'model'      => (string) ( $data['model'] ?? '' ),
		'judged'     => (int) $j['judged'],
		'unsure'     => (int) $j['unsure'],
		'findings'   => array_map( static function ( $f ) { return array( 'id' => $f['subject_id'], 'title' => $f['subject_label'], 'note' => $f['note'] ); }, $j['findings'] ),
		'usage'      => $data['usage'] ?? null,
		'last_error' => (string) ( $data['last_error'] ?? '' ),
		'note'       => 'One request per note per day; a finding needs a low rubric position AND confidence at or above the floor.',
	);
}

function snt_ability_jev_notes( $input = array() ) {
	return sn_jev_status_shape( function_exists( 'sn_jev_data' ) ? sn_jev_data() : null, function_exists( 'sn_jev_is_ready' ) && sn_jev_is_ready() );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/jev-notes', array(
		'label'               => 'Jev over the notes: the stored pass',
		'description'         => 'What TypeSafe\'s Jev judged about each published and scheduled note in the last daily pass (search title as a query, description as a summary, opening naming the subject), the findings above the confidence floor, the count below it, and the pass\'s own usage. Read-only; nothing here calls Jev.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_jev_notes',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array(
			'ok' => array( 'type' => 'boolean' ), 'source' => array( 'type' => 'string' ), 'ready' => array( 'type' => 'boolean' ), 'synced' => array( 'type' => 'boolean' ),
			'synced_at' => array( 'type' => 'integer' ), 'model' => array( 'type' => 'string' ), 'judged' => array( 'type' => 'integer' ), 'unsure' => array( 'type' => 'integer' ),
			'findings' => array( 'type' => 'array' ), 'usage' => array( 'type' => array( 'object', 'null' ) ), 'last_error' => array( 'type' => 'string' ), 'note' => array( 'type' => 'string' ),
		) ),
		'meta'                => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ) ),
	) );
} );
