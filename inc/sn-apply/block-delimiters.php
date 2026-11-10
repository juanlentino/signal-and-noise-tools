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
 * Read-only: it returns byte offsets into the string it was given and never
 * re-serializes it, so a writer splicing at those offsets leaves every other
 * byte as stored.
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
