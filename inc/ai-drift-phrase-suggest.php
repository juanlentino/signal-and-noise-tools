<?php
/**
 * Signal & Noise Tools — AI-assisted drift-phrase replacement.
 *
 * Two impl functions + two REST endpoints + two Abilities API
 * registrations (in inc/abilities-registration.php) that together
 * deliver Suggest+Apply UX for the drift_time_phrases Health check.
 *
 * The drift detection (sn_health_check_drift_time_phrases() in
 * inc/health-checks.php) already uses AI to flag stale phrases.
 * This module adds the suggest+apply layer that proposes a
 * replacement phrase and writes it to post_content on user
 * confirmation.
 *
 * Concurrency safety: post_content can be edited between scan
 * and apply. Suggest returns a `fingerprint` (md5 of phrase +
 * 80-char context window). Apply rejects if the current
 * post_content's fingerprint at $position doesn't match.
 * Failure mode: snt_ai_apply_conflict → JS prompts "re-run scan".
 *
 * Surface convention follows ai-alt-text-suggest.php (and earlier
 * ai-meta-description.php): pure impl + REST + ability.
 *
 * @package SignalNoiseTools
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// v4.2.0 PROMPT DESIGN (D-09): paired with inc/health-checks.php's
// SNT_AI_DRIFT_SYSTEM detection prompt. This prompt generates replacement
// suggestions for positions identified by the detection pass.
const SNT_AI_DRIFT_SUGGEST_SYSTEM = 'Replace a time-relative phrase with a temporally-explicit equivalent. ' .
	'Given the original phrase, the surrounding context, the post\'s last-modified date, and today\'s date, ' .
	'output ONLY the replacement phrase. Rules: ' .
	'(1) keep grammatical form (verb tense, plurality); ' .
	'(2) prefer absolute references (years, dates, named events) over time-relative phrases; ' .
	'(3) preserve the original meaning — replace the phrase, not the surrounding sentence; ' .
	'(4) if no good replacement exists (the phrase is intentionally vague or the context does not support a specific date), output the literal marker PHRASE_NO_REPLACEMENT. ' .
	'No preamble, no quotes, no markdown — output only the replacement string or the marker.';

const SNT_AI_DRIFT_SUGGEST_MAX_TOKENS     = 60;
const SNT_AI_DRIFT_FINGERPRINT_WINDOW     = 80;
const SNT_AI_DRIFT_REPLACEMENT_MAX_LENGTH = 200;

/**
 * Compute the fingerprint for a phrase at a position in post_content.
 *
 * Window: SNT_AI_DRIFT_FINGERPRINT_WINDOW chars centered on $position.
 * Phrase is prepended (separated by '|') so a phrase change inside the
 * window invalidates the fingerprint even if surrounding chars didn't move.
 *
 * @param string $post_content
 * @param string $phrase
 * @param int    $position
 * @return string md5 hash (32 chars)
 *
 * @since 4.0.0
 */
function snt_ai_drift_fingerprint( $post_content, $phrase, $position ) {
	$half   = (int) ( SNT_AI_DRIFT_FINGERPRINT_WINDOW / 2 );
	$start  = max( 0, (int) $position - $half );
	$window = substr( (string) $post_content, $start, SNT_AI_DRIFT_FINGERPRINT_WINDOW );
	return md5( (string) $phrase . '|' . $window );
}

/**
 * Locate $phrase in $raw_content at the position whose surroundings best
 * match $context_snippet.
 *
 * The extractor (`sn_health_extract_time_phrase_candidates`) operates on
 * STRIPPED content — `strip_shortcodes()` + `wp_strip_all_tags()`. Its
 * reported byte offsets do NOT line up with raw `post_content` byte offsets
 * for any post containing block markup or shortcodes. v4.0.0 through v4.1.0
 * mistakenly used the stored offsets directly against raw content, which
 * meant Suggest+Apply produced `snt_ai_phrase_drifted` (409) for every
 * Gutenberg post — silently broken since v4.0.0. v4.1.1 fix: resolve the
 * raw-content offset dynamically, treating the stored `position` as advisory
 * only.
 *
 * Algorithm:
 * - If $phrase occurs once in raw content → return that offset (fast path).
 * - If it occurs multiple times → for each occurrence, strip a 200-char
 *   window around it the same way the extractor does, then score similarity
 *   against $context_snippet. The highest-similarity occurrence wins.
 * - If it doesn't occur → return -1 (caller surfaces a 409 drift error).
 *
 * @param string $raw_content     Raw post_content (may contain block markup, shortcodes, HTML).
 * @param string $phrase          The phrase as captured by the extractor.
 * @param string $context_snippet ~200 chars from stripped content around the phrase (from scan).
 * @return int                    Byte offset in $raw_content, or -1 if not found.
 *
 * @since 4.1.1
 */
