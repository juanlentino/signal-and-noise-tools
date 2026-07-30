<?php
/**
 * Signal & Noise Tools — Abilities API: corpus inspection (read-only).
 *
 * Six abilities for pre-publish collision checking and candidate generation
 * against the whole corpus (all non-trash statuses — scheduled/draft posts
 * are the point):
 *   - signal-noise/duplicate-body-scan  (exact-duplicate hash groups)
 *   - signal-noise/near-duplicate-scan  (cousin pairs — TF-IDF cosine, v10.16.0)
 *   - signal-noise/keyword-candidates   (TF-IDF keyword/bigram ranking, v10.17.0)
 *   - signal-noise/link-candidates      (unlinked related notes, v10.17.0)
 *   - signal-noise/list-posts           (metadata-only corpus listing)
 *   - signal-noise/get-post-content     (full bodies, bounded ID set)
 *
 * Category 'tools' — deterministic reads, no AI. All six use the
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

	// ─── 1b. Near-duplicate (cousin) scan — v10.16.0 ────────────────
	wp_register_ability( 'signal-noise/near-duplicate-scan', array(
		'label'               => 'Scan the corpus for near-duplicate (cousin) post pairs',
		'description'         => 'Tokenizes every non-empty body across publish, future, draft, pending, and private statuses, vectors them as TF-IDF against the corpus\' own stats, and returns pairs whose cosine similarity meets the threshold (clamped 0.3-0.95, default 0.6) — each pair: two {post_id, title, slug, status} members plus the 4dp cosine, sorted cosine-descending. Byte-exact duplicates are EXCLUDED (those are duplicate-body-scan\'s finding); empty bodies never pair. Catches the duplicated-then-lightly-edited post the exact scan cannot see. No caching: always a fresh walk.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_corpus_near_duplicate_scan',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ), // bodyless GET delivers null
			'properties'           => array(
				// No post_type this release: the cousin corpus is 'post' by
				// construction, like the ML artifact build.
				'threshold' => array(
					'type'    => 'number',
					'minimum' => 0.3,
					'maximum' => 0.95,
					'default' => 0.6,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'            => array( 'type' => 'boolean' ),
				'pairs'         => array( 'type' => 'array' ),
				'pair_count'    => array( 'type' => 'integer' ),
				'threshold'     => array( 'type' => 'number' ),
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

	// ─── 1c. Keyword candidates — v10.17.0 ──────────────────────────
	// post_id is REQUIRED, so the input type stays plain 'object': the
	// [object,null] union is ONLY for no-required-fields abilities (a
	// bodyless GET could never satisfy `required` anyway — the exact rule
	// tests/abilities-categories.php Group E enforces).
	wp_register_ability( 'signal-noise/keyword-candidates', array(
		'label'               => 'Rank a post\'s own terms as keyword candidates (TF-IDF)',
		'description'         => 'Deterministic candidate generator, no AI: tokenizes the post\'s body and ranks its own unigrams plus adjacent bigrams (both members must survive tokenization; a stopword between two words breaks adjacency — bigrams are phrases that literally appear) by TF-IDF weight against corpus statistics built over ALL five non-trash statuses, bigrams boosted 1.25x, weights 4dp, sorted weight-descending. Returns candidates for a human to accept as focus keywords or tags — nothing auto-writes. Empty body returns ok with zero candidates (an empty body is an answer); unknown/trash/non-post IDs are a 404.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_corpus_keyword_candidates',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'limit'   => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 20,
					'default' => 8,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'         => array( 'type' => 'boolean' ),
				'post_id'    => array( 'type' => 'integer' ),
				'candidates' => array( 'type' => 'array' ),
				'count'      => array( 'type' => 'integer' ),
				'limit'      => array( 'type' => 'integer' ),
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

	// ─── 1d. Link candidates — v10.17.0 ─────────────────────────────
	// Same plain-'object' input type as 1c: post_id is required.
	wp_register_ability( 'signal-noise/link-candidates', array(
		'label'               => 'Suggest related notes the post does not link to yet',
		'description'         => 'Deterministic candidate generator, no AI: reads the prebuilt ML related index (_snt_ml_related) for the post and subtracts every target the body ALREADY links to (internal /notes/ hrefs, the same extractor the artifact build uses) and every non-published target. Returns {post_id, title, slug, url, score} rows (url = resolved permalink), score-descending, for a human to turn into internal links — nothing auto-writes. Returns a 503 while the ML artifacts are unbuilt (same contract as the related pipeline); an empty result after exclusions is a real answer, not an error.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_corpus_link_candidates',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'limit'   => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 10,
					'default' => 5,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'         => array( 'type' => 'boolean' ),
				'post_id'    => array( 'type' => 'integer' ),
				'candidates' => array( 'type' => 'array' ),
				'count'      => array( 'type' => 'integer' ),
				'limit'      => array( 'type' => 'integer' ),
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

	// ─── 1e. Topic clusters — v10.21.0 ──────────────────────────────
	// No inputs at all, so the bodyless-GET union type applies.
	wp_register_ability( 'signal-noise/topic-clusters', array(
		'label'               => 'Read the corpus topic partition',
		'description'         => 'Deterministic topic map, no AI: the stored partition of published notes into topics — connected components over TF-IDF cosine similarity, computed at artifact-build time (publish transitions + the nightly rebuild), never on demand. Each cluster: {members: [post IDs ascending], label: top shared terms}. Singletons are excluded (a topic needs two notes). Returns a 503 while the ML artifacts are unbuilt; an empty cluster list is a real answer, not an error.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_corpus_topic_clusters',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ), // bodyless GET delivers null
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'            => array( 'type' => 'boolean' ),
				'clusters'      => array( 'type' => 'array' ),
				'cluster_count' => array( 'type' => 'integer' ),
				'built_at'      => array( 'type' => 'integer' ),
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
 * Ability wrapper: routes through the ML pipeline registry to
 * snt_ml_cousin_pairs() — the registry (snt_ml_run) is the single dispatch
 * seam for every ML surface, so the ability stays a thin door.
 *
 * @param array|null $input Validated against input_schema above.
 * @return array|WP_Error
 *
 * @since 10.16.0
 */
