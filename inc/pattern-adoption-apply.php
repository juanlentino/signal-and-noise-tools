<?php
/**
 * Signal & Noise Tools — Pattern-adoption apply impl.
 *
 * Fingerprint-validated structural-block replacement via the
 * parse_blocks ↔ serialize_blocks round-trip. Operates entirely on
 * block-tree nodes — no byte offsets — sidestepping the v4.1.1
 * raw-vs-stripped coordinate bug class that the drift-phrase impl
 * had to engineer around.
 *
 * Flow:
 *   1. Load post, parse_blocks($post->post_content)
 *   2. Walk tree, find block where md5(serialize_block($node)) === fingerprint
 *   3. If not found → snt_pattern_adoption_conflict (409)
 *   4. Mutate the matching node in place to the replacement parse result
 *   5. serialize_blocks($modified_tree) → new post_content
 *   6. wp_update_post(['ID'=>$id, 'post_content'=>$new], true)
 *
 * Acknowledged tradeoff (carries from v4.0.x): wp_update_post() triggers
 * downstream save-hook fanout (cache busts, revisions, save_post listeners).
 * Cost is proportional to applies (small).
 *
 * @package SignalNoiseTools
 * @since 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure impl: apply a fingerprint-validated structural replacement.
 *
 * @param int    $post_id
 * @param string $block_fingerprint  md5 from the scan (Suggest path).
 * @param string $replacement_markup Block markup string (typically the
 *                                   suggestion_markup from the suggest impl,
 *                                   possibly user-edited in the modal).
 * @param string $pattern_type       For diagnostic echo + invalid-type gate.
 * @return array{ok:bool,post_id:int,replaced_pattern_type:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_pattern_adoption_invalid_pattern_type   (422)
 *   snt_pattern_adoption_post_not_found         (404)
 *   snt_pattern_adoption_conflict               (409)
 *   snt_pattern_adoption_capability             (403)
 *   snt_pattern_adoption_write_failed           (500)
 *
 * @since 4.3.0
 */
function snt_ai_pattern_adoption_apply_impl( $post_id, $block_fingerprint, $replacement_markup, $pattern_type ) {
	$post_id            = (int) $post_id;
	$block_fingerprint  = (string) $block_fingerprint;
	$replacement_markup = (string) $replacement_markup;
	$pattern_type       = (string) $pattern_type;

	if ( ! defined( 'SNT_PATTERN_ADOPTION_VALID_TYPES' ) ) {
		// Defensive — suggest module declares this. Fallback for test-isolation runs.
		define( 'SNT_PATTERN_ADOPTION_VALID_TYPES', array( 'pull-quote', 'steps-enumerated' ) );
	}
	if ( ! in_array( $pattern_type, SNT_PATTERN_ADOPTION_VALID_TYPES, true ) ) {
		return new WP_Error(
			'snt_pattern_adoption_invalid_pattern_type',
			__( 'pattern_type must be one of: pull-quote, steps-enumerated.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error(
			'snt_pattern_adoption_capability',
			__( 'You cannot edit this post.', 'signal-noise-tools' ),
			array( 'status' => 403 )
		);
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error(
			'snt_pattern_adoption_post_not_found',
			__( 'Post not found.', 'signal-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	$blocks       = parse_blocks( (string) $post->post_content );
	$replacement  = parse_blocks( $replacement_markup );
	if ( empty( $replacement ) || ! is_array( $replacement[0] ?? null ) ) {
		return new WP_Error(
			'snt_pattern_adoption_invalid_pattern_type',
			__( 'Replacement markup did not parse to a valid block.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}
	$replacement_node = $replacement[0];

	$found = false;
	snt_pattern_adoption_replace_in_tree( $blocks, $block_fingerprint, $replacement_node, $found );

	if ( ! $found ) {
		return new WP_Error(
			'snt_pattern_adoption_conflict',
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
			'snt_pattern_adoption_write_failed',
			sprintf( __( 'wp_update_post failed: %s', 'signal-noise-tools' ), $result->get_error_message() ),
			array( 'status' => 500 )
		);
	}

	return array(
		'ok'                    => true,
		'post_id'               => $post_id,
		'replaced_pattern_type' => $pattern_type,
	);
}

/**
 * Recursive in-place mutator. Walks $tree, replaces the first block
 * whose md5(serialize_block) matches $fingerprint with $replacement_node.
 * Sets $found = true on success.
 *
 * @param array $tree             By reference.
 * @param string $fingerprint
 * @param array  $replacement_node
 * @param bool   $found           By reference.
 * @return void
 *
 * @since 4.3.0
 */
function snt_pattern_adoption_replace_in_tree( &$tree, $fingerprint, $replacement_node, &$found ) {
	foreach ( $tree as $i => &$block ) {
		if ( $found ) { return; }
		if ( md5( serialize_block( $block ) ) === $fingerprint ) {
			$tree[ $i ] = $replacement_node;
			$found = true;
			return;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			snt_pattern_adoption_replace_in_tree( $block['innerBlocks'], $fingerprint, $replacement_node, $found );
		}
	}
}

/* ════════════════════════════════════════════════════════════════════════
 * REST endpoint — apply (back-compat surface for CI/wp-cli).
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/ai/pattern-adoption-apply', array(
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $request ) {
			$result = snt_ai_pattern_adoption_apply_impl(
				(int)    $request->get_param( 'post_id' ),
				(string) $request->get_param( 'block_fingerprint' ),
				(string) $request->get_param( 'replacement_markup' ),
				(string) $request->get_param( 'pattern_type' )
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
			'replacement_markup' => array( 'required' => true, 'type' => 'string' ), // intentional: no sanitize — block markup contains HTML
			'pattern_type'       => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
		),
	) );
} );
