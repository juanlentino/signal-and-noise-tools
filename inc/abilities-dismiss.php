<?php
/**
 * Signal & Noise Tools — Abilities API: unified scan-candidate dismiss.
 *
 * One ability replaces the two structurally-identical fingerprint dismissals
 * (signal-noise/block-migrations-dismiss + signal-noise/pattern-adoption-dismiss,
 * both deprecated v7.7.0, removed v8.0.0):
 *
 *   signal-noise/dismiss-candidate
 *     surface=block-migrations → snt_block_migrations_dismiss_impl()
 *       (appends candidate_type:block_fingerprint to _snt_block_migrations_dismissed)
 *     surface=pattern-adoption → snt_pattern_adoption_dismiss_impl()
 *       (appends candidate_type:block_fingerprint to _snt_pattern_adoption_dismissed)
 *
 * Both paths write the SAME store their scanner filters against, and both
 * invalidate the dismissing user's scan transient. Idempotent — re-dismissing
 * an already-dismissed candidate is a no-op.
 *
 * Category 'tools' — deterministic structural state, no AI anywhere.
 *
 * @package SignalNoiseTools
 * @since 7.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/dismiss-candidate', array(
		'label'               => 'Dismiss a scan candidate',
		'description'         => 'Unified dismiss for scan candidates. surface=block-migrations dismisses a block-migrations-scan candidate (candidate_type is the migration_type, e.g. heading-hierarchy-skip); surface=pattern-adoption dismisses a pattern-adoption-scan candidate (candidate_type is the pattern slug, e.g. pull-quote). Appends candidate_type:block_fingerprint to the surface\'s dismissed-meta store so the candidate never reappears on subsequent scans. Idempotent — re-dismissing is a no-op.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_dismiss_candidate',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'surface', 'post_id', 'block_fingerprint', 'candidate_type' ),
			'properties'           => array(
				'surface'           => array(
					'type'        => 'string',
					'enum'        => array( 'block-migrations', 'pattern-adoption' ),
					'description' => 'Which scanner produced the candidate.',
				),
				'post_id'           => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the post the candidate belongs to.',
				),
				'block_fingerprint' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Block fingerprint from the scan output.',
				),
				'candidate_type'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'migration_type (block-migrations) or pattern_type (pattern-adoption) from the scan output.',
					'examples'    => array( 'heading-hierarchy-skip', 'pull-quote', 'steps-enumerated' ),
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
 * Ability execute_callback for signal-noise/dismiss-candidate.
 *
 * Dispatches by surface to the shared dismiss impls. NO deprecation notice
 * anywhere on this path — this is the canonical replacement (the notice lives
 * only in the deprecated wrappers, per the inc/abilities-deprecations.php
 * placement rule).
 *
 * @param array $input { surface, post_id, block_fingerprint, candidate_type }.
 * @return array{ok:bool,message:string}|WP_Error
 */
function snt_ability_dismiss_candidate( $input ) {
	$surface     = isset( $input['surface'] ) ? (string) $input['surface'] : '';
	$post_id     = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$fingerprint = isset( $input['block_fingerprint'] ) ? (string) $input['block_fingerprint'] : '';
	$type        = isset( $input['candidate_type'] ) ? (string) $input['candidate_type'] : '';

	if ( 'block-migrations' === $surface ) {
		if ( ! function_exists( 'snt_block_migrations_dismiss_impl' ) ) {
			return new WP_Error( 'snt_helper_unavailable', __( 'Block migrations module not loaded.', 'signal-noise-tools' ), array( 'status' => 500 ) );
		}
		return snt_block_migrations_dismiss_impl( $post_id, $fingerprint, $type );
	}

	if ( 'pattern-adoption' === $surface ) {
		if ( ! function_exists( 'snt_pattern_adoption_dismiss_impl' ) ) {
			return new WP_Error( 'snt_helper_unavailable', __( 'Pattern-adoption module not loaded.', 'signal-noise-tools' ), array( 'status' => 500 ) );
		}
		// The pattern-adoption impl's signature is ( post_id, pattern_type, fingerprint ).
		return snt_pattern_adoption_dismiss_impl( $post_id, $type, $fingerprint );
	}

	// Schema enum already blocks this via the run controller; guard anyway for
	// direct callers (tests, future internal dispatch).
	return new WP_Error( 'snt_dismiss_unknown_surface', __( 'Unknown dismiss surface.', 'signal-noise-tools' ), array( 'status' => 422 ) );
}
