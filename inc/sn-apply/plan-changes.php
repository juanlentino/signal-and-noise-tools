<?php
/**
 * Signal & Noise Tools — sn_apply: HETEROGENEOUS changes to ONE post, ONE write.
 *
 * WHY. `payload.edits` (v10.66.0) already batches N edits into one write and
 * one ledger version — but only within ONE change type, and only across the
 * plain-prose splice family. block_insert was refused by name, on a reason that
 * was correct at the time: "block edits interact through tag structure in ways
 * the prose batch byte-range overlap check cannot see."
 *
 * That left the common editorial shape unbatchable. On 2026-09-03 a single
 * amendment to "Detection scales the wrong way" — one sentence_replace fixing a
 * stale figure, one block_insert adding references, one block_insert adding a
 * correction notice — minted THREE anchored ledger versions. Editorially it was
 * one act. Two of the three intermediate states were never intended to be
 * published, and they are permanent.
 *
 * THE UNIFYING SHAPE. Every supported change already reduces to a byte claim
 * against the ORIGINAL content:
 *
 *   sentence_replace  start = phrase offset      length = strlen(phrase)
 *   block_insert      start = boundary offset    length = 0
 *   block_replace     start = span start         length = span width
 *   block_delete      start = removal start      length = removal width
 *
 * So the v10.66.0 algorithm carries over unchanged: locate EVERY claim against
 * the original string, refuse on conflict, then splice in DESCENDING start
 * order so no offset needs re-resolving.
 *
 * WHAT THE BYTE CHECK ALREADY HANDLES, and what it does not. Nesting is
 * covered for free: a prose span inside a block another change replaces starts
 * within that block range, so the ordered sweep catches it. The gap is
 * ZERO-LENGTH claims. An insert occupies no bytes, so:
 *
 *   - two inserts at the SAME point never overlap, and their relative order is
 *     undefined — the caller would get one of two different posts;
 *   - an insert at the exact start or end of a span being replaced or deleted
 *     passes the range test while its anchor block is being destroyed, so
 *     where the inserted markup lands is undefined;
 *   - an insert strictly inside another claim is a byte-range overlap only
 *     when that claim has width, which a second insert does not.
 *
 * Those three are refused explicitly below. That is the whole of the extra
 * engineering the exclusion comment was pointing at, and it is why this is a
 * separate planner rather than a wider $types list on the prose one.
 *
 * ALL-OR-NOTHING, unchanged: any change that fails to validate, locate or
 * reconcile refuses the WHOLE batch naming the 1-based change index. A
 * partially-applied editorial act is exactly the half-converted state this
 * exists to prevent.
 *
 * PURE. Content in, content out: no DB reads, no writes, no capability checks.
 * Gate 1 calls it read-only to build the combined diff; the executor calls it
 * to produce the string it writes. One implementation, two callers.
 *
 * @package SignalNoiseTools
 * @since 13.94.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maximum changes in one heterogeneous batch.
 *
 * Matches the prose batch ceiling: the limit exists to bound the planning
 * cost and the size of a refusal message, and there is no reason for the two
 * doors to disagree on it.
 */
if ( ! defined( 'SNT_SN_APPLY_CHANGES_MAX' ) ) {
	define( 'SNT_SN_APPLY_CHANGES_MAX', 50 );
}

/**
 * Change types accepted inside a heterogeneous `changes` batch.
 *
 * sentence_replace is the prose member. The block family joins it because
 * each resolves to exactly one top-level byte span.
 *
 * DELIBERATELY ABSENT: link_insert, link_reshape and unlink (they rewrite tag
 * structure INSIDE a text node, so two of them can interact in ways a span
 * claim does not model), block_move (it is a delete plus an insert, i.e. two
 * claims from one change — representable, but its destination resolves
 * against post-delete content, which breaks the plan-against-original rule),
 * and every candidate-driven type whose fingerprint its own scan mints.
 *
 * @return string[]
 */
