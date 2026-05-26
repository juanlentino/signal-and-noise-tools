<?php
/**
 * Signal & Noise Tools — Abilities API: AI content generation (post editor).
 *
 * Three abilities wrapping the post-editor AI features:
 *   - signal-noise/ai-generate-meta-description  (writes _sn_meta_description)
 *   - signal-noise/ai-generate-og-card-title     (writes _sn_og_card_title + regenerates PNG)
 *   - signal-noise/ai-generate-excerpt           (returns text; caller writes post_excerpt)
 *
 * All three back the post-editor meta-box buttons in the AI Tools section.
 * Co-located here because they share auth (edit_post on $input.post_id) and
 * a similar shape (single post_id input, text output).
 *
 * Extracted from inc/abilities-registration.php by the v4.1.3 split (B-11).
 *
 * @package SignalNoiseTools
 * @since 4.1.3 (registrations from 2.5.0)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/ai-generate-meta-description', array(
		'label'               => 'Generate SEO meta description with AI',
		'description'         => 'Generates a 140-160 character meta description from post content via the WP AI Client. Writes to the _sn_meta_description post meta override.',
		'category'            => 'ai-generation',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_ai_generate_meta_description',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID to summarize.',
					'minimum'     => 1,
					'examples'    => array( 42, 1023 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'          => array( 'type' => 'boolean' ),
				'description' => array( 'type' => 'string' ),
				'length'      => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-generate-og-card-title', array(
		'label'               => 'Generate OG card title with AI',
		'description'         => 'Generates a 60-90 character punchy variant of the post title via the WP AI Client, writes to _sn_og_card_title post meta, AND re-runs sn_generate_og_card so the social-share PNG reflects the new title immediately.',
		'category'            => 'ai-generation',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_ai_generate_og_card_title',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID.',
					'minimum'     => 1,
					'examples'    => array( 42, 1023 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'               => array( 'type' => 'boolean' ),
				'title'            => array( 'type' => 'string' ),
				'length'           => array( 'type' => 'integer' ),
				'card_regenerated' => array( 'type' => 'boolean' ),
				'card_url'         => array( 'type' => 'string', 'format' => 'uri' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-generate-excerpt', array(
		'label'               => 'Generate post excerpt with AI',
		'description'         => 'Generates a 50-75 word, 2-3 sentence excerpt from post content via the WP AI Client. Returns the text; the caller writes it to WP\'s native post_excerpt field.',
		'category'            => 'ai-generation',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_ai_generate_excerpt',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID to summarize.',
					'minimum'     => 1,
					'examples'    => array( 42, 1023 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'excerpt' => array( 'type' => 'string' ),
				'length'  => array( 'type' => 'integer' ),
				'words'   => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );
} );

/**
 * Ability execute callback: signal-noise/ai-generate-meta-description.
 * Thin wrapper around snt_ai_meta_desc_impl().
 * @since 3.7.5
 */
function snt_ability_ai_generate_meta_description( $input ) {
	if ( ! function_exists( 'snt_ai_meta_desc_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Meta-desc helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_meta_desc_impl( (int) $input['post_id'] );
}

/**
 * Ability execute callback: signal-noise/ai-generate-og-card-title.
 * Thin wrapper around snt_ai_og_card_title_impl().
 * @since 3.7.5
 */
function snt_ability_ai_generate_og_card_title( $input ) {
	if ( ! function_exists( 'snt_ai_og_card_title_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'OG-title helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_og_card_title_impl( (int) $input['post_id'] );
}

/**
 * Ability execute callback: signal-noise/ai-generate-excerpt.
 * Thin wrapper around snt_ai_excerpt_impl().
 * @since 3.7.5
 */
function snt_ability_ai_generate_excerpt( $input ) {
	if ( ! function_exists( 'snt_ai_excerpt_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Excerpt helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_excerpt_impl( (int) $input['post_id'] );
}
