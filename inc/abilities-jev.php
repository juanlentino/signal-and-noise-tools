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
		return array( 'ok' => true, 'source' => 'typesafe-jev', 'ready' => (bool) $ready, 'synced' => false, 'synced_at' => 0, 'model' => '', 'judged' => 0, 'unsure' => 0, 'findings' => array(), 'usage' => null, 'last_error' => '', 'note' => $ready ? 'A key is stored; the first daily pass has not run.' : SN_JEV_NOT_READY );
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

/**
 * 16.4.0: the collision gate's two doors. `jev-collision-check` (WRITE, rw
 * door) judges one note now and stores the reading on it; `jev-lane-map`
 * (WRITE, rw door) judges every published note against the others and
 * stores the pairs; `jev-lanes` (READ) hands the stored map out.
 */
function snt_ability_jev_collision_check( $input = array() ) {
	$id = (int) ( is_array( $input ) ? ( $input['post_id'] ?? 0 ) : 0 );
	if ( $id <= 0 ) {
		return array( 'ok' => false, 'error' => 'post_id required' );
	}
	if ( ! function_exists( 'sn_jev_collision_check' ) ) {
		return array( 'ok' => false, 'error' => 'unavailable' );
	}
	$r = sn_jev_collision_check( $id, ! empty( $input['force'] ) );
	return array_merge( array( 'ok' => empty( $r['error'] ) ), $r );
}

function snt_ability_jev_lane_map( $input = array() ) {
	return function_exists( 'sn_jev_lane_map' ) ? sn_jev_lane_map() : array( 'ok' => false, 'error' => 'unavailable' );
}

function snt_ability_jev_lanes( $input = array() ) {
	$d = function_exists( 'sn_jev_lanes' ) ? sn_jev_lanes() : null;
	if ( null === $d ) {
		return array( 'ok' => true, 'mapped' => false, 'at' => 0, 'judged' => 0, 'pairs' => array(), 'note' => 'No lane map yet; run jev-lane-map.' );
	}
	return array( 'ok' => true, 'mapped' => true, 'at' => (int) $d['at'], 'requests' => (int) ( $d['requests'] ?? 0 ), 'judged' => (int) $d['judged'], 'failed' => (int) ( $d['failed'] ?? 0 ), 'pairs' => (array) $d['pairs'], 'input_tokens' => (int) ( $d['input_tokens'] ?? 0 ), 'error' => (string) ( $d['error'] ?? '' ), 'note' => 'Pairs at or above 0.5: two published notes Jev reads as making the same argument. Notes are never edited; the remedy is the next note, not these.' );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$rw = array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) );
	wp_register_ability( 'signal-noise/jev-collision-check', array(
		'label'               => 'Jev: does this draft re-argue a published note?',
		'description'         => 'Judges one note (draft, pending or scheduled; a published one is allowed but pointless) against every published note now, one request, one Noul per note, and stores the reading on the post for the pre-publish panel. Skips the request when the draft has not changed since the last reading unless force is true. Rows are the top five by probability; collisions counts those at or above 0.5.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_jev_collision_check',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'force' => array( 'type' => 'boolean' ) ), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'rows' => array( 'type' => 'array' ), 'collisions' => array( 'type' => 'integer' ), 'against' => array( 'type' => 'integer' ), 'at' => array( 'type' => 'integer' ), 'error' => array( 'type' => 'string' ) ) ),
		'meta'                => $rw,
	) );
	wp_register_ability( 'signal-noise/jev-lane-map', array(
		'label'               => 'Jev: map the lanes the published notes share',
		'description'         => 'Judges every unordered pair of published notes and stores the pairs at or above 0.5 as the lane map. The corpus is sent once per chunk of 300 pair questions: four requests for 44 notes, about 55k tokens, a quarter of a cent. Take an edge reading twice; the 0.5 line moves between runs.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_jev_lane_map',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'requests' => array( 'type' => 'integer' ), 'judged' => array( 'type' => 'integer' ), 'failed' => array( 'type' => 'integer' ), 'pairs' => array( 'type' => 'integer' ), 'input_tokens' => array( 'type' => 'integer' ), 'error' => array( 'type' => 'string' ) ) ),
		'meta'                => $rw,
	) );
	wp_register_ability( 'signal-noise/jev-lanes', array(
		'label'               => 'Jev: the stored lane map',
		'description'         => 'The pairs of published notes Jev read as making the same argument (probability at or above 0.5), from the last lane map. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_jev_lanes',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'mapped' => array( 'type' => 'boolean' ), 'at' => array( 'type' => 'integer' ), 'judged' => array( 'type' => 'integer' ), 'pairs' => array( 'type' => 'array' ), 'note' => array( 'type' => 'string' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ) ),
	) );
} );

