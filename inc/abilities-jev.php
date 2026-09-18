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
		// 16.3.2: every note's readings, so the distribution can be read rather than guessed at when tuning the rubric or the floor.
		'notes'      => sn_jev_notes_readings( $data['notes'] ?? array() ),
		'usage'      => $data['usage'] ?? null,
		'last_error' => (string) ( $data['last_error'] ?? '' ),
		'note'       => 'One request per note per day; a finding needs a low rubric position AND confidence at or above the floor.',
	);
}

/** PURE: {id, title, title_score, title_confidence, description_score, description_confidence, opening_noul, error} per judged note, scores on 0..2. */
function sn_jev_notes_readings( $notes ) {
	$out = array();
	foreach ( (array) $notes as $id => $n ) {
		if ( ! is_array( $n ) ) {
			continue;
		}
		$v = is_array( $n['verdict'] ?? null ) ? $n['verdict'] : array();
		$out[] = array(
			'id'                     => (int) $id,
			'title'                  => (string) ( $n['title'] ?? '' ),
			'title_score'            => isset( $v['title']['score'] ) ? round( (float) $v['title']['score'], 2 ) : null,
			'title_confidence'       => isset( $v['title']['confidence'] ) ? round( (float) $v['title']['confidence'], 2 ) : null,
			'description_score'      => isset( $v['description']['score'] ) ? round( (float) $v['description']['score'], 2 ) : null,
			'description_confidence' => isset( $v['description']['confidence'] ) ? round( (float) $v['description']['confidence'], 2 ) : null,
			'opening_noul'           => isset( $v['opening']['noul'] ) ? round( (float) $v['opening']['noul'], 2 ) : null,
			'error'                  => (string) ( $n['error'] ?? '' ),
		);
	}
	return $out;
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
			'findings' => array( 'type' => 'array' ), 'notes' => array( 'type' => 'array', 'description' => 'Every judged note with its scores (0..2) and confidences.' ), 'usage' => array( 'type' => array( 'object', 'null' ) ), 'last_error' => array( 'type' => 'string' ), 'note' => array( 'type' => 'string' ),
		) ),
		'meta'                => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ) ),
	) );
} );

/**
 * 16.3.3: `signal-noise/jev-pass-now`, WRITE: runs the daily pass now and
 * returns its counts. One bounded pass (one request per note, the refused-key
 * stop, the last-good-verdict rule) behind the rw door's envelope, so a
 * rubric change is read in minutes, not a day.
 */
function snt_ability_jev_pass_now( $input = array() ) {
	if ( ! function_exists( 'sn_jev_sync' ) ) {
		return array( 'ok' => false, 'error' => 'unavailable' );
	}
	$r = sn_jev_sync();
	$d = function_exists( 'sn_jev_data' ) ? sn_jev_data() : null;
	return array(
		'ok'     => (bool) $r['ok'],
		'judged' => (int) $r['judged'],
		'failed' => (int) $r['failed'],
		'error'  => (string) $r['error'],
		'usage'  => is_array( $d ) ? ( $d['usage'] ?? null ) : null,
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/jev-pass-now', array(
		'label'               => 'Run the Jev pass now',
		'description'         => 'Runs the daily Jev pass over every published and scheduled note now (one request per note, a refused key stops after one, a failed request keeps the note\'s previous verdict) and returns judged, failed and the pass\'s usage. Idempotent: the same notes produce the same stored verdicts; the cost is a fifth of a cent.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_jev_pass_now',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'judged' => array( 'type' => 'integer' ), 'failed' => array( 'type' => 'integer' ), 'error' => array( 'type' => 'string' ), 'usage' => array( 'type' => array( 'object', 'null' ) ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) ),
	) );
} );
