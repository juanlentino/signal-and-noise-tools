<?php
/**
 * Signal & Noise Tools — sn_apply change.type "sentence_replace".
 *
 * The first sn_apply primitive for AGENT-COMPOSED body edits. Every other
 * body executor (drift_replace, emdash_replace, link_insert,
 * block_migration, pattern_adoption) is candidate-driven: its fingerprint
 * is minted by its own scan/suggest pipeline (drift's is
 * md5(phrase|window), inc/ai-drift-phrase-suggest.php), so a caller
 * composing its OWN edit can never produce one — fingerprint parity, the
 * same rule FINDINGS.md records for why link_candidates carries no
 * apply_hint. sentence_replace swaps the concurrency proof for one a
 * composing caller CAN legitimately produce: the LIVE post's
 * content_hash (snt_corpus_content_hash(), the exact value
 * signal-noise/sn-posts exposes per row), the same binding
 * restore_revision already uses. The caller proves "I am editing the
 * post as it currently exists"; the gate proves nothing moved
 * underneath; mode:"revision" keeps the human accept step.
 *
 * Scope fences, deliberate:
 *   - PLAIN-TEXT prose only — the replacement may not contain angle
 *     brackets, so block structure and markup are unreachable from this
 *     type. Structural edits stay with the candidate-driven executors.
 *   - The phrase must be a sentence-scale span (SNT_SN_APPLY_SENTENCE_PHRASE_MIN
 *     chars) — a short token would splice its FIRST occurrence anywhere
 *     in the post (snt_ai_drift_locate_in_raw()'s fallback when no
 *     context disambiguates).
 *   - The phrase must match the stored content BYTE-EXACTLY (same
 *     strpos contract as drift): punctuation, quotes, and entities
 *     included. The caller copies it from sn_posts' content field,
 *     never retypes it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_SN_APPLY_SENTENCE_PHRASE_MIN     = 20;
const SNT_SN_APPLY_SENTENCE_REPLACEMENT_MAX = 2000;

/**
 * Validate the phrase/replacement pair for sentence_replace. Shared by
 * gate 1 (so the refusal reports a 422 caller error, not a 409) and the
 * write impl (defense-in-depth — the impl must hold alone).
 *
 * @param string $phrase      Exact span to replace.
 * @param string $replacement Replacement prose (pre-trim).
 * @return true|WP_Error
 */
function snt_sn_apply_sentence_pair_error( $phrase, $replacement ) {
	$phrase      = (string) $phrase;
	$replacement = trim( (string) $replacement );

	if ( strlen( $phrase ) < SNT_SN_APPLY_SENTENCE_PHRASE_MIN ) {
		return new WP_Error(
			'snt_sn_apply_invalid_phrase',
			sprintf(
				/* translators: %d is the minimum phrase length in characters */
				__( 'sentence_replace targets sentence-scale spans: payload.phrase must be at least %d characters, copied byte-exactly from the stored content.', 'signal-and-noise-tools' ),
				SNT_SN_APPLY_SENTENCE_PHRASE_MIN
			),
			array( 'status' => 422 )
		);
	}
	if ( '' === $replacement ) {
		return new WP_Error( 'snt_sn_apply_invalid_replacement', __( 'payload.replacement is empty.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( strlen( $replacement ) > SNT_SN_APPLY_SENTENCE_REPLACEMENT_MAX ) {
		return new WP_Error(
			'snt_sn_apply_invalid_replacement',
			sprintf(
				/* translators: %d is the maximum replacement length in characters */
				__( 'payload.replacement exceeds %d characters — sentence_replace is sentence surgery, not a section rewrite.', 'signal-and-noise-tools' ),
				SNT_SN_APPLY_SENTENCE_REPLACEMENT_MAX
			),
			array( 'status' => 422 )
		);
	}
	// Tag-SHAPED sequences only ('<' followed by a letter, '/', '!' or '?'),
	// not any '<' — a strip_tags()-equality check false-rejects ordinary
	// prose notation like "<5 percent" (review MEDIUM, confirmed live).
	if ( preg_match( '#<[a-zA-Z/!?]#', $replacement ) ) {
		return new WP_Error( 'snt_sn_apply_invalid_replacement', __( 'payload.replacement contains HTML: sentence_replace writes plain prose only, so block structure is unreachable from this type.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	return true;
}

/**
 * Replace one sentence-scale span of a post's content, gated on the LIVE
 * content_hash.
 *
 * Same splice/write contract as snt_ai_drift_apply_impl() — locate in
 * raw content, substr_replace, write via $write_callback (revision
 * staging) or wp_update_post — with the fingerprint scheme swapped to
 * the whole-post binding described in the file docblock.
 *
 * @param int           $post_id         Target post ID.
 * @param string        $phrase          Exact span to replace (byte-exact).
 * @param string        $replacement     Replacement prose.
 * @param string        $fingerprint     snt_corpus_content_hash() of the live content, as observed via sn_posts.
 * @param string        $context_snippet ~200 chars around the span, to disambiguate repeated spans. Optional.
 * @param callable|null $write_callback  Same contract as snt_ai_drift_apply_impl()'s: called as ($post_id, $new_content) instead of wp_update_post().
 * @return array{ok:bool,post_id:int,replaced:string,with:string,old_content:string,new_content:string}|WP_Error
 */
function snt_sn_apply_sentence_replace_impl( $post_id, $phrase, $replacement, $fingerprint, $context_snippet = '', $write_callback = null ) {
	$post_id         = (int) $post_id;
	$phrase          = (string) $phrase;
	$replacement     = trim( (string) $replacement );
	$fingerprint     = (string) $fingerprint;
	$context_snippet = (string) $context_snippet;

	$pair = snt_sn_apply_sentence_pair_error( $phrase, $replacement );
	if ( is_wp_error( $pair ) ) {
		return $pair;
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
	if ( ! hash_equals( $observed, $fingerprint ) ) {
		return new WP_Error( 'snt_sn_apply_fingerprint_stale', __( 'Live post content has changed since this fingerprint was observed. Re-fetch content_hash via sn_posts and retry.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}

	$raw_position = snt_ai_drift_locate_in_raw( $current_content, $phrase, $context_snippet );
	if ( -1 === $raw_position ) {
		return new WP_Error( 'snt_sn_apply_phrase_not_found', __( 'Phrase not found in post content — the match is byte-exact, punctuation and quotes included. Copy the span verbatim from sn_posts\' content field.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}

	$new_content = substr_replace( $current_content, $replacement, $raw_position, strlen( $phrase ) );

	if ( is_callable( $write_callback ) ) {
		$result = call_user_func( $write_callback, $post_id, $new_content );
	} else {
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
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
		'replaced'    => $phrase,
		'with'        => $replacement,
		'old_content' => $current_content,
		'new_content' => $new_content,
	);
}
