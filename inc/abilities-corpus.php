<?php
/**
 * Signal & Noise Tools — Abilities API: corpus inspection (read-only).
 *
 * Three abilities for pre-publish collision checking against the whole
 * corpus (all non-trash statuses — scheduled/draft posts are the point):
 *   - signal-noise/duplicate-body-scan  (exact-duplicate hash groups)
 *   - signal-noise/list-posts           (metadata-only corpus listing)
 *   - signal-noise/get-post-content     (full bodies, bounded ID set)
 *
 * Category 'tools' — deterministic reads, no AI. All three use the
 * manage_options permission callback like the sibling scans; content
 * from non-public statuses is therefore double-gated (ability cap +
 * the MCP read door's own auth). Exposed on the sn READ door only —
 * the mutation surface does not grow.
 *
 * Impls live in inc/corpus-inspect.php. Mirrors the registration shape
 * of inc/abilities-block-migrations.php.
 *
 * @package SignalNoiseTools
 * @since 10.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	// ─── 1. Duplicate body scan ─────────────────────────────────────
	wp_register_ability( 'signal-noise/duplicate-body-scan', array(
		'label'               => 'Scan the corpus for posts with identical bodies',
		'description'         => 'Hashes trimmed post_content for every post across publish, future, draft, pending, and private statuses; returns groups where the same non-empty hash appears more than once (each member: post ID, title, slug, status, post_date). Exact duplicates only — catches duplicate-to-seed posts whose body was never replaced. Empty bodies never group. No caching: always a fresh walk.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_corpus_duplicate_scan',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ), // bodyless GET delivers null
			'properties'           => array(
				'post_type' => array( 'type' => 'string', 'default' => 'post' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'            => array( 'type' => 'boolean' ),
				'groups'        => array( 'type' => 'array' ),
				'group_count'   => array( 'type' => 'integer' ),
				'posts_scanned' => array( 'type' => 'integer' ),
				'truncated'     => array( 'type' => 'boolean' ),
				'scanned_at'    => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	// ─── 2. List posts ──────────────────────────────────────────────
	wp_register_ability( 'signal-noise/list-posts', array(
		'label'               => 'List corpus metadata for every post',
		'description'         => 'Metadata-only corpus listing across all non-trash statuses, optionally filtered by status and post_type. Per post: ID, title, slug, status, post_type, post_date, post_modified, categories, tags, word count, content hash, and the excerpt (manual excerpt, else a 55-word trim). Never returns bodies — pair with get-post-content for the few posts that turn out genuinely close.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_corpus_list_posts',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ), // bodyless GET delivers null
			'properties'           => array(
				'status'    => array(
					'type'    => 'string',
					'enum'    => array( 'any', 'publish', 'future', 'draft', 'pending', 'private' ),
					'default' => 'any',
				),
				'post_type' => array( 'type' => 'string', 'default' => 'post' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'        => array( 'type' => 'boolean' ),
				'posts'     => array( 'type' => 'array' ),
				'count'     => array( 'type' => 'integer' ),
				'truncated' => array( 'type' => 'boolean' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	// ─── 3. Get post content ────────────────────────────────────────
	wp_register_ability( 'signal-noise/get-post-content', array(
		'label'               => 'Fetch full bodies for a bounded set of posts',
		'description'         => 'Given 1-20 post IDs, returns full post_content plus the same metadata row list-posts returns for each. Unknown or trashed IDs come back in `missing` rather than being silently dropped. For the small number of posts a collision check flags as genuinely close — not a corpus dump.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_corpus_get_post_content',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_ids' ),
			'properties'           => array(
				'post_ids' => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'integer', 'minimum' => 1 ),
					'minItems' => 1,
					'maxItems' => 20,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'posts'   => array( 'type' => 'array' ),
				'missing' => array( 'type' => 'array' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

} );

/* ════════════════════════════════════════════════════════════════════════
 * Ability execute wrappers — delegate to impls in inc/corpus-inspect.php.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Ability wrapper: delegates to snt_corpus_duplicate_scan().
 *
 * @param array|null $input Validated against input_schema above.
 * @return array|WP_Error
 *
 * @since 10.6.0
 */
function snt_ability_corpus_duplicate_scan( $input ) {
	if ( ! function_exists( 'snt_corpus_duplicate_scan' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Corpus inspect helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	// Post-type validation (registered + public) lives in the impl, which
	// returns WP_Error 422 for internal/unknown types.
	return snt_corpus_duplicate_scan(
		is_array( $input ) && isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post'
	);
}

/**
 * Ability wrapper: delegates to snt_corpus_list_posts().
 *
 * @param array|null $input Validated against input_schema above.
 * @return array|WP_Error
 *
 * @since 10.6.0
 */
function snt_ability_corpus_list_posts( $input ) {
	if ( ! function_exists( 'snt_corpus_list_posts' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Corpus inspect helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	return snt_corpus_list_posts(
		is_array( $input ) && isset( $input['status'] ) ? (string) $input['status'] : 'any',
		is_array( $input ) && isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post'
	);
}

/**
 * Ability wrapper: delegates to snt_corpus_get_post_content().
 *
 * @param array|null $input Validated against input_schema above.
 * @return array|WP_Error
 *
 * @since 10.6.0
 */
function snt_ability_corpus_get_post_content( $input ) {
	if ( ! function_exists( 'snt_corpus_get_post_content' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Corpus inspect helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	return snt_corpus_get_post_content( is_array( $input ) ? ( $input['post_ids'] ?? array() ) : array() );
}
