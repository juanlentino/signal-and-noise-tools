<?php
/**
 * Signal & Noise Tools — sn_apply: MULTIPLE body edits to ONE post in ONE write.
 *
 * WHY. sn_apply's `target` array batches across POSTS ("per-post writes are
 * atomic, across posts they are independent"). It has never been able to batch
 * WITHIN a post, so N candidates in one post meant N calls, N wp_update_post()
 * calls, and — for a Note — N anchored ledger versions.
 *
 * That is not hypothetical. The public ledger records "Two kinds of provenance"
 * at v1 -> v2 -> v3, where both increments are halves of ONE edit (converting a
 * single dashed aside to parentheses). v2 is a permanently anchored state in
 * which the sentence had an opening parenthesis and a closing em-dash — a state
 * nobody intended to publish. The em-dash scanner's pairing rule reasons about
 * the pair as one edit but emits it as two candidates; the apply path splices
 * one per call. This module closes that gap.
 *
 * THE ALGORITHM, and why the obvious one is wrong.
 *
 * Looping the existing impls and writing once at the end fails twice:
 *
 *   1. Each impl re-reads get_post()->post_content, so edit 2 would splice the
 *      ORIGINAL string and silently clobber edit 1.
 *   2. drift/em-dash fingerprints are md5(phrase|window) over an 80-char window
 *      (SNT_AI_DRIFT_FINGERPRINT_WINDOW). An edit inside a neighbour's window
 *      changes the bytes that neighbour's fingerprint was minted over, so a
 *      sequential batch can 409 against its own first write.
 *
 * So: validate and locate EVERY edit against the ORIGINAL content, then splice
 * in DESCENDING position order — which is what keeps every offset valid without
 * re-locating — then write once. All-or-nothing: any edit that fails refuses the
 * whole batch, because a partially-applied "one logical edit" is exactly the
 * half-converted state this exists to prevent.
 *
 * PURE BY DESIGN. This planner takes content and returns content: no DB reads,
 * no writes, no capability checks. Gate 1 calls it read-only to build the diff
 * and feed gate 2's body validation; the executor calls it to produce the string
 * it writes. One implementation, two callers — the alternative (a preview path
 * that models what the write path does) is the exact shape of the bug that put
 * `reach for:the studio` on a live page.
 *
 * @package SignalNoiseTools
 * @since 10.66.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upper bound on edits in a single batch. Not a correctness limit — the
 * planner is O(n log n) — but a blast-radius bound: one call rewriting 50
 * spans of a published post is already far past the point a human should be
 * reviewing the diff instead.
 */
const SNT_SN_APPLY_BATCH_EDITS_MAX = 50;

/**
 * Change types whose payload may carry `edits`. Deliberately only the
 * plain-prose splice family: every member locates a byte span and replaces it
 * with plain text, which is what makes descending-order splicing sound.
 *
 * link_insert, link_reshape and unlink are excluded on purpose — they rewrite
 * markup rather than prose, so two of them in one post can interact through the
 * tag structure in ways a byte-range overlap check cannot see.
 *
 * @return string[]
 */
function snt_sn_apply_batch_edit_types() {
	return array( 'emdash_replace', 'drift_replace', 'sentence_replace' );
}

/**
 * Does this change type carry a PER-EDIT fingerprint?
 *
 * The drift family mints md5(phrase|window) per candidate, so each edit brings
 * its own. sentence_replace binds to the whole-post content_hash on
 * change.fingerprint — one value for the entire batch, already checked by gate
 * 1 — so its edits carry none.
 *
 * @param string $type
 * @return bool
 */
function snt_sn_apply_batch_edit_has_per_edit_fingerprint( $type ) {
	return in_array( $type, array( 'emdash_replace', 'drift_replace' ), true );
}

/**
 * Plan a multi-edit splice against one content string.
 *
 * @param string $content Original post_content. Every edit is located and
 *                        fingerprinted against THIS string, never against a
 *                        partially-edited one.
 * @param string $type    A member of snt_sn_apply_batch_edit_types().
 * @param array  $edits   List of {phrase, replacement, fingerprint?, context_snippet?}.
 * @return array{new_content:string,count:int,edits:array}|WP_Error
 */
