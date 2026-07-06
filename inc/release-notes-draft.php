<?php
/**
 * Signal & Noise Tools — AI release-notes drafter (v4.11.0, Task 4).
 *
 * Turns a pasted CHANGELOG delta (or raw "what changed" notes) into
 * Mimestream-style, human-readable release notes via the WP AI Client.
 * One on-demand call per submission; FRUGAL by design:
 *   - hard-caps the input to ~4000 chars (word-boundary) BEFORE the call so a
 *     giant paste can't balloon token cost;
 *   - pins Sonnet 4.6 + a 700-token output cap via snt_ai_generate_with_constraints().
 *
 * Output is markdown — the user pastes it straight into a Mimestream-style
 * release-notes document (per the v4.11.0 design decision).
 *
 * Surfaced two ways:
 *   - Tools → Release Notes sub-tab (sn_action=release_notes_draft); and
 *   - the signal-noise/draft-release-notes ability (read-only, NOT idempotent —
 *     a generative call returns different prose each time).
 *
 * @package SignalNoiseTools
 * @since 4.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hard cap on the characters of delta we send to the model.
 *
 * Release notes are summaries, not transcripts — the first ~4000 chars of a
 * delta carry every meaningful change for a single version. Capping here (not
 * relying on max_tokens alone) bounds INPUT token cost, which scales with the
 * whole prompt regardless of how short the output is.
 *
 * @since 4.11.0
 */
if ( ! defined( 'SNT_RELEASE_NOTES_MAX_INPUT' ) ) {
	define( 'SNT_RELEASE_NOTES_MAX_INPUT', 4000 );
}

/**
 * The Mimestream-style system instruction for release-notes drafting.
 *
 * Constraints (voice/format/length) live here instead of temperature/top_p —
 * see inc/ai-bootstrap.php for why SN pins a provider-agnostic call. The model
 * MUST only summarize what the delta states (never invent changes) and emit
 * only the three reader-facing sections.
 *
 * @since 4.11.0
 */
if ( ! defined( 'SNT_RELEASE_NOTES_SYSTEM' ) ) {
	define(
		'SNT_RELEASE_NOTES_SYSTEM',
		'You are a release-notes editor for a personal website. Turn the raw change log below into concise, human-readable release notes in the Mimestream style: warm, plain English, written for the person reading the update — not the engineer who wrote it. '
		. 'Output GitHub-flavored markdown using ONLY these three section headers, in this order, and ONLY the ones that apply: "### New" for net-new capabilities, "### Improvements" for refinements to existing behavior, "### Fixed" for bug fixes. '
		. 'Under each header, write one short bullet per change starting with "- ". Lead each bullet with a verb. Keep bullets to a single line; merge trivially related changes. '
		. 'Do NOT invent, infer, or embellish changes that are not in the input. Do NOT include version numbers, dates, internal file or function names, ticket IDs, or a title/preamble. If a section has no changes, omit that header entirely. Return only the markdown — no surrounding commentary or code fences.'
	);
}

/**
 * Draft Mimestream-style release notes from a pasted CHANGELOG delta.
 *
 * @since 4.11.0
 *
 * @param string $changelog_delta Raw notes / CHANGELOG delta describing what changed.
 * @return string|WP_Error Markdown release notes, or WP_Error on empty input / AI failure.
 */
function snt_release_notes_draft_impl( $changelog_delta ) {
	// Outer gate first — short-circuit before any string work or prompt build.
	$gate = snt_ai_require_text_generation();
	if ( $gate ) {
		return $gate;
	}

	$delta = trim( (string) $changelog_delta );
	if ( '' === $delta ) {
		return new WP_Error(
			'snt_rn_empty',
			__( 'Paste a change log delta (what changed in this version) before drafting release notes.', 'signal-and-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	// FRUGAL: hard-cap the input at a word boundary BEFORE the call so a huge
	// paste can't balloon input token cost. Only truncate when over the cap.
	if ( strlen( $delta ) > SNT_RELEASE_NOTES_MAX_INPUT ) {
		$delta = snt_release_notes_cap_input( $delta, SNT_RELEASE_NOTES_MAX_INPUT );
	}

	$result = snt_ai_generate_with_constraints( $delta, SNT_RELEASE_NOTES_SYSTEM, 700 );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return (string) $result;
}

/**
 * Truncate text to at most $max chars on a word boundary (no ellipsis).
 *
 * Trims first, then — if still over — cuts at the last whitespace at/under the
 * cap so a token is never split mid-word. Falls back to a hard substr when the
 * first $max chars contain no whitespace (a single giant token).
 *
 * @since 4.11.0
 *
 * @param string $text The (already-trimmed-friendly) input.
 * @param int    $max  Maximum character length.
 * @return string Truncated text, never longer than $max, trimmed.
 */
function snt_release_notes_cap_input( $text, $max ) {
	$text = (string) $text;
	$max  = (int) $max;
	if ( $max <= 0 || strlen( $text ) <= $max ) {
		return trim( $text );
	}

	// mb_strcut bounds by BYTES without splitting a multibyte char (the cap is
	// a token-budget guard, so a byte ceiling is what we want); plain substr is
	// the fallback when mbstring is unavailable.
	$slice = function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $max ) : substr( $text, 0, $max );
	$last  = strrpos( $slice, ' ' );
	if ( false === $last ) {
		// No space within the cap — fall back to a hard cut.
		return trim( $slice );
	}

	return trim( substr( $slice, 0, $last ) );
}
