<?php
/**
 * Signal & Noise Tools: sn_apply block_edit, the compute and the write (the replace and move computes, the prose delta, the impl).
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
	// (snt_sn_apply_write_preserving_schedule, inc/sn-apply/plan-changes.php)
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