function snt_sn_apply_change_types() {
	return array( 'sentence_replace', 'block_insert', 'block_replace', 'block_delete' );
}

/**
 * Is this change type a zero-width claim (an insertion point, not a span)?
 *
 * @param string $type
 * @return bool
 */
function snt_sn_apply_change_is_insertion( $type ) {
	return 'block_insert' === $type;
}

/**
 * Build the byte claim for ONE change against the ORIGINAL content.
 *
 * @param string $content Original post_content.
 * @param int    $n       1-based change index, for refusals.
 * @param array  $change  {type, payload}.
 * @return array{index:int,type:string,start:int,length:int,replacement:string}|WP_Error
 */
function snt_sn_apply_plan_one_change( $content, $n, array $change ) {
	$type    = (string) ( $change['type'] ?? '' );
	$payload = (array) ( $change['payload'] ?? array() );

	if ( ! in_array( $type, snt_sn_apply_change_types(), true ) ) {
		return new WP_Error(
			'snt_sn_apply_changes_unsupported_type',
			sprintf(
				/* translators: 1: the requested change type, 2: comma-separated supported types */
				__( 'change %1$d: type "%2$s" cannot appear in a changes batch.', 'signal-and-noise-tools' ),
				$n,
				$type
			),
			array( 'status' => 422, 'supported' => snt_sn_apply_change_types() )
		);
	}

	if ( 'sentence_replace' === $type ) {
		return snt_sn_apply_plan_prose_claim( $content, $n, $payload );
	}
	return snt_sn_apply_plan_block_claim( $content, $n, $type, $payload );
}

/**
 * The prose claim: locate a byte-exact phrase, refuse if ambiguous.
 *
 * Reuses the single path validator (snt_sn_apply_sentence_pair_error) rather
 * than restating its sentence-scale floor and plain-prose rule — a parallel
 * copy is how the two drift apart.
 *
 * @param string $content
 * @param int    $n
 * @param array  $payload
 * @return array|WP_Error
 */
function snt_sn_apply_plan_prose_claim( $content, $n, array $payload ) {
	$phrase = (string) ( $payload['phrase'] ?? '' );
	$pair   = snt_sn_apply_sentence_pair_error( $phrase, (string) ( $payload['replacement'] ?? '' ) );
	if ( is_wp_error( $pair ) ) {
		return snt_sn_apply_changes_error( $n, $pair );
	}

	$first = strpos( $content, $phrase );
	if ( false === $first ) {
		return new WP_Error(
			'snt_sn_apply_changes_phrase_not_found',
			sprintf(
				/* translators: %d: 1-based change index */
				__( 'change %d: phrase not present in post content. The match is byte-exact — re-read the post.', 'signal-and-noise-tools' ),
				$n
			),
			array( 'status' => 409 )
		);
	}

	$context = (string) ( $payload['context_snippet'] ?? '' );
	if ( false !== strpos( $content, $phrase, $first + strlen( $phrase ) ) ) {
		// Ambiguous. The single path disambiguates by context; do the same, and
		// refuse only when that still cannot separate the occurrences. Guessing
		// here resurfaces later as a fingerprint 409 reading "the post changed"
		// when nothing changed.
		$located = function_exists( 'snt_ai_drift_locate_in_raw' )
			? snt_ai_drift_locate_in_raw( $content, $phrase, $context )
			: -1;
		if ( -1 === $located ) {
			return new WP_Error(
				'snt_sn_apply_changes_ambiguous_phrase',
				sprintf(
					/* translators: %d: 1-based change index */
					__( 'change %d: this phrase occurs more than once and context_snippet did not separate them. Widen context_snippet.', 'signal-and-noise-tools' ),
					$n
				),
				array( 'status' => 422 )
			);
		}
		$first = $located;
	}

	return array(
		'index'       => $n,
		'type'        => 'sentence_replace',
		'start'       => (int) $first,
		'length'      => strlen( $phrase ),
		'replacement' => trim( (string) ( $payload['replacement'] ?? '' ) ),
	);
}

