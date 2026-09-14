<?php
/**
 * Signal & Noise Tools — editorial convention DRIFT detector. (v14.7.0)
 *
 * Markup that matches a house convention's SEMANTIC SHAPE but not its FORM:
 * a trailing paragraph that opens "<strong>Correction," and carries no
 * sn-correction class; a bibliography list under an H2 "References" without
 * is-style-references; a first-block <em> paragraph without sn-lead; an
 * ordered list of <strong>Term.</strong> items outside the steps group; an
 * inline SVG missing the accessible trio or painted in a frozen hex. All
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

/** The ids this detector knows how to test, in the order findings sort. */
const SNT_EDITORIAL_CONVENTION_CHECKS = array( 'correction', 'references', 'lead', 'steps-enumerated', 'svg-figure' );

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

		// ── correction: opens "<strong>Correction," ─────────────────────
		if ( isset( $reg['correction'] ) && 'core/paragraph' === $name && preg_match( '/<p[^>]*>\s*<strong>\s*Correction,/i', $html ) ) {
			if ( ! in_array( 'sn-correction', $classes, true ) ) {
				$findings[] = $finding( 'correction', $entry, $b, 'A dated correction notice without the sn-correction class: the house form is a core/paragraph carrying className "sn-correction" (convention id: correction).', 'class', serialize_block( snt_editorial_block_with_class( $b, 'sn-correction' ) ) );
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

		// ── steps: an ordered list of <strong>Term.</strong> items outside the group
		if ( isset( $reg['steps-enumerated'] ) && 'core/list' === $name && ! empty( $b['attrs']['ordered'] ) && ! in_array( 'sn-steps__list', $classes, true ) ) {
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
