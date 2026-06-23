<?php
/**
 * Signal & Noise Tools — Abilities API: content-facing artifacts.
 *
 * Two abilities for per-post content artifacts and feed-level activity:
 *   - signal-noise/regenerate-og-card  (category: content)
 *   - signal-noise/get-rss-stats        (category: diagnostics)
 *
 * Both touch user-visible content surfaces — OG cards are the social-share
 * artifact, RSS stats expose feed-subscriber activity. Co-located here so
 * a future "content audit" reviewer reads one file to cover both.
 *
 * Extracted from inc/abilities-registration.php by the v4.1.3 split (B-11).
 *
 * @package SignalNoiseTools
 * @since 4.1.3 (registrations from 2.0.4 + 3.7.5)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/regenerate-og-card', array(
		'label'               => 'Regenerate Open Graph card image',
		'description'         => 'Rebuilds the social-share card image (/wp-content/uploads/sn-og/post-{ID}.png) for a single post. Use after editing post title or featured image.',
		'category'            => 'content',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_regenerate_og_card',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID whose OG card should be regenerated.',
					'minimum'     => 1,
					'examples'    => array( 42, 1023 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'        => array( 'type' => 'boolean' ),
				'image_url' => array( 'type' => 'string', 'format' => 'uri' ),
				'message'   => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations' => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-rss-stats', array(
		'label'               => 'Get RSS feed activity statistics',
		'description'         => 'Returns the most recent RSS feed request timestamp + 24h / 7d / 30d totals + unique visitor counts. Backed by the sn_rss_tracker module. Use to verify RSS feed traffic before changing feed structure or auditing crawler activity.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_rss_stats',
		'input_schema'        => array(
			// v2.5.4: see purge-all-caches comment — null accepted because
			// readonly abilities (GET) receive null when caller omits ?input=.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'   => array( 'type' => 'boolean' ),
				'data' => array(
					'type'        => 'object',
					'description' => 'Stats payload from sn_rss_tracker_window_stats_multi().',
					'properties'  => array(
						'last_request'          => array(
							'type'        => array( 'string', 'null' ),
							'description' => 'UTC timestamp of the most recent RSS feed request (Y-m-d H:i:s), or null if no requests recorded.',
						),
						'last_request_relative' => array(
							'type'        => 'string',
							'description' => 'Human-readable relative time (e.g. "3 hours ago"). Empty string when no last_request.',
						),
						'windows'               => array(
							'type'                 => 'object',
							'description'          => 'Per-window aggregate counts. Keys are day-counts (1, 7, 30). Each value has total request count + count of distinct ua_hash values.',
							'additionalProperties' => array(
								'type'       => 'object',
								'properties' => array(
									'total'   => array(
										'type'        => 'integer',
										'description' => 'Total RSS feed requests in this window.',
										'minimum'     => 0,
									),
									'uniques' => array(
										'type'        => 'integer',
										'description' => 'Distinct ua_hash count in this window (proxy for unique subscriber clients).',
										'minimum'     => 0,
									),
								),
							),
						),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/merge-tags', array(
		'label'               => 'Merge duplicate post tags',
		'description'         => 'Folds one or more source post tags into a canonical tag: reassigns every post to the canonical tag, deletes the sources, and 301-redirects the old /notes/tag/ archives. Destructive and not idempotent. Use to consolidate near-duplicate tags (e.g. "AI-Generated Music" + "AI Generated Music").',
		'category'            => 'content',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_merge_tags',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'from_slugs', 'into_slug' ),
			'properties'           => array(
				'from_slugs' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'minItems'    => 1,
					'description' => 'Slugs of the source tags to fold in.',
				),
				'into_slug'  => array(
					'type'        => 'string',
					'description' => 'Slug of the canonical tag to keep.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'          => array( 'type' => 'boolean' ),
				'posts_moved' => array( 'type' => 'integer' ),
				'into_slug'   => array( 'type' => 'string' ),
				'message'     => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent'  => false,
				'destructive' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/suggest-tags', array(
		'label'               => 'Suggest tags for a post',
		'description'         => 'Reads a post and suggests relevant tags chosen ONLY from the existing post_tag vocabulary (never invents tags). Read-only: returns suggestions to apply, does not assign anything.',
		'category'            => 'content',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_suggest_tags',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The post to suggest tags for.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'        => array( 'type' => 'boolean' ),
				'post_id'   => array( 'type' => 'integer' ),
				'suggested' => array( 'type' => 'array' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array( 'readonly' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/prune-unused-tags', array(
		'label'               => 'Delete unused (zero-post) tags',
		'description'         => 'Deletes every post_tag term that has zero posts. Destructive. Use to clear tag cruft after merges/imports.',
		'category'            => 'content',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_prune_unused_tags',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'deleted' => array( 'type' => 'array' ),
				'count'   => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array( 'destructive' => true, 'idempotent' => false ),
		),
	) );
} );

/**
 * Execute callback for signal-noise/regenerate-og-card.
 */
