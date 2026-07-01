<?php
/**
 * Signal & Noise Tools — Abilities API: dismiss the AI-prepopulation notice.
 *
 * One ability: signal-noise/prepop-dismiss. It is the Abilities-API replacement
 * for the legacy POST /signal-noise/v1/prepop/dismiss REST route
 * (inc/ai-prepopulate-notice.php). Both dispatch the SAME shared impl —
 * sn_prepop_clear_sentinels() in inc/ai-prepopulate.php — which deletes the
 * three "auto-generated at publish" sentinels from post meta so the SN
 * meta-box notice stops rendering.
 *
 * Building this ability unblocks migrating the prepop-notice JS caller off the
 * legacy route to the run-path, the last step before that route can carry a
 * deprecation marker and eventually be removed.
 *
 * Category 'tools' + per-post edit_post permission, mirroring the sibling
 * dismiss abilities (block-migrations-dismiss, pattern-adoption-dismiss).
 *
 * @package SignalNoiseTools
 * @since 6.55.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/prepop-dismiss', array(
		'label'               => 'Dismiss the AI-prepopulation notice for a post',
		'description'         => 'Clears the "auto-generated at publish" sentinels (_sn_autogen_meta_description / _sn_autogen_excerpt / _sn_autogen_og_card_title) on a post so the Signal & Noise meta-box notice no longer renders. Delegates to the same sn_prepop_clear_sentinels() helper the editor save + legacy dismiss route use. Idempotent — dismissing an already-clear post is a no-op.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_prepop_dismiss',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the post whose prepop notice should be dismissed.',
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok' => array( 'type' => 'boolean' ),
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
 * Ability execute_callback for signal-noise/prepop-dismiss.
 *
 * Validates the post id, then delegates to sn_prepop_clear_sentinels()
 * (inc/ai-prepopulate.php) — the shared impl the legacy REST handler also
 * calls — so the ability and the route stay behaviourally identical.
 *
 * @param array $input { post_id: int }
 * @return array{ok:bool}|WP_Error
 */
function snt_ability_prepop_dismiss( $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

	if ( $post_id < 1 ) {
		return new WP_Error(
			'snt_prepop_invalid_post',
			'A positive post_id is required.',
			array( 'status' => 422 )
		);
	}

	if ( ! function_exists( 'sn_prepop_clear_sentinels' ) ) {
		return new WP_Error(
			'snt_prepop_unavailable',
			'Prepopulation module not loaded.',
			array( 'status' => 500 )
		);
	}

	sn_prepop_clear_sentinels( $post_id );

	return array( 'ok' => true );
}