/** 16.5.0: query-to-page fit. `jev-fit-now` (WRITE, rw door) runs the pass; `jev-query-fit` (READ) hands the two lists out. */
function snt_ability_jev_fit_now( $input = array() ) {
	return function_exists( 'sn_jev_fit_sync' ) ? sn_jev_fit_sync() : array( 'ok' => false, 'error' => 'unavailable' );
}

function snt_ability_jev_query_fit( $input = array() ) {
	$d = function_exists( 'sn_jev_fit_data' ) ? sn_jev_fit_data() : null;
	if ( null === $d || empty( $d['synced_at'] ) ) {
		return array( 'ok' => true, 'judged' => false, 'at' => 0, 'gaps' => array(), 'stray' => array(), 'note' => 'No fit pass yet; run jev-fit-now.' );
	}
	$r = sn_jev_fit_readings( $d );
	return array( 'ok' => true, 'judged' => true, 'at' => (int) $d['synced_at'], 'window' => (array) ( $d['window'] ?? array() ), 'notes' => count( (array) $d['notes'] ), 'judged_notes' => (object) (array) $d['notes'], 'gaps' => $r['gaps'], 'stray' => $r['stray'], 'input_tokens' => (int) ( $d['usage']['input_tokens'] ?? 0 ), 'error' => (string) ( $d['last_error'] ?? '' ), 'note' => 'judged_notes: every note judged with its rows (query, counts, score, confidence). gaps: queries with 5+ impressions in the window the note scores under 1 of 2 on (the next note). stray: clicks on a query scored under 0.5 (a title chasing the wrong search).' );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/jev-fit-now', array(
		'label'               => 'Jev: judge the queries Google sends to each note, now',
		'description'         => 'One Search Console read (page × query, the 28-day window), then one Jev request per note that has queries with 5 or more impressions (top eight per note), one Score per query: does the note answer it (2), touch it (1), or did the query land on vocabulary (0). Stores the pass; the weekly hook runs the same. About forty requests; a cent at most.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_jev_fit_now',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'judged' => array( 'type' => 'integer' ), 'failed' => array( 'type' => 'integer' ), 'queries' => array( 'type' => 'integer' ), 'error' => array( 'type' => 'string' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) ),
	) );
	wp_register_ability( 'signal-noise/jev-query-fit', array(
		'label'               => 'Jev: the queries each note is seen for and does not answer',
		'description'         => 'The stored fit pass as two lists. gaps: queries with real impressions the note scores under 1 of 2 on, by impressions; the raw material for the next note. stray: clicks on a query the note scores under 0.5 on; a title chasing the wrong search. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_jev_query_fit',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'judged' => array( 'type' => 'boolean' ), 'at' => array( 'type' => 'integer' ), 'judged_notes' => array( 'type' => 'object' ), 'gaps' => array( 'type' => 'array' ), 'stray' => array( 'type' => 'array' ), 'note' => array( 'type' => 'string' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ) ),
	) );
} );

/** 16.6.0: the Jev meter (READ). This cycle's spend per feature, from the site's own priced ledger. */
function snt_ability_jev_meter( $input = array() ) {
	if ( ! function_exists( 'sn_jev_meter_reading' ) ) {
		return array( 'ok' => false, 'error' => 'unavailable' );
	}
	$r = sn_jev_meter_reading();
	$r['by_feature'] = (object) ( $r['by_feature'] ?? array() ); // an empty cycle is {} at the door, never []
	return array_merge( array( 'ok' => true, 'ready' => function_exists( 'sn_jev_is_ready' ) && sn_jev_is_ready(), 'price_per_m_input' => SN_JEV_PRICE_PER_M_INPUT ), $r, array( 'note' => 'USD from the tokens each answer reports, priced at the pinned rate; cached requests hit the connector\'s one-hour cache and cost nothing. The cycle runs from the credit day. Nothing is projected; the TypeSafe console is the bill.' ) );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/jev-meter', array(
		'label'               => 'Jev: this cycle\'s spend, by feature',
		'description'         => 'The site\'s own priced ledger of Jev use: requests, cached hits, failures, input tokens and USD per feature (notes, collision, lane_map, fit) for the current credit cycle, with the credit, the remaining amount and the days left. Priced from reported tokens at the pinned rate; never projected. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_jev_meter',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'ready' => array( 'type' => 'boolean' ), 'cycle' => array( 'type' => 'object' ), 'credit' => array( 'type' => 'number' ), 'spent' => array( 'type' => 'number' ), 'remaining' => array( 'type' => 'number' ), 'requests' => array( 'type' => 'integer' ), 'cached' => array( 'type' => 'integer' ), 'by_feature' => array( 'type' => 'object' ), 'note' => array( 'type' => 'string' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ) ),
	) );
} );

