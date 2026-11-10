<?php
/**
 * Signal & Noise Tools: where each HTML token sits in the string it came from.
 *
 * Built on core: WP_HTML_Tag_Processor reads the markup the way a browser
 * does (a ">" inside an attribute value, any quoting, comments, raw-text
 * elements). It keeps each token's byte span in a bookmark and has no public
 * reader for it, so this subclass adds one, the way core's own
 * wp_strip_inline_note_markers() reaches the same span.
 *
 * Read-only. Callers that write splice the original string at these offsets
 * themselves, so no byte outside their edit can change.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A tag processor over $html whose token_span() gives the current token's
 * byte range.
 *
 * @param string $html Markup to walk.
 * @return WP_HTML_Tag_Processor With token_span(): array{0:int,1:int}, start and exclusive end.
 */
function snt_html_span_processor( $html ) {
	return new class( (string) $html ) extends WP_HTML_Tag_Processor {
		/**
		 * The byte range of the token the processor is paused on.
		 *
		 * @return array{0:int,1:int} Start and exclusive end.
		 */
		public function token_span() {
			$this->set_bookmark( 'snt' );
			$span = $this->bookmarks['snt'];
			return array( $span->start, $span->start + $span->length );
		}
	};
}

/**
 * Every element named in $tags, from its opening tag through its own closing
 * tag, in document order. An element of the same name nested inside one is
 * counted, so the outer one ends at its own closer; a tag that never closes
 * gives no element.
 *
 * @param string   $html Markup to walk.
 * @param string[] $tags Upper-case tag names, e.g. array( 'A' ).
 * @return array<int,array{tag:string,start:int,open_end:int,close_start:int,end:int,classes:string[]}>
 *         Byte offsets into $html; classes are the opening tag's, as core lists them.
 */
function snt_html_elements( $html, array $tags ) {
	$html      = (string) $html;
	$processor = snt_html_span_processor( $html );
	$open      = array();
	$found     = array();
	while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
		$tag = $processor->get_tag();
		if ( ! in_array( $tag, $tags, true ) ) {
			continue;
		}
		list( $start, $end ) = $processor->token_span();
		if ( ! $processor->is_tag_closer() ) {
			$open[ $tag ][] = array( 'tag' => $tag, 'start' => $start, 'open_end' => $end, 'classes' => iterator_to_array( $processor->class_list(), false ) );
			continue;
		}
		if ( ! empty( $open[ $tag ] ) ) {
			$element                      = array_pop( $open[ $tag ] );
			$found[ $element['start'] ] = $element + array( 'close_start' => $start, 'end' => $end );
		}
	}
	ksort( $found );
	return array_values( $found );
}
