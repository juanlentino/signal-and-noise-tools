<?php
/**
 * Signal & Noise Tools — Block-migrations apply impl.
 *
 * Fingerprint-validated structural-block replacement. Since v7.7.1 the
 * pipeline (capability → type gate → parse → named-block guard → sanitize →
 * replace-by-fingerprint → serialize → wp_update_post) lives in the shared
 * engine, inc/block-fingerprint-engine.php — this file passes the surface's
 * error-code map and shapes the success payload. v7.7.1 also brings this
 * surface the v6.39.2 wp_kses_post replacement sanitization that only
 * pattern-adoption had (stored-XSS parity).
 *
 * @package SignalNoiseTools
 * @since 4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure impl: apply a fingerprint-validated block migration.
 *
 * @param int           $post_id
 * @param string        $block_fingerprint  md5 from the scan.
 * @param string        $replacement_markup Block markup string (suggest output, possibly user-edited).
 * @param string        $migration_type     For diagnostic echo + invalid-type gate.
 * @param callable|null $write_callback     (v10.40.0, sn_apply session 6b) Optional
 *                                          write-step override — see
 *                                          snt_block_fp_apply()'s docblock. Default
 *                                          null preserves the original
 *                                          wp_update_post() behavior byte-for-byte.
 * @return array{ok:bool,post_id:int,migration_type:string,old_content:string,new_content:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_block_migration_capability        (403)
 *   snt_block_migration_invalid_type      (422)
 *   snt_block_migration_post_not_found    (404)
 *   snt_block_migration_conflict          (409)
 *   snt_block_migration_invalid_markup    (422)
 *   snt_block_migration_write_failed      (500)
 *
 * @since 4.5.0
 */
function snt_block_migrations_apply_impl( $post_id, $block_fingerprint, $replacement_markup, $migration_type, $write_callback = null ) {
	$post_id        = (int) $post_id;
	$migration_type = (string) $migration_type;

	if ( ! defined( 'SNT_BLOCK_MIGRATIONS_VALID_TYPES' ) ) {
		define( 'SNT_BLOCK_MIGRATIONS_VALID_TYPES', array( 'heading-hierarchy-skip' ) );
	}

	$result = snt_block_fp_apply( array(
		'post_id'            => $post_id,
		'block_fingerprint'  => (string) $block_fingerprint,
		'replacement_markup' => (string) $replacement_markup,
		'type'               => $migration_type,
		'valid_types'        => SNT_BLOCK_MIGRATIONS_VALID_TYPES,
		'error_codes'        => array(
			'capability'     => 'snt_block_migration_capability',
			'invalid_type'   => 'snt_block_migration_invalid_type',
			'post_not_found' => 'snt_block_migration_post_not_found',
			'invalid_markup' => 'snt_block_migration_invalid_markup',
			'conflict'       => 'snt_block_migration_conflict',
			'write_failed'   => 'snt_block_migration_write_failed',
		),
		'error_messages'     => array(
			'invalid_type' => __( 'migration_type must be one of: heading-hierarchy-skip.', 'signal-and-noise-tools' ),
		),
		'write_callback'     => $write_callback,
	) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'ok'             => true,
		'post_id'        => $post_id,
		'migration_type' => $migration_type,
		'old_content'    => $result['old_content'] ?? '',
		'new_content'    => $result['new_content'] ?? '',
	);
}