function snt_ai_drift_locate_in_raw( $raw_content, $phrase, $context_snippet ) {
	$raw_content = (string) $raw_content;
	$phrase      = (string) $phrase;
	if ( '' === $phrase ) {
		return -1;
	}

	$first = strpos( $raw_content, $phrase );
	if ( false === $first ) {
		return -1;
	}
	$second = strpos( $raw_content, $phrase, $first + strlen( $phrase ) );
	if ( false === $second ) {
		// Unambiguous — only one occurrence.
		return $first;
	}

	// Multiple occurrences. Disambiguate by similarity of stripped surroundings
	// to $context_snippet.
	$snippet_norm = snt_ai_drift_normalize_for_compare( (string) $context_snippet );
	if ( '' === $snippet_norm ) {
		// No context to disambiguate with — return first occurrence (least surprising fallback).
		return $first;
	}

	$best_pos   = $first;
	$best_score = -1;
	$pos        = 0;
	$plen       = strlen( $phrase );

	while ( false !== ( $pos = strpos( $raw_content, $phrase, $pos ) ) ) {
		$start  = max( 0, $pos - 80 );
		$window = substr( $raw_content, $start, 200 );

		if ( function_exists( 'strip_shortcodes' ) ) {
			$window = strip_shortcodes( $window );
		}
		$window      = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $window ) : strip_tags( $window );
		$window_norm = snt_ai_drift_normalize_for_compare( $window );

		// similar_text returns common-char count; higher = better match.
		$score = 0;
		if ( '' !== $window_norm ) {
			similar_text( $snippet_norm, $window_norm, $pct );
			$score = (int) ( $pct * 100 );
		}
		if ( $score > $best_score ) {
			$best_score = $score;
			$best_pos   = $pos;
		}
		$pos += $plen;
	}

	return $best_pos;
}

/**
 * Normalize a string for similarity comparison: collapse whitespace, trim.
 * Keeps unicode characters intact (no `sanitize_text_field` — that strips
 * em-dashes and curly quotes).
 *
 * @param string $str
 * @return string
 *
 * @since 4.1.1
 */
function snt_ai_drift_normalize_for_compare( $str ) {
	return trim( preg_replace( '/\s+/u', ' ', (string) $str ) );
}

/**
 * Pure impl: generate a replacement phrase for a drifted time-phrase.
 *
 * Pre-flight: resolves the phrase's RAW-content position via
 * `snt_ai_drift_locate_in_raw()` (the stored `$position` from the extractor
 * is in STRIPPED-content coordinates and cannot be used directly — see the
 * locator's docblock for the v4.1.1 fix history). The returned response's
 * `position` field is the RAW-content offset, which Apply must use.
 *
 * @param int    $post_id
 * @param string $phrase          Original phrase (e.g., "recently")
 * @param int    $position        Byte offset in STRIPPED content from extractor (advisory).
 * @param string $context_snippet ~200 chars around phrase (from scan) — used to disambiguate.
 * @return array{ok:bool,suggestion:string,fingerprint:string,post_id:int,position:int}|WP_Error
 *
 * WP_Error codes:
 *   snt_ai_unavailable
 *   snt_ai_post_not_found  (404)
 *   snt_ai_phrase_drifted  (409) — phrase no longer present in post_content
 *   snt_ai_no_replacement  (422) — AI returned PHRASE_NO_REPLACEMENT marker
 *   snt_ai_runtime_error
 *   snt_ai_empty_response
 *
 * @since 4.0.0 (4.1.1 fix: dynamic raw-position resolution)
 */
