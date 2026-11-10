<?php
/**
 * Whether core's block parser and the sn_apply delimiter reader read a post as
 * the same top-level blocks: as many named parse_blocks() nodes as top-level
 * spans from snt_sn_apply_block_edit_scan_spans(), and each span's bytes,
 * parsed alone, give back exactly the node the full parse holds there.
 *
 * Core 7.1's parser tokenizes with its own pattern and reads some malformed
 * markup differently from WP_Block_Processor (a delimiter inside a plain HTML
 * comment, attribute JSON that never closes). From 7.2 the parser is built on
 * the processor and those inputs read the same. A suite asserts the refusal
 * where this is false and an exact splice where it is true, so each core runs
 * the branch it really takes.
 *
 * Computed from parse_blocks() and the spans only, never from the guard under
 * test (snt_sn_apply_block_edit_parser_agrees()), so a guard that refuses too
 * much or too little cannot pass by agreeing with itself.
 *
 * Needs core's parse_blocks() (tests/lib/wp-core-blocks.php) and
 * inc/sn-apply/block-edit.php loaded first.
 *
 * @param string $content Raw block markup.
 * @return bool
 */
function snt_test_parser_agrees_with_walker( $content ) {
	$nodes = array_values( array_filter( parse_blocks( $content ), static function ( $node ) { return null !== $node['blockName']; } ) );
	$spans = snt_sn_apply_block_edit_scan_spans( $content )['spans'];
	if ( count( $nodes ) !== count( $spans ) ) {
		return false;
	}
	foreach ( $spans as $k => $span ) {
		if ( array( $nodes[ $k ] ) !== parse_blocks( substr( $content, $span['start'], $span['end'] - $span['start'] ) ) ) {
			return false;
		}
	}
	return true;
}
