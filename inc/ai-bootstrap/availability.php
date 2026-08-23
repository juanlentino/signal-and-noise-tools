<?php
/**
 * Signal & Noise — AI bootstrap: the availability cache and the guards built on it.
 *
 * Split out of inc/ai-bootstrap.php in v12.21.4, which had grown to 1,054
 * lines. Nothing about behaviour changed.
 *
 * This layer has no registry and no dispatch map — other modules call these
 * functions DIRECTLY, so the public surface is the contract.
 * tests/ai-bootstrap-surface-coverage.php pins all 21 declarations, the eight
 * SN_AI_* constants, the two load-time route registrations, and the single
 * admin_enqueue_scripts hook, so a symbol lost in a move is a build failure
 * rather than a silent behaviour change.
 *
 * Provides: snt_ai_availability_cache(), snt_ai_reset_availability_cache(),
 * snt_ai_is_available(), snt_ai_require_text_generation()
 *
 * @package SignalNoiseTools
 * @since 12.21.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request-static storage for the AI availability check (v6.39.2 PERF).
 *
 * Returned BY REFERENCE so both snt_ai_can_text_generate() (which writes the
 * memoized bool) and snt_ai_reset_availability_cache() (which clears it) share
 * one slot without a global. `null` = not yet derived; `true`/`false` = derived.
 *
 * Availability is genuinely request-stable in production (provider/connector
 * config does not change inside a single PHP request), so a parameterless
 * request-static is correct. The only reason it needs a reset at all is the
 * standalone test harness, which toggles provider state between assertion
 * blocks in one process — see tests/ai-bootstrap.php::fixture_reset().
 *
 * @return bool|null Reference to the cache slot.
 *
 * @since 6.39.2
 */
function &snt_ai_availability_cache() {
	static $cache = null;
	return $cache;
}

/**
 * Clear the request-static AI availability cache, forcing the next
 * snt_ai_can_text_generate() call to re-derive from the provider.
 *
 * Production has no need to call this (availability is request-stable). It
 * exists for the test harness, and as an escape hatch if a future feature
 * legitimately changes provider config mid-request (it would call this after).
 *
 * @return void
 *
 * @since 6.39.2
 */
function snt_ai_reset_availability_cache() {
	$cache = &snt_ai_availability_cache();
	$cache = null;
}

/**
 * Public name for the same check. Convention (audit D-12, v4.1.6):
 *
 *   - **`snt_ai_is_available()`** — preferred at CALL SITES (admin enqueue
 *     guards, REST permission_callbacks, conditional rendering). Reads as
 *     "is AI available?" which is what the caller actually wants to know.
 *   - **`snt_ai_can_text_generate()`** — internal implementation detail
 *     (the underlying capability check against wp-ai-client). Stays as the
 *     impl-named function for clarity when reading ai-bootstrap.php itself.
 *
 * Every external caller in inc/ uses `snt_ai_is_available()` after the
 * v4.1.1 D-03 consolidation (verified by `grep -rn 'snt_ai_can_text_generate' inc/`).
 * Keep both names indefinitely — renaming the impl would touch the wp-ai-client
 * provenance trail in the docblock above.
 *
 * @return bool
 */
function snt_ai_is_available() {
	return snt_ai_can_text_generate();
}

/**
 * v4.1.1 (D-03): single-source-of-truth gate for AI-feature impl functions.
 *
 * Returns null when AI text generation is available, or a uniform WP_Error
 * with code 'snt_ai_unavailable' (HTTP 503) when it isn't. Every AI impl
 * file (alt-text-suggest, alt-inline-suggest, drift-phrase-suggest,
 * meta-description, og-card-title, excerpt, orphan-suggest) opens with the
 * same gate — pre-v4.1.1 each duplicated the 4-line if-block with subtly
 * divergent error messages (one file already used a shortened "AI text
 * generation is not available." while the other six used the longer text).
 * Centralizing here eliminates the drift and gives us one message to localize.
 *
 * Usage:
 *
 *     function snt_ai_foo_impl( ... ) {
 *         $gate = snt_ai_require_text_generation();
 *         if ( $gate ) { return $gate; }
 *         // ... real impl
 *     }
 *
 * The inner gate inside snt_ai_generate_with_constraints() also fires
 * (with a slightly different message about the wp-ai-client plugin
 * specifically) — that one stays in place as defense-in-depth. This
 * outer gate lets impls short-circuit BEFORE building expensive prompts.
 *
 * @return WP_Error|null  WP_Error when AI is unavailable, null when it is.
 *
 * @since 4.1.1
 */
function snt_ai_require_text_generation() {
	if ( snt_ai_can_text_generate() ) {
		return null;
	}
	return new WP_Error(
		'snt_ai_unavailable',
		__( 'AI text generation is not available. Upgrade to WordPress 7.0+ and configure a provider in Settings > Connectors.', 'signal-and-noise-tools' ),
		array( 'status' => 503 )
	);
}