function snt_sn_apply_plan_batch_edits( $content, $type, $edits ) {
	$content = (string) $content;
	$type    = (string) $type;

	if ( ! in_array( $type, snt_sn_apply_batch_edit_types(), true ) ) {
		return new WP_Error(
			'snt_sn_apply_batch_unsupported_type',
			sprintf(
				/* translators: 1: the requested change type, 2: comma-separated list of supported types */
				__( 'change.payload.edits is not supported for change.type "%1$s". Batched edits apply to the plain-prose splice family only: %2$s.', 'signal-and-noise-tools' ),
				$type,
				implode( ', ', snt_sn_apply_batch_edit_types() )
			),
			array( 'status' => 422 )
		);
	}

	if ( ! is_array( $edits ) || ! $edits ) {
		return new WP_Error( 'snt_sn_apply_batch_empty', __( 'change.payload.edits must be a non-empty list.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( count( $edits ) > SNT_SN_APPLY_BATCH_EDITS_MAX ) {
		return new WP_Error(
			'snt_sn_apply_batch_too_many',
			sprintf(
				/* translators: %d is the maximum number of edits allowed in one batch */
				__( 'change.payload.edits carries more than the %d-edit maximum for a single call.', 'signal-and-noise-tools' ),
				SNT_SN_APPLY_BATCH_EDITS_MAX
			),
			array( 'status' => 422 )
		);
	}

	// emdash_replace's replacements carry MEANINGFUL edge whitespace (': ', '. ',
	// ', ', ' (', ') '); drift_replace's are whole-phrase swaps that should trim.
	// Inheriting the per-type posture rather than picking one for both is the
	// v10.65.2 bug class ("reach for:the studio").
	$preserve = ( 'emdash_replace' === $type );
	$per_edit_fp = snt_sn_apply_batch_edit_has_per_edit_fingerprint( $type );

	$planned = array();
	foreach ( array_values( $edits ) as $i => $edit ) {
		$n    = $i + 1; // 1-based: a refusal must name the edit a human can find.
		$edit = (array) $edit;

		$phrase  = (string) ( $edit['phrase'] ?? '' );
		$context = (string) ( $edit['context_snippet'] ?? '' );

		if ( 'sentence_replace' === $type ) {
			// Its own sentence-scale floor and plain-prose rule, from the single
			// path's validator — not a copy of them.
			$pair = snt_sn_apply_sentence_pair_error( $phrase, (string) ( $edit['replacement'] ?? '' ) );
			if ( is_wp_error( $pair ) ) {
				return snt_sn_apply_batch_edit_error( $n, $pair );
			}
			$replacement = trim( (string) ( $edit['replacement'] ?? '' ) );
		} else {
			$replacement       = snt_ai_drift_normalize_replacement( $edit['replacement'] ?? '', $preserve );
			$replacement_error = snt_ai_drift_replacement_error( $replacement, $preserve );
			if ( is_wp_error( $replacement_error ) ) {
				return snt_sn_apply_batch_edit_error( $n, $replacement_error );
			}
		}

		// Resolve the span. An explicit `position` WINS when the phrase really sits
		// at that offset, because in a batch the phrases are frequently IDENTICAL:
		// the em-dash scanner's spaced-dash phrase is the dash plus its surrounding
		// spaces and nothing more, so one parenthetical yields two byte-identical
		// phrases. snt_ai_drift_locate_in_raw() disambiguates by context similarity,
		// which cannot separate two occurrences whose contexts are equally plausible
		// — it resolved both halves to the same occurrence, and the second
		// fingerprint check then failed against content that was never wrong.
		//
		// The offset is still only trusted when it is CORROBORATED (the phrase is
		// byte-present there); a stale or bogus advisory offset falls back to the
		// locator, preserving the single path's defense against a post edited
		// between scan and apply.
		$position = -1;
		if ( isset( $edit['position'] ) && (int) $edit['position'] >= 0 ) {
			$claimed = (int) $edit['position'];
			if ( '' !== $phrase && substr( $content, $claimed, strlen( $phrase ) ) === $phrase ) {
				$position = $claimed;
			}
		}
		if ( -1 === $position ) {
			// Uncorroborated. If the phrase is AMBIGUOUS (more than one occurrence),
			// refuse and say so rather than letting the locator pick one: guessing
			// here surfaces later as a fingerprint 409 that reads "the post changed"
			// when nothing changed, sending the caller to diagnose the wrong thing.
			if ( '' !== $phrase && false !== strpos( $content, $phrase, ( strpos( $content, $phrase ) === false ? 0 : strpos( $content, $phrase ) + strlen( $phrase ) ) ) ) {
				return new WP_Error(
					'snt_sn_apply_batch_ambiguous_phrase',
					sprintf(
						/* translators: %d is the 1-based index of the ambiguous edit */
						__( 'edit %d: this phrase occurs more than once and no usable position was supplied. Pass the scan candidate\'s `position` so the intended occurrence is unambiguous.', 'signal-and-noise-tools' ),
						$n
					),
					array( 'status' => 422 )
				);
			}
			$position = snt_ai_drift_locate_in_raw( $content, $phrase, $context );
		}
		if ( -1 === $position ) {
			return new WP_Error(
				'snt_sn_apply_batch_phrase_not_found',
				sprintf(
					/* translators: %d is the 1-based index of the failing edit */
					__( 'edit %d: phrase not present in post content. The match is byte-exact — re-run the scan.', 'signal-and-noise-tools' ),
					$n
				),
				array( 'status' => 409 )
			);
		}

		if ( $per_edit_fp ) {
			$expected = (string) ( $edit['fingerprint'] ?? '' );
			$observed = snt_ai_drift_fingerprint( $content, $phrase, $position );
			if ( '' === $expected || ! hash_equals( $observed, $expected ) ) {
				return new WP_Error(
					'snt_sn_apply_batch_fingerprint_stale',
					sprintf(
						/* translators: %d is the 1-based index of the failing edit */
						__( 'edit %d: fingerprint does not match the live content. The post changed since the scan — re-run it and retry the whole batch.', 'signal-and-noise-tools' ),
						$n
					),
					array( 'status' => 409 )
				);
			}
		}

		$planned[] = array(
			'index'       => $n,
			'position'    => $position,
			'length'      => strlen( $phrase ),
			'phrase'      => $phrase,
			'replacement' => $replacement,
		);
	}

	$overlap = snt_sn_apply_batch_overlap_error( $planned );
	if ( is_wp_error( $overlap ) ) {
		return $overlap;
	}

	// Descending by position: each splice only ever touches bytes AFTER the
	// remaining edits' offsets, so no offset needs re-resolving. Ascending would
	// invalidate every later position by the running length delta.
	usort(
		$planned,
		static function ( $a, $b ) {
			return $b['position'] <=> $a['position'];
		}
	);

	$new_content = $content;
	foreach ( $planned as $p ) {
		$new_content = substr_replace( $new_content, $p['replacement'], $p['position'], $p['length'] );
	}

	// Report in the caller's own order, not the internal splice order.
	usort(
		$planned,
		static function ( $a, $b ) {
			return $a['index'] <=> $b['index'];
		}
	);

	return array(
		'new_content' => $new_content,
		'count'       => count( $planned ),
		'edits'       => $planned,
	);
}

/**
 * Apply a batch of edits to one post in ONE write.
 *
 * The write half of the planner above: capability, load, plan, write once.
 * Deliberately mirrors snt_ai_drift_apply_impl()'s contract — same
 * $write_callback shape (called as ($post_id, $new_content) instead of
 * wp_update_post()), same return keys — so mode:"revision" staging works
 * through the identical seam the single-edit path already uses.
 *
 * @param string        $post_id
 * @param string        $type
 * @param array         $edits
 * @param string        $fingerprint    Whole-post content_hash. Required for
 *                                      sentence_replace (its binding); ignored
 *                                      for the drift family, whose fingerprints
 *                                      are per-edit.
 * @param callable|null $write_callback
 * @return array{ok:bool,post_id:int,count:int,old_content:string,new_content:string,edits:array}|WP_Error
 */
function snt_sn_apply_batch_edits_impl( $post_id, $type, $edits, $fingerprint = '', $write_callback = null ) {
	$post_id = (int) $post_id;

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'snt_sn_apply_capability', __( 'You cannot edit this post.', 'signal-and-noise-tools' ), array( 'status' => 403 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_sn_apply_target_not_found', __( 'Post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}

	$current_content = (string) $post->post_content;

	// sentence_replace's binding is the whole-post hash. Gate 1 already checked
	// it; re-checking here is the same defense-in-depth the single impl carries —
	// the write path must hold on its own, not because a caller ran the gate.
	if ( 'sentence_replace' === $type ) {
		$observed = snt_corpus_content_hash( $current_content );
		if ( ! hash_equals( $observed, (string) $fingerprint ) ) {
			return new WP_Error( 'snt_sn_apply_fingerprint_stale', __( 'Live post content has changed since this fingerprint was observed. Re-fetch content_hash via sn_posts and retry.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
		}
	}

	$plan = snt_sn_apply_plan_batch_edits( $current_content, $type, $edits );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}

	if ( is_callable( $write_callback ) ) {
		$result = call_user_func( $write_callback, $post_id, $plan['new_content'] );
	} else {
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $plan['new_content'],
			),
			true
		);
	}
	if ( is_wp_error( $result ) ) {
		/* translators: %s is the error message from the write step */
		return new WP_Error( 'snt_sn_apply_write_failed', sprintf( __( 'Write failed: %s', 'signal-and-noise-tools' ), $result->get_error_message() ), array( 'status' => 500 ) );
	}

	return array(
		'ok'          => true,
		'post_id'     => $post_id,
		'count'       => $plan['count'],
		'old_content' => $current_content,
		'new_content' => $plan['new_content'],
		'edits'       => $plan['edits'],
	);
}

/**
 * Re-wrap a per-edit validation error so the message names WHICH edit failed.
 * A batch refusal that does not say which of 12 spans was wrong is a refusal
 * the caller cannot act on.
 *
 * @param int      $n     1-based edit index.
 * @param WP_Error $error
 * @return WP_Error
 */
function snt_sn_apply_batch_edit_error( $n, $error ) {
	return new WP_Error(
		$error->get_error_code(),
		sprintf(
			/* translators: 1: the 1-based index of the failing edit, 2: the underlying error message */
			__( 'edit %1$d: %2$s', 'signal-and-noise-tools' ),
			$n,
			$error->get_error_message()
		),
		is_array( $error->data ) ? $error->data : array( 'status' => 422 )
	);
}

/**
 * Refuse two edits that claim overlapping byte ranges.
 *
 * Descending-order splicing is only sound for DISJOINT ranges. Overlapping
 * spans would have one splice rewrite bytes another had already consumed,
 * producing content neither edit described — silent corruption rather than a
 * refusal. Two candidates over the same span means the scan is stale or the
 * caller sent a duplicate; both deserve an error.
 *
 * @param array $planned Each {index, position, length}.
 * @return true|WP_Error
 */
function snt_sn_apply_batch_overlap_error( array $planned ) {
	$sorted = $planned;
	usort(
		$sorted,
		static function ( $a, $b ) {
			return $a['position'] <=> $b['position'];
		}
	);

	$count = count( $sorted );
	for ( $i = 1; $i < $count; $i++ ) {
		$prev = $sorted[ $i - 1 ];
		$cur  = $sorted[ $i ];
		if ( $cur['position'] < $prev['position'] + $prev['length'] ) {
			return new WP_Error(
				'snt_sn_apply_batch_overlap',
				sprintf(
					/* translators: 1 and 2 are the 1-based indexes of the two overlapping edits */
					__( 'edits %1$d and %2$d target overlapping spans of the post. Each edit in a batch must claim its own bytes.', 'signal-and-noise-tools' ),
					$prev['index'],
					$cur['index']
				),
				array( 'status' => 422 )
			);
		}
	}
	return true;
}