/**
 * The block claim: resolve one top-level span, then convert the verb into a
 * byte range and its replacement text.
 *
 * @param string $content
 * @param int    $n
 * @param string $type
 * @param array  $payload
 * @return array|WP_Error
 */
function snt_sn_apply_plan_block_claim( $content, $n, $type, array $payload ) {
	$shape = snt_sn_apply_block_edit_payload_error( $type, $payload );
	if ( is_wp_error( $shape ) ) {
		return snt_sn_apply_changes_error( $n, $shape );
	}

	$position = (string) ( $payload['position'] ?? ( 'block_insert' === $type ? 'after' : '' ) );
	$blocks   = (string) ( $payload['blocks'] ?? '' );

	// position "end" needs no locator: the claim is the tail of the content.
	if ( 'block_insert' === $type && 'end' === $position ) {
		return array(
			'index'       => $n,
			'type'        => $type,
			'start'       => strlen( $content ),
			'length'      => 0,
			'replacement' => "\n\n" . $blocks,
		);
	}

	$resolved = snt_sn_apply_block_edit_resolve_span( $content, $payload );
	if ( is_wp_error( $resolved ) ) {
		return snt_sn_apply_changes_error( $n, $resolved );
	}
	$span = $resolved['span'];

	if ( 'block_insert' === $type ) {
		$at = 'before' === $position ? (int) $span['start'] : (int) $span['end'];
		return array(
			'index'       => $n,
			'type'        => $type,
			'start'       => $at,
			'length'      => 0,
			'replacement' => 'before' === $position ? $blocks . "\n\n" : "\n\n" . $blocks,
		);
	}

	if ( 'block_replace' === $type ) {
		return array(
			'index'       => $n,
			'type'        => $type,
			'start'       => (int) $span['start'],
			'length'      => (int) $span['end'] - (int) $span['start'],
			'replacement' => $blocks,
		);
	}

	// block_delete: the span PLUS one adjacent whitespace separator, so the
	// remaining content keeps its canonical rhythm. Same helper the single
	// path uses — never a second opinion about what a deletion consumes.
	$range = snt_sn_apply_block_edit_removal_range( $content, $resolved['spans'], (int) $resolved['ordinal'] );
	return array(
		'index'       => $n,
		'type'        => $type,
		'start'       => (int) $range[0],
		'length'      => (int) $range[1] - (int) $range[0],
		'replacement' => '',
	);
}

/**
 * Reconcile every claim: byte overlap PLUS the three zero-width cases a range
 * test structurally cannot see.
 *
 * @param array $claims
 * @return true|WP_Error
 */
