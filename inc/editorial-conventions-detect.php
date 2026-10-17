<?php
/**
 * Signal & Noise Tools — editorial convention DRIFT detector. (v14.7.0)
 *
 * Markup that matches a house convention's SEMANTIC SHAPE but not its FORM:
 * a trailing paragraph that opens "<strong>Correction," and carries no
 * sn-correction class; a bibliography list under an H2 "References" without
 * is-style-references; a first-block <em> paragraph without sn-lead; an
 * ordered list of <strong>Term.</strong> items outside the steps group; an
 * inline SVG missing the accessible trio or painted in a frozen hex; and,
 * since 2026-10 (inc/editorial-conventions-detect-aids.php), a dated sequence
 * set as steps, a steps or compare group nested in its own kind, and a table
 * without the house style or without a header row. All
 * deterministic, all warning severity: a caller may be doing something new
 * on purpose, so the finding NAMES the convention and its id and stops there.
 *
 * ONE DETECTOR, TWO CONSUMERS. sn-validate runs it over proposed body markup
 * (inc/sn-validate-checks-media.php); sn-scan runs it over the corpus with
 * block_path + position-bound fingerprints (inc/sn-scan-editorial-conventions.php).
 * The registry it reads is the THEME's (sn_theme_editorial_conventions(),
 * inc/editorial-conventions.php): the theme owns the CSS and the patterns,
 * so the theme owns the data. Without it the detector answers UNAVAILABLE,
 * never "no findings".
 *
 * Shape tests, and only shape tests: "reads as a correction" is a judgment
 * and this file makes none. "Opens with <strong>Correction," is a string.
 *
 * @package SignalNoiseTools
 * @since 14.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/editorial-conventions-detect-aids.php';

/**
 * The registry, keyed by id, or null when the theme does not provide it.
 *
 * @return array<string,array<string,mixed>>|null
 */
function snt_editorial_conventions_registry() {
	if ( ! function_exists( 'sn_theme_editorial_conventions' ) ) {
		return null;
	}
	$out = array();
	foreach ( (array) sn_theme_editorial_conventions() as $row ) {
		if ( is_array( $row ) && '' !== (string) ( $row['id'] ?? '' ) ) {
			$out[ (string) $row['id'] ] = $row;
		}
	}
	return $out;
}

/**
 * The classes a parsed block carries, from attrs.className.
 *
 * @param array $block
 * @return string[]
 */
function snt_editorial_block_classes( array $block ) {
	$cn = (string) ( $block['attrs']['className'] ?? '' );
	return '' === trim( $cn ) ? array() : preg_split( '/\s+/', trim( $cn ) );
}

/**
 * The same block with one class added to attrs.className AND to the first
 * tag's class attribute in innerHTML/innerContent, so the result round-trips
 * through serialize_block the way the editor would have written it.
 *
 * @param array  $block
 * @param string $class
 * @return array
 */
function snt_editorial_block_with_class( array $block, $class ) {
	$classes = snt_editorial_block_classes( $block );
	if ( in_array( $class, $classes, true ) ) {
		return $block;
	}
	$classes[]                       = $class;
	$block['attrs']['className']     = implode( ' ', $classes );
	$rewrite = static function ( $html ) use ( $class ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		// First opening tag only; the class attribute either exists or does not.
		return preg_replace_callback( '/^(\s*<[a-z][a-z0-9]*)([^>]*)>/i', static function ( $m ) use ( $class ) {
			$attrs = $m[2];
			if ( preg_match( '/\sclass="([^"]*)"/', $attrs, $cm ) ) {
				$attrs = str_replace( $cm[0], ' class="' . trim( $cm[1] . ' ' . $class ) . '"', $attrs );
			} else {
				$attrs .= ' class="' . $class . '"';
			}
			return $m[1] . $attrs . '>';
		}, $html, 1 );
	};
	$block['innerHTML'] = $rewrite( $block['innerHTML'] ?? '' );
	if ( isset( $block['innerContent'][0] ) ) {
		$block['innerContent'][0] = $rewrite( $block['innerContent'][0] );
	}
	return $block;
}

