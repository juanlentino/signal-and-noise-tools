<?php
/**
 * Signal & Noise Tools — Abilities API: block-migrations Suggest+Apply.
 *
 * Four abilities backing the Tools-tab Block Migration tool (v4.5.0):
 *   - signal-noise/block-migrations-scan      (full repository scan)
 *   - signal-noise/block-migrations-suggest   (deterministic transformation preview)
 *   - signal-noise/block-migrations-apply     (fingerprint-validated write)
 *   - signal-noise/block-migrations-dismiss   (per-finding dismiss state)
 *
 * Category **'tools'** — NOT 'ai-generation'. These are pure structural
 * operations; no AI calls anywhere in the impl. AI agents discovering
 * abilities by category should find these under 'tools' alongside other
 * deterministic utilities.
 *
 * Mirrors inc/abilities-ai-pattern-adoption.php registration shape.
 *
 * @package SignalNoiseTools
 * @since 4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	// ─── 1. Scan ────────────────────────────────────────────────────
	wp_register_ability( 'signal-noise/block-migrations-scan', array(
		'label'               => 'Scan all posts for block-migration candidates',
		'description'         => 'Walks all published post_type=post posts; identifies core/heading blocks where attrs.level === 3 with no preceding core/heading attrs.level === 2 in the same post (heading-hierarchy-skip, WCAG 1.3.1 violation). Returns candidates with fingerprints for safe-apply concurrency control. Caches result per-user for 1 hour.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_block_migrations_scan',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'candidates' => array( 'type' => 'array' ),
				'counts'     => array(
					'type'       => 'object',
					'properties' => array(
						'heading_hierarchy_skip' => array( 'type' => 'integer' ),
						'posts_affected'         => array( 'type' => 'integer' ),
					),
				),
				'scanned_at' => array( 'type' => 'integer' ),
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

	// ─── 2. Suggest ─────────────────────────────────────────────────
	wp_register_ability( 'signal-noise/block-migrations-suggest', array(
		'label'               => 'Generate a block-migration suggestion preview',
		'description'         => 'Given (post_id, block_fingerprint, migration_type), returns the deterministic transformation as block markup. NO AI — pure structural mutation via parse_blocks + serialize_block round-trip. Does NOT write.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_block_migrations_suggest',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'block_fingerprint', 'migration_type' ),
			'properties'           => array(
				'post_id'           => array( 'type' => 'integer', 'minimum' => 1 ),
				'block_fingerprint' => array( 'type' => 'string', 'minLength' => 32, 'maxLength' => 32 ),
				'migration_type'    => array(
					'type' => 'string',
					'enum' => array( 'heading-hierarchy-skip' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'                => array( 'type' => 'boolean' ),
				'suggestion_markup' => array( 'type' => 'string' ),
				'fingerprint'       => array( 'type' => 'string' ),
				'post_id'           => array( 'type' => 'integer' ),
				'migration_type'    => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
		),
	) );

	// ─── 3. Apply ───────────────────────────────────────────────────
	wp_register_ability( 'signal-noise/block-migrations-apply', array(
		'label'               => 'Apply a block migration to a post',
		'description'         => 'Fingerprint-validated structural-block replacement. parse_blocks → locate by fingerprint → mutate node → serialize_blocks → wp_update_post. Returns 409 conflict if the block changed since the suggest call (re-run scan to refresh). Triggers WP revision automatically.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_block_migrations_apply',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'block_fingerprint', 'replacement_markup', 'migration_type' ),
			'properties'           => array(
				'post_id'            => array( 'type' => 'integer', 'minimum' => 1 ),
				'block_fingerprint'  => array( 'type' => 'string', 'minLength' => 32, 'maxLength' => 32 ),
				'replacement_markup' => array( 'type' => 'string', 'minLength' => 1 ),
				'migration_type'     => array(
					'type' => 'string',
					'enum' => array( 'heading-hierarchy-skip' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'             => array( 'type' => 'boolean' ),
				'post_id'        => array( 'type' => 'integer' ),
				'migration_type' => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array( 'idempotent' => false, 'destructive' => true ),
		),
	) );

	// ─── 4. Dismiss ─────────────────────────────────────────────────
	wp_register_ability( 'signal-noise/block-migrations-dismiss', array(
		'label'               => 'Dismiss a block-migration candidate',
		'description'         => 'Appends the fingerprint to the post\'s _snt_block_migrations_dismissed meta. Subsequent scans exclude this candidate. Idempotent — re-dismissing the same fingerprint is a no-op.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_block_migrations_dismiss',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'block_fingerprint', 'migration_type' ),
			'properties'           => array(
				'post_id'           => array( 'type' => 'integer', 'minimum' => 1 ),
				'block_fingerprint' => array( 'type' => 'string', 'minLength' => 32, 'maxLength' => 32 ),
				'migration_type'    => array(
					'type' => 'string',
					'enum' => array( 'heading-hierarchy-skip' ),
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
			'annotations'  => array( 'idempotent' => true ),
		),
	) );
} );

/* ════════════════════════════════════════════════════════════════════════
 * Ability execute wrappers — delegate to impls.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Ability wrapper: delegates to snt_block_migrations_run_scan().
 *
 * @param array $input  Validated against input_schema above (empty object — no args).
 * @return array|WP_Error
 *
 * @since 4.5.0
 */
