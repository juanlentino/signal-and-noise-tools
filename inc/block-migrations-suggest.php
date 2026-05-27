<?php
/**
 * Signal & Noise Tools — Block-migrations suggest impl.
 *
 * Given (post_id, block_fingerprint, migration_type), locates the
 * candidate block in the post's parse_blocks() tree and synthesizes
 * replacement markup via deterministic block-tree mutation + serialize_block.
 *
 * NO AI. Mirrors inc/pattern-adoption-suggest.php structurally.
 *
 * Migration types (extensible via SNT_BLOCK_MIGRATIONS_VALID_TYPES in
 * inc/block-migrations-detect.php):
 *   - heading-hierarchy-skip  ← h3-without-preceding-h2 → h2
 *
 * @package SignalNoiseTools
 * @since 4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure impl: generate the replacement markup for a block-migration candidate.
 *
 * @param int    $post_id
 * @param string $block_fingerprint  md5 from the scan.
 * @param string $migration_type     'heading-hierarchy-skip' (currently the only type).
 * @return array{ok:bool,suggestion_markup:string,fingerprint:string,post_id:int,migration_type:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_block_migration_invalid_type           (422)
 *   snt_block_migration_post_not_found         (404)
 *   snt_block_migration_candidate_not_found    (404)
 *
 * @since 4.5.0
 */
function snt_block_migrations_suggest_impl( $post_id, $block_fingerprint, $migration_type ) {
	$post_id           = (int) $post_id;
	$block_fingerprint = (string) $block_fingerprint;
	$migration_type    = (string) $migration_type;

	// Defensive: detect module may not have loaded in test-isolation runs.
	if ( ! defined( 'SNT_BLOCK_MIGRATIONS_VALID_TYPES' ) ) {
		define( 'SNT_BLOCK_MIGRATIONS_VALID_TYPES', array( 'heading-hierarchy-skip' ) );
	}

	if ( ! in_array( $migration_type, SNT_BLOCK_MIGRATIONS_VALID_TYPES, true ) ) {
		return new WP_Error(
			'snt_block_migration_invalid_type',
			__( 'migration_type must be one of: heading-hierarchy-skip.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error(
			'snt_block_migration_post_not_found',
			__( 'Post not found.', 'signal-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	$blocks = parse_blocks( (string) $post->post_content );
	$match  = snt_block_migrations_find_block( $blocks, $block_fingerprint );

	if ( null === $match ) {
		return new WP_Error(
			'snt_block_migration_candidate_not_found',
			__( 'Candidate block not found in current post content. Re-run scan.', 'signal-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	if ( 'heading-hierarchy-skip' === $migration_type ) {
		$markup = snt_block_migrations_build_heading_promotion( $match );
	} else {
		// Defensive — invalid type was already gated above. Defense-in-depth.
		return new WP_Error(
			'snt_block_migration_invalid_type',
			__( 'Unknown migration_type.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	return array(
		'ok'                => true,
		'suggestion_markup' => $markup,
		'fingerprint'       => $block_fingerprint,
		'post_id'           => $post_id,
		'migration_type'    => $migration_type,
	);
}

/**
 * Recursive search for a block matching $fingerprint.
 *
 * @param array  $tree
 * @param string $fingerprint
 * @return array|null  The matching block, or null.
 *
 * @since 4.5.0
 */
function snt_block_migrations_find_block( $tree, $fingerprint ) {
	foreach ( $tree as $block ) {
		if ( md5( serialize_block( $block ) ) === $fingerprint ) {
			return $block;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$found = snt_block_migrations_find_block( $block['innerBlocks'], $fingerprint );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	return null;
}

/**
 * Build the heading-promotion replacement markup.
 *
 * Mutates the heading block's level attribute (unsets it — omitting the
 * JSON key is the canonical wp:heading representation for h2 per
 * wp-includes/blocks.php serialize_block behavior).
 *
 * Also rewrites innerContent (what serialize_block actually USES for
 * reconstruction — not innerHTML). innerHTML is updated for consistency
 * with downstream code that inspects parse_blocks output.
 *
 * @param array $heading_block
 * @return string  serialize_block output.
 *
 * @since 4.5.0
 */
function snt_block_migrations_build_heading_promotion( $heading_block ) {
	// Unset level: serialize_block omits the JSON key when attrs is empty,
	// which is canonical for h2 (default level).
	unset( $heading_block['attrs']['level'] );

	// Mutate innerContent — what serialize_block uses for reconstruction.
	foreach ( $heading_block['innerContent'] ?? array() as $i => $content ) {
		if ( is_string( $content ) ) {
			$heading_block['innerContent'][ $i ] = preg_replace(
				array( '/<h3(\b[^>]*)>/', '/<\/h3>/' ),
				array( '<h2$1>', '</h2>' ),
				$content
			);
		}
	}

	// innerHTML mutation is informational — serialize_block ignores it for
	// reconstruction. Update for consistency.
	if ( isset( $heading_block['innerHTML'] ) ) {
		$heading_block['innerHTML'] = preg_replace(
			array( '/<h3(\b[^>]*)>/', '/<\/h3>/' ),
			array( '<h2$1>', '</h2>' ),
			$heading_block['innerHTML']
		);
	}

	return serialize_block( $heading_block );
}

/* ════════════════════════════════════════════════════════════════════════
 * REST endpoint — suggest dispatch.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/tools/block-migrations-suggest', array(
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $request ) {
			$result = snt_block_migrations_suggest_impl(
				(int)    $request->get_param( 'post_id' ),
				(string) $request->get_param( 'block_fingerprint' ),
				(string) $request->get_param( 'migration_type' )
			);
			if ( is_wp_error( $result ) ) { return $result; }
			return rest_ensure_response( $result );
		},
		'permission_callback' => function( WP_REST_Request $request ) {
			return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
		},
		'args' => array(
			'post_id'           => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'block_fingerprint' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'migration_type'    => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
		),
	) );
} );