function snt_sn_apply_changes_conflict_error( array $claims ) {
	$sorted = $claims;
	usort(
		$sorted,
		static function ( $a, $b ) {
			// Ties broken by index so a refusal names the pair deterministically.
			return $a['start'] === $b['start'] ? $a['index'] <=> $b['index'] : $a['start'] <=> $b['start'];
		}
	);

	$count = count( $sorted );
	for ( $i = 1; $i < $count; $i++ ) {
		$prev = $sorted[ $i - 1 ];
		$cur  = $sorted[ $i ];

		$prev_ins = snt_sn_apply_change_is_insertion( $prev['type'] );
		$cur_ins  = snt_sn_apply_change_is_insertion( $cur['type'] );

		// (a) Two insertions at the same point: order is undefined, so the
		// caller would get one of two different posts at random. Refuse rather
		// than pick — a silent choice here is unreviewable.
		if ( $prev['start'] === $cur['start'] && $prev_ins && $cur_ins ) {
			return snt_sn_apply_changes_conflict( $prev['index'], $cur['index'], __( 'both insert at the same point, so their order would be undefined. Combine them into one block_insert.', 'signal-and-noise-tools' ) );
		}

		// (b) An insertion anchored to a span another change replaces or
		// removes. The byte ranges do not overlap (an insert has no width), but
		// the anchor is being destroyed, so where the markup lands is undefined.
		if ( $prev['start'] === $cur['start'] && ( $prev_ins xor $cur_ins ) ) {
			$ins  = $prev_ins ? $prev : $cur;
			$span = $prev_ins ? $cur : $prev;
			return snt_sn_apply_changes_conflict( $ins['index'], $span['index'], __( 'the insertion is anchored at the boundary of a block the other change replaces or deletes, so its landing point would be undefined.', 'signal-and-noise-tools' ) );
		}

		// (c) Ordinary byte-range overlap. This also covers NESTING for free: a
		// prose span inside a replaced block starts within that block range.
		if ( $cur['start'] < $prev['start'] + $prev['length'] ) {
			return snt_sn_apply_changes_conflict( $prev['index'], $cur['index'], __( 'they target overlapping spans of the post. Each change must claim its own bytes.', 'signal-and-noise-tools' ) );
		}

		// (d) A zero-width insert landing at the far edge of a span claim. The
		// range test above uses a strict <, so an insert exactly at
		// prev.start + prev.length passes it while still being anchored to
		// content the other change is rewriting.
		if ( $cur_ins && ! $prev_ins && $cur['start'] === $prev['start'] + $prev['length'] && $prev['length'] > 0 ) {
			return snt_sn_apply_changes_conflict( $cur['index'], $prev['index'], __( 'the insertion sits at the trailing edge of a span the other change rewrites, so its landing point would be undefined.', 'signal-and-noise-tools' ) );
		}
	}
	return true;
}

/**
 * A conflict refusal naming both changes and the reason.
 *
 * @param int    $a   1-based index.
 * @param int    $b   1-based index.
 * @param string $why Human sentence.
 * @return WP_Error
 */
function snt_sn_apply_changes_conflict( $a, $b, $why ) {
	return new WP_Error(
		'snt_sn_apply_changes_conflict',
		sprintf(
			/* translators: 1 and 2 are 1-based change indexes, 3 is the reason. */
			__( 'changes %1$d and %2$d conflict: %3$s', 'signal-and-noise-tools' ),
			$a,
			$b,
			$why
		),
		array( 'status' => 422 )
	);
}

/**
 * Re-wrap a single-path WP_Error so it names the change index.
 *
 * The underlying message is kept verbatim — the caller needs the specific
 * reason, and restating it in our own words is how two error vocabularies
 * drift apart.
 *
 * @param int      $n
 * @param WP_Error $e
 * @return WP_Error
 */
function snt_sn_apply_changes_error( $n, $e ) {
	return new WP_Error(
		$e->get_error_code(),
		sprintf(
			/* translators: 1: 1-based change index, 2: the underlying refusal. */
			__( 'change %1$d: %2$s', 'signal-and-noise-tools' ),
			$n,
			$e->get_error_message()
		),
		$e->get_error_data()
	);
}

/**
 * Plan a heterogeneous batch against one content string.
 *
 * @param string $content Original post_content. EVERY claim is located
 *                        against THIS string, never a partially-edited one.
 * @param array  $changes List of {type, payload}.
 * @return array{new_content:string,count:int,changes:array}|WP_Error
 */
