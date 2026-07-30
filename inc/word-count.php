<?php
/**
 * Signal & Noise — snt_word_count(), the Unicode-safe word counter
 * (v10.24.0). Its own tiny PURE module (zero WP calls) so every consumer —
 * reading time, schema.org wordCount, the AI prepop gate, AI excerpt
 * length — and every standalone CLI fixture can load exactly this and
 * nothing else.
 *
 * str_word_count is ASCII-only: accented letters split words apart and
 * standalone numbers count as ZERO — so "señal" under-counted and
 * schema.org wordCount published wrong numbers for digit-bearing prose.
 * A word here is a run of Unicode letters/numbers, with interior
 * apostrophes (straight or typographic) and hyphens JOINING — the old
 * counter's semantics kept deliberately, so contraction-heavy prose does
 * not inflate ~15% overnight. Invalid UTF-8 never zeroes a real post:
 * malformed bytes are dropped and the surviving words count.
 *
 * @package SignalNoiseTools
 * @since 10.24.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'snt_word_count' ) ) {
	/**
	 * Count words in plain text (caller strips markup first).
	 *
	 * @param string $text Plain text.
	 * @return int
	 */
	function snt_word_count( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return 0;
		}
		$pattern = '/[\p{L}\p{N}]+(?:[\'\x{2019}-][\p{L}\p{N}]+)*/u';
		$n       = preg_match_all( $pattern, $text );
		if ( false === $n ) {
			// /u refuses invalid UTF-8 outright (returns false, not 0) — a
			// single malformed byte must not zero-count a 300-word post, so
			// drop the bad bytes and count what survives.
			$clean = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'UTF-8//IGNORE', $text ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- iconv notices on the exact input this branch exists for.
			$n     = is_string( $clean ) ? preg_match_all( $pattern, $clean ) : false;
			if ( false === $n ) {
				return str_word_count( $text ); // Last resort: never worse than the old counter.
			}
		}
		return (int) $n;
	}
}
