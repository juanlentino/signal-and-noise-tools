<?php
/**
 * Signal & Noise Tools — em-dash prose scanner.
 *
 * House style is no em-dashes in PROSE. It is NOT "no em-dashes anywhere", and the
 * difference is the entire point of this module. A blanket regex over post content
 * damages things that are not copy:
 *
 *   - "— Juan Lentino, May 7, 2026 · 7 min read"  an attribution lead
 *   - a table cell whose whole content is "—"      the no-value glyph
 *   - <code>/<pre>                                 literal content
 *   - tag attributes, Gutenberg block comments     markup, not copy
 *
 * The v10.48.2 sweep had no classifier and rewrote three AI system prompts and the
 * 404 document-title separator as though they were copy (both reverted in v10.49.1).
 * This module puts that distinction in code, with tests, so it stops depending on
 * whoever is running the regex that day.
 *
 * PURE by design: content in, candidates out, no writes and no WordPress calls. The
 * apply path is sn-apply's `emdash_replace` type, which reuses drift_replace's
 * locate + fingerprint + splice machinery unchanged — this file deliberately does
 * not re-implement any of it.
 *
 * @package SignalNoiseTools
 * @since 10.50.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_EMDASH = "\xE2\x80\x94"; // U+2014, as raw UTF-8 bytes: post_content is a byte string.

/**
 * Every way an em-dash is actually stored in post_content.
 *
 * The CMS writes the ENTITY form for pages authored in the editor — /about/uses/ and
 * /now/ both store `&mdash;`. A scanner matching only the raw U+2014 bytes reports
 * those pages clean while the reader plainly sees a dash, which is exactly what
 * happened on the first pass of this module.
 */
const SNT_EMDASH_PATTERN = '/(\x{2014}|&mdash;|&#8212;|&#x2014;)/iu';

/**
 * Byte ranges in $content that are markup rather than copy.
 *
 * Covers HTML tags (so attribute values are excluded), HTML/Gutenberg comments, and
 * the full span of <code>, <pre>, <kbd>, <samp>, <script> and <style> elements.
 *
 * @param string $content Raw post content.
 * @return array<int,array{0:int,1:int}> Sorted [start, end) byte ranges.
 */
function snt_emdash_markup_ranges( $content ) {
	$ranges = array();

	// Comments first: a block comment can contain angle brackets that would
	// otherwise be mis-read as tags.
	if ( preg_match_all( '/<!--.*?-->/s', $content, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[0] as $hit ) {
			$ranges[] = array( $hit[1], $hit[1] + strlen( $hit[0] ) );
		}
	}
	// Verbatim elements, whole span including their text.
	if ( preg_match_all( '#<(code|pre|kbd|samp|script|style)\b[^>]*>.*?</\1\s*>#is', $content, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[0] as $hit ) {
			$ranges[] = array( $hit[1], $hit[1] + strlen( $hit[0] ) );
		}
	}
	// Any remaining tag, so attribute values are markup.
	if ( preg_match_all( '/<[^!][^>]*>/s', $content, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[0] as $hit ) {
			$ranges[] = array( $hit[1], $hit[1] + strlen( $hit[0] ) );
		}
	}
	usort( $ranges, function ( $a, $b ) { return $a[0] <=> $b[0]; } );
	return $ranges;
}

/**
 * Whether a byte offset falls inside any markup range.
 *
 * @param int   $pos    Byte offset.
 * @param array $ranges From snt_emdash_markup_ranges().
 * @return bool
 */
function snt_emdash_in_markup( $pos, $ranges ) {
	foreach ( $ranges as $r ) {
		if ( $pos >= $r[0] && $pos < $r[1] ) {
			return true;
		}
		if ( $r[0] > $pos ) {
			break; // Sorted: no later range can contain it.
		}
	}
	return false;
}

/**
 * The text run an offset sits in: the copy between the surrounding tags.
 *
 * @param string $content Raw post content.
 * @param int    $pos     Byte offset of the em-dash.
 * @return array{0:int,1:string} [run start offset, run text]
 */
function snt_emdash_text_run( $content, $pos ) {
	$start = strrpos( substr( $content, 0, $pos ), '>' );
	$start = ( false === $start ) ? 0 : $start + 1;
	$end   = strpos( $content, '<', $pos );
	$end   = ( false === $end ) ? strlen( $content ) : $end;
	return array( $start, substr( $content, $start, $end - $start ) );
}

