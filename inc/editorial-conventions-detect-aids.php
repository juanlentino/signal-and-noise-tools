<?php
/**
 * Signal & Noise Tools: shape tests for the 2026-10 reading aids, used by
 * snt_editorial_conventions_detect() (inc/editorial-conventions-detect.php).
 *
 * Three shapes, each a string test like the rest of the detector:
 *
 *   - a DATED sequence set as steps (every item opens with a date), which is
 *     the timeline convention's job;
 *   - a steps or compare-columns group nested inside a group of its own kind,
 *     which renders a panel inside a panel and double-numbers the list
 *     (reported on a published note in 2026-10 and fixed there by hand);
 *   - a core/table without the house table style, or without the header row
 *     the style's convention requires. scope="col" is NOT checked: core's
 *     editor never writes it, so the theme's style adds it on render
 *     (signal-and-noise inc/table-header-scope.php).
 *
 * Every helper reads SERIALIZED markup (serialize_block() of the top-level
 * block), so it behaves the same under core's nested parser and the flat
 * fixture parser the standalone suites use.
 * Tags and attributes are read with core's WP_HTML_Tag_Processor and group
 * delimiters with core's WP_Block_Processor.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/html-token-spans.php'; // snt_html_elements(): the table's <thead> as core reads it.

/**
 * Whether a list item's opening text is a date: a month name followed by a
 * number or by punctuation ("Dec 2015.", "March: ..."), or a year followed by
 * punctuation ("2019."). Leading <strong>/<em> are skipped. "Mayday" and
 * "1500 tracks" are not dates; "May the" is not either.
 *
 * @param string $html The item's markup from just after its <li> tag.
 * @return bool
 */
function snt_editorial_is_date_opener( $html ) {
	$t      = (string) preg_replace( '/^(?:\s*<(?:strong|b|em|i)\b[^>]*>)+/i', '', (string) $html );
	$months = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
	if ( preg_match( '/^\s*(?:' . $months . ')\b\.?(?=\s+\d|\s*[:,\x{2013}-])/iu', $t ) ) {
		return true;
	}
	return 1 === preg_match( '/^\s*(?:1[6-9]|20)\d{2}(?=\s*[.,:;)\x{2013}-]|\s*<\/)/u', $t );
}

/**
 * The opening markup of every <li> in a serialized block, in order.
 *
 * @param string $markup
 * @return string[]
 */
function snt_editorial_list_item_openers( $markup ) {
	if ( ! preg_match_all( '#<li\b[^>]*>#i', (string) $markup, $m, PREG_OFFSET_CAPTURE ) ) {
		return array();
	}
	$out = array();
	foreach ( $m[0] as $hit ) {
		$out[] = substr( (string) $markup, (int) $hit[1] + strlen( $hit[0] ), 160 );
	}
	return $out;
}

/**
 * How many core/group openers carrying $class sit inside another core/group
 * carrying $class, walking the group delimiters with a stack.
 *
 * @param string $markup Serialized block markup.
 * @param string $class  Whole-token className, e.g. sn-pattern-steps-enumerated.
 * @return int
 */
function snt_editorial_nested_same_class( $markup, $class ) {
	$stack     = array();
	$nested    = 0;
	$processor = new WP_Block_Processor( (string) $markup );
	while ( $processor->next_delimiter( 'core/group' ) ) {
		$type = $processor->get_delimiter_type();
		if ( WP_Block_Processor::CLOSER === $type ) {
			array_pop( $stack );
			continue;
		}
		if ( WP_Block_Processor::VOID === $type ) {
			continue; // A void group has no children.
		}
		$attrs = $processor->allocate_and_return_parsed_attributes();
		$cn    = is_array( $attrs ) ? trim( (string) ( $attrs['className'] ?? '' ) ) : '';
		$has   = '' !== $cn && in_array( $class, preg_split( '/\s+/', $cn ), true );
		if ( $has && in_array( true, $stack, true ) ) {
			++$nested;
		}
		$stack[] = $has;
	}
	return $nested;
}

/**
 * The header row of a table block's markup: whether a <thead> holds header
 * cells, and how many of them lack scope="col".
 *
 * @param string $html
 * @return array{thead:bool,th:int,th_without_scope:int}
 */
function snt_editorial_table_header( $html ) {
	$html  = (string) $html;
	$thead = snt_html_elements( $html, array( 'THEAD' ) )[0] ?? null;
	if ( null === $thead ) {
		return array( 'thead' => false, 'th' => 0, 'th_without_scope' => 0 );
	}
	$th        = 0;
	$without   = 0;
	$processor = new WP_HTML_Tag_Processor( substr( $html, $thead['open_end'], $thead['close_start'] - $thead['open_end'] ) );
	while ( $processor->next_tag( 'TH' ) ) {
		++$th;
		$scope = $processor->get_attribute( 'scope' );
		if ( ! is_string( $scope ) || 'col' !== strtolower( $scope ) ) {
			++$without;
		}
	}
	return array( 'thead' => $th > 0, 'th' => $th, 'th_without_scope' => $without );
}

/**
 * What an inline SVG figure's <svg> tags lack of the two accessibility
 * attributes the house form carries: role="img" and aria-labelledby.
 *
 * @param string $html A core/html block's markup.
 * @return string[] Any of 'role="img"', 'aria-labelledby'.
 */
function snt_editorial_svg_missing_attributes( $html ) {
	$has_role  = false;
	$has_label = false;
	$processor = new WP_HTML_Tag_Processor( (string) $html );
	while ( $processor->next_tag( 'SVG' ) ) {
		$role      = $processor->get_attribute( 'role' );
		$has_role  = $has_role || ( is_string( $role ) && 'img' === strtolower( $role ) );
		$has_label = $has_label || is_string( $processor->get_attribute( 'aria-labelledby' ) );
	}
	return array_merge( $has_role ? array() : array( 'role="img"' ), $has_label ? array() : array( 'aria-labelledby' ) );
}