function snt_ability_corpus_near_duplicate_scan( $input ) {
	if ( ! function_exists( 'snt_ml_run' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'ML pipeline registry not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$args = array();
	if ( is_array( $input ) && isset( $input['threshold'] ) && is_numeric( $input['threshold'] ) ) {
		$args['threshold'] = (float) $input['threshold']; // Clamp 0.3..0.95 lives in the impl.
	}
	return snt_ml_run( 'near-duplicates', $args );
}

/**
 * Ability wrapper: routes through the ML pipeline registry to the stored
 * topic partition — the registry (snt_ml_run) stays the single ML dispatch
 * seam.
 *
 * @param array|null $input Validated against input_schema above (input-less).
 * @return array|WP_Error
 *
 * @since 10.21.0
 */
function snt_ability_corpus_topic_clusters( $input ) {
	if ( ! function_exists( 'snt_ml_run' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'ML pipeline registry not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	return snt_ml_run( 'topic-clusters', array() );
}

/**
 * Ability wrapper: routes through the ML pipeline registry to
 * snt_ml_keyword_candidates() — snt_ml_run stays the single ML dispatch seam.
 *
 * @param array|null $input Validated against input_schema above.
 * @return array|WP_Error
 *
 * @since 10.17.0
 */
function snt_ability_corpus_keyword_candidates( $input ) {
	if ( ! function_exists( 'snt_ml_run' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'ML pipeline registry not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$args = array(
		'post_id' => is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0,
	);
	if ( is_array( $input ) && isset( $input['limit'] ) && is_numeric( $input['limit'] ) ) {
		$args['limit'] = (int) $input['limit']; // Clamp 1..20 lives in the impl.
	}
	return snt_ml_run( 'extract-keywords', $args );
}

/**
 * Ability wrapper: routes through the ML pipeline registry to
 * snt_ml_link_candidates() — snt_ml_run stays the single ML dispatch seam.
 *
 * @param array|null $input Validated against input_schema above.
 * @return array|WP_Error
 *
 * @since 10.17.0
 */
function snt_ability_corpus_link_candidates( $input ) {
	if ( ! function_exists( 'snt_ml_run' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'ML pipeline registry not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$args = array(
		'post_id' => is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0,
	);
	if ( is_array( $input ) && isset( $input['limit'] ) && is_numeric( $input['limit'] ) ) {
		$args['limit'] = (int) $input['limit']; // Clamp 1..10 lives in the impl.
	}
	return snt_ml_run( 'link-candidates', $args );
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
