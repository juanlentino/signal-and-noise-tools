<?php
/**
 * Signal & Noise Tools — Block-migrations apply impl.
 *
 * Fingerprint-validated structural-block replacement via parse_blocks ↔
 * serialize_blocks round-trip. Mirrors inc/pattern-adoption-apply.php.
 *
 * Flow:
 *   1. Capability check (edit_post for $post_id)
 *   2. Load post, parse_blocks($post->post_content)
 *   3. Walk tree, find block where md5(serialize_block) === fingerprint
 *   4. If not found → snt_block_migration_conflict (409)
 *   5. Mutate matching node in place with replacement (parse_blocks($replacement_markup)[0])
 *   6. serialize_blocks($modified_tree) → new post_content
 *   7. wp_update_post() → revision created automatically
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
 * @param int    $post_id
 * @param string $block_fingerprint  md5 from the scan.
 * @param string $replacement_markup Block markup string (suggest output, possibly user-edited).
 * @param string $migration_type     For diagnostic echo + invalid-type gate.
 * @return array{ok:bool,post_id:int,migration_type:string}|WP_Error
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
function snt_block_migrations_apply_impl( $post_id, $block_fingerprint, $replacement_markup, $migration_type ) {
	$post_id            = (int) $post_id;
	$block_fingerprint  = (string) $block_fingerprint;
	$replacement_markup = (string) $replacement_markup;
	$migration_type     = (string) $migration_type;

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error(
			'snt_block_migration_capability',
			__( 'You cannot edit this post.', 'signal-noise-tools' ),
			array( 'status' => 403 )
		);
	}

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

	$blocks      = parse_blocks( (string) $post->post_content );
	$replacement = parse_blocks( $replacement_markup );

	// parse_blocks returns a list; if it decoded as an assoc array (bare JSON
	// object = single block), treat it directly as the replacement node.
	if ( ! empty( $replacement ) && isset( $replacement['blockName'] ) ) {
		$replacement_node = $replacement;
	} elseif ( ! empty( $replacement ) && is_array( $replacement[0] ?? null ) ) {
		$replacement_node = $replacement[0];
	} else {
		return new WP_Error(
			'snt_block_migration_invalid_markup',
			__( 'Replacement markup did not parse to a valid block.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	$found = false;
	snt_block_migrations_replace_in_tree( $blocks, $block_fingerprint, $replacement_node, $found );

	if ( ! $found ) {
		return new WP_Error(
			'snt_block_migration_conflict',
			__( 'Block changed or removed since scan. Re-run scan.', 'signal-noise-tools' ),
			array( 'status' => 409 )
		);
	}

	$new_content = serialize_blocks( $blocks );

	$result = wp_update_post( array(
		'ID'           => $post_id,
		'post_content' => $new_content,
	), true );

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			'snt_block_migration_write_failed',
			sprintf( __( 'wp_update_post failed: %s', 'signal-noise-tools' ), $result->get_error_message() ),
			array( 'status' => 500 )
		);
	}

	return array(
		'ok'             => true,
		'post_id'        => $post_id,
		'migration_type' => $migration_type,
	);
}

/**
 * Recursive in-place mutator. Walks $tree, replaces first block whose
 * md5(serialize_block) matches $fingerprint with $replacement_node.
 *
 * @param array  $tree              By reference.
 * @param string $fingerprint
 * @param array  $replacement_node
 * @param bool   $found             By reference.
 * @return void
 *
 * @since 4.5.0
 */
function snt_block_migrations_replace_in_tree( &$tree, $fingerprint, $replacement_node, &$found ) {
	foreach ( $tree as $i => &$block ) {
		if ( $found ) { return; }
		if ( md5( serialize_block( $block ) ) === $fingerprint ) {
			$tree[ $i ] = $replacement_node;
			$found = true;
			return;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			snt_block_migrations_replace_in_tree( $block['innerBlocks'], $fingerprint, $replacement_node, $found );
		}
	}
}

/* ════════════════════════════════════════════════════════════════════════
 * REST endpoint — apply.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/tools/block-migrations-apply', array(
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $request ) {
			$result = snt_block_migrations_apply_impl(
				(int)    $request->get_param( 'post_id' ),
				(string) $request->get_param( 'block_fingerprint' ),
				(string) $request->get_param( 'replacement_markup' ),
				(string) $request->get_param( 'migration_type' )
			);
			if ( is_wp_error( $result ) ) { return $result; }
			return rest_ensure_response( $result );
		},
		'permission_callback' => function( WP_REST_Request $request ) {
			return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
		},
		'args' => array(
			'post_id'            => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'block_fingerprint'  => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'replacement_markup' => array( 'required' => true, 'type' => 'string' ),
			'migration_type'     => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
		),
	) );
} );
