<?php
/**
 * Signal & Noise Tools: sn_apply block_edit, span resolution (the block_path lookup, the span resolver, the removal range, the anchor locator).
 *
 * Split from inc/sn-apply/block-edit.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a block_path ("0/<index>") to its top-level span — the locator
 * that does NOT depend on visible text (v13.5.0), for blocks whose content
 * lives entirely in delimiter attributes (sidenote, pull-quote: write-once
 * through the anchor locator by construction).
 *
 * THE SYNTAX IS REUSED, not invented: scan_type "block_migrations" already
 * surfaces block_path on targets[] — a '0' seed plus RAW parse_blocks()
 * indices (whitespace separator nodes between blocks COUNT, exactly as the
 * detect walkers enumerate them), '/innerBlocks/<i>' for nesting. This
 * family operates on TOP-LEVEL blocks only, so nested paths refuse by name.
 *
 * STALENESS: a path is position-bound, but it can never silently hit the
 * wrong block — change.fingerprint (the REQUIRED live content_hash) binds
 * the caller's entire view of the post and 409s at gate 1 BEFORE any path
 * is dereferenced. A path that misses under a FRESH hash is caller
 * arithmetic, refused here naming the path and what actually sits at it.
 * block_migrations' descending-order constraint on same-post candidates
 * does not apply: one call is one splice, and the next call must re-fetch
 * the content_hash by construction.
 *
 * @param string $content    Raw stored post_content.
 * @param string $block_path e.g. "0/2".
 * @return array{span:array{start:int,end:int,block_name:string},ordinal:int,spans:array}|WP_Error
 */
