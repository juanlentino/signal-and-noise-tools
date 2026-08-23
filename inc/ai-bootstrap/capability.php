<?php
/**
 * Signal & Noise — AI bootstrap: whether the host can generate text at all.
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
 * Provides: snt_ai_can_text_generate()
 *
 * @package SignalNoiseTools
 * @since 12.21.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is the WP AI Client wired up with at least one provider that can
 * generate text on this install?
 *
 * Canonical check per the WordPress 7.0 AI Client dev note (2026-03-24):
 *   https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/
 *
 *   > "Available support check methods include is_supported_for_text_generation(),
 *   >  is_supported_for_image_generation(), and others — NOT wp_has_ai_client()."
 *
 * The check builds a no-cost prompt and asks the resulting builder whether
 * its configuration (no provider/model pinned, default sampling) is
 * supported by any registered, configured provider. Per the dev note,
 * this is deterministic — no API calls fired.
 *
 * Returns false when:
 *   - WP < 7.0 (and no wp-ai-client backport plugin)
 *   - AI Client present but no provider plugin installed (e.g. Anthropic provider missing)
 *   - Provider plugin present but no API key configured in Settings > Connectors
 *
 * @since v2.5.0 — replaces wp_has_ai_client() gate that returned true even
 * when no provider was configured (causing AI buttons to render then 503).
 * @since v3.7.1 — removed `method_exists()` guard that always returned false.
 * Prompt_Builder dispatches snake_case methods via `__call` magic, which
 * `method_exists()` cannot detect (only `is_callable()` can). The try/catch
 * already handles a missing method (BadMethodCallException → return false),
 * so the guard's only effect was to unconditionally disable ALL SN AI
 * features since v2.5.0. Verified against wp-ai-client trunk:
 *   https://github.com/WordPress/wp-ai-client/blob/trunk/includes/Builders/Prompt_Builder.php
 *
 * @return bool
 */
function snt_ai_can_text_generate() {
	$cache = &snt_ai_availability_cache();
	if ( null !== $cache ) {
		return $cache;
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		$cache = false;
		return $cache;
	}

	try {
		$builder = wp_ai_client_prompt( 'check' );
		if ( ! is_object( $builder ) ) {
			$cache = false;
			return $cache;
		}
		$cache = (bool) $builder->is_supported_for_text_generation();
		return $cache;
	} catch ( \Throwable $e ) {
		// v3.7.6: server-log surfacing per v3.7.1 lesson. The bug-of-record
		// for SN AI features (6 months of silently no-op'ing in v2.5.0–v3.7.0)
		// was a silent guard. Even though this catch returns false correctly
		// from the v3.7.1 fix, runtime exceptions deserve a log trail so future
		// regressions surface in PHP error log instead of vanishing.
		//
		// v6.39.2: caching the false below means this catch (and its error_log)
		// fires AT MOST ONCE per request even on a persistently broken provider,
		// where pre-cache it spammed the log on all 15-23 admin call sites.
		error_log( 'snt_ai_can_text_generate exception: ' . $e->getMessage() );
		$cache = false;
		return $cache;
	}
}
