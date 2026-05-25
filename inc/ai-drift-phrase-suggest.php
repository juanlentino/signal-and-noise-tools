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
 * Pure impl: generate a replacement phrase for a drifted time-phrase.
 *
 * Pre-flight: verifies the phrase still exists at $position in current
 * post_content. If not, returns snt_ai_phrase_drifted WITHOUT calling AI
 * (cheaper than calling AI then discovering the post moved).
 *
 * @param int    $post_id
 * @param string $phrase          Original phrase (e.g., "recently")
 * @param int    $position        Byte offset in post_content
 * @param string $context_snippet ~200 chars around phrase (from scan)
 * @return array{ok:bool,suggestion:string,fingerprint:string,post_id:int,position:int}|WP_Error
 *
 * WP_Error codes:
 *   snt_ai_unavailable
 *   snt_ai_post_not_found  (404)
 *   snt_ai_phrase_drifted  (409) — phrase no longer at position
 *   snt_ai_no_replacement  (422) — AI returned PHRASE_NO_REPLACEMENT marker
 *   snt_ai_runtime_error
 *   snt_ai_empty_response
 *
 * @since 4.0.0
 */
function snt_ai_drift_suggest_impl( $post_id, $phrase, $position, $context_snippet ) {
	if ( ! function_exists( 'snt_ai_can_text_generate' ) || ! snt_ai_can_text_generate() ) {
		return new WP_Error( 'snt_ai_unavailable', __( 'AI text generation is not available.', 'signal-noise-tools' ), array( 'status' => 503 ) );
	}

	$post_id  = (int) $post_id;
	$position = (int) $position;
	$phrase   = (string) $phrase;

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_ai_post_not_found', __( 'Post not found.', 'signal-noise-tools' ), array( 'status' => 404 ) );
	}

	// Pre-flight: phrase must still exist at the recorded position.
	$current_content = (string) $post->post_content;
	$at_position     = substr( $current_content, $position, strlen( $phrase ) );
	if ( $at_position !== $phrase ) {
		return new WP_Error(
			'snt_ai_phrase_drifted',
			__( 'Phrase no longer at the recorded position — post was edited since the scan. Re-run the scan to refresh.', 'signal-noise-tools' ),
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
		return new WP_Error( 'snt_ai_runtime_error', __( 'Failed to encode AI payload.', 'signal-noise-tools' ), array( 'status' => 500 ) );
	}

	$result = snt_ai_generate_with_constraints( $prompt, SNT_AI_DRIFT_SUGGEST_SYSTEM, SNT_AI_DRIFT_SUGGEST_MAX_TOKENS );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$suggestion = trim( (string) $result );
	$suggestion = trim( $suggestion, "\"'" );

	if ( 'PHRASE_NO_REPLACEMENT' === $suggestion ) {
		return new WP_Error( 'snt_ai_no_replacement', __( 'AI could not generate a useful replacement for this phrase.', 'signal-noise-tools' ), array( 'status' => 422 ) );
	}

	return array(
		'ok'          => true,
		'suggestion'  => $suggestion,
		'fingerprint' => snt_ai_drift_fingerprint( $current_content, $phrase, $position ),
		'post_id'     => $post_id,
		'position'    => $position,
	);
}

/**
 * Pure impl: replace a drifted phrase in post_content.
 *
 * Atomicity: loads current post_content, validates fingerprint + phrase
 * still at position, splices in $replacement, calls wp_update_post() with
 * the wp_error flag.
 *
 * @param int    $post_id
 * @param string $phrase       Original phrase (must still match at $position)
 * @param int    $position     Byte offset
 * @param string $replacement  AI suggestion (possibly user-edited; max length enforced)
 * @param string $fingerprint  md5 from Suggest response — must still match current state
 * @return array{ok:bool,post_id:int,replaced:string,with:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_ai_post_not_found      (404)
 *   snt_ai_apply_conflict      (409) — fingerprint mismatch (post changed)
 *   snt_ai_replacement_invalid (422) — empty / too-long / contains HTML
 *   snt_ai_capability          (403)
 *   snt_ai_write_failed        (500)
 *
 * @since 4.0.0
 */