function snt_sn_apply_block_edit_span_for_path( $content, $block_path ) {
	$block_path = trim( (string) $block_path );
	// Nested syntax is recognized BEFORE the numeric grammar, so a real
	// scan-emitted nested path gets the honest top-level refusal instead of
	// a generic syntax complaint.
	if ( false !== strpos( $block_path, '/innerBlocks' ) ) {
		return new WP_Error( 'snt_sn_apply_block_path_not_top_level', __( 'This family edits TOP-LEVEL blocks only — a nested path ("…/innerBlocks/…") has no whole-block splice to perform. Target the containing top-level block instead.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( ! preg_match( '#^0(/[0-9]+)+$#', $block_path ) ) {
		return new WP_Error( 'snt_sn_apply_bad_block_path', __( 'payload.block_path must use the block_migrations path syntax: "0/<index>" (a literal 0 seed, then the parse_blocks index) — copy it from a scan\'s targets[].block_path or count parse_blocks nodes, whitespace separators included.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	$segments = explode( '/', $block_path );
	if ( count( $segments ) > 2 ) {
		return new WP_Error( 'snt_sn_apply_block_path_not_top_level', __( 'This family edits TOP-LEVEL blocks only — a nested path ("…/innerBlocks/…") has no whole-block splice to perform. Target the containing top-level block instead.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	$n     = (int) $segments[1];
	$nodes = parse_blocks( (string) $content );
	$count = count( $nodes );
	if ( $n >= $count ) {
		return new WP_Error(
			'snt_sn_apply_block_path_out_of_range',
			sprintf(
				/* translators: 1: the requested path, 2: the number of parse_blocks nodes */
				__( 'payload.block_path "%1$s" points past the end of the post — parse_blocks yields %2$d nodes (whitespace separators included). The live content_hash you supplied is CURRENT, so this is a path arithmetic error, not staleness.', 'signal-and-noise-tools' ),
				$block_path,
				$count
			),
			array( 'status' => 422 )
		);
	}
	$node = (array) $nodes[ $n ];
	if ( null === ( $node['blockName'] ?? null ) ) {
		$is_ws = '' === trim( (string) ( $node['innerHTML'] ?? '' ) );
		return new WP_Error(
			'snt_sn_apply_block_path_not_a_block',
			$is_ws
				? sprintf(
					/* translators: %s: the requested path */
					__( 'payload.block_path "%s" lands on a WHITESPACE separator between blocks — parse_blocks indices count them (block, separator, block, …), so real blocks usually sit at even indices. Aim one index up or down.', 'signal-and-noise-tools' ),
					$block_path
				)
				: sprintf(
					/* translators: %s: the requested path */
					__( 'payload.block_path "%s" lands on freeform (non-block) HTML — this family splices whole delimiter-bounded blocks and cannot operate on classic content.', 'signal-and-noise-tools' ),
					$block_path
				),
			array( 'status' => 422 )
		);
	}

	$ordinal = 0;
	for ( $i = 0; $i < $n; $i++ ) {
		if ( null !== ( ( (array) $nodes[ $i ] )['blockName'] ?? null ) ) {
			$ordinal++;
		}
	}
	$scan  = snt_sn_apply_block_edit_scan_spans( (string) $content );
	$spans = $scan['spans'];
	if ( $ordinal >= count( $spans ) ) {
		return new WP_Error( 'snt_sn_apply_block_path_unresolvable', __( 'The stored content\'s block delimiters and parse_blocks disagree about how many top-level blocks exist — the content is malformed; nothing was located. Repair the post in the editor first.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	// Belt: the span the ordinal picked must be the SAME block the parser
	// saw (delimiter names elide the core/ namespace; normalize to compare).
	$span      = $spans[ $ordinal ];
	$span_full = false !== strpos( (string) $span['block_name'], '/' ) ? (string) $span['block_name'] : 'core/' . $span['block_name'];
	if ( $span_full !== (string) $node['blockName'] ) {
		return new WP_Error( 'snt_sn_apply_block_path_unresolvable', __( 'The block at this path does not match the delimiter scan (parser/scanner disagreement) — the content is malformed; nothing was located.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	return array( 'span' => $span, 'ordinal' => $ordinal, 'spans' => $spans );
}

/**
 * Resolve whichever locator the payload carries (exactly-one already
 * enforced by the payload validator) to {span, ordinal, spans}.
 *
 * @param string $content
 * @param array  $payload
 * @return array{span:array,ordinal:int,spans:array}|WP_Error
 */
function snt_sn_apply_block_edit_resolve_span( $content, array $payload ) {
	$path = trim( (string) ( $payload['block_path'] ?? '' ) );
	if ( '' !== $path ) {
		// context_snippet disambiguates ANCHOR matches; a path is already
		// exact. Accepting-and-ignoring it would be a silent no-op input —
		// the family refuses those by name (review round).
		if ( '' !== trim( (string) ( $payload['context_snippet'] ?? '' ) ) ) {
			return new WP_Error( 'snt_sn_apply_locator_conflict', __( 'payload.context_snippet disambiguates anchor matches and is unused with payload.block_path — omit it; a path is already exact.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		return snt_sn_apply_block_edit_span_for_path( $content, $path );
	}
	$located = snt_sn_apply_block_edit_locate( (string) $content, (string) ( $payload['anchor'] ?? '' ), (string) ( $payload['context_snippet'] ?? '' ) );
	if ( is_wp_error( $located ) ) {
		return $located;
	}
	$scan    = snt_sn_apply_block_edit_scan_spans( (string) $content );
	$ordinal = null;
	foreach ( $scan['spans'] as $i => $candidate ) {
		if ( $candidate['start'] === $located['span']['start'] ) {
			$ordinal = $i;
			break;
		}
	}
	return array( 'span' => $located['span'], 'ordinal' => (int) $ordinal, 'spans' => $scan['spans'] );
}

/**
 * The byte range a delete/move removes for a span: the span PLUS one
 * adjacent whitespace-only separator (the following one when present, else
 * the preceding one for a last block), so the remaining content keeps its
 * canonical "\n\n" rhythm. A NON-whitespace gap (classic freeform HTML) is
 * never consumed.
 *
 * @param string $content
 * @param array  $spans   All top-level spans.
 * @param int    $i       Index of the span being removed.
 * @return array{0:int,1:int} [start, end) of the removal.
 */
function snt_sn_apply_block_edit_removal_range( $content, array $spans, $i ) {
	$span = $spans[ $i ];
	$next = $spans[ $i + 1 ] ?? null;
	$prev = $i > 0 ? $spans[ $i - 1 ] : null;
	if ( null !== $next && '' === trim( substr( $content, $span['end'], $next['start'] - $span['end'] ) ) ) {
		return array( $span['start'], $next['start'] );
	}
	if ( null === $next && null !== $prev && '' === trim( substr( $content, $prev['end'], $span['start'] - $prev['end'] ) ) ) {
		return array( $prev['end'], $span['end'] );
	}
	return array( $span['start'], $span['end'] );
}

/**
 * Locate the anchor in raw stored content and resolve its containing
 * TOP-LEVEL block span. Multiple matches disambiguate via context_snippet
 * within a fixed window; still-ambiguous refuses naming the anchor.
 *
 * @param string $content         Raw post_content.
 * @param string $anchor          Byte-exact substring.
 * @param string $context_snippet Optional disambiguator (~200 chars around the match).
 * @return array{offset:int,span:array{start:int,end:int,block_name:string}}|WP_Error
 *
 * WP_Error codes:
 *   snt_sn_apply_anchor_not_found   (409)
 *   snt_sn_apply_anchor_ambiguous   (422)
 *   snt_sn_apply_anchor_in_delimiter (422)
 *   snt_sn_apply_anchor_boundary    (422)
 */
function snt_sn_apply_block_edit_locate( $content, $anchor, $context_snippet = '' ) {
	$content = (string) $content;
	$anchor  = (string) $anchor;

	$offsets = array();
	$pos     = 0;
	while ( false !== ( $pos = strpos( $content, $anchor, $pos ) ) ) {
		$offsets[] = $pos;
		$pos++;
	}

	if ( empty( $offsets ) ) {
		return new WP_Error(
			'snt_sn_apply_anchor_not_found',
			sprintf(
				/* translators: %s is the anchor text that was not found */
				__( 'payload.anchor "%s" does not occur in the stored post_content — the match is byte-exact, entities and markup included. Copy it verbatim from sn_posts\' content field.', 'signal-and-noise-tools' ),
				$anchor
			),
			array( 'status' => 409 )
		);
	}

	if ( count( $offsets ) > 1 && '' !== (string) $context_snippet ) {
		$window   = SNT_SN_APPLY_BLOCK_EDIT_CONTEXT_WINDOW;
		$length   = strlen( $anchor );
		$filtered = array_values( array_filter( $offsets, static function ( $offset ) use ( $content, $context_snippet, $window, $length ) {
			$start = max( 0, $offset - $window );
			$slice = substr( $content, $start, $length + 2 * $window );
			return false !== strpos( $slice, (string) $context_snippet );
		} ) );
		if ( ! empty( $filtered ) ) {
			$offsets = $filtered;
		}
	}

	if ( count( $offsets ) > 1 ) {
		return new WP_Error(
			'snt_sn_apply_anchor_ambiguous',
			sprintf(
				/* translators: 1: number of matches, 2: the anchor text */
				__( '%1$d identical occurrences of payload.anchor "%2$s" match — pass payload.context_snippet (~200 chars around the intended one) to disambiguate; this tool refuses ambiguity rather than guessing.', 'signal-and-noise-tools' ),
				count( $offsets ),
				$anchor
			),
			array( 'status' => 422 )
		);
	}

	$offset = $offsets[0];
	$end    = $offset + strlen( $anchor );
	$scan   = snt_sn_apply_block_edit_scan_spans( $content );

	foreach ( $scan['delimiters'] as $range ) {
		if ( $offset < $range[1] && $end > $range[0] ) {
			return new WP_Error( 'snt_sn_apply_anchor_in_delimiter', __( 'payload.anchor intersects a block-delimiter comment — anchor on the block\'s visible content, never its <!-- wp:... --> machinery.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
	}

	foreach ( $scan['spans'] as $span ) {
		if ( $offset >= $span['start'] && $end <= $span['end'] ) {
			return array( 'offset' => $offset, 'span' => $span );
		}
	}

	return new WP_Error( 'snt_sn_apply_anchor_boundary', __( 'payload.anchor does not sit entirely inside one top-level block — it spans a block boundary or lands in non-block (freeform) content. Anchor within a single block\'s content.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
}