/**
 * Classify one em-dash occurrence and, when it is prose, say what replaces it.
 *
 * @param string $content Raw post content.
 * @param int    $pos     Byte offset of the em-dash.
 * @param array  $ranges  Markup ranges.
 * @return array A candidate row.
 */
function snt_emdash_classify( $content, $pos, $ranges, $token = SNT_EMDASH ) {
	$row = array(
		'position'        => $pos,
		'phrase'          => $token,
		'replacement'     => '',
		'context_snippet' => trim( substr( $content, max( 0, $pos - 40 ), 80 ) ),
		'classification'  => 'structural',
		'reason'          => '',
		'pair'            => '',
	);

	if ( snt_emdash_in_markup( $pos, $ranges ) ) {
		// One reason, two shapes: inside a verbatim element vs inside a tag or
		// comment. Naming them apart makes a surprising skip explainable.
		$verbatim = false;
		if ( preg_match_all( '#<(code|pre|kbd|samp|script|style)\b[^>]*>.*?</\1\s*>#is', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				if ( $pos >= $hit[1] && $pos < $hit[1] + strlen( $hit[0] ) ) { $verbatim = true; break; }
			}
		}
		$row['reason'] = $verbatim ? 'code_or_preformatted' : 'inside_markup';
		return $row;
	}

	list( $run_start, $run ) = snt_emdash_text_run( $content, $pos );
	$offset_in_run = $pos - $run_start;
	$trimmed       = trim( $run );

	// The no-value glyph: the entire run is the dash, in any of its forms.
	if ( $token === $trimmed ) {
		$row['reason'] = 'no_value_glyph';
		return $row;
	}

	// An attribution lead: the dash opens the run and a name follows.
	$before_in_run = substr( $run, 0, $offset_in_run );
	if ( '' === trim( $before_in_run ) ) {
		$row['reason'] = 'attribution_lead';
		return $row;
	}

	// Everything else in a text run is prose.
	$row['classification'] = 'prose';
	$row['reason']         = 'prose';

	$after = substr( $content, $pos + strlen( $token ) );
	$prev  = substr( $content, max( 0, $pos - 1 ), 1 );
	$next  = substr( $after, 0, 1 );

	// Unspaced infix: photos—exactly
	if ( '' !== $prev && '' !== $next && ! ctype_space( $prev ) && ! ctype_space( $next ) ) {
		$row['replacement'] = ', ';
		return $row;
	}

	// Spaced: absorb the surrounding single spaces so the splice is one clean unit.
	$lead = ( ' ' === $prev ) ? 1 : 0;
	$row['position'] = $pos - $lead;
	$row['phrase']   = ( $lead ? ' ' : '' ) . $token . ( ( ' ' === $next ) ? ' ' : '' );

	$rest      = ltrim( substr( $after, ( ' ' === $next ) ? 1 : 0 ) );
	$first     = substr( $rest, 0, 1 );
	$is_upper  = ( '' !== $first && ( ctype_upper( $first ) || ctype_digit( $first ) || '<' === $first || '&' === $first ) );
	$row['replacement'] = $is_upper ? '. ' : ': ';
	return $row;
}

/**
 * Scan raw post content for em-dashes and classify each one.
 *
 * Reports EVERY occurrence, structural ones included, so a skip is auditable rather
 * than invisible. Only rows with classification 'prose' are valid apply candidates;
 * sn-apply's emdash_replace gate enforces that server-side.
 *
 * @param string $content Raw post content.
 * @return array<int,array> Candidate rows in document order.
 */
