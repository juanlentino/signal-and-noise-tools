<?php
/**
 * Signal & Noise Tools — Abilities API: AI Suggest+Apply for Health checks.
 *
 * Seven abilities backing the Health-tab AI-assisted fix flows shipped
 * across v4.0.0–v4.1.0:
 *   - signal-noise/ai-alt-suggest          (alt text for attachment; v4.0.0)
 *   - signal-noise/ai-alt-apply             (write _wp_attachment_image_alt; v4.0.0)
 *   - signal-noise/ai-drift-suggest         (time-phrase replacement; v4.0.0; raw-pos fix v4.1.1)
 *   - signal-noise/ai-drift-apply           (post_content splice; v4.0.0; fingerprint gated)
 *   - signal-noise/ai-alt-inline-suggest    (inline <img> alt; v4.0.2; no-apply variant)
 *   - signal-noise/ai-orphan-suggest        (delete/keep/unsure verdict; v4.1.0)
 *   - signal-noise/ai-orphan-apply          (force-delete attachment; v4.1.0)
 *
 * All in the 'ai-generation' category. File-level grouping is by feature
 * (Health-tab Suggest+Apply UX) so a future reviewer reads one file to
 * cover all 7. Each impl wrapper delegates to its dedicated module in
 * inc/ai-alt-text-suggest.php / inc/ai-drift-phrase-suggest.php /
 * inc/ai-alt-inline-suggest.php / inc/ai-orphan-suggest.php.
 *
 * Extracted from inc/abilities-registration.php by the v4.1.3 split (B-11).
 *
 * @package SignalNoiseTools
 * @since 4.1.3 (registrations from 4.0.0 + 4.0.2 + 4.1.0)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/ai-alt-suggest', array(
		'label'               => 'Suggest alt text for an attachment',
		'description'         => 'Generate descriptive 80-125 character alt text for an image attachment via the WP AI Client, using attachment title + caption + filename + first referencing post as context. Does NOT write — returns the suggestion for review.',
		'category'            => 'ai-generation',
		'permission_callback' => 'snt_ability_perm_edit_attachment',
		'execute_callback'    => 'snt_ability_ai_alt_suggest',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'attachment_id' ),
			'properties'           => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => 'Image-attachment post ID to generate alt text for.',
					'minimum'     => 1,
					'examples'    => array( 1234 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'            => array( 'type' => 'boolean' ),
				'suggestion'    => array( 'type' => 'string' ),
				'attachment_id' => array( 'type' => 'integer' ),
				'thumbnail_url' => array( 'type' => 'string', 'description' => 'Thumbnail URL for the attachment, or empty string if no thumbnail exists.' ),
				'filename'      => array( 'type' => 'string', 'description' => 'Bare filename of the attachment (e.g., "my-image.png"), or empty string if unavailable.' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-alt-apply', array(
		'label'               => 'Apply alt text to an attachment',
		'description'         => 'Writes a (possibly user-edited) alt text string to an image attachment\'s _wp_attachment_image_alt meta. Destructive — requires edit_post on the attachment.',
		'category'            => 'ai-generation',
		'permission_callback' => 'snt_ability_perm_edit_attachment',
		'execute_callback'    => 'snt_ability_ai_alt_apply',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'attachment_id', 'alt_text' ),
			'properties'           => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => 'Image-attachment post ID to update.',
					'minimum'     => 1,
					'examples'    => array( 1234 ),
				),
				'alt_text' => array(
					'type'        => 'string',
					'description' => 'The alt text to write. Trimmed, non-empty, max 250 chars.',
					'minLength'   => 1,
					'maxLength'   => 250,
					'examples'    => array( 'A red barn at dusk with two figures walking toward it.' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'            => array( 'type' => 'boolean' ),
				'attachment_id' => array( 'type' => 'integer' ),
				'written_alt'   => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-drift-suggest', array(
		'label'               => 'Suggest replacement for a drifted time-phrase',
		'description'         => 'Generate a temporally-explicit replacement for a stale time-relative phrase (e.g., "recently" → "in early 2025") via the WP AI Client. Includes a fingerprint that the apply call must echo back to detect post_content drift since the suggest. Does NOT write — returns the suggestion + fingerprint for review.',
		'category'            => 'ai-generation',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_ai_drift_suggest',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'phrase', 'position', 'context_snippet' ),
			'properties'           => array(
				'post_id'         => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Post that owns the phrase.', 'examples' => array( 42 ) ),
				'phrase'          => array( 'type' => 'string', 'minLength' => 1, 'description' => 'Original phrase as flagged by drift detection.', 'examples' => array( 'recently' ) ),
				'position'        => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Byte offset of phrase in post_content (from scan).', 'examples' => array( 145 ) ),
				'context_snippet' => array( 'type' => 'string', 'description' => '~200 chars around phrase (from scan; helps AI judge replacement appropriateness).', 'examples' => array( 'we recently shipped a new feature that' ) ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'          => array( 'type' => 'boolean' ),
				'suggestion'  => array( 'type' => 'string' ),
				'fingerprint' => array( 'type' => 'string', 'description' => 'md5 hash to echo back on apply.' ),
				'post_id'     => array( 'type' => 'integer' ),
				'position'    => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-drift-apply', array(
		'label'               => 'Apply replacement for a drifted time-phrase',
		'description'         => 'Replaces a drifted phrase in post_content with a (possibly user-edited) replacement string. The phrase\'s RAW-content position is resolved at runtime via the context_snippet (the stored position from scan is in stripped-content coords; using it directly broke Apply for Gutenberg posts pre-v4.1.1). Gated on a fingerprint match against current post_content to detect concurrent edits since the suggest call. Destructive — writes via wp_update_post().',
		'category'            => 'ai-generation',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_ai_drift_apply',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'phrase', 'position', 'replacement', 'fingerprint', 'context_snippet' ),
			'properties'           => array(
				'post_id'         => array( 'type' => 'integer', 'minimum' => 1, 'examples' => array( 42 ) ),
				'phrase'          => array( 'type' => 'string', 'minLength' => 1, 'examples' => array( 'recently' ) ),
				'position'        => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Raw-content byte offset from the matching suggest call. Advisory — re-resolved at apply time via context_snippet.', 'examples' => array( 145 ) ),
				'replacement'     => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'examples' => array( 'in early 2025' ) ),
				'fingerprint'     => array( 'type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'description' => 'md5 hash from the matching suggest call.', 'examples' => array( 'a1b2c3d4e5f6789012345678901234ab' ) ),
				'context_snippet' => array( 'type' => 'string', 'description' => '~200 chars around the phrase from the scan. Used at apply time to disambiguate which occurrence of $phrase to replace when the phrase appears multiple times in the post.', 'examples' => array( 'we recently shipped a new feature that' ) ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'       => array( 'type' => 'boolean' ),
				'post_id'  => array( 'type' => 'integer' ),
				'replaced' => array( 'type' => 'string' ),
				'with'     => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-alt-inline-suggest', array(
		'label'               => 'Suggest alt text for an inline <img> in a post body',
		'description'         => 'Generate descriptive 80-125 character alt text for an <img> tag found in a post\'s post_content. Uses post title + image filename + ~500 chars of surrounding paragraph context. Does NOT write — returns the suggestion for the user to copy + paste into the editor. Inline-img Apply is deferred indefinitely per block-serialization risk.',
		'category'            => 'ai-generation',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_ai_alt_inline_suggest',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'image_src' ),
			'properties'           => array(
				'post_id'   => array(
					'type'        => 'integer',
					'description' => 'Post that contains the inline <img> tag.',
					'minimum'     => 1,
					'examples'    => array( 42 ),
				),
				'image_src' => array(
					'type'        => 'string',
					'description' => 'The <img src="..."> URL as it appears in post_content. Must match byte-for-byte.',
					'minLength'   => 1,
					'examples'    => array( 'https://juanlentino.com/wp-content/uploads/2026/05/example.png' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'         => array( 'type' => 'boolean' ),
				'suggestion' => array( 'type' => 'string' ),
				'post_id'    => array( 'type' => 'integer' ),
				'image_src'  => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-orphan-suggest', array(
		'label'               => 'Suggest orphan-media verdict for an attachment',
		'description'         => 'AI evaluates a SQL-flagged orphaned attachment and returns a binary-ish verdict (delete/keep/unsure) with reason. Inputs: attachment metadata (filename, title, caption, parent post). Cached 30 days per (attachment_id, post_modified, prompt_version_md5).',
		'category'            => 'ai-generation',
		'permission_callback' => 'snt_ability_perm_edit_attachment',
		'execute_callback'    => 'snt_ability_ai_orphan_suggest',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'attachment_id' ),
			'properties'           => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => 'Image-attachment post ID to evaluate.',
					'minimum'     => 1,
					'examples'    => array( 1234 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'            => array( 'type' => 'boolean' ),
				'verdict'       => array( 'type' => 'string', 'enum' => array( 'delete', 'keep', 'unsure' ) ),
				'reason'        => array( 'type' => 'string' ),
				'attachment_id' => array( 'type' => 'integer' ),
				'thumbnail_url' => array( 'type' => 'string' ),
				'filename'      => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-orphan-apply', array(
		'label'               => 'Delete an orphan attachment',
		'description'         => 'Force-deletes an orphan-verdict attachment via wp_delete_attachment($id, true). Destructive. Skips trash. Clears the orphan verdict cache for this attachment.',
		'category'            => 'ai-generation',
		'permission_callback' => 'snt_ability_perm_delete_attachment',
		'execute_callback'    => 'snt_ability_ai_orphan_apply',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'attachment_id' ),
			'properties'           => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => 'Image-attachment post ID to delete.',
					'minimum'     => 1,
					'examples'    => array( 1234 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'            => array( 'type' => 'boolean' ),
				'attachment_id' => array( 'type' => 'integer' ),
				'deleted'       => array( 'type' => 'boolean' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );
} );

/**
 * Execute callback for signal-noise/ai-alt-suggest.
 * Thin wrapper around snt_ai_alt_suggest_impl().
 *
 * @since 4.0.0
 */
