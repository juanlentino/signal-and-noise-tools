<?php
/**
 * Signal & Noise Tools — legacy REST route deprecation notices.
 *
 * The deprecation ladder for the Ability-replaced legacy REST routes: each legacy
 * route emits _deprecated_function() pointing integrators at the canonical
 * Abilities run-path. This OPENS the warning window — v7.0.0 removes the routes
 * that complete the window with no surviving caller. The full classified set
 * (deprecate-now / blocked / keep) was produced by the v7 deprecation audit.
 *
 * CRITICAL PLACEMENT RULE: the notice fires at the REST ENTRY POINT only (the
 * route handler function body or the route's callback closure), NEVER inside the
 * shared snt_*_impl() helpers. The Abilities run-path calls those same impls and
 * must stay warning-free — the Ability is the REPLACEMENT, not the deprecated
 * thing. tests/rest-deprecations.php guards this (every notice call lives in the
 * rest_api_init registration block, after the impls).
 *
 * Production-silent: _deprecated_function() only triggers under WP_DEBUG, so
 * integrators/devs see the migration hint while production users never do.
 *
 * @package SignalNoiseTools
 * @since 6.54.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emit a deprecation notice for a legacy REST route, pointing at its Ability.
 *
 * @param string $route        The legacy REST path, e.g. /signal-noise/v1/ai/alt-suggest.
 * @param string $ability_slug The replacement Ability slug, e.g. signal-noise/ai-alt-suggest.
 * @param string $version      The plugin version the route was deprecated in. Defaults to
 *                             6.54.0 (the original pass). v6.56.0 deprecates the routes whose
 *                             JS callers were migrated to the run-path in v6.55.0, so those
 *                             pass '6.56.0' to read the accurate version.
 */
function snt_rest_deprecated_notice( $route, $ability_slug, $version = '6.54.0' ) {
	_deprecated_function(
		esc_html( 'REST route ' . $route ),
		esc_html( $version ),
		esc_html( 'the Abilities run-path /wp-abilities/v1/abilities/' . $ability_slug . '/run' )
	);
}
