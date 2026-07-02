<?php
/**
 * Signal & Noise Tools — ability deprecation notices (v7.7.0 ladder, step 1).
 *
 * The v7.7.0 consolidation deprecates nine abilities in favor of consolidated
 * replacements (see CHANGELOG v7.7.0 for the full mapping). Each deprecated
 * ability's execute wrapper emits this notice, pointing integrators at the
 * canonical replacement — the same ladder shape the legacy REST routes walked
 * (deprecate v6.54.0 → remove v7.0.0). Removal of these nine lands in v8.0.0.
 *
 * CRITICAL PLACEMENT RULE (inherited from inc/rest-deprecations.php, v6.54.0):
 * the notice fires at the deprecated ability's EXECUTE WRAPPER only, NEVER
 * inside a shared snt_*_impl() helper. The replacement abilities call those
 * same impls and must stay warning-free — the replacement is the canonical
 * path, not the deprecated thing. tests/abilities-deprecations.php Group D
 * guards this behaviorally.
 *
 * Production-silent: _deprecated_function() only triggers under WP_DEBUG, so
 * agents/devs see the migration hint while production users never do.
 *
 * @package SignalNoiseTools
 * @since 7.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emit a deprecation notice for a v7.7.0-deprecated ability.
 *
 * @param string $ability_slug     The deprecated ability, e.g. signal-noise/full-reset.
 * @param string $replacement_hint Human/agent-readable replacement, e.g.
 *                                 'signal-noise/purge-all-caches with include_template_overrides=true'.
 */
function snt_ability_deprecated_notice( $ability_slug, $replacement_hint ) {
	_deprecated_function(
		esc_html( 'Ability ' . $ability_slug ),
		'7.7.0',
		esc_html( $replacement_hint )
	);
}
