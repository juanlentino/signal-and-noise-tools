<?php
/**
 * Signal & Noise Tools — sn_apply change.types "block_insert" and
 * "block_replace" (v13.2.0).
 *
 * ── Origin (owner spec, 2026-08-25) ──
 *
 * Before these two types, an MCP caller could not ADD block markup to an
 * existing post: sentence_replace is deliberately prose-only (markup is
 * unreachable from it), and every markup-capable type (block_migration,
 * pattern_adoption) is candidate-driven — its fingerprint is minted by its
 * own scan pipeline, which a composing caller cannot produce. block_insert
 * splices caller-composed block markup before/after an anchored top-level
 * block (or at the end of the post); block_replace swaps the WHOLE
 * top-level block containing the anchor for the supplied markup.
 *
 * ── Contracts (all server-side, gate 1 payload/locate + the impl) ──
 *
 *   1. payload.blocks must round-trip: serialize_blocks(parse_blocks(x))
 *      byte-equal (trailing-whitespace tolerant). CRITICAL companion check:
 *      every non-whitespace TOP-LEVEL chunk must parse with a non-null
 *      blockName — a malformed delimiter parses as freeform and
 *      byte-round-trips CLEANLY, so the round-trip check alone is blind to
 *      exactly the failure it exists to catch.
 *   2. Every block name (innerBlocks included, recursively) must be
 *      registered — refused BY NAME, never a generic "invalid".
 *   3. payload.anchor resolves against the RAW stored post_content
 *      (byte-exact substring, sentence-scale >= SNT_SN_APPLY_BLOCK_EDIT_ANCHOR_MIN
 *      chars). Zero matches and still-ambiguous matches both refuse AND
 *      name the anchor. The match must sit ENTIRELY inside one top-level
 *      block span and never intersect a block-delimiter comment.
 *   4. change.fingerprint REQUIRED = the LIVE content_hash
 *      (sentence_replace's binding exactly: missing 422, stale 409).
 *   5. payload.edits is REFUSED (422): block edits interact through tag
 *      structure in ways the prose batch's byte-range overlap check cannot
 *      see — same reason link_insert/link_reshape/unlink refuse it.
 *   6. Scheduled posts: post_status and post_date are captured before the
 *      write, passed EXPLICITLY through it, and re-asserted after — a
 *      violation attempts a restore whose effect is VERIFIED by re-reading
 *      the row (core's silent future→publish coercion returns a plain post
 *      ID, so a return-code check would report success while the post
 *      stayed published), and fails LOUDLY (500) naming the verified
 *      outcome. An OVERDUE 'future' post (post_date_gmt within a minute of
 *      now or past — core's own coercion threshold) refuses UP FRONT in
 *      the publish path (409): any wp_update_post on it, restore included,
 *      would be coerced to an early publish, so nothing is written at all.
 *
 * ── The prose delta (what matters most) ──
 *
 * The ledger signs NORMALIZED prose (sn_prov_normalize_v1: markup never
 * reaches the hash), so a restructure-only edit coalesces to no new
 * version while new text mints one. Every diff for these types carries
 * {prose_changed, prose_added, prose_removed, ledger_impact} — computed by
 * ONE helper (snt_sn_apply_block_edit_prose_delta()), surfaced under
 * dry_run AND the real write, so the ledger consequence is visible BEFORE
 * anything is written.
 *
 * @package SignalNoiseTools
 * @since 13.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Sentence-scale anchor floor — sentence_replace's rationale: a short token
// would resolve its first occurrence anywhere in the post.
const SNT_SN_APPLY_BLOCK_EDIT_ANCHOR_MIN = 20;
// context_snippet disambiguation window, in bytes around a candidate match —
// the link_reshape locator's window, reused as a value not a coupling.
const SNT_SN_APPLY_BLOCK_EDIT_CONTEXT_WINDOW = 250;

/**
 * Scan raw post_content for block-delimiter comments and derive the
 * TOP-LEVEL block spans (byte ranges) plus every delimiter comment's own
 * byte range. Freeform gaps between spans are NOT spans — an anchor there
 * has no containing block and refuses at the boundary check.
 *
 * @param string $content Raw stored post_content.
 * @return array{spans:array<int,array{start:int,end:int,block_name:string}>,delimiters:array<int,array{0:int,1:int}>}
 */
