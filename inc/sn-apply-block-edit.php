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
 *      violation attempts a restore and fails LOUDLY (500), because a
 *      silent early publish is the worst possible outcome of this type.
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
		return new WP_Error( 'snt_sn_apply_edits_not_supported', __( 'payload.edits is not available for block_insert/block_replace: block edits interact through tag structure in ways the prose batch\'s byte-range overlap check cannot see. One call per block edit.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	$position = 'block_insert' === $type ? (string) ( $payload['position'] ?? 'after' ) : null;
	if ( 'block_insert' === $type && ! in_array( $position, array( 'before', 'after', 'end' ), true ) ) {
		return new WP_Error( 'snt_sn_apply_invalid_position', __( 'payload.position must be "before", "after" or "end" (default "after").', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	$anchor = (string) ( $payload['anchor'] ?? '' );
	if ( 'end' === $position ) {
		if ( '' !== $anchor ) {
			return new WP_Error( 'snt_sn_apply_invalid_anchor', __( 'payload.anchor is unused with position "end" — omit it rather than passing one that does not anchor anything.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
	} elseif ( strlen( $anchor ) < SNT_SN_APPLY_BLOCK_EDIT_ANCHOR_MIN ) {
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
		$new_content = '' === $content ? $blocks : $content . "\n\n" . $blocks;
		return array( 'new_content' => $new_content, 'replaced_block' => null );
	}

	$located = snt_sn_apply_block_edit_locate( $content, (string) ( $payload['anchor'] ?? '' ), (string) ( $payload['context_snippet'] ?? '' ) );
	if ( is_wp_error( $located ) ) {
		return $located;
	}
	$span = $located['span'];

	if ( 'block_replace' === $type ) {
		$replaced    = substr( $content, $span['start'], $span['end'] - $span['start'] );
		$new_content = substr_replace( $content, $blocks, $span['start'], $span['end'] - $span['start'] );
		return array( 'new_content' => $new_content, 'replaced_block' => $replaced );
	}

	$position    = (string) ( $payload['position'] ?? 'after' );
	$new_content = 'before' === $position
		? substr_replace( $content, $blocks . "\n\n", $span['start'], 0 )
		: substr_replace( $content, "\n\n" . $blocks, $span['end'], 0 );
	return array( 'new_content' => $new_content, 'replaced_block' => null );
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
	$suffix = 0;
	while ( $suffix < $min_len - $prefix && $old[ $old_len - 1 - $suffix ] === $new[ $new_len - 1 - $suffix ] ) {
		$suffix++;
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

	$before_status   = (string) $post->post_status;
	$before_date     = (string) $post->post_date;
	$before_date_gmt = (string) $post->post_date_gmt;

	if ( is_callable( $write_callback ) ) {
		$result = call_user_func( $write_callback, $post_id, $new_content );
	} else {
		$result = wp_update_post(
			array(
				'ID'            => $post_id,
				'post_content'  => $new_content,
				'post_status'   => $before_status,
				'post_date'     => $before_date,
				'post_date_gmt' => $before_date_gmt,
			),
			true
		);
	}
	if ( is_wp_error( $result ) ) {
		/* translators: %s is the error message from the write step */
		return new WP_Error( 'snt_sn_apply_write_failed', sprintf( __( 'Write failed: %s', 'signal-and-noise-tools' ), $result->get_error_message() ), array( 'status' => 500 ) );
	}

	// The guard's second half: ASSERT status/date survived, never assume.
	// (Revision mode never touches the live row — asserting it anyway is
	// free and turns "never" from a belief into a checked fact.)
	$after = get_post( $post_id );
	if ( $after && ( (string) $after->post_status !== $before_status || (string) $after->post_date !== $before_date ) ) {
		$restore = wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => $before_status,
				'post_date'     => $before_date,
				'post_date_gmt' => $before_date_gmt,
			),
			true
		);
		return new WP_Error(
			'snt_sn_apply_schedule_violation',
			sprintf(
				/* translators: 1: expected status, 2: observed status, 3: expected date, 4: observed date, 5: restore outcome */
				__( 'The write changed post_status/post_date (expected %1$s @ %3$s, observed %2$s @ %4$s) — a scheduled post must never publish early as a side effect of a block edit. Restore attempt: %5$s. Verify the post\'s schedule in wp-admin before retrying.', 'signal-and-noise-tools' ),
				$before_status,
				(string) $after->post_status,
				$before_date,
				(string) $after->post_date,
				is_wp_error( $restore ) ? 'FAILED — ' . $restore->get_error_message() : 'succeeded'
			),
			array( 'status' => 500 )
		);
	}

	return array(
		'ok'             => true,
		'post_id'        => $post_id,
		'replaced_block' => $computed['replaced_block'],
		'old_content'    => $current_content,
		'new_content'    => $new_content,
		'prose_delta'    => snt_sn_apply_block_edit_prose_delta( $current_content, $new_content ),
	);
}
