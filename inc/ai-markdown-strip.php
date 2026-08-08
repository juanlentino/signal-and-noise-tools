<?php
/**
 * Signal & Noise Tools — markdown stripper for AI prose (v9.64.2).
 *
 * Defense-in-depth behind the "plain prose only" system instructions: models
 * occasionally emit markdown anyway, and the digest render paths output plain
 * text — the owner's live screenshot (2026-07-18) showed "**Weekly Analytics
 * Digest**" with literal asterisks in the insights band. The marks must be
 * REMOVED at store/serve time, never escaped. Pure string transform, no WP
 * dependencies; shared by inc/analytics-narrator.php (digest + narrate run
 * paths) and inc/insights-narration.php (narration parse boundary).
 *
 * @package SignalNoiseTools
 * @since 9.64.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strip markdown emphasis and heading markers from model prose.
 *
 * Removes ATX heading markers at line start (# through ######), **bold**,
 * __bold__, *italic*, and _italic_. Deliberately conservative so legitimate
 * arithmetic and identifiers survive: a spaced asterisk ("2 * 3") is never
 * an emphasis delimiter (emphasis must sit flush against its text), and
 * underscore emphasis only matches at word boundaries (the CommonMark rule),
 * so field names like pageview_visits pass through untouched.
 *
 * @param string $text Model output (plain text, possibly carrying markdown).
 * @return string The same text with emphasis/heading markers removed.
 */
function snt_ai_strip_markdown( $text ) {
	$text = (string) $text;
	// ATX heading markers at line start: "## Head" → "Head".
	$text = (string) preg_replace( '/^[ \t]*#{1,6}[ \t]+/m', '', $text );
	// Bold first (double delimiters), then italic (single) — order matters so
	// "**x**" never degrades into a stray emphasis pair.
	$text = (string) preg_replace( '/\*\*(?!\s)([^*\n]+?)(?<!\s)\*\*/', '$1', $text );
	$text = (string) preg_replace( '/(?<![A-Za-z0-9_])__(?!\s)([^_\n]+?)(?<!\s)__(?![A-Za-z0-9_])/', '$1', $text );
	$text = (string) preg_replace( '/\*(?!\s)([^*\n]+?)(?<!\s)\*/', '$1', $text );
	$text = (string) preg_replace( '/(?<![A-Za-z0-9_])_(?!\s)([^_\n]+?)(?<!\s)_(?![A-Za-z0-9_])/', '$1', $text );
	return $text;
}

/**
 * Normalize model prose for display to a human — the R2 boundary from
 * docs/security/agent-surface-threat-model.md (v10.64.0): narrated prose
 * is an agent→human channel read with the owner's authority, so it is
 * UNTRUSTED DISPLAY by construction, never markup and never spoofable.
 * On top of snt_ai_strip_markdown():
 *
 *   - HTML tags removed (the tag, inner text kept) — a model-emitted
 *     <a href=…> must never reach a surface that renders it clickable
 *     (the MCP narration abilities serve this text to chat clients that
 *     linkify), and esc_html at a render sink would keep the tag as
 *     visible noise rather than removing it.
 *   - C0/C1 control characters removed (newline and tab kept) — no
 *     terminal-style rewriting of what the owner sees.
 *   - Zero-width and bidi-control characters removed (U+200B–U+200D,
 *     U+FEFF, U+202A–U+202E, U+2066–U+2069) — an RLO override can make
 *     displayed prose read differently than it compares, the classic
 *     display-spoof; narration has no legitimate use for any of them.
 *
 * Pure string transform, no WP dependencies, same contract as the
 * stripper above. Shared by inc/insights-narration.php (parse boundary)
 * and inc/analytics-narrator.php (store boundaries).
 *
 * @param string $text Model output destined for human eyes.
 * @return string The same prose, markup-free and spoof-character-free.
 */
function snt_ai_untrusted_display( $text ) {
	$text = snt_ai_strip_markdown( (string) $text );
	// Tags out, inner text kept. Possessive quantifier: no backtracking on
	// pathological input; an unclosed "<" with no ">" survives as literal text.
	$text = (string) preg_replace( '/<[^>]*+>/', '', $text );
	// Control characters except \n (x0A) and \t (x09).
	$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
	// Zero-width + bidi controls.
	$text = (string) preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text );
	return $text;
}