/** 16.7.0: the anti-tell pass. `jev-tells-check` (WRITE) judges one note now; `jev-tells-pass` (WRITE) judges every published note; `jev-tells` (READ) hands the stored pass out. */
function snt_ability_jev_tells_check( $input = array() ) {
	$id = (int) ( is_array( $input ) ? ( $input['post_id'] ?? 0 ) : 0 );
	if ( $id <= 0 ) {
		return array( 'ok' => false, 'error' => 'post_id required' );
	}
	if ( ! function_exists( 'sn_jev_tells_check' ) ) {
		return array( 'ok' => false, 'error' => 'unavailable' );
	}
	$r = sn_jev_tells_check( $id, ! empty( $input['force'] ) );
	return array_merge( array( 'ok' => empty( $r['error'] ) ), $r );
}

function snt_ability_jev_tells_pass( $input = array() ) {
	return function_exists( 'sn_jev_tells_pass' ) ? sn_jev_tells_pass() : array( 'ok' => false, 'error' => 'unavailable' );
}

function snt_ability_jev_tells( $input = array() ) {
	$d = function_exists( 'sn_jev_tells_data' ) ? sn_jev_tells_data() : null;
	if ( null === $d ) {
		return array( 'ok' => true, 'judged' => false, 'at' => 0, 'notes' => new stdClass(), 'note' => 'No anti-tell pass yet; run jev-tells-pass.' );
	}
	$flagged = array();
	foreach ( (array) $d['notes'] as $id => $n ) {
		if ( array() !== (array) ( $n['rows'] ?? array() ) || array() !== (array) ( $n['deterministic'] ?? array() ) ) {
			$flagged[ (int) $id ] = $n;
		}
	}
	return array( 'ok' => true, 'judged' => true, 'at' => (int) $d['at'], 'notes_judged' => (int) $d['judged'], 'failed' => (int) $d['failed'], 'flagged' => count( $flagged ), 'notes' => (object) $flagged, 'input_tokens' => (int) ( $d['input_tokens'] ?? 0 ), 'error' => (string) ( $d['error'] ?? '' ), 'note' => 'rows: Jev at or above 0.6 per paragraph (tricolon, anaphora, symmetric, closer). deterministic: regex counts (em_dash, quietly, not_just, hedge_cluster, uniform_rhythm). Published notes are never edited; this is a reading of the voice, and the gate on drafts is where it acts.' );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$rw = array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) );
	wp_register_ability( 'signal-noise/jev-tells-check', array(
		'label'               => 'Jev: the anti-tell pass on one note, now',
		'description'         => 'Counts the regex tells (em dash, "quietly", "not just X but Y", hedge clusters, three same-length sentences) and asks Jev, one request, three Nouls per paragraph (tricolon for rhythm, anaphora, the symmetric pair) and one for the closer. Stores the reading on the post for the pre-publish panel. Skips the request when the paragraphs have not changed unless force is true.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_jev_tells_check',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'force' => array( 'type' => 'boolean' ) ), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'rows' => array( 'type' => 'array' ), 'deterministic' => array( 'type' => 'array' ), 'paragraphs' => array( 'type' => 'integer' ), 'error' => array( 'type' => 'string' ) ) ),
		'meta'                => $rw,
	) );
	wp_register_ability( 'signal-noise/jev-tells-pass', array(
		'label'               => 'Jev: the anti-tell pass over every published note',
		'description'         => 'One request per published note; stores the rows at or above 0.6 and the regex counts per note. A reading of the voice, never a remedy: published notes are not edited. About seventy requests; two cents.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_jev_tells_pass',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'judged' => array( 'type' => 'integer' ), 'failed' => array( 'type' => 'integer' ), 'flagged' => array( 'type' => 'integer' ), 'error' => array( 'type' => 'string' ) ) ),
		'meta'                => $rw,
	) );
	wp_register_ability( 'signal-noise/jev-tells', array(
		'label'               => 'Jev: the stored anti-tell pass',
		'description'         => 'The notes the last corpus pass flagged, with Jev\'s rows per paragraph and the regex counts. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_jev_tells',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'judged' => array( 'type' => 'boolean' ), 'flagged' => array( 'type' => 'integer' ), 'notes' => array( 'type' => 'object' ), 'note' => array( 'type' => 'string' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ) ),
	) );
} );