function snt_sn_apply_plan_changes( $content, $changes ) {
	$content = (string) $content;

	if ( ! is_array( $changes ) || ! $changes ) {
		return new WP_Error( 'snt_sn_apply_changes_empty', __( 'change.changes must be a non-empty list.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( count( $changes ) > SNT_SN_APPLY_CHANGES_MAX ) {
		return new WP_Error(
			'snt_sn_apply_changes_too_many',
			sprintf(
				/* translators: %d: the maximum number of changes in one call */
				__( 'change.changes carries more than the %d-change maximum for a single call.', 'signal-and-noise-tools' ),
				SNT_SN_APPLY_CHANGES_MAX
			),
			array( 'status' => 422 )
		);
	}

	$claims = array();
	foreach ( array_values( $changes ) as $i => $change ) {
		$claim = snt_sn_apply_plan_one_change( $content, $i + 1, (array) $change );
		if ( is_wp_error( $claim ) ) {
			return $claim;
		}
		$claims[] = $claim;
	}

	$conflict = snt_sn_apply_changes_conflict_error( $claims );
	if ( is_wp_error( $conflict ) ) {
		return $conflict;
	}

	// Descending by start: each splice only ever touches bytes AFTER the
	// remaining claims, so no offset is re-resolved. Ascending would invalidate
	// every later start by the running length delta.
	$ordered = $claims;
	usort(
		$ordered,
		static function ( $a, $b ) {
			return $b['start'] <=> $a['start'];
		}
	);

	$new_content = $content;
	foreach ( $ordered as $c ) {
		$new_content = substr_replace( $new_content, $c['replacement'], $c['start'], $c['length'] );
	}

	return array(
		'new_content' => $new_content,
		'count'       => count( $claims ),
		'changes'     => $claims, // caller order, not splice order
	);
}

/**
 * Write new content to a post while GUARANTEEING its schedule survives.
 *
 * Extracted verbatim from snt_sn_apply_block_edit_impl() so the batch path
 * cannot drift from the single path on the one guarantee where drift is a
 * disaster rather than an inconsistency: a scheduled note publishing early as
 * a side effect of an edit. Both callers now share ONE implementation. The
 * refusal and violation strings are kept BYTE-IDENTICAL, because the existing
 * block-edit suite asserts against them and a reworded safety message is a
 * silent test change dressed up as a copy-edit.
 *
 * Three parts, each earned by a real failure:
 *   1. An OVERDUE 'future' post refuses UP FRONT — core coerces an explicit
 *      'future' to 'publish' within a minute of post_date_gmt, and a restore
 *      would be coerced identically, so the only honest move is to not write.
 *   2. The write passes status and dates EXPLICITLY.
 *   3. The result is ASSERTED by re-reading the row, never inferred from the
 *      return code: core's silent future->publish coercion returns a plain
 *      post ID, so is_wp_error() alone reports success on exactly the disaster
 *      this exists for.
 *
 * post_date is bound strictly for status 'future' ONLY. Core floats a draft
 * post_date on save, and binding it for every status made routine draft edits
 * 500 after the content had already landed.
 *
 * @param WP_Post|object $post           The post BEFORE the write.
 * @param string         $new_content    Content to write.
 * @param callable|null  $write_callback Revision-mode seam; when callable the
 *                                       live row is never touched.
 * @return true|WP_Error
 */
function snt_sn_apply_write_preserving_schedule( $post, $new_content, $write_callback = null ) {
	$post_id         = (int) $post->ID;
	$before_status   = (string) $post->post_status;
	$before_date     = (string) $post->post_date;
	$before_date_gmt = (string) $post->post_date_gmt;

	$minute = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
	if ( ! is_callable( $write_callback ) && 'future' === $before_status
		&& ( strtotime( $before_date_gmt ) - strtotime( gmdate( 'Y-m-d H:i:s' ) ) < $minute ) ) {
		return new WP_Error(
			'snt_sn_apply_schedule_overdue',
			sprintf(
				/* translators: %s: the post's scheduled datetime (GMT) */
				__( 'This scheduled post is overdue (post_date_gmt %s has passed or is under a minute away, and WP-Cron has not published it yet). Writing now would trip WordPress core\'s own status resolution and publish it early as a side effect — refused. Wait for cron to publish it (or publish it deliberately), then retry with a fresh fingerprint.', 'signal-and-noise-tools' ),
				$before_date_gmt
			),
			array( 'status' => 409 )
		);
	}

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

	$strict_schedule = ( 'future' === $before_status );
	$after           = get_post( $post_id );
	if ( $after && ( (string) $after->post_status !== $before_status || ( $strict_schedule && (string) $after->post_date !== $before_date ) ) ) {
		$restore = wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => $before_status,
				'post_date'     => $before_date,
				'post_date_gmt' => $before_date_gmt,
			),
			true
		);
		$restored_row = get_post( $post_id );
		$restore_held = $restored_row
			&& (string) $restored_row->post_status === $before_status
			&& ( ! $strict_schedule || (string) $restored_row->post_date === $before_date );
		if ( is_wp_error( $restore ) ) {
			$restore_outcome = 'FAILED — ' . $restore->get_error_message();
		} elseif ( ! $restore_held ) {
			$restore_outcome = sprintf(
				'FAILED — the post remains %s @ %s; restore it manually in wp-admin NOW',
				$restored_row ? (string) $restored_row->post_status : 'unknown',
				$restored_row ? (string) $restored_row->post_date : 'unknown'
			);
		} else {
			$restore_outcome = 'verified restored';
		}
		return new WP_Error(
			'snt_sn_apply_schedule_violation',
			sprintf(
				/* translators: 1: expected status, 2: observed status, 3: expected date, 4: observed date, 5: restore outcome */
				__( 'The write changed post_status/post_date (expected %1$s @ %3$s, observed %2$s @ %4$s) — a scheduled post must never publish early as a side effect of a block edit. Restore attempt: %5$s. Verify the post\'s schedule in wp-admin before retrying.', 'signal-and-noise-tools' ),
				$before_status,
				(string) $after->post_status,
				$before_date,
				(string) $after->post_date,
				$restore_outcome
			),
			array( 'status' => 500 )
		);
	}

	return true;
}

