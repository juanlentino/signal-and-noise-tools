<?php
/**
 * Signal & Noise Tools — shared block-fingerprint engine.
 *
 * One engine behind both fingerprint-validated Suggest/Apply surfaces
 * (block-migrations + pattern-adoption), which had carried byte-identical
 * find/replace walkers and near-identical apply pipelines since 4.3.0/4.5.0
 * (each file said "mirrors" the other — this module IS the mirror):
 *
 *   snt_block_fp_fingerprint( $block )                   md5(serialize_block)
 *   snt_block_fp_find( $tree, $fp )                      node|null, depth-first
 *   snt_block_fp_replace_in_tree( &$tree, $fp, $n, &$f ) first match, in place
 *   snt_block_fp_sanitize_node( $node )                  recursive wp_kses_post
 *   snt_block_fp_apply( $args )                          the shared pipeline
 *
 * The apply pipeline is parameterized by per-surface error codes + messages
 * so each surface's public WP_Error contract stays byte-identical (including
 * pattern-adoption's quirk of reusing its invalid-type code for invalid
 * markup). Two deliberate v7.7.1 behavior changes, both changelogged:
 *
 *   1. capability gates FIRST on both surfaces (pattern-adoption used to
 *      check the type before the capability; 403 now wins over 422 for
 *      unauthorized callers — strictly less information disclosed);
 *   2. block-migrations gains the v6.39.2 wp_kses_post sanitization of the
 *      user-editable replacement markup that only pattern-adoption had
 *      (stored-XSS defense-in-depth parity).
 *
 * A third surface (a future migration type) plugs in by passing its own
 * code map — the spec's trigger for collapsing the ABILITY surface too.
 *
 * @package SignalNoiseTools
 * @since 7.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Concurrency fingerprint for a parsed block node.
 *
 * @param array $block Parsed block (parse_blocks node).
 * @return string 32-char md5 of the serialized block.
 */
function snt_block_fp_fingerprint( $block ) {
	return md5( serialize_block( $block ) );
}

/**
 * Depth-first search for the block matching $fingerprint.
 *
 * @param array  $tree        parse_blocks() output (or an innerBlocks array).
 * @param string $fingerprint md5 from the scan.
 * @return array|null The matching block, or null.
 */
