<?php
/**
 * Signal & Noise Tools: the one reader of block comment delimiters for the
 * sn_apply write path.
 *
 * Built on core: WP_Block_Processor decides what a delimiter is, its type and
 * its byte span. It replaced two regular expressions that disagreed with each
 * other and with core. Core does not compare a closer's name with the block it
 * closes, so the callers keep their own stack for that.
 *
 * The reader returns byte offsets into the string it was given and never
 * re-serializes it, so a writer splicing at those offsets leaves every other
 * byte as stored. snt_sn_apply_fp_splice() is that writer for the fingerprint
 * apply (block_migration, pattern_adoption): it returns new content and never
 * writes it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every block comment delimiter in $content, in document order.
 *
 * A name is spelled as the editor writes it: "paragraph" for core/paragraph,
 * "acme/card" for a namespaced block. A delimiter carrying both the closing
 * and the void flag is void, as core reads it.
 *
 * @param string $content Raw block markup.
 * @return array{delimiters:array<int,array{type:string,name:string,start:int,end:int}>,error:?string}
 *         type is 'opener', 'closer' or 'void'; start and end are byte offsets
 *         (end exclusive); error is core's reason when the text ends inside a
 *         comment that never closes, else null.
 */
function snt_sn_apply_block_delimiters( $content ) {
	$processor  = new WP_Block_Processor( (string) $content );
	$delimiters = array();
	while ( $processor->next_delimiter() ) {
		$span         = $processor->get_span();
		$name         = (string) $processor->get_block_type();
		$delimiters[] = array(
			'type'  => (string) $processor->get_delimiter_type(),
			'name'  => 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name,
			'start' => $span->start,
			'end'   => $span->start + $span->length,
		);
	}
	return array( 'delimiters' => $delimiters, 'error' => $processor->get_last_error() );
}

/**
 * Index path of the node whose position-bound fingerprint matches, found in
 * the same order as snt_block_fp_find(): the top-level index into
 * parse_blocks() output, then one innerBlocks index per level.
 *
 * @param array  $tree        parse_blocks() output (or an innerBlocks list).
 * @param string $fingerprint md5 from the scan.
 * @param int    $post_id     The post being searched.
 * @param string $prefix      Internal recursion state; leave default.
 * @return array{path:int[],node:array}|null
 */
function snt_sn_apply_fp_path( $tree, $fingerprint, $post_id, $prefix = '0' ) {
	foreach ( $tree as $i => $block ) {
		if ( snt_block_fp_fingerprint( $block, $post_id, $prefix . '/' . $i ) === $fingerprint ) {
			return array( 'path' => array( (int) $i ), 'node' => $block );
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$hit = snt_sn_apply_fp_path( $block['innerBlocks'], $fingerprint, $post_id, $prefix . '/' . $i . '/innerBlocks' );
			if ( null !== $hit ) {
				return array( 'path' => array_merge( array( (int) $i ), $hit['path'] ), 'node' => $hit['node'] );
			}
		}
	}
	return null;
}

/**
 * Nested block spans from core's delimiters: each is {start, end, children},
 * children being its inner blocks in order. Null when a closer has no opener
 * or an opener never closes.
 *
 * @param string $content Raw stored post_content.
 * @return array|null Top-level spans.
 */
function snt_sn_apply_block_span_tree( $content ) {
	$read = snt_sn_apply_block_delimiters( $content );
	if ( null !== $read['error'] ) {
		return null;
	}
	$levels = array( array() );
	$opened = array();
	foreach ( $read['delimiters'] as $d ) {
		if ( 'opener' === $d['type'] ) {
			$opened[]  = $d['start'];
			$levels[] = array();
			continue;
		}
		$span = array( 'start' => $d['start'], 'end' => $d['end'], 'children' => array() );
		if ( 'closer' === $d['type'] ) {
			if ( ! $opened ) {
				return null;
			}
			$span['start']    = array_pop( $opened );
			$span['children'] = array_pop( $levels );
		}
		$levels[ count( $levels ) - 1 ][] = $span;
	}
	return $opened ? null : $levels[0];
}

/**
 * Replace the one block a fingerprint names by splicing its byte span, so
 * every byte outside that block stays exactly as stored. The span comes from
 * core's delimiters (an inner block uses its own) and must be, byte for byte,
 * the block parse_blocks() matched, or nothing is replaced.
 *
 * @param string $content          Raw stored post_content.
 * @param string $fingerprint      md5 from the scan.
 * @param array  $replacement_node Sanitized parsed replacement block.
 * @param int    $post_id          The post.
 * @return string|null|WP_Error New content; null when no block matches; WP_Error (422) when the span cannot be located unambiguously.
 */
function snt_sn_apply_fp_splice( $content, $fingerprint, array $replacement_node, $post_id ) {
	$content = (string) $content;
	$tree    = parse_blocks( $content );
	$hit     = snt_sn_apply_fp_path( $tree, (string) $fingerprint, (int) $post_id );
	if ( null === $hit ) {
		return null;
	}
	$refuse = new WP_Error( 'snt_sn_apply_block_path_unresolvable', __( 'The matched block\'s byte span could not be located unambiguously in the stored content (core\'s block parser and its delimiter reader disagree): nothing was replaced. Repair the post in the editor first.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	$spans  = snt_sn_apply_block_span_tree( $content );
	if ( null === $spans ) {
		return $refuse;
	}
	// Top-level indexes count freeform chunks; spans do not.
	$path    = $hit['path'];
	$ordinal = 0;
	foreach ( array_slice( $tree, 0, $path[0] ) as $node ) {
		$ordinal += null === ( $node['blockName'] ?? null ) ? 0 : 1;
	}
	if ( is_wp_error( snt_sn_apply_block_edit_parser_agrees( $content, $spans, $ordinal ) ) ) {
		return $refuse;
	}
	$span = $spans[ $ordinal ];
	$node = $tree[ $path[0] ];
	foreach ( array_slice( $path, 1 ) as $i ) {
		if ( count( $span['children'] ) !== count( $node['innerBlocks'] ) ) {
			return $refuse;
		}
		$span = $span['children'][ $i ];
		$node = $node['innerBlocks'][ $i ];
	}
	$alone = parse_blocks( substr( $content, $span['start'], $span['end'] - $span['start'] ) );
	if ( 1 !== count( $alone ) || $alone[0] != $hit['node'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- two parses of the same bytes: equal trees, whatever array or object the parser returns.
		return $refuse;
	}
	return substr_replace( $content, serialize_block( $replacement_node ), $span['start'], $span['end'] - $span['start'] );
}
