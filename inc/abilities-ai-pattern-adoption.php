<?php
/**
 * Signal & Noise Tools — Abilities API: pattern-adoption Suggest+Apply.
 *
 * Two abilities backing the Health-tab pattern-adoption Suggest+Apply
 * flow (v4.3.0):
 *   - signal-noise/pattern-adoption-suggest   (deterministic template
 *                                              substitution; NO AI calls)
 *   - signal-noise/pattern-adoption-apply     (fingerprint-validated
 *                                              parse_blocks↔serialize_blocks
 *                                              round-trip; mutates post_content)
 *
 * Category 'ai-generation' chosen for consumer discoverability — AI
 * agents like desktop-mode Copilot look in this category for actionable
 * abilities. The category name is about the CONSUMER, not the producer
 * (this module's impl is heuristic + deterministic, no AI calls).
 *
 * Surface convention: mirror inc/abilities-ai-health.php — ability
 * registrations inside add_action('wp_abilities_api_init') closure,
 * with thin wrapper functions that delegate to the underlying impl
 * (inc/pattern-adoption-suggest.php / inc/pattern-adoption-apply.php).
 *
 * @package SignalNoiseTools
 * @since 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/pattern-adoption-suggest', array(
		'label'               => 'Suggest a v9.2.0 pattern upgrade for a structural block',
		'description'         => 'Given a post and a fingerprinted candidate block (from the pattern-adoption scan), generate the replacement markup for the target signal-noise/pull-quote or signal-noise/steps-enumerated pattern via deterministic template substitution. Does NOT write — returns the suggestion for review.',
		'category'            => 'ai-generation',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_pattern_adoption_suggest',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'block_fingerprint', 'pattern_type' ),
			'properties'           => array(
				'post_id'           => array(
					'type'        => 'integer',
					'description' => 'Post ID containing the candidate block.',
					'minimum'     => 1,
				),
				'block_fingerprint' => array(
					'type'        => 'string',
					'description' => 'md5 of serialize_block($node) from the scan.',
					'minLength'   => 32,
					'maxLength'   => 32,
				),
				'pattern_type'      => array(
					'type'        => 'string',
					'description' => 'Target pattern. One of: pull-quote, steps-enumerated.',
					'enum'        => array( 'pull-quote', 'steps-enumerated' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'                => array( 'type' => 'boolean' ),
				'suggestion_markup' => array( 'type' => 'string', 'description' => 'Replacement block markup string ready for parse_blocks().' ),
				'fingerprint'       => array( 'type' => 'string' ),
				'post_id'           => array( 'type' => 'integer' ),
				'pattern_type'      => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array( 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/pattern-adoption-apply', array(
		'label'               => 'Apply a v9.2.0 pattern upgrade to a post',
		'description'         => 'Fingerprint-validated structural-block replacement. parse_blocks → locate by fingerprint → mutate node → serialize_blocks → wp_update_post. Returns 409 conflict if the block changed since the suggest call (re-run scan to refresh).',
		'category'            => 'ai-generation',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_pattern_adoption_apply',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'block_fingerprint', 'replacement_markup', 'pattern_type' ),
			'properties'           => array(
				'post_id'            => array( 'type' => 'integer', 'minimum' => 1 ),
				'block_fingerprint'  => array( 'type' => 'string', 'minLength' => 32, 'maxLength' => 32 ),
				'replacement_markup' => array(
					'type'        => 'string',
					'description' => 'Block markup string (typically the suggestion_markup, possibly user-edited).',
					'minLength'   => 1,
				),
				'pattern_type'       => array(
					'type' => 'string',
					'enum' => array( 'pull-quote', 'steps-enumerated' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'                    => array( 'type' => 'boolean' ),
				'post_id'               => array( 'type' => 'integer' ),
				'replaced_pattern_type' => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array( 'idempotent' => false, 'destructive' => true ),
		),
	) );
} );

/**
 * Ability wrapper: delegates to snt_ai_pattern_adoption_suggest_impl().
 *
 * @param array $input  Validated against input_schema above.
 * @return array|WP_Error
 *
 * @since 4.3.0
 */
function snt_ability_pattern_adoption_suggest( $input ) {
	return snt_ai_pattern_adoption_suggest_impl(
		(int)    ( $input['post_id'] ?? 0 ),
		(string) ( $input['block_fingerprint'] ?? '' ),
		(string) ( $input['pattern_type'] ?? '' )
	);
}

/**
 * Ability wrapper: delegates to snt_ai_pattern_adoption_apply_impl().
 *
 * @param array $input  Validated against input_schema above.
 * @return array|WP_Error
 *
 * @since 4.3.0
 */
function snt_ability_pattern_adoption_apply( $input ) {
	return snt_ai_pattern_adoption_apply_impl(
		(int)    ( $input['post_id'] ?? 0 ),
		(string) ( $input['block_fingerprint'] ?? '' ),
		(string) ( $input['replacement_markup'] ?? '' ),
		(string) ( $input['pattern_type'] ?? '' )
	);
}