function snt_block_fp_find( $tree, $fingerprint ) {
	foreach ( $tree as $block ) {
		if ( snt_block_fp_fingerprint( $block ) === $fingerprint ) {
			return $block;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$found = snt_block_fp_find( $block['innerBlocks'], $fingerprint );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	return null;
}

/**
 * Recursive in-place mutator. Replaces the FIRST block whose fingerprint
 * matches with $replacement_node and sets $found = true.
 *
 * @param array  $tree             By reference.
 * @param string $fingerprint      md5 from the scan.
 * @param array  $replacement_node Parsed replacement block node.
 * @param bool   $found            By reference.
 * @return void
 */
function snt_block_fp_replace_in_tree( &$tree, $fingerprint, $replacement_node, &$found ) {
	foreach ( $tree as $i => &$block ) {
		if ( $found ) { return; }
		if ( snt_block_fp_fingerprint( $block ) === $fingerprint ) {
			$tree[ $i ] = $replacement_node;
			$found = true;
			return;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			snt_block_fp_replace_in_tree( $block['innerBlocks'], $fingerprint, $replacement_node, $found );
		}
	}
}

/**
 * Return a copy of a parsed block node with its inner HTML run through
 * wp_kses_post, recursively for nested innerBlocks. Pure: the input node is
 * not mutated (PHP arrays are value types). Both innerHTML and each string
 * segment of innerContent are sanitized so the two stay consistent for
 * serialize_block(); block delimiters/attrs are untouched (the serializer
 * regenerates them). Sanitizing the PARSED node rather than the raw markup
 * matters because wp_kses strips HTML comments — and block delimiters
 * (<!-- wp:… -->) ARE HTML comments (the v6.39.2 lesson).
 *
 * @param array $node Parsed block node.
 * @return array Sanitized copy.
 */
function snt_block_fp_sanitize_node( $node ) {
	if ( ! is_array( $node ) ) {
		return $node;
	}
	if ( isset( $node['innerHTML'] ) && is_string( $node['innerHTML'] ) ) {
		$node['innerHTML'] = wp_kses_post( $node['innerHTML'] );
	}
	if ( ! empty( $node['innerContent'] ) && is_array( $node['innerContent'] ) ) {
		foreach ( $node['innerContent'] as $i => $chunk ) {
			if ( is_string( $chunk ) ) {
				$node['innerContent'][ $i ] = wp_kses_post( $chunk );
			}
		}
	}
	if ( ! empty( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ) {
		foreach ( $node['innerBlocks'] as $i => $child ) {
			$node['innerBlocks'][ $i ] = snt_block_fp_sanitize_node( $child );
		}
	}
	return $node;
}

/**
 * The shared fingerprint-validated apply pipeline:
 * capability → type gate → load post → parse both sides → named-block guard
 * → sanitize replacement → replace-by-fingerprint (409 on drift) → serialize
 * → wp_update_post (revision fires automatically).
 *
 * @param array $args {
 *     @type int    $post_id
 *     @type string $block_fingerprint  md5 from the scan.
 *     @type string $replacement_markup Block markup (suggest output, possibly user-edited).
 *     @type string $type               Surface's candidate type (migration_type / pattern_type).
 *     @type array  $valid_types        Allowed values for $type.
 *     @type array  $error_codes        Per-surface WP_Error codes, keys:
 *                                      capability, invalid_type, post_not_found,
 *                                      invalid_markup, conflict, write_failed.
 *     @type array  $error_messages     Optional per-key message overrides
 *                                      (write_failed takes the underlying
 *                                      error message via sprintf %s).
 * }
 * @return array{ok:bool,post_id:int}|WP_Error Success payload is minimal —
 *                                             surface wrappers add their own
 *                                             echo keys.
 */
function snt_block_fp_apply( $args ) {
	$post_id            = (int) ( $args['post_id'] ?? 0 );
	$block_fingerprint  = (string) ( $args['block_fingerprint'] ?? '' );
	$replacement_markup = (string) ( $args['replacement_markup'] ?? '' );
	$type               = (string) ( $args['type'] ?? '' );
	$valid_types        = (array) ( $args['valid_types'] ?? array() );
	$codes              = (array) ( $args['error_codes'] ?? array() );
	$messages           = (array) ( $args['error_messages'] ?? array() );

	$err = function ( $key, $status, $message ) use ( $codes, $messages ) {
		return new WP_Error(
			isset( $codes[ $key ] ) ? (string) $codes[ $key ] : 'snt_block_fp_' . $key,
			isset( $messages[ $key ] ) ? (string) $messages[ $key ] : $message,
			array( 'status' => $status )
		);
	};

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return $err( 'capability', 403, __( 'You cannot edit this post.', 'signal-and-noise-tools' ) );
	}

	if ( ! in_array( $type, $valid_types, true ) ) {
		/* translators: %s is a comma-separated list of valid block types */
		return $err( 'invalid_type', 422, sprintf( __( 'Type must be one of: %s.', 'signal-and-noise-tools' ), implode( ', ', $valid_types ) ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return $err( 'post_not_found', 404, __( 'Post not found.', 'signal-and-noise-tools' ) );
	}

	$blocks           = parse_blocks( (string) $post->post_content );
	$replacement      = parse_blocks( $replacement_markup );
	$replacement_node = $replacement[0] ?? null;

	// v4.5.2 (both surfaces): require a NAMED block. parse_blocks() on
	// non-block input returns a single node with blockName === null (a
	// "freeform" classic block); accepting it would splice raw HTML.
	if ( ! is_array( $replacement_node ) || empty( $replacement_node['blockName'] ) ) {
		return $err( 'invalid_markup', 422, __( 'Replacement markup did not parse to a valid block.', 'signal-and-noise-tools' ) );
	}

	// v6.39.2 SECURITY (now both surfaces): the replacement is untrusted —
	// user-editable in the modal before apply. Sanitize the parsed node so a
	// <script>/onerror payload can't be stored (front-end stored-XSS).
	$replacement_node = snt_block_fp_sanitize_node( $replacement_node );

	$found = false;
	snt_block_fp_replace_in_tree( $blocks, $block_fingerprint, $replacement_node, $found );

	if ( ! $found ) {
		return $err( 'conflict', 409, __( 'Block changed or removed since scan. Re-run scan.', 'signal-and-noise-tools' ) );
	}

	$result = wp_update_post( array(
		'ID'           => $post_id,
		'post_content' => serialize_blocks( $blocks ),
	), true );

	if ( is_wp_error( $result ) ) {
		/* translators: %s is the error message from wp_update_post() */
		$template = isset( $messages['write_failed'] ) ? (string) $messages['write_failed'] : __( 'wp_update_post failed: %s', 'signal-and-noise-tools' );
		return new WP_Error(
			isset( $codes['write_failed'] ) ? (string) $codes['write_failed'] : 'snt_block_fp_write_failed',
			sprintf( $template, $result->get_error_message() ),
			array( 'status' => 500 )
		);
	}

	return array(
		'ok'      => true,
		'post_id' => $post_id,
	);
}
