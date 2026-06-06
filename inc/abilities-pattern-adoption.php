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
		'description'         => 'Walks every published post + page, identifying blocks that match the heuristic templates for signal-noise/pull-quote or signal-noise/steps-enumerated. Returns the candidate list. Caches result in option sn_pattern_adoption_last_scan; cache expires on next post save.',
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
		'description'         => 'Adds a candidate fingerprint to the dismissal list (option sn_pattern_adoption_dismissed) so it doesn\'t reappear on subsequent scans. Idempotent — dismissing the same fingerprint twice is a no-op.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_pattern_adoption_dismiss',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'fingerprint' ),
			'properties'           => array(
				'fingerprint' => array(
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
 * Delegates to snt_pattern_adoption_run_scan() in inc/pattern-adoption-detect.php.
 *
 * @param mixed $input Ignored.
 * @return array{ok:bool,candidates:array,count:int}
 */
function snt_ability_pattern_adoption_scan( $input ) {
	if ( ! function_exists( 'snt_pattern_adoption_run_scan' ) ) {
		return array( 'ok' => false, 'candidates' => array(), 'count' => 0 );
	}
	$candidates = snt_pattern_adoption_run_scan();
	if ( ! is_array( $candidates ) ) {
		$candidates = array();
	}
	return array( 'ok' => true, 'candidates' => $candidates, 'count' => count( $candidates ) );
}

/**
 * Ability execute_callback for signal-noise/pattern-adoption-dismiss.
 *
 * Mutates option sn_pattern_adoption_dismissed (array of fingerprints).
 * Idempotent — in_array() check before append.
 *
 * @param array $input { fingerprint: string }
 * @return array{ok:bool,message:string}
 */
function snt_ability_pattern_adoption_dismiss( $input ) {
	$fp = isset( $input['fingerprint'] ) ? (string) $input['fingerprint'] : '';
	if ( '' === $fp ) {
		return array( 'ok' => false, 'message' => 'Missing fingerprint.' );
	}

	$dismissed = get_option( 'sn_pattern_adoption_dismissed', array() );
	if ( ! is_array( $dismissed ) ) {
		$dismissed = array();
	}
	if ( in_array( $fp, $dismissed, true ) ) {
		return array( 'ok' => true, 'message' => 'Already dismissed (no-op).' );
	}
	$dismissed[] = $fp;
	update_option( 'sn_pattern_adoption_dismissed', $dismissed, false );

	return array( 'ok' => true, 'message' => 'Dismissed.' );
}
