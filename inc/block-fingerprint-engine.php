<?php
/**
 * Signal & Noise Tools — shared block-fingerprint engine.
 *
 * One engine behind both fingerprint-validated Suggest/Apply surfaces
 * (block-migrations + pattern-adoption), which had carried byte-identical
 * find/replace walkers and near-identical apply pipelines since 4.3.0/4.5.0
 * (each file said "mirrors" the other — this module IS the mirror):
 *
 *   snt_block_fp_fingerprint( $block, $post_id, $path )  md5(post|path|serialize_block)
 *   snt_block_fp_find( $tree, $fp, $post_id )            node|null, depth-first
 *   snt_block_fp_replace_in_tree( &$tree, $fp, $n, &$f, $post_id )  first match, in place
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
 * Concurrency fingerprint for a parsed block node, BOUND to the post and
 * the block's position in the tree.
 *
 * The pre-v11.4.0 scheme hashed the serialized block alone, which made
 * identical blocks collide: two posts sharing a heading produced ONE
 * fingerprint that validated against either post, and a repeated block
 * inside one post produced an ambiguous locate. Since apply resolves a
 * block BY fingerprint, both shapes were live foot-guns (observed on the
 * real corpus: a cross-post collision between posts 1589/1587 and in-post
 * duplicates on 1570). Binding post_id + block_path makes the wrong write
 * unrepresentable rather than unlikely: a fingerprint minted against one
 * post cannot validate against another, a duplicate block resolves to one
 * position, and a block that MOVES invalidates its candidates (409 →
 * re-scan), which is exactly what a concurrency fingerprint is for.
 *
 * $block_path uses the scanners' shared grammar: "0/<idx>" at top level,
 * "0/<idx>/innerBlocks/<idx>" nested — the same string the candidates
 * report as block_path.
 *
 * @param array  $block      Parsed block (parse_blocks node).
 * @param int    $post_id    The post the block belongs to.
 * @param string $block_path Position in the tree (scanner grammar).
 * @return string 32-char md5.
 */
function snt_block_fp_fingerprint( $block, $post_id, $block_path ) {
	return md5( (int) $post_id . '|' . (string) $block_path . '|' . serialize_block( $block ) );
}

/**
 * Depth-first search for the block matching $fingerprint, regenerating
 * each node's path with the scanner grammar so position-bound fingerprints
 * resolve to exactly one node.
 *
 * @param array  $tree        parse_blocks() output (or an innerBlocks array).
 * @param string $fingerprint md5 from the scan.
 * @param int    $post_id     The post being searched.
 * @param string $path_prefix Internal recursion state; leave default.
 * @return array|null The matching block, or null.
 */
function snt_block_fp_find( $tree, $fingerprint, $post_id, $path_prefix = '0' ) {
	foreach ( $tree as $idx => $block ) {
		if ( snt_block_fp_fingerprint( $block, $post_id, $path_prefix . '/' . $idx ) === $fingerprint ) {
			return $block;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$found = snt_block_fp_find( $block['innerBlocks'], $fingerprint, $post_id, $path_prefix . '/' . $idx . '/innerBlocks' );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	return null;
}

/**
 * Recursive in-place mutator. Replaces the block whose position-bound
 * fingerprint matches with $replacement_node and sets $found = true.
 * With path-bound fingerprints at most one node can match.
 *
 * @param array  $tree             By reference.
 * @param string $fingerprint      md5 from the scan.
 * @param array  $replacement_node Parsed replacement block node.
 * @param bool   $found            By reference.
 * @param int    $post_id          The post being mutated.
 * @param string $path_prefix      Internal recursion state; leave default.
 * @return void
 */
function snt_block_fp_replace_in_tree( &$tree, $fingerprint, $replacement_node, &$found, $post_id, $path_prefix = '0' ) {
	foreach ( $tree as $i => &$block ) {
		if ( $found ) { return; }
		if ( snt_block_fp_fingerprint( $block, $post_id, $path_prefix . '/' . $i ) === $fingerprint ) {
			$tree[ $i ] = $replacement_node;
			$found = true;
			return;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			snt_block_fp_replace_in_tree( $block['innerBlocks'], $fingerprint, $replacement_node, $found, $post_id, $path_prefix . '/' . $i . '/innerBlocks' );
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
 *     @type callable|null $write_callback (v10.40.0, sn_apply session 6b)
 *                                      Optional. When set, called as
 *                                      $write_callback( $post_id, $new_content )
 *                                      INSTEAD of wp_update_post() — used by
 *                                      sn_apply's mode:"revision" to route the
 *                                      write step through
 *                                      snt_sn_apply_stage_revision() instead of
 *                                      touching the live post. Must return the
 *                                      same shape wp_update_post( ..., true )
 *                                      would: post ID (or any non-WP_Error
 *                                      truthy value) on success, WP_Error on
 *                                      failure. Every existing caller omits
 *                                      this — default null preserves the
 *                                      original wp_update_post() behavior
 *                                      byte-for-byte.
 * }
 * @return array{ok:bool,post_id:int,old_content:string,new_content:string}|WP_Error
 *                                             Success payload's ok/post_id are
 *                                             the original contract; old_content/
 *                                             new_content are additive (v10.40.0,
 *                                             sn_apply's diff reporting) — surface
 *                                             wrappers add their own echo keys.
 */
function snt_block_fp_apply( $args ) {
	$post_id            = (int) ( $args['post_id'] ?? 0 );
	$block_fingerprint  = (string) ( $args['block_fingerprint'] ?? '' );
	$replacement_markup = (string) ( $args['replacement_markup'] ?? '' );
	$type               = (string) ( $args['type'] ?? '' );
	$valid_types        = (array) ( $args['valid_types'] ?? array() );
	$codes              = (array) ( $args['error_codes'] ?? array() );
	$messages           = (array) ( $args['error_messages'] ?? array() );
	$write_callback     = $args['write_callback'] ?? null;

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

	$old_content      = (string) $post->post_content;
	$blocks           = parse_blocks( $old_content );
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
	snt_block_fp_replace_in_tree( $blocks, $block_fingerprint, $replacement_node, $found, $post_id );

	if ( ! $found ) {
		return $err( 'conflict', 409, __( 'Block changed or removed since scan. Re-run scan.', 'signal-and-noise-tools' ) );
	}

	$new_content = serialize_blocks( $blocks );

	if ( is_callable( $write_callback ) ) {
		$result = call_user_func( $write_callback, $post_id, $new_content );
	} else {
		$result = wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $new_content,
		), true );
	}

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
		'ok'          => true,
		'post_id'     => $post_id,
		'old_content' => $old_content,
		'new_content' => $new_content,
	);
}