/**
 * Apply a heterogeneous batch to ONE post in ONE write.
 *
 * ONE fingerprint check at the start, one plan, one write, one ledger
 * version. The fingerprint is the whole-post content_hash — the binding
 * sentence_replace and the block family already share — and it covers the
 * WHOLE batch by construction: every claim was located against the exact
 * content that hash names.
 *
 * @param int           $post_id
 * @param array         $changes        List of {type, payload}.
 * @param string        $fingerprint    Whole-post content_hash. REQUIRED.
 * @param callable|null $write_callback Revision-mode seam.
 * @return array{ok:bool,post_id:int,count:int,old_content:string,new_content:string,changes:array,prose_delta:array}|WP_Error
 */
function snt_sn_apply_batch_changes_impl( $post_id, $changes, $fingerprint = '', $write_callback = null ) {
	$post_id = (int) $post_id;

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'snt_sn_apply_capability', __( 'You cannot edit this post.', 'signal-and-noise-tools' ), array( 'status' => 403 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_sn_apply_target_not_found', __( 'Post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}

	$current_content = (string) $post->post_content;

	// Defense in depth: gate 1 already checked this, but the write path must
	// hold on its own rather than because a caller ran the gate.
	$observed = snt_corpus_content_hash( $current_content );
	if ( ! hash_equals( $observed, (string) $fingerprint ) ) {
		return new WP_Error( 'snt_sn_apply_fingerprint_stale', __( 'Live post content has changed since this fingerprint was observed. Re-fetch content_hash via sn_posts and retry.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}

	$plan = snt_sn_apply_plan_changes( $current_content, $changes );
	if ( is_wp_error( $plan ) ) {
		return $plan; // all-or-nothing: nothing has been written
	}

	$written = snt_sn_apply_write_preserving_schedule( $post, $plan['new_content'], $write_callback );
	if ( is_wp_error( $written ) ) {
		return $written;
	}

	return array(
		'ok'          => true,
		'post_id'     => $post_id,
		'count'       => $plan['count'],
		'old_content' => $current_content,
		'new_content' => $plan['new_content'],
		'changes'     => $plan['changes'],
		'prose_delta' => function_exists( 'snt_sn_apply_block_edit_prose_delta' )
			? snt_sn_apply_block_edit_prose_delta( $current_content, $plan['new_content'] )
			: array(),
	);
}
