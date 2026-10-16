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
	$active = snt_sn_apply_active_content_error( $blocks, 'payload.blocks' );
	if ( is_wp_error( $active ) ) {
		return $active;
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

// The rest of the module lives in two parts files (house line cap, 2026-10-14),
// loaded in the order it always ran, after the span scan and payload checks above.
require_once __DIR__ . '/block-edit-resolve-parts.php';
require_once __DIR__ . '/block-edit-compute-parts.php';