/** 16.8.0: tag fit. `jev-tags-now` (WRITE) runs the pass; `jev-tags` (READ) hands the stored pass out. */
function snt_ability_jev_tags_now( $input = array() ) {
	return function_exists( 'sn_jev_tags_sync' ) ? sn_jev_tags_sync() : array( 'ok' => false, 'error' => 'unavailable' );
}

function snt_ability_jev_tags( $input = array() ) {
	$d = function_exists( 'sn_jev_tags_data' ) ? sn_jev_tags_data() : null;
	if ( null === $d ) {
		return array( 'ok' => true, 'judged' => false, 'at' => 0, 'notes' => new stdClass(), 'by_tag' => array(), 'note' => 'No tag-fit pass yet; run jev-tags-now.' );
	}
	$flagged = array();
	foreach ( (array) $d['notes'] as $id => $n ) {
		$misfits = array_values( array_filter( (array) ( $n['attached'] ?? array() ), 'sn_jev_tag_is_misfit' ) );
		if ( array() !== $misfits ) {
			$flagged[ (int) $id ] = array( 'title' => (string) $n['title'], 'misfits' => $misfits, 'attached' => (array) $n['attached'] );
		}
	}
	return array( 'ok' => true, 'judged' => true, 'at' => (int) $d['synced_at'], 'tags' => (int) ( $d['tags'] ?? 0 ), 'notes_judged' => count( (array) $d['notes'] ), 'flagged' => count( $flagged ), 'notes' => (object) $flagged, 'by_tag' => sn_jev_tags_by_tag( $d ), 'input_tokens' => (int) ( $d['usage']['input_tokens'] ?? 0 ), 'error' => (string) ( $d['last_error'] ?? '' ), 'note' => 'misfits: attached tags whose subject the note does not touch, scored under 0.5 of 2 at confidence 0.7 or better (a lower confidence is a shrug, not a verdict, and is not listed). `attached` carries every tag\'s score for the record. by_tag: the pass pivoted per tag, what a reader of that archive gets: every note carrying the tag counted, the mean score, and `touching`, the notes under 1 of 2 (they touch the tag rather than being about it), by touching share descending. Jev does not propose tags: what a note carries is the owner\'s call. Jev read each tag\'s description; a wrong reading of a right tag is the description to fix. Tags are not prose: a published note can take the change.' );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/jev-tags-now', array(
		'label'               => 'Jev: read every note against its tags, now',
		'description'         => 'One request per published or scheduled note that carries tags: one Score per attached tag (does the note touch what the tag names, so a reader browsing the tag would find it relevant: 0 the subject is absent, attached for reach; 1 touches it; 2 is about it), the tag descriptions as the state. Stores the pass; the weekly hook runs the same. About seventy requests; under a cent.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_jev_tags_now',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'judged' => array( 'type' => 'integer' ), 'failed' => array( 'type' => 'integer' ), 'misfits' => array( 'type' => 'integer' ), 'error' => array( 'type' => 'string' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) ),
	) );
	wp_register_ability( 'signal-noise/jev-tags', array(
		'label'               => 'Jev: the stored tag-fit pass',
		'description'         => 'The notes the last tag-fit pass flagged: attached tags whose subject the note does not touch, scored under 0.5 of 2 at confidence 0.7 or better, with every attached tag\'s score beside them; and `by_tag`, the same pass pivoted per tag (notes, mean score, the notes that only touch it), which is what a reader of that tag\'s archive gets. No proposed tags: what a note carries is the owner\'s call. Read-only; check 31 and the Tags leaf read the same lines.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_jev_tags',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'judged' => array( 'type' => 'boolean' ), 'flagged' => array( 'type' => 'integer' ), 'notes' => array( 'type' => 'object' ), 'by_tag' => array( 'type' => 'array' ), 'note' => array( 'type' => 'string' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ) ),
	) );
} );
