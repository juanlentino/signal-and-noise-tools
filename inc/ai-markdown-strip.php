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