function snt_sn_apply_block_edit_scan_spans( $content ) {
	$content    = (string) $content;
	$spans      = array();
	$delimiters = array();

	if ( ! preg_match_all( '#<!--\s+(/)?wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)(\s+\{.*?\})?\s+?(/)?-->#s', $content, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		return array( 'spans' => array(), 'delimiters' => array() );
	}

	$depth      = 0;
	$open_start = 0;
	$open_name  = '';
	foreach ( $m as $match ) {
		$offset       = (int) $match[0][1];
		$length       = strlen( $match[0][0] );
		$delimiters[] = array( $offset, $offset + $length );

		$is_closer = '' !== ( $match[1][0] ?? '' );
		$is_void   = '' !== ( $match[4][0] ?? '' );
		$name      = (string) $match[2][0];

		if ( $is_closer ) {
			if ( $depth > 0 ) {
				$depth--;
				if ( 0 === $depth ) {
					$spans[] = array( 'start' => $open_start, 'end' => $offset + $length, 'block_name' => $open_name );
				}
			}
			// A stray closer at depth 0 is malformed stored content — no span
			// can be derived from it; the anchor boundary check will refuse.
			continue;
		}

		if ( $is_void ) {
			if ( 0 === $depth ) {
				$spans[] = array( 'start' => $offset, 'end' => $offset + $length, 'block_name' => $name );
			}
			continue;
		}

		if ( 0 === $depth ) {
			$open_start = $offset;
			$open_name  = $name;
		}
		$depth++;
	}

	return array( 'spans' => $spans, 'delimiters' => $delimiters );
}

/**
 * Recursively find the first UNregistered block name in a parse_blocks()
 * tree — the refusal names the block, never a generic "invalid markup".
 * Freeform nodes (null blockName) are skipped here; the top-level freeform
 * check in snt_sn_apply_block_edit_payload_error() owns that refusal.
 *
 * @param array $blocks parse_blocks() output (or an innerBlocks list).
 * @return string|null The first unknown block name, or null when all known.
 */
function snt_sn_apply_block_edit_unknown_block( $blocks ) {
	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		return null;
	}
	$registry = WP_Block_Type_Registry::get_instance();
	foreach ( (array) $blocks as $block ) {
		$block = (array) $block;
		$name  = $block['blockName'] ?? null;
		if ( null !== $name && ! $registry->is_registered( (string) $name ) ) {
			return (string) $name;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$inner = snt_sn_apply_block_edit_unknown_block( $block['innerBlocks'] );
			if ( null !== $inner ) {
				return $inner;
			}
		}
	}
	return null;
}

/**
 * Validate a block_insert/block_replace payload BEFORE the fingerprint
 * check (same ordering as link_reshape's pair validator: a malformed
 * payload is a 422 caller error, never a 409 conflict). Shared by gate 1
 * and the write impl (defense-in-depth — the impl must hold alone).
 *
 * @param string $type    'block_insert'|'block_replace'.
 * @param array  $payload The change.payload object.
 * @return true|WP_Error
 */
