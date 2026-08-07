<?php
/**
 * Signal & Noise Tools — Abilities API: block-migrations Suggest+Apply.
 *
 * Three abilities backing the Tools-tab Block Migration tool (v4.5.0):
 *   - signal-noise/block-migrations-scan      (full repository scan)
 *   - signal-noise/block-migrations-suggest   (deterministic transformation preview)
 *   - signal-noise/block-migrations-apply     (fingerprint-validated write)
 *
 * Dismissal is signal-noise/dismiss-candidate surface=block-migrations
 * (inc/abilities-dismiss.php), which dispatches to this file's
 * snt_block_migrations_dismiss_impl(). The per-surface dismiss ability
 * was removed in v8.0.0.
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
		'description'         => 'Walks all published post_type=post posts; identifies core/heading blocks where attrs.level === 3 with no preceding core/heading attrs.level === 2 in the same post (heading-hierarchy-skip, WCAG 1.3.1 violation). SCOPE, deliberate: level 3 ONLY — H4 subheads with no H2/H3 above them are the accepted house pattern for Notes (most of the corpus is built that way) and are NOT flagged; a post using H3 the same way IS flagged, because H3-without-H2 is the drift this check exists to catch against that H4 convention. Publish-only: scheduled (status "future") posts are not walked — a scheduled post with an H3 skip surfaces only after it publishes. Returns candidates with fingerprints for safe-apply concurrency control. Caches result per-user for 1 hour.',
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
		return new WP_Error( 'snt_helper_unavailable', __( 'Block migrations scan helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
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
		return new WP_Error( 'snt_helper_unavailable', __( 'Block migrations suggest helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
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
		return new WP_Error( 'snt_helper_unavailable', __( 'Block migrations apply helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	return snt_block_migrations_apply_impl(
		(int)    ( $input['post_id'] ?? 0 ),
		(string) ( $input['block_fingerprint'] ?? '' ),
		(string) ( $input['replacement_markup'] ?? '' ),
		(string) ( $input['migration_type'] ?? '' )
	);
}

/**
 * Shared dismiss impl (extracted from the ability wrapper in v7.7.0 so the
 * canonical signal-noise/dismiss-candidate dispatcher and the deprecated
 * per-surface wrapper share ONE store-write — and only the deprecated
 * wrapper emits the notice, per the abilities-deprecations placement rule).
 *
 * Appends "<migration_type>:<fingerprint>" to the post's
 * _snt_block_migrations_dismissed meta and invalidates the current
 * user's scan transient. Idempotent — re-dismissing the same
 * fingerprint is a no-op.
 *
 * @since 7.7.0 (logic inline in the wrapper since 4.5.0)
 * @param int    $post_id        Post the candidate belongs to.
 * @param string $fingerprint    Block fingerprint from the scan.
 * @param string $migration_type Migration type key, e.g. heading-hierarchy-skip.
 * @return array{ok:bool,message:string}|WP_Error
 */
function snt_block_migrations_dismiss_impl( $post_id, $fingerprint, $migration_type ) {
	$post_id        = (int) $post_id;
	$fingerprint    = (string) $fingerprint;
	$migration_type = (string) $migration_type;

	if ( ! $post_id || ! $fingerprint || ! $migration_type ) {
		return new WP_Error( 'snt_block_migration_invalid_input', __( 'post_id, block_fingerprint, and migration_type are required.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	// get_post_meta( …, true ) returns '' when the key is unset, and (array)''
	// is array('') — filter the phantom empty entry out so a first dismiss
	// stores exactly one key (latent since 4.5.0; harmless to the scanner's
	// in_array check but wrong data, cleaned with the 7.7.0 extraction).
	$existing = array_values( array_filter(
		(array) get_post_meta( $post_id, '_snt_block_migrations_dismissed', true ),
		'strlen'
	) );
	$key = $migration_type . ':' . $fingerprint;
	if ( ! in_array( $key, $existing, true ) ) {
		$existing[] = $key;
		update_post_meta( $post_id, '_snt_block_migrations_dismissed', $existing );
	}

	// Invalidate the user's scan transient.
	$tkey = 'snt_block_migrations_candidates_' . (int) get_current_user_id();
	delete_transient( $tkey );

	return array( 'ok' => true, 'message' => __( 'Candidate dismissed.', 'signal-and-noise-tools' ) );
}
