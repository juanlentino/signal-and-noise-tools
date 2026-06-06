<?php
/**
 * Signal & Noise Tools — Abilities API: pattern-adoption structural actions.
 *
 * Two abilities for the pattern-adoption Tools sub-tab (scan + dismiss).
 * Separate from inc/abilities-ai-pattern-adoption.php (which holds the AI
 * suggest+apply pair) because these actions are deterministic structural
 * — category 'tools' rather than 'ai-generation'.
 *
 * Mirrors inc/abilities-block-migrations.php category convention.
 *
 * Impl functions live in inc/pattern-adoption-detect.php (scan) and
 * inc/pattern-adoption-admin.php (dismiss state).
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

	wp_register_ability( 'signal-noise/pattern-adoption-scan', array(
		'label'               => 'Scan posts for v9.2.0 pattern-adoption opportunities',
		'description'         => 'Walks every published post + page, identifying blocks that match the heuristic templates for signal-noise/pull-quote or signal-noise/steps-enumerated. Returns the candidate list. Caches the result in a user-scoped transient (`snt_pattern_adoption_candidates_<user_id>`).',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_pattern_adoption_scan',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'         => array( 'type' => 'boolean' ),
				'candidates' => array( 'type' => 'array' ),
				'count'      => array( 'type' => 'integer' ),
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

	wp_register_ability( 'signal-noise/pattern-adoption-dismiss', array(
		'label'               => 'Dismiss a pattern-adoption candidate',
		'description'         => 'Marks a scanned candidate as dismissed by appending its `pattern_type:block_fingerprint` key to the target post\'s `_snt_pattern_adoption_dismissed` meta — the same store the scanner filters against — so it doesn\'t reappear on subsequent scans. Idempotent — dismissing the same candidate twice is a no-op.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_pattern_adoption_dismiss',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'pattern_type', 'block_fingerprint' ),
			'properties'           => array(
				'post_id'           => array(
					'type'        => 'integer',
					'description' => 'ID of the post the candidate belongs to.',
					'minimum'     => 1,
				),
				'pattern_type'      => array(
					'type'        => 'string',
					'description' => 'Pattern slug from pattern-adoption-scan output (e.g. pull-quote, steps-enumerated).',
					'minLength'   => 1,
				),
				'block_fingerprint' => array(
					'type'        => 'string',
					'description' => 'Block fingerprint from pattern-adoption-scan output.',
					'minLength'   => 1,
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
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );
} );

/**
 * Ability execute_callback for signal-noise/pattern-adoption-scan.
 *
 * Delegates to snt_pattern_adoption_run_scan() in inc/pattern-adoption-detect.php,
 * which returns an ENVELOPE: { candidates, counts, scanned_at }. We surface
 * only the candidate list + its count to match the output_schema.
 *
 * @param mixed $input Ignored.
 * @return array{ok:bool,candidates:array,count:int}
 */
function snt_ability_pattern_adoption_scan( $input ) {
	if ( ! function_exists( 'snt_pattern_adoption_run_scan' ) ) {
		return array( 'ok' => false, 'candidates' => array(), 'count' => 0 );
	}
	$result     = snt_pattern_adoption_run_scan();
	$candidates = ( is_array( $result ) && isset( $result['candidates'] ) && is_array( $result['candidates'] ) )
		? $result['candidates']
		: array();
	return array( 'ok' => true, 'candidates' => $candidates, 'count' => count( $candidates ) );
}

/**
 * Ability execute_callback for signal-noise/pattern-adoption-dismiss.
 *
 * Delegates to snt_pattern_adoption_dismiss_impl() (inc/pattern-adoption-admin.php),
 * which writes the `pattern_type:block_fingerprint` key into the post's
 * `_snt_pattern_adoption_dismissed` meta — the real store the scanner reads.
 * Idempotent.
 *
 * @param array $input { post_id: int, pattern_type: string, block_fingerprint: string }
 * @return array{ok:bool,message:string}
 */
function snt_ability_pattern_adoption_dismiss( $input ) {
	$post_id      = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$pattern_type = isset( $input['pattern_type'] ) ? (string) $input['pattern_type'] : '';
	$fingerprint  = isset( $input['block_fingerprint'] ) ? (string) $input['block_fingerprint'] : '';

	if ( ! function_exists( 'snt_pattern_adoption_dismiss_impl' ) ) {
		return array( 'ok' => false, 'message' => 'Pattern-adoption module not loaded.' );
	}

	return snt_pattern_adoption_dismiss_impl( $post_id, $pattern_type, $fingerprint );
}