function snt_sn_apply_block_edit_payload_error( $type, array $payload ) {
	if ( array_key_exists( 'edits', $payload ) ) {
		return new WP_Error( 'snt_sn_apply_edits_not_supported', __( 'payload.edits is not available for the block edit family: block edits interact through tag structure in ways the prose batch\'s byte-range overlap check cannot see. One call per block edit.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	$position = null;
	if ( 'block_insert' === $type || 'block_move' === $type ) {
		$position = (string) ( $payload['position'] ?? ( 'block_insert' === $type ? 'after' : '' ) );
		if ( ! in_array( $position, array( 'before', 'after', 'end' ), true ) ) {
			return new WP_Error( 'snt_sn_apply_invalid_position', __( 'payload.position must be "before", "after" or "end" (block_insert defaults to "after"; block_move states it explicitly — a move has no natural default direction).', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
	}

	$has_anchor = '' !== trim( (string) ( $payload['anchor'] ?? '' ) );
	$has_path   = '' !== trim( (string) ( $payload['block_path'] ?? '' ) );

	if ( 'block_move' === $type ) {
		// The SOURCE of a move is always block_path — a text anchor names
		// words, and the whole point of this family is blocks with no words
		// outside the delimiter. The `anchor`/`to_block_path` inputs name
		// the DESTINATION instead, exactly-one when position is not "end".
		if ( ! $has_path ) {
			return new WP_Error( 'snt_sn_apply_locator_required', __( 'block_move requires payload.block_path naming the SOURCE block ("0/<index>", the block_migrations path syntax). payload.anchor / payload.to_block_path name the destination, never the source.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		$has_dest_path = '' !== trim( (string) ( $payload['to_block_path'] ?? '' ) );
		if ( 'end' === $position ) {
			if ( $has_anchor || $has_dest_path ) {
				return new WP_Error( 'snt_sn_apply_locator_conflict', __( 'position "end" takes no destination locator — omit payload.anchor and payload.to_block_path rather than passing one that does not anchor anything.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
			}
		} elseif ( $has_anchor && $has_dest_path ) {
			return new WP_Error( 'snt_sn_apply_locator_conflict', __( 'payload.anchor AND payload.to_block_path both name a destination — pass exactly one; this tool refuses a silent precedence rule.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		} elseif ( ! $has_anchor && ! $has_dest_path ) {
			return new WP_Error( 'snt_sn_apply_locator_required', __( 'block_move with position "before"/"after" needs a destination: exactly one of payload.anchor (visible text inside the destination block) or payload.to_block_path.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		if ( $has_anchor && strlen( (string) $payload['anchor'] ) < SNT_SN_APPLY_BLOCK_EDIT_ANCHOR_MIN ) {
			return snt_sn_apply_block_edit_anchor_min_error();
		}
	} else {
		// block_insert / block_replace / block_delete: exactly ONE locator
		// per call — both supplied, or neither, is a named 422, never a
		// silent precedence rule (v13.5.0). block_insert position "end"
		// needs none and refuses either.
		$needs_locator = ! ( 'block_insert' === $type && 'end' === $position );
		if ( ! $needs_locator ) {
			if ( $has_anchor || $has_path ) {
				return new WP_Error( 'snt_sn_apply_invalid_anchor', __( 'payload.anchor and payload.block_path are unused with position "end" — omit them rather than passing a locator that does not locate anything.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
			}
		} elseif ( $has_anchor && $has_path ) {
			return new WP_Error( 'snt_sn_apply_locator_conflict', __( 'payload.anchor AND payload.block_path both supplied — pass exactly one locator; this tool refuses a silent precedence rule.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		} elseif ( ! $has_anchor && ! $has_path ) {
			return new WP_Error( 'snt_sn_apply_locator_required', __( 'This change type needs exactly one locator: payload.anchor (a sentence-scale span of the block\'s visible text) or payload.block_path ("0/<index>", the block_migrations path syntax — the only way to reach a block whose text lives in its delimiter attributes).', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		} elseif ( $has_anchor && strlen( (string) $payload['anchor'] ) < SNT_SN_APPLY_BLOCK_EDIT_ANCHOR_MIN ) {
			return snt_sn_apply_block_edit_anchor_min_error();
		}
	}

	// block_delete removes; block_move carries the existing block unchanged
	// (a reword is block_replace). Accepting payload.blocks on either would
	// silently ignore caller intent — refuse by name instead.
	if ( 'block_delete' === $type || 'block_move' === $type ) {
		if ( array_key_exists( 'blocks', $payload ) ) {
			return new WP_Error( 'snt_sn_apply_blocks_not_accepted', __( 'payload.blocks is not accepted here: block_delete removes the located block and block_move carries it unchanged. To change a block\'s content, use block_replace.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		return true;
	}

	$blocks = (string) ( $payload['blocks'] ?? '' );
	if ( '' === trim( $blocks ) ) {
		return new WP_Error( 'snt_sn_apply_invalid_blocks', __( 'payload.blocks is empty — it must be serialized Gutenberg block markup.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	$parsed = parse_blocks( $blocks );
	// The freeform check MUST come before the round-trip check: a malformed
	// delimiter parses as freeform and byte-round-trips cleanly, so the
	// round-trip alone is blind to exactly this failure.
	foreach ( (array) $parsed as $node ) {
		$node = (array) $node;
		if ( null === ( $node['blockName'] ?? null ) && '' !== trim( (string) ( $node['innerHTML'] ?? '' ) ) ) {
			return new WP_Error( 'snt_sn_apply_invalid_blocks', __( 'payload.blocks contains content that does not parse as a block — every non-whitespace top-level chunk must be a valid <!-- wp:... --> delimited block. A malformed delimiter parses as freeform HTML, which this type refuses rather than writing.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
	}

	$unknown = snt_sn_apply_block_edit_unknown_block( $parsed );
	if ( null !== $unknown ) {
		return new WP_Error(
			'snt_sn_apply_unknown_block',
			sprintf(
				/* translators: %s is the unregistered block name */
				__( 'payload.blocks uses a block that is not registered on this site: "%s".', 'signal-and-noise-tools' ),
				$unknown
			),
			array( 'status' => 422 )
		);
	}

	if ( trim( (string) serialize_blocks( $parsed ) ) !== trim( $blocks ) ) {
		return new WP_Error( 'snt_sn_apply_markup_roundtrip', __( 'payload.blocks does not survive a parse/serialize round-trip byte-identically — re-emit it in canonical serialized form (core\'s own serialize_blocks output) so what is reviewed is exactly what is stored.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	return true;
}

/** The shared 422 for a present-but-sub-sentence anchor. */
function snt_sn_apply_block_edit_anchor_min_error() {
	return new WP_Error(
		'snt_sn_apply_invalid_anchor',
		sprintf(
			/* translators: %d is the minimum anchor length in characters */
			__( 'payload.anchor must be a sentence-scale span (at least %d characters) copied byte-exactly from the stored post_content — a short token would resolve its first occurrence anywhere in the post.', 'signal-and-noise-tools' ),
			SNT_SN_APPLY_BLOCK_EDIT_ANCHOR_MIN
		),
		array( 'status' => 422 )
	);
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

/**
 * Compute the edited content for either type — validate the payload,
 * resolve the anchor to its top-level span, splice. Never writes.
 *
 * @param string $content Raw stored post_content.
 * @param string $type    'block_insert'|'block_replace'.
 * @param array  $payload
 * @return array{new_content:string,replaced_block:?string}|WP_Error
 */
function snt_sn_apply_block_edit_compute( $content, $type, array $payload ) {
	$payload_ok = snt_sn_apply_block_edit_payload_error( $type, $payload );
	if ( is_wp_error( $payload_ok ) ) {
		return $payload_ok;
	}

	$content = (string) $content;
	$blocks  = trim( (string) ( $payload['blocks'] ?? '' ) );

	if ( 'block_insert' === $type && 'end' === (string) ( $payload['position'] ?? 'after' ) ) {
		// rtrim before appending (review round): a post with trailing
		// whitespace after its last block would otherwise gain a \n\n\n run —
		// parseable but off-rhythm, contradicting the canonical-separator
		// promise. Same fix in block_move's 'end' branch.
		$new_content = '' === trim( $content ) ? $blocks : rtrim( $content ) . "\n\n" . $blocks;
		return array( 'new_content' => $new_content, 'replaced_block' => null );
	}

	if ( 'block_move' === $type ) {
		return snt_sn_apply_block_edit_compute_move( $content, $payload );
	}

	$resolved = snt_sn_apply_block_edit_resolve_span( $content, $payload );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	$span = $resolved['span'];

	if ( 'block_replace' === $type ) {
		$replaced    = substr( $content, $span['start'], $span['end'] - $span['start'] );
		$new_content = substr_replace( $content, $blocks, $span['start'], $span['end'] - $span['start'] );
		return array( 'new_content' => $new_content, 'replaced_block' => $replaced );
	}

	if ( 'block_delete' === $type ) {
		if ( count( $resolved['spans'] ) <= 1 ) {
			return new WP_Error( 'snt_sn_apply_delete_would_empty', __( 'Refusing to delete the post\'s only block — an empty post is never the intent of a block edit. Deleting the whole draft is delete_draft; a published post is retired deliberately, never emptied.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		list( $r_start, $r_end ) = snt_sn_apply_block_edit_removal_range( $content, $resolved['spans'], (int) $resolved['ordinal'] );
		return array(
			'new_content'   => substr_replace( $content, '', $r_start, $r_end - $r_start ),
			'replaced_block' => null,
			'removed_block' => substr( $content, $span['start'], $span['end'] - $span['start'] ),
		);
	}

	$position    = (string) ( $payload['position'] ?? 'after' );
	$new_content = 'before' === $position
		? substr_replace( $content, $blocks . "\n\n", $span['start'], 0 )
		: substr_replace( $content, "\n\n" . $blocks, $span['end'], 0 );
	return array( 'new_content' => $new_content, 'replaced_block' => null );
}

/**
 * block_move (v13.5.0): relocate one top-level block in a SINGLE call.
 *
 * WHY A SINGLE OPERATION: without it, "move" is two individually-legal
 * block_replace calls — and the owner ran exactly that sequence, where
 * step 1 (paragraph → sidenote) passed all four gates and step 2 (the old
 * sidenote → the paragraph) was structurally impossible, leaving the
 * paragraph deleted with no scripted way back. The gates only ever see one
 * call; the fix is a verb whose one call IS the whole move.
 *
 * Source is payload.block_path (required); destination is position
 * before|after one block (exactly one of payload.anchor /
 * payload.to_block_path) or "end". The removal consumes one adjacent
 * whitespace separator (snt_sn_apply_block_edit_removal_range()); the
 * insertion re-adds canonical "\n\n" separators. A move whose result is
 * byte-identical to the input refuses as a no-op.
 *
 * @param string $content
 * @param array  $payload
 * @return array{new_content:string,replaced_block:null,moved_block:string}|WP_Error
 */
function snt_sn_apply_block_edit_compute_move( $content, array $payload ) {
	$content = (string) $content;
	$source  = snt_sn_apply_block_edit_span_for_path( $content, (string) ( $payload['block_path'] ?? '' ) );
	if ( is_wp_error( $source ) ) {
		return $source;
	}
	$span     = $source['span'];
	$position = (string) ( $payload['position'] ?? '' );

	if ( 'end' === $position ) {
		$insert_at = strlen( $content );
	} else {
		$dest_path = trim( (string) ( $payload['to_block_path'] ?? '' ) );
		if ( '' !== $dest_path ) {
			$dest = snt_sn_apply_block_edit_span_for_path( $content, $dest_path );
			if ( is_wp_error( $dest ) ) {
				return $dest;
			}
			$dest_span = $dest['span'];
		} else {
			$located = snt_sn_apply_block_edit_locate( $content, (string) ( $payload['anchor'] ?? '' ), (string) ( $payload['context_snippet'] ?? '' ) );
			if ( is_wp_error( $located ) ) {
				return $located;
			}
			$dest_span = $located['span'];
		}
		if ( $dest_span['start'] === $span['start'] ) {
			return new WP_Error( 'snt_sn_apply_move_source_is_destination', __( 'The destination locator resolves to the source block itself — a block cannot move relative to itself.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		$insert_at = 'before' === $position ? $dest_span['start'] : $dest_span['end'];
	}

	list( $r_start, $r_end ) = snt_sn_apply_block_edit_removal_range( $content, $source['spans'], (int) $source['ordinal'] );
	if ( $insert_at > $r_start && $insert_at < $r_end ) {
		// The insertion point sits INSIDE the removal range only when the
		// destination is the separator being consumed — a geometry no
		// resolvable destination produces. Refuse rather than guess.
		return new WP_Error( 'snt_sn_apply_block_path_unresolvable', __( 'The destination falls inside the byte range the move removes — the content is malformed; nothing was moved.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	$moved = substr( $content, $span['start'], $span['end'] - $span['start'] );
	$base  = substr_replace( $content, '', $r_start, $r_end - $r_start );
	$point = $insert_at >= $r_end ? $insert_at - ( $r_end - $r_start ) : $insert_at;

	if ( 'end' === $position ) {
		// rtrim mirrors block_insert's 'end' branch (review round): trailing
		// whitespace on the base must not stack into a \n\n\n run.
		$new_content = '' === trim( $base ) ? $moved : rtrim( $base ) . "\n\n" . $moved;
	} elseif ( 'before' === $position ) {
		$new_content = substr_replace( $base, $moved . "\n\n", $point, 0 );
	} else {
		$new_content = substr_replace( $base, "\n\n" . $moved, $point, 0 );
	}

	if ( $new_content === $content ) {
		return new WP_Error( 'snt_sn_apply_move_noop', __( 'The block already sits exactly where this move would put it — a no-op move refuses rather than minting an empty edit.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	return array( 'new_content' => $new_content, 'replaced_block' => null, 'moved_block' => $moved );
}

/**
 * The prose delta — the ledger consequence of a block edit, computed ONCE
 * here and surfaced by both the dry-run diff and the real write's diff.
 * Normalization is snt_sn_apply_link_prose_normalize(): the provenance
 * normalizer itself when active (the exact function whose output feeds the
 * signature), else the strip-tags fallback. added/removed come from a
 * longest-common-prefix/suffix trim, which is EXACT for a single-splice
 * edit (everything outside the splice is untouched by construction).
 *
 * @param string $old_content Raw content before the edit.
 * @param string $new_content Raw content after the edit.
 * @return array{prose_changed:bool,prose_added:string,prose_removed:string,ledger_impact:string}
 */
function snt_sn_apply_block_edit_prose_delta( $old_content, $new_content ) {
	$old = snt_sn_apply_link_prose_normalize( (string) $old_content );
	$new = snt_sn_apply_link_prose_normalize( (string) $new_content );

	if ( $old === $new ) {
		return array( 'prose_changed' => false, 'prose_added' => '', 'prose_removed' => '', 'ledger_impact' => 'coalesces' );
	}

	$old_len = strlen( $old );
	$new_len = strlen( $new );
	$min_len = min( $old_len, $new_len );

	$prefix = 0;
	while ( $prefix < $min_len && $old[ $prefix ] === $new[ $prefix ] ) {
		$prefix++;
	}
	// mb-safe boundary snap (adversarial review, MEDIUM): the byte-wise trim
	// can land mid-character ("café"→"cafè" shares the 0xC3 lead byte), and a
	// lone continuation byte in prose_added/prose_removed degrades to "?"
	// under wp_json_encode()'s sanity pass — a corrupted preview of exactly
	// the delta this helper exists to show. Walk the boundary back off any
	// UTF-8 continuation byte (0b10xxxxxx) in either string.
	while ( $prefix > 0 && (
		( $prefix < $old_len && 0x80 === ( ord( $old[ $prefix ] ) & 0xC0 ) )
		|| ( $prefix < $new_len && 0x80 === ( ord( $new[ $prefix ] ) & 0xC0 ) )
	) ) {
		$prefix--;
	}
	$suffix = 0;
	while ( $suffix < $min_len - $prefix && $old[ $old_len - 1 - $suffix ] === $new[ $new_len - 1 - $suffix ] ) {
		$suffix++;
	}
	// Same snap for the suffix: its region must not START on a continuation
	// byte in either string.
	while ( $suffix > 0 && (
		0x80 === ( ord( $old[ $old_len - $suffix ] ) & 0xC0 )
		|| 0x80 === ( ord( $new[ $new_len - $suffix ] ) & 0xC0 )
	) ) {
		$suffix--;
	}

	return array(
		'prose_changed' => true,
		'prose_added'   => substr( $new, $prefix, $new_len - $prefix - $suffix ),
		'prose_removed' => substr( $old, $prefix, $old_len - $prefix - $suffix ),
		'ledger_impact' => 'new_version',
	);
}

/**
 * Apply one block edit, gated on the LIVE content_hash — sentence_replace's
 * splice/write contract, plus this type's scheduled-post guard: post_status
 * and post_date are captured before the write, passed EXPLICITLY through
 * it, and re-asserted after. A violation attempts a restore and fails
 * LOUDLY — a silently early-published scheduled post is the worst outcome
 * this type can produce, and it must never be a quiet one.
 *
 * @param int           $post_id
 * @param string        $type           'block_insert'|'block_replace'.
 * @param array         $payload
 * @param string        $fingerprint    snt_corpus_content_hash() of the live content.
 * @param callable|null $write_callback ($post_id, $new_content) instead of wp_update_post().
 * @return array{ok:bool,post_id:int,replaced_block:?string,old_content:string,new_content:string,prose_delta:array}|WP_Error
 */
function snt_sn_apply_block_edit_impl( $post_id, $type, array $payload, $fingerprint, $write_callback = null ) {
	$post_id = (int) $post_id;

	$payload_ok = snt_sn_apply_block_edit_payload_error( $type, $payload );
	if ( is_wp_error( $payload_ok ) ) {
		return $payload_ok;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'snt_sn_apply_capability', __( 'You cannot edit this post.', 'signal-and-noise-tools' ), array( 'status' => 403 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_sn_apply_target_not_found', __( 'Post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}

	$current_content = (string) $post->post_content;
	$observed        = snt_corpus_content_hash( $current_content );
	if ( ! hash_equals( $observed, (string) $fingerprint ) ) {
		return new WP_Error( 'snt_sn_apply_fingerprint_stale', __( 'Live post content has changed since this fingerprint was observed. Re-fetch content_hash via sn_posts and retry.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}

	$computed = snt_sn_apply_block_edit_compute( $current_content, $type, $payload );
	if ( is_wp_error( $computed ) ) {
		return $computed;
	}
	$new_content = $computed['new_content'];

	// v13.94.0: the scheduled-post guarantee now lives in ONE place
	// (snt_sn_apply_write_preserving_schedule, inc/sn-apply-plan-changes.php)
	// so the batch path cannot drift from this one. Behaviour is unchanged —
	// the overdue refusal, the explicit status/date write, and the
	// re-read-and-verify assertion moved verbatim, error strings included.
	$written = snt_sn_apply_write_preserving_schedule( $post, $new_content, $write_callback );
	if ( is_wp_error( $written ) ) {
		return $written;
	}

	return array(
		'ok'             => true,
		'post_id'        => $post_id,
		'replaced_block' => $computed['replaced_block'],
		'removed_block'  => $computed['removed_block'] ?? null,
		'moved_block'    => $computed['moved_block'] ?? null,
		'old_content'    => $current_content,
		'new_content'    => $new_content,
		'prose_delta'    => snt_sn_apply_block_edit_prose_delta( $current_content, $new_content ),
	);
}