/**
 * Plain text of a block's own HTML (tags stripped, whitespace folded).
 *
 * @param array $block
 * @return string
 */
function snt_editorial_block_text( array $block ) {
	return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) ( $block['innerHTML'] ?? '' ) ) ) );
}

/**
 * The top-level, non-whitespace blocks of a parse_blocks() tree with their
 * raw indexes (the scanner's block_path grammar: "0/<raw index>").
 *
 * @param array $tree
 * @return array<int,array{idx:int,block:array}>
 */
function snt_editorial_top_level( array $tree ) {
	$out = array();
	foreach ( $tree as $idx => $block ) {
		if ( is_array( $block ) && null !== ( $block['blockName'] ?? null ) ) {
			$out[] = array( 'idx' => (int) $idx, 'block' => $block );
		}
	}
	return $out;
}

/**
 * Run every shape test over a parsed tree.
 *
 * Each finding: {id, block_path, block_name, message, fix ('class'|'form'),
 * replacement (serialized block with the class added, when fix is 'class'),
 * evidence}. Returns null when the registry is unavailable.
 *
 * @param array $tree parse_blocks() output.
 * @return array<int,array<string,mixed>>|null
 */
function snt_editorial_conventions_detect( array $tree ) {
	$reg = snt_editorial_conventions_registry();
	if ( null === $reg ) {
		return null;
	}
	$top      = snt_editorial_top_level( $tree );
	$findings = array();
	$n        = count( $top );
	$finding  = static function ( $id, $entry, $block, $message, $fix, $replacement = '', array $evidence = array() ) use ( $reg ) {
		return array(
			'id'          => $id,
			'label'       => (string) ( $reg[ $id ]['label'] ?? $id ),
			'block_path'  => '0/' . $entry['idx'],
			'block_name'  => (string) ( $block['blockName'] ?? '' ),
			'message'     => $message,
			'fix'         => $fix,
			'replacement' => $replacement,
			'evidence'    => $evidence,
		);
	};

	foreach ( $top as $i => $entry ) {
		$b       = $entry['block'];
		$name    = (string) ( $b['blockName'] ?? '' );
		$classes = snt_editorial_block_classes( $b );
		$html    = (string) ( $b['innerHTML'] ?? '' );

		// ── correction: a paragraph whose TEXT opens "Correction," ─────
		// Matched on text, not markup, so an <em>, <b>, <i> or bare notice is
		// seen too (note 1514's italic colon form matched nothing before).
		if ( isset( $reg['correction'] ) && 'core/paragraph' === $name && preg_match( '/^Correction,/i', snt_editorial_block_text( $b ) ) ) {
			$house = (bool) preg_match( '/^\s*<p[^>]*>\s*<strong>\s*Correction, (?:January|February|March|April|May|June|July|August|September|October|November|December) \d{1,2}, \d{4}\.\s*<\/strong>/', $html );
			if ( ! in_array( 'sn-correction', $classes, true ) ) {
				// The class-only fix is offered only when the lead-in is already
				// right: on any other form it would bless a wrong lead-in.
				$findings[] = $house
					? $finding( 'correction', $entry, $b, 'A dated correction notice without the sn-correction class: the house form is a core/paragraph carrying className "sn-correction" (convention id: correction).', 'class', serialize_block( snt_editorial_block_with_class( $b, 'sn-correction' ) ) )
					: $finding( 'correction', $entry, $b, 'A correction notice without the sn-correction class, and not in the house lead-in either, so no class-only fix is offered: fix the lead-in and add className "sn-correction" by hand (convention id: correction).', 'form' );
			}
			if ( ! $house ) {
				// No replacement: rewriting the lead-in changes signed prose.
				$findings[] = $finding( 'correction', $entry, $b, 'A correction notice not in the house lead-in: the house form opens <strong>Correction, Month D, YYYY.</strong> (bold, a period after the date) followed by the text. The owner applies it by hand (convention id: correction).', 'form', '', array( 'opens' => mb_substr( snt_editorial_block_text( $b ), 0, 60 ) ) );
			}
			if ( $i !== $n - 1 ) {
				$findings[] = $finding( 'correction', $entry, $b, 'A correction notice that is not the last block: the house placement is last, after References (convention id: correction).', 'form', '', array( 'position' => $i + 1, 'of' => $n ) );
			}
		}

		// ── references: a list right after an H2 "References" ──────────
		if ( isset( $reg['references'] ) && 'core/heading' === $name && 2 === (int) ( $b['attrs']['level'] ?? 2 ) && 'references' === strtolower( snt_editorial_block_text( $b ) ) ) {
			$next = $top[ $i + 1 ] ?? null;
			if ( $next && 'core/list' === (string) ( $next['block']['blockName'] ?? '' ) && ! in_array( 'is-style-references', snt_editorial_block_classes( $next['block'] ), true ) ) {
				$findings[] = $finding( 'references', $next, $next['block'], 'A list under an H2 "References" without the References block style: the house form is core/list with className "is-style-references" (convention id: references).', 'class', serialize_block( snt_editorial_block_with_class( $next['block'], 'is-style-references' ) ) );
			}
		}

		// ── lead: the first block, a whole-<em> paragraph ───────────────
		if ( isset( $reg['lead'] ) && 0 === $i && 'core/paragraph' === $name && preg_match( '/^\s*<p[^>]*>\s*<em>.*<\/em>\s*<\/p>\s*$/is', $html ) && ! in_array( 'sn-lead', $classes, true ) ) {
			$findings[] = $finding( 'lead', $entry, $b, 'An opening paragraph set wholly in <em> without the sn-lead class: the house form for a standfirst is a core/paragraph carrying className "sn-lead" (convention id: lead).', 'class', serialize_block( snt_editorial_block_with_class( $b, 'sn-lead' ) ) );
		}

		// ── timeline: an ordered list, or a steps group, whose EVERY item
		// opens with a date. Checked before steps so a dated list gets one
		// suggestion (timeline), not two that contradict each other.
		$is_dated = false;
		$steps_group = 'core/group' === $name && in_array( 'sn-pattern-steps-enumerated', $classes, true );
		if ( isset( $reg['timeline'] ) && ( $steps_group || ( 'core/list' === $name && ! empty( $b['attrs']['ordered'] ) && ! in_array( 'sn-timeline__list', $classes, true ) ) ) ) {
			$items = snt_editorial_list_item_openers( serialize_block( $b ) );
			$dated = count( array_filter( $items, 'snt_editorial_is_date_opener' ) );
			if ( count( $items ) >= 2 && count( $items ) === $dated ) {
				$is_dated   = true;
				$findings[] = $finding( 'timeline', $entry, $b, ( $steps_group ? 'An enumerated-steps group' : 'An ordered list' ) . ' whose every item opens with a date: a dated sequence is a timeline, not steps. The house form is a core/group "sn-pattern-timeline" holding a "sn-timeline__label" paragraph and an unordered list "sn-timeline__list", each item opening with the date in <strong> (convention id: timeline).', 'form', '', array( 'dated_items' => $dated ) );
			}
		}

		// ── nesting: a steps or compare group inside a group of its own kind.
		// It renders a panel inside a panel and numbers the inner list twice.
		foreach ( array( 'steps-enumerated' => 'sn-pattern-steps-enumerated', 'compare-columns' => 'sn-pattern-compare-columns' ) as $cid => $ccls ) {
			if ( ! isset( $reg[ $cid ] ) || 'core/group' !== $name ) {
				continue;
			}
			$nested = snt_editorial_nested_same_class( serialize_block( $b ), $ccls );
			if ( $nested > 0 ) {
				$findings[] = $finding( $cid, $entry, $b, 'Malformed: a "' . $ccls . '" group nested inside another "' . $ccls . '" group. The house form is one group per sequence or comparison; unwrap the inner group so its contents sit directly in the outer one (convention id: ' . $cid . ').', 'form', '', array( 'nested' => $nested ) );
			}
		}

		// ── table: the house style, and the header row it requires ─────────
		if ( isset( $reg['table'] ) && 'core/table' === $name ) {
			$head   = snt_editorial_table_header( $html );
			$styled = in_array( 'is-style-sn-table', $classes, true );
			if ( ! $head['thead'] ) {
				$findings[] = $finding( 'table', $entry, $b, 'A table with no header row' . ( $styled ? '' : ' and without the comparison-table style' ) . ': the house form is core/table with className "is-style-sn-table" and a <thead> header row (the style adds scope="col" to its cells on render) (convention id: table).', 'form', '', $head );
			} else {
				if ( ! $styled ) {
					$findings[] = $finding( 'table', $entry, $b, 'A table without the comparison-table style: the house form is core/table with className "is-style-sn-table" (convention id: table).', 'class', serialize_block( snt_editorial_block_with_class( $b, 'is-style-sn-table' ) ) );
				}
			}
		}

		// ── steps: an ordered list of <strong>Term.</strong> items outside the group
		if ( ! $is_dated && isset( $reg['steps-enumerated'] ) && 'core/list' === $name && ! empty( $b['attrs']['ordered'] ) && ! in_array( 'sn-steps__list', $classes, true ) ) {
			// Items live as innerBlocks under core's parser and as <li> markup
			// under a flat one; count whichever the tree carries, never both.
			$strong = 0;
			$items  = (array) ( $b['innerBlocks'] ?? array() );
			if ( array() !== $items ) {
				foreach ( $items as $li ) {
					if ( preg_match( '/<li[^>]*>\s*<strong>[^<]{1,60}\.<\/strong>/', (string) ( $li['innerHTML'] ?? '' ) ) ) {
						++$strong;
					}
				}
			} else {
				$strong = preg_match_all( '/<li[^>]*>\s*<strong>[^<]{1,60}\.<\/strong>/', $html );
			}
			if ( $strong >= 2 ) {
				$findings[] = $finding( 'steps-enumerated', $entry, $b, 'An ordered list of <strong>Term.</strong> items outside the enumerated-steps group: the house form is a core/group "sn-pattern-steps-enumerated" holding a "sn-steps__label" paragraph and the list as "sn-steps__list" (convention id: steps-enumerated).', 'form', '', array( 'strong_items' => $strong ) );
			}
		}

		// ── svg-figure: the accessible trio and the palette ─────────────
		if ( isset( $reg['svg-figure'] ) && 'core/html' === $name && false !== stripos( $html, '<svg' ) ) {
			$missing = array();
			if ( ! preg_match( '/<svg[^>]*\srole="img"/i', $html ) ) {
				$missing[] = 'role="img"';
			}
			if ( ! preg_match( '/<svg[^>]*\saria-labelledby="/i', $html ) ) {
				$missing[] = 'aria-labelledby';
			}
			if ( false === stripos( $html, '<title' ) ) {
				$missing[] = '<title>';
			}
			if ( false === stripos( $html, '<desc' ) ) {
				$missing[] = '<desc>';
			}
			if ( array() !== $missing ) {
				$findings[] = $finding( 'svg-figure', $entry, $b, 'An inline SVG figure missing ' . implode( ', ', $missing ) . ': the house form carries role="img", aria-labelledby, a <title> and a <desc> (convention id: svg-figure).', 'form', '', array( 'missing' => $missing ) );
			}
			$hex = array();
			if ( preg_match_all( '/\b(?:fill|stroke)="(#[0-9a-f]{3,8})"/i', $html, $hm ) ) {
				$hex = array_values( array_unique( array_map( 'strtolower', $hm[1] ) ) );
			}
			if ( array() !== $hex ) {
				$findings[] = $finding( 'svg-figure', $entry, $b, 'An inline SVG painted in fixed hex (' . implode( ', ', $hex ) . '): the house form uses currentColor for text and strokes and the blood preset token for the one accent, so the figure follows both palettes (convention id: svg-figure).', 'form', '', array( 'hex' => $hex ) );
			}
		}
	}
	return $findings;
}