function snt_ability_regenerate_og_card( $input ) {
	$post_id = (int) $input['post_id'];
	$post    = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_post_not_found', sprintf( 'Post %d not found.', $post_id ), array( 'status' => 404 ) );
	}

	if ( ! function_exists( 'sn_generate_og_card' ) ) {
		return new WP_Error( 'snt_og_unavailable', 'OG card generator not available.', array( 'status' => 500 ) );
	}

	// sn_generate_og_card() returns bool — true on PNG write, false on
	// any failure (missing GD/font/upload-dir). Build the URL ourselves
	// from sn_og_image_url_for_post() on success.
	$ok = sn_generate_og_card( $post_id );
	if ( ! $ok ) {
		return new WP_Error( 'snt_og_failed', 'OG card regeneration failed (check that GD + theme fonts are available).', array( 'status' => 500 ) );
	}

	$image_url = function_exists( 'sn_og_image_url_for_post' ) ? sn_og_image_url_for_post( $post ) : '';

	return array(
		'ok'        => true,
		'image_url' => $image_url,
		'message'   => sprintf( 'OG card regenerated for "%s".', wp_strip_all_tags( get_the_title( $post ) ) ),
	);
}

/**
 * Execute callback for signal-noise/get-rss-stats.
 * Thin wrapper around snt_cmd_impl_rss_stats().
 *
 * @since 3.7.5
 */
function snt_ability_get_rss_stats() {
	if ( ! function_exists( 'snt_cmd_impl_rss_stats' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'RSS-stats helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_cmd_impl_rss_stats();
}

/**
 * Execute callback for signal-noise/merge-tags. Resolves from/into slugs to post_tag
 * term ids and merges (sn_tag_merge in inc/tag-consolidation.php).
 *
 * @param array $input { from_slugs:[string], into_slug:string }.
 * @return array { ok, posts_moved, into_slug, message }.
 */
function snt_ability_merge_tags( $input ) {
	$from_slugs = isset( $input['from_slugs'] ) ? (array) $input['from_slugs'] : array();
	$into_slug  = isset( $input['into_slug'] ) ? (string) $input['into_slug'] : '';
	$from_ids   = array();
	foreach ( $from_slugs as $s ) {
		$t = get_term_by( 'slug', (string) $s, 'post_tag' );
		if ( $t ) {
			$from_ids[] = (int) $t->term_id;
		}
	}
	$into = get_term_by( 'slug', $into_slug, 'post_tag' );
	if ( ! $into || ! $from_ids || ! function_exists( 'sn_tag_merge' ) ) {
		return array( 'ok' => false, 'posts_moved' => 0, 'into_slug' => $into_slug, 'message' => 'Unknown tag slug(s).' );
	}
	$res = sn_tag_merge( $from_ids, (int) $into->term_id );
	if ( is_wp_error( $res ) ) {
		return array( 'ok' => false, 'posts_moved' => 0, 'into_slug' => $into_slug, 'message' => $res->get_error_message() );
	}
	return array( 'ok' => true, 'posts_moved' => (int) $res['posts_moved'], 'into_slug' => (string) $res['into_slug'], 'message' => 'Merged.' );
}

/**
 * Ability execute: suggest existing tags for a post.
 *
 * @param array $input { post_id:int }.
 * @return array|WP_Error
 */
function snt_ability_suggest_tags( $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( ! $post_id || ! function_exists( 'snt_ai_tag_suggest_impl' ) ) {
		return new WP_Error( 'snt_tag_suggest_unavailable', 'Tag suggestion is unavailable.', array( 'status' => 400 ) );
	}
	return snt_ai_tag_suggest_impl( $post_id );
}

/**
 * Ability execute: delete all zero-post tags.
 *
 * @return array|WP_Error
 */
function snt_ability_prune_unused_tags() {
	if ( ! function_exists( 'sn_tag_find_unused' ) || ! function_exists( 'sn_tag_delete_unused' ) ) {
		return new WP_Error( 'snt_tag_prune_unavailable', 'Tag pruning is unavailable.', array( 'status' => 500 ) );
	}
	$ids = array_map( function ( $r ) { return (int) $r['term_id']; }, sn_tag_find_unused() );
	if ( ! $ids ) {
		return array( 'ok' => true, 'deleted' => array(), 'count' => 0 );
	}
	$res = sn_tag_delete_unused( $ids );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return array( 'ok' => true, 'deleted' => $res['deleted'], 'count' => $res['count'] );
}
