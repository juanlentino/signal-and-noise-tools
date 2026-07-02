<?php
/**
 * Signal & Noise Tools — Abilities API: pattern-adoption structural actions.
 *
 * One ability for the pattern-adoption Tools sub-tab (scan). Dismissal is
 * signal-noise/dismiss-candidate surface=pattern-adoption
 * (inc/abilities-dismiss.php); the per-surface dismiss ability was removed
 * in v8.0.0.
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