function snt_ability_ai_alt_suggest( $input ) {
	if ( ! function_exists( 'snt_ai_alt_suggest_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Alt-suggest helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_alt_suggest_impl( (int) $input['attachment_id'] );
}

/**
 * Execute callback for signal-noise/ai-alt-apply.
 * Thin wrapper around snt_ai_alt_apply_impl().
 *
 * @since 4.0.0
 */
function snt_ability_ai_alt_apply( $input ) {
	if ( ! function_exists( 'snt_ai_alt_apply_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Alt-apply helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_alt_apply_impl(
		(int) $input['attachment_id'],
		(string) $input['alt_text']
	);
}

/**
 * Execute callback for signal-noise/ai-drift-suggest.
 * Thin wrapper around snt_ai_drift_suggest_impl().
 *
 * @since 4.0.0
 */
function snt_ability_ai_drift_suggest( $input ) {
	if ( ! function_exists( 'snt_ai_drift_suggest_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Drift-suggest helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_drift_suggest_impl(
		(int) $input['post_id'],
		(string) $input['phrase'],
		(int) $input['position'],
		(string) $input['context_snippet']
	);
}

/**
 * Execute callback for signal-noise/ai-drift-apply.
 * Thin wrapper around snt_ai_drift_apply_impl().
 *
 * @since 4.0.0
 */
function snt_ability_ai_drift_apply( $input ) {
	if ( ! function_exists( 'snt_ai_drift_apply_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Drift-apply helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_drift_apply_impl(
		(int) $input['post_id'],
		(string) $input['phrase'],
		(int) $input['position'],
		(string) $input['replacement'],
		(string) $input['fingerprint'],
		(string) ( $input['context_snippet'] ?? '' )
	);
}

/**
 * Execute callback for signal-noise/ai-alt-inline-suggest.
 * Thin wrapper around snt_ai_alt_inline_suggest_impl().
 *
 * @since 4.0.2
 */
function snt_ability_ai_alt_inline_suggest( $input ) {
	if ( ! function_exists( 'snt_ai_alt_inline_suggest_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Inline-alt suggest helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_alt_inline_suggest_impl(
		(int) $input['post_id'],
		(string) $input['image_src']
	);
}

/**
 * Execute callback for signal-noise/ai-orphan-suggest.
 * Thin wrapper around snt_ai_orphan_suggest_impl().
 *
 * @since 4.1.0
 */
function snt_ability_ai_orphan_suggest( $input ) {
	if ( ! function_exists( 'snt_ai_orphan_suggest_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Orphan-suggest helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_orphan_suggest_impl( (int) $input['attachment_id'] );
}

/**
 * Execute callback for signal-noise/ai-orphan-apply.
 * Thin wrapper around snt_ai_orphan_apply_impl().
 *
 * @since 4.1.0
 */
function snt_ability_ai_orphan_apply( $input ) {
	if ( ! function_exists( 'snt_ai_orphan_apply_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Orphan-apply helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_orphan_apply_impl( (int) $input['attachment_id'] );
}