function snt_ai_drift_apply_impl( $post_id, $phrase, $position, $replacement, $fingerprint ) {
	$post_id     = (int) $post_id;
	$position    = (int) $position;
	$phrase      = (string) $phrase;
	$replacement = trim( (string) $replacement );
	$fingerprint = (string) $fingerprint;

	if ( '' === $replacement ) {
		return new WP_Error( 'snt_ai_replacement_invalid', __( 'Replacement is empty.', 'signal-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( strlen( $replacement ) > SNT_AI_DRIFT_REPLACEMENT_MAX_LENGTH ) {
		return new WP_Error( 'snt_ai_replacement_invalid', sprintf( __( 'Replacement exceeds %d characters.', 'signal-noise-tools' ), SNT_AI_DRIFT_REPLACEMENT_MAX_LENGTH ), array( 'status' => 422 ) );
	}
	// Reject any HTML angle brackets — drift replacements should be plain text.
	if ( $replacement !== wp_strip_all_tags( $replacement ) ) {
		return new WP_Error( 'snt_ai_replacement_invalid', __( 'Replacement contains HTML — only plain text allowed.', 'signal-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'snt_ai_capability', __( 'You cannot edit this post.', 'signal-noise-tools' ), array( 'status' => 403 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_ai_post_not_found', __( 'Post not found.', 'signal-noise-tools' ), array( 'status' => 404 ) );
	}

	$current_content = (string) $post->post_content;

	// Fingerprint must still match.
	$current_fp = snt_ai_drift_fingerprint( $current_content, $phrase, $position );
	if ( $current_fp !== $fingerprint ) {
		return new WP_Error( 'snt_ai_apply_conflict', __( 'Post changed since scan. Re-run scan to refresh.', 'signal-noise-tools' ), array( 'status' => 409 ) );
	}

	// Defense in depth: phrase still at position byte-for-byte.
	$at_position = substr( $current_content, $position, strlen( $phrase ) );
	if ( $at_position !== $phrase ) {
		return new WP_Error( 'snt_ai_apply_conflict', __( 'Phrase no longer at recorded position. Re-run scan.', 'signal-noise-tools' ), array( 'status' => 409 ) );
	}

	// Splice.
	$new_content = substr_replace( $current_content, $replacement, $position, strlen( $phrase ) );

	$result = wp_update_post( array(
		'ID'           => $post_id,
		'post_content' => $new_content,
	), true );

	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'snt_ai_write_failed', sprintf( __( 'wp_update_post failed: %s', 'signal-noise-tools' ), $result->get_error_message() ), array( 'status' => 500 ) );
	}

	return array(
		'ok'       => true,
		'post_id'  => $post_id,
		'replaced' => $phrase,
		'with'     => $replacement,
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * REST endpoints — back-compat surface for non-JS callers (CI, wp-cli).
 * JS clients use the Abilities API REST surface via wp.apiFetch.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/ai/drift-suggest', array(
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $request ) {
			$result = snt_ai_drift_suggest_impl(
				(int) $request->get_param( 'post_id' ),
				(string) $request->get_param( 'phrase' ),
				(int) $request->get_param( 'position' ),
				(string) $request->get_param( 'context_snippet' )
			);
			if ( is_wp_error( $result ) ) { return $result; }
			return rest_ensure_response( $result );
		},
		'permission_callback' => function( WP_REST_Request $request ) {
			return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
		},
		'args' => array(
			'post_id'         => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'phrase'          => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'position'        => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'context_snippet' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
		),
	) );

	register_rest_route( 'signal-noise/v1', '/ai/drift-apply', array(
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $request ) {
			$result = snt_ai_drift_apply_impl(
				(int) $request->get_param( 'post_id' ),
				(string) $request->get_param( 'phrase' ),
				(int) $request->get_param( 'position' ),
				(string) $request->get_param( 'replacement' ),
				(string) $request->get_param( 'fingerprint' )
			);
			if ( is_wp_error( $result ) ) { return $result; }
			return rest_ensure_response( $result );
		},
		'permission_callback' => function( WP_REST_Request $request ) {
			return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
		},
		'args' => array(
			'post_id'     => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'phrase'      => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'position'    => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'replacement' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'fingerprint' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
		),
	) );
} );