function snt_ability_block_migrations_scan( $input ) {
	if ( ! function_exists( 'snt_block_migrations_run_scan' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Block migrations scan helper not loaded.', 'signal-noise-tools' ), array( 'status' => 500 ) );
	}
	return snt_block_migrations_run_scan();
}

/**
 * Ability wrapper: delegates to snt_block_migrations_suggest_impl().
 *
 * @param array $input  Validated against input_schema above.
 * @return array|WP_Error
 *
 * @since 4.5.0
 */
function snt_ability_block_migrations_suggest( $input ) {
	if ( ! function_exists( 'snt_block_migrations_suggest_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Block migrations suggest helper not loaded.', 'signal-noise-tools' ), array( 'status' => 500 ) );
	}
	return snt_block_migrations_suggest_impl(
		(int)    ( $input['post_id'] ?? 0 ),
		(string) ( $input['block_fingerprint'] ?? '' ),
		(string) ( $input['migration_type'] ?? '' )
	);
}

/**
 * Ability wrapper: delegates to snt_block_migrations_apply_impl().
 *
 * @param array $input  Validated against input_schema above.
 * @return array|WP_Error
 *
 * @since 4.5.0
 */
function snt_ability_block_migrations_apply( $input ) {
	if ( ! function_exists( 'snt_block_migrations_apply_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Block migrations apply helper not loaded.', 'signal-noise-tools' ), array( 'status' => 500 ) );
	}
	return snt_block_migrations_apply_impl(
		(int)    ( $input['post_id'] ?? 0 ),
		(string) ( $input['block_fingerprint'] ?? '' ),
		(string) ( $input['replacement_markup'] ?? '' ),
		(string) ( $input['migration_type'] ?? '' )
	);
}

/**
 * Ability wrapper: dismisses a block-migration candidate (inline impl).
 *
 * Appends "<migration_type>:<fingerprint>" to the post's
 * _snt_block_migrations_dismissed meta and invalidates the current
 * user's scan transient. Idempotent — re-dismissing the same
 * fingerprint is a no-op.
 *
 * @param array $input  Validated against input_schema above.
 * @return array|WP_Error
 *
 * @since 4.5.0
 */
function snt_ability_block_migrations_dismiss( $input ) {
	$post_id        = (int) ( $input['post_id'] ?? 0 );
	$fingerprint    = (string) ( $input['block_fingerprint'] ?? '' );
	$migration_type = (string) ( $input['migration_type'] ?? '' );

	if ( ! $post_id || ! $fingerprint || ! $migration_type ) {
		return new WP_Error( 'snt_block_migration_invalid_input', __( 'post_id, block_fingerprint, and migration_type are required.', 'signal-noise-tools' ), array( 'status' => 422 ) );
	}

	$existing = (array) get_post_meta( $post_id, '_snt_block_migrations_dismissed', true );
	$key = $migration_type . ':' . $fingerprint;
	if ( ! in_array( $key, $existing, true ) ) {
		$existing[] = $key;
		update_post_meta( $post_id, '_snt_block_migrations_dismissed', $existing );
	}

	// Invalidate the user's scan transient.
	$tkey = 'snt_block_migrations_candidates_' . (int) get_current_user_id();
	delete_transient( $tkey );

	return array( 'ok' => true );
}