function snt_emdash_scan_content( $content ) {
	$content = (string) $content;
	if ( '' === $content ) {
		return array();
	}
	if ( ! preg_match_all( SNT_EMDASH_PATTERN, $content, $m, PREG_OFFSET_CAPTURE ) ) {
		return array();
	}

	$ranges = snt_emdash_markup_ranges( $content );
	$rows   = array();
	foreach ( $m[0] as $hit ) {
		$rows[] = snt_emdash_classify( $content, $hit[1], $ranges, $hit[0] );
	}

	// A PAIR of prose dashes inside one text run is a parenthetical, not two
	// independent breaks. Rewriting them separately produced "site: two native,
	// one third-party: and every one" during the v10.48.2 sweep — the reason this
	// is handled here rather than per-occurrence.
	$by_run   = array();
	$coalesce = array();
	foreach ( $rows as $i => $r ) {
		if ( 'prose' !== $r['classification'] ) {
			continue;
		}
		list( $run_start, ) = snt_emdash_text_run( $content, $r['position'] );
		$by_run[ $run_start ][] = $i;
	}
	foreach ( $by_run as $idxs ) {
		if ( 2 !== count( $idxs ) ) {
			continue;
		}
		list( $a, $b ) = $idxs;
		if ( ' ' !== substr( $rows[ $a ]['phrase'], 0, 1 ) || ' ' !== substr( $rows[ $b ]['phrase'], 0, 1 ) ) {
			continue; // Only spaced pairs form a parenthetical.
		}
		$rows[ $a ]['replacement'] = ' (';
		$rows[ $b ]['replacement'] = ') ';
		$coalesce[ $a ]            = $b;
	}

	// v10.66.0: a pair is emitted as ONE candidate carrying BOTH splices.
	//
	// It used to emit two rows marked paired_open/paired_close — correctly
	// reasoning about the parenthetical as one edit, then leaving the caller to
	// notice the marker and group them. Nobody did. Each half was applied on its
	// own, so one logical edit wrote twice and, because every publish re-anchors,
	// minted TWO provenance ledger versions — the intermediate one a permanently
	// anchored sentence carrying an opening parenthesis and a closing em-dash.
	//
	// The coalesced row deliberately carries NO top-level phrase/replacement, so a
	// caller that ignores `edits` and reaches for `phrase` gets an empty string and
	// a loud 422 rather than half a parenthetical: the wrong thing is
	// unrepresentable, not merely discouraged. Feed `edits` straight to sn-apply's
	// change.payload.edits (inc/sn-apply-batch-edits.php), which writes once.
	if ( $coalesce ) {
		$out   = array();
		$eaten = array_flip( $coalesce );
		foreach ( $rows as $i => $row ) {
			if ( isset( $eaten[ $i ] ) ) {
				continue; // The closing half now lives inside its opener's row.
			}
			if ( ! isset( $coalesce[ $i ] ) ) {
				$out[] = $row;
				continue;
			}
			$close      = $rows[ $coalesce[ $i ] ];
			$span_start = max( 0, $row['position'] - 40 );
			$span_end   = min( strlen( $content ), $close['position'] + strlen( $close['phrase'] ) + 40 );
			$out[]      = array(
				'position'        => $row['position'],
				// Deliberately empty — see the note above.
				'phrase'          => '',
				'replacement'     => '',
				'context_snippet' => trim( substr( $content, $span_start, $span_end - $span_start ) ),
				'classification'  => 'prose_pair',
				'reason'          => 'parenthetical: one edit, two splices',
				'pair'            => 'paired',
				'edits'           => array(
					array(
						'phrase'          => $row['phrase'],
						'position'        => $row['position'],
						'replacement'     => $row['replacement'],
						'context_snippet' => $row['context_snippet'],
					),
					array(
						'phrase'          => $close['phrase'],
						'position'        => $close['position'],
						'replacement'     => $close['replacement'],
						'context_snippet' => $close['context_snippet'],
					),
				),
			);
		}
		$rows = $out;
	}

	return $rows;
}

/**
 * Apply an em-dash prose fix.
 *
 * A thin, named wrapper over snt_ai_drift_apply_impl() with $preserve_whitespace on.
 * The locate + fingerprint + splice machinery is deliberately NOT duplicated; the only
 * difference between the two change types at the write layer is whether the replacement
 * may keep its edge whitespace, and em-dash replacements always must.
 *
 * @since 10.65.2
 * @param int         $post_id
 * @param string      $phrase          Exact bytes to replace (may include surrounding spaces).
 * @param int         $position        Advisory raw-content offset; re-resolved downstream.
 * @param string      $replacement     Kept verbatim, whitespace included.
 * @param string      $fingerprint     From the scan.
 * @param string      $context_snippet Used to re-locate after an edit.
 * @param callable|null $write_callback Revision-staging callback, or null to write live.
 * @return array|WP_Error
 */
function snt_emdash_apply_impl( $post_id, $phrase, $position, $replacement, $fingerprint, $context_snippet = '', $write_callback = null ) {
	return snt_ai_drift_apply_impl( $post_id, $phrase, $position, $replacement, $fingerprint, $context_snippet, $write_callback, true );
}
