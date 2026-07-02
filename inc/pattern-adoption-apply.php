<?php
/**
 * Signal & Noise Tools — Pattern-adoption apply impl.
 *
 * Fingerprint-validated structural-block replacement. Operates entirely on
 * block-tree nodes — no byte offsets — sidestepping the v4.1.1
 * raw-vs-stripped coordinate bug class. Since v7.7.1 the pipeline
 * (capability → type gate → parse → named-block guard → wp_kses_post
 * sanitize → replace-by-fingerprint → serialize → wp_update_post) lives in
 * the shared engine, inc/block-fingerprint-engine.php — this file passes the
 * surface's error-code map (including the historical quirk that invalid
 * markup reuses snt_pattern_adoption_invalid_pattern_type) and shapes the
 * success payload. One v7.7.1 ordering change: capability now gates BEFORE
 * the type check (403 wins over 422 for unauthorized callers).
 *
 * Acknowledged tradeoff (carries from v4.0.x): wp_update_post() triggers
 * downstream save-hook fanout (cache busts, revisions, save_post listeners).
 * Cost is proportional to applies (small).
 *
 * @package SignalNoiseTools
 * @since 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure impl: apply a fingerprint-validated structural replacement.
 *
 * @param int    $post_id
 * @param string $block_fingerprint  md5 from the scan (Suggest path).
 * @param string $replacement_markup Block markup string (typically the
 *                                   suggestion_markup from the suggest impl,
 *                                   possibly user-edited in the modal).
 * @param string $pattern_type       For diagnostic echo + invalid-type gate.
 * @return array{ok:bool,post_id:int,replaced_pattern_type:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_pattern_adoption_invalid_pattern_type   (422)
 *   snt_pattern_adoption_post_not_found         (404)
 *   snt_pattern_adoption_conflict               (409)
 *   snt_pattern_adoption_capability             (403)
 *   snt_pattern_adoption_write_failed           (500)
 *
 * @since 4.3.0
 */
function snt_ai_pattern_adoption_apply_impl( $post_id, $block_fingerprint, $replacement_markup, $pattern_type ) {
	$post_id      = (int) $post_id;
	$pattern_type = (string) $pattern_type;

	if ( ! defined( 'SNT_PATTERN_ADOPTION_VALID_TYPES' ) ) {
		// Defensive — suggest module declares this. Fallback for test-isolation runs.
		define( 'SNT_PATTERN_ADOPTION_VALID_TYPES', array( 'pull-quote', 'steps-enumerated' ) );
	}

	$result = snt_block_fp_apply( array(
		'post_id'            => $post_id,
		'block_fingerprint'  => (string) $block_fingerprint,
		'replacement_markup' => (string) $replacement_markup,
		'type'               => $pattern_type,
		'valid_types'        => SNT_PATTERN_ADOPTION_VALID_TYPES,
		'error_codes'        => array(
			'capability'     => 'snt_pattern_adoption_capability',
			'invalid_type'   => 'snt_pattern_adoption_invalid_pattern_type',
			'post_not_found' => 'snt_pattern_adoption_post_not_found',
			// Historical quirk preserved: invalid markup reuses the
			// invalid-pattern-type code (public contract since 4.5.2).
			'invalid_markup' => 'snt_pattern_adoption_invalid_pattern_type',
			'conflict'       => 'snt_pattern_adoption_conflict',
			'write_failed'   => 'snt_pattern_adoption_write_failed',
		),
		'error_messages'     => array(
			'invalid_type' => __( 'pattern_type must be one of: pull-quote, steps-enumerated.', 'signal-noise-tools' ),
		),
	) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'ok'                    => true,
		'post_id'               => $post_id,
		'replaced_pattern_type' => $pattern_type,
	);
}