function snt_ai_drift_suggest_impl( $post_id, $phrase, $position, $context_snippet ) {
	// v4.1.1 (D-03): shared AI-gate helper. Pre-v4.1.1 this file used a shorter
	// error message ("AI text generation is not available.") which diverged from
	// the six other AI impls — centralization eliminates the drift.
	$gate = snt_ai_require_text_generation();
	if ( $gate ) { return $gate; }

	$post_id  = (int) $post_id;
	$position = (int) $position; // Advisory — from extractor in stripped coords; not used directly.
	$phrase   = (string) $phrase;

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_ai_post_not_found', __( 'Post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}

	// Pre-flight: phrase must still exist somewhere in raw post_content.
	// Resolve the RAW offset (extractor's stored $position is in stripped coords).
	$current_content = (string) $post->post_content;
	$raw_position    = snt_ai_drift_locate_in_raw( $current_content, $phrase, (string) $context_snippet );
	if ( -1 === $raw_position ) {
		return new WP_Error(
			'snt_ai_phrase_drifted',
			__( 'Phrase no longer present in post content: post was edited since the scan. Re-run the scan to refresh.', 'signal-and-noise-tools' ),
			array( 'status' => 409 )
		);
	}

	// Build the AI prompt: JSON with phrase + context + dates.
	$payload = array(
		'phrase'        => $phrase,
		'context'       => (string) $context_snippet,
		'last_modified' => substr( (string) $post->post_modified_gmt, 0, 10 ),
		'now'           => gmdate( 'Y-m-d' ),
	);
	$prompt  = wp_json_encode( $payload );
	if ( false === $prompt ) {
		return new WP_Error( 'snt_ai_runtime_error', __( 'Failed to encode AI payload.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}

	$result = snt_ai_generate_with_constraints( $prompt, SNT_AI_DRIFT_SUGGEST_SYSTEM, SNT_AI_DRIFT_SUGGEST_MAX_TOKENS, 'drift_phrase' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$suggestion = (string) $result;  // v4.1.6 (D-10): quote-strip now happens in snt_ai_generate_with_constraints().

	if ( 'PHRASE_NO_REPLACEMENT' === $suggestion ) {
		return new WP_Error( 'snt_ai_no_replacement', __( 'AI could not generate a useful replacement for this phrase.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	return array(
		'ok'          => true,
		'suggestion'  => $suggestion,
		'fingerprint' => snt_ai_drift_fingerprint( $current_content, $phrase, $raw_position ),
		'post_id'     => $post_id,
		'position'    => $raw_position, // RAW offset, not the extractor's stripped-coords offset.
	);
}

/**
 * Apply a drift-replacement to post_content. Validates the fingerprint
 * at the resolved position before splicing; returns a WP_Error if the
 * post has been modified since the suggestion was generated.
 *
 * Atomicity: loads current post_content, resolves the phrase's RAW-content
 * position via `snt_ai_drift_locate_in_raw()` (the client-passed `$position`
 * is the value returned by Suggest, which is also RAW-coords as of v4.1.1;
 * we re-resolve here for defense-in-depth against any future client drift).
 * Validates fingerprint at the resolved position, splices in $replacement,
 * calls wp_update_post() with the wp_error flag.
 *
 * KNOWN LIMITATION (audit B-06, 2026-05-25): this calls wp_update_post()
 * to splice the AI suggestion into post_content. That triggers downstream
 * WordPress hooks — post-save indexing, cache busts, revision creation,
 * any save_post listeners. The md5 fingerprint produced by
 * snt_ai_drift_fingerprint() (this file, line 59) and validated at the
 * splice site (lines 290-310 region) is the mitigation — if post_content
 * has shifted, we return WP_Error instead of writing.
 *
 * Acknowledged as a design tradeoff, not a bug. The hook fanout cost is
 * proportional to the number of phrases applied, which is small (handful
 * per post per scan cycle).
 *
 * @since 4.0.0 (4.1.1 fix: dynamic raw-position resolution via context_snippet)
 *
 * @param int    $post_id          Target post ID.
 * @param string $phrase           Original time-relative phrase from the detect pass.
 * @param int    $position         Byte offset returned by Suggest (raw coords; advisory — re-resolved here).
 * @param string $replacement      Proposed replacement phrase from the suggest pass (possibly user-edited; max length enforced).
 * @param string $fingerprint      md5 from snt_ai_drift_fingerprint() — required match.
 * @param string        $context_snippet  ~200 chars around phrase from scan — used to disambiguate phrase occurrences. Required since 4.1.1.
 * @param callable|null $write_callback   (v10.40.0, sn_apply session 6b) Optional
 *                                        write-step override: when set, called as
 *                                        $write_callback( $post_id, $new_content )
 *                                        INSTEAD of wp_update_post() — lets
 *                                        sn_apply's mode:"revision" route this
 *                                        surface's write through
 *                                        snt_sn_apply_stage_revision() without
 *                                        touching the live post. Must return the
 *                                        post ID (or any non-WP_Error truthy
 *                                        value) on success, WP_Error on failure.
 *                                        Default null preserves the original
 *                                        wp_update_post() behavior byte-for-byte.
 * @return array{ok:bool,post_id:int,replaced:string,with:string,old_content:string,new_content:string}|WP_Error Apply result on success; WP_Error on fingerprint mismatch or write failure.
 *
 * WP_Error codes:
 *   snt_ai_post_not_found      (404)
 *   snt_ai_apply_conflict      (409) — fingerprint mismatch (post changed) OR phrase gone
 *   snt_ai_replacement_invalid (422) — empty / too-long / contains HTML
 *   snt_ai_capability          (403)
 *   snt_ai_write_failed        (500)
 */
function snt_ai_drift_apply_impl( $post_id, $phrase, $position, $replacement, $fingerprint, $context_snippet = '', $write_callback = null ) {
	$post_id         = (int) $post_id;
	$position        = (int) $position; // Advisory — re-resolved below.
	$phrase          = (string) $phrase;
	$replacement     = trim( (string) $replacement );
	$fingerprint     = (string) $fingerprint;
	$context_snippet = (string) $context_snippet;

	if ( '' === $replacement ) {
		return new WP_Error( 'snt_ai_replacement_invalid', __( 'Replacement is empty.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( strlen( $replacement ) > SNT_AI_DRIFT_REPLACEMENT_MAX_LENGTH ) {
		/* translators: %d is the maximum allowed number of characters */
		return new WP_Error( 'snt_ai_replacement_invalid', sprintf( __( 'Replacement exceeds %d characters.', 'signal-and-noise-tools' ), SNT_AI_DRIFT_REPLACEMENT_MAX_LENGTH ), array( 'status' => 422 ) );
	}
	// Reject any HTML angle brackets — drift replacements should be plain text.
	if ( $replacement !== wp_strip_all_tags( $replacement ) ) {
		return new WP_Error( 'snt_ai_replacement_invalid', __( 'Replacement contains HTML: only plain text allowed.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'snt_ai_capability', __( 'You cannot edit this post.', 'signal-and-noise-tools' ), array( 'status' => 403 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_ai_post_not_found', __( 'Post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}

	$current_content = (string) $post->post_content;

	// Re-resolve the raw-content position. The client-passed $position should
	// already be in raw coords (returned by v4.1.1+ Suggest), but we re-resolve
	// for defense-in-depth (e.g., post edited between suggest and apply).
	$raw_position = snt_ai_drift_locate_in_raw( $current_content, $phrase, $context_snippet );
	if ( -1 === $raw_position ) {
		return new WP_Error( 'snt_ai_apply_conflict', __( 'Phrase no longer present in post content. Re-run scan.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}

	// Fingerprint must still match at the resolved position.
	$current_fp = snt_ai_drift_fingerprint( $current_content, $phrase, $raw_position );
	if ( $current_fp !== $fingerprint ) {
		return new WP_Error( 'snt_ai_apply_conflict', __( 'Post changed since scan. Re-run scan to refresh.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}

	// Splice at the resolved raw-content position.
	$new_content = substr_replace( $current_content, $replacement, $raw_position, strlen( $phrase ) );

	if ( is_callable( $write_callback ) ) {
		$result = call_user_func( $write_callback, $post_id, $new_content );
	} else {
		$result = wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $new_content,
		), true );
	}

	if ( is_wp_error( $result ) ) {
		/* translators: %s is the error message from wp_update_post() */
		return new WP_Error( 'snt_ai_write_failed', sprintf( __( 'wp_update_post failed: %s', 'signal-and-noise-tools' ), $result->get_error_message() ), array( 'status' => 500 ) );
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

/* ════════════════════════════════════════════════════════════════════════
 * v7.0.0: the /ai/drift-suggest + /ai/drift-apply back-compat REST routes
 * were removed — both are served by the signal-noise/ai-drift-suggest and
 * signal-noise/ai-drift-apply Abilities run-path (inc/abilities-ai-health.php),
 * which call the snt_ai_drift_suggest_impl() / snt_ai_drift_apply_impl()
 * impls above. The route-only snt_ai_drift_sanitize_prose() sanitizer went
 * with them.
 * ════════════════════════════════════════════════════════════════════════ */
