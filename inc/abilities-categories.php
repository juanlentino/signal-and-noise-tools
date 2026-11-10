<?php
/**
 * Signal & Noise Tools — Abilities API category registrations.
 *
 * Extracted from inc/abilities-registration.php by the v4.1.3 split
 * (audit B-11). Registers the SN-owned ability categories on the
 * `wp_abilities_api_categories_init` action so subsequent ability
 * registrations (in the per-feature inc/abilities-*.php files) can
 * cite a registered category. (5 since v4.1.3; `tools` added v4.6.0;
 * `analytics` added to stop the WP 6.9.0 _doing_it_wrong for
 * inc/abilities-analytics.php's 2 abilities — see below.)
 *
 * WP_Abilities_Registry::register() checks `wp_has_ability_category()`. If
 * the category is not registered it calls _doing_it_wrong() and returns null,
 * so the ability is not registered. This file MUST therefore be required
 * before any of the per-feature abilities files, which the orchestrator
 * inc/abilities-registration.php guarantees.
 *
 * X-02 (audit, v4.1.1): every category registration is guarded by
 * `wp_has_ability_category()`. The theme also registers `content`,
 * `diagnostics`, and `ai-generation` (with its own guards). wp-settings.php
 * loads active plugins before the theme, so at equal priority this callback
 * is added first, runs first, and the plugin's category metadata is the one
 * registered (except `content`, see below). The theme, with its own guard, then skips the slug. Without guards on both
 * sides the second registration would fire `_doing_it_wrong` on every
 * request on debug installs.
 *
 * `content` is shared with the official AI plugin (1.5.0), which registers
 * the same slug at priority 11 behind the same guard, and core may register
 * it at 10 (core's own category hook runs at 10). S&N registers `content` in
 * its own callback at priority 20, so another registrant's wording wins and
 * S&N's is the fallback. The other five stay at 10.
 *
 * @package SignalNoiseTools
 * @since 4.1.3 (extracted from 2.0.4)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_categories_init', function() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'maintenance' ) ) {
		wp_register_ability_category( 'maintenance', array(
			'label'       => 'Maintenance',
			'description' => 'Cache + template-override + update-detection housekeeping operations.',
		) );
	}

	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'diagnostics' ) ) {
		wp_register_ability_category( 'diagnostics', array(
			'label'       => 'Diagnostics',
			'description' => 'Read-only inspection of the theme + plugin pair\'s state.',
		) );
	}

	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'ai-generation' ) ) {
		wp_register_ability_category( 'ai-generation', array(
			'label'       => 'AI Generation',
			'description' => 'AI Client-backed content generation (meta descriptions, OG card titles, excerpts).',
		) );
	}

	// v4.6.0: 'tools' backs the deterministic/structural site-tool abilities —
	// the 4 block-migrations abilities and the 2 pattern-adoption abilities
	// cite it. Without this registration the registry's wp_has_ability_category()
	// check fails: _doing_it_wrong() fires and wp_register_ability() returns null.
	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'tools' ) ) {
		wp_register_ability_category( 'tools', array(
			'label'       => 'Tools',
			'description' => 'Structural/deterministic site tools — block migrations, pattern adoption.',
		) );
	}

	// 'analytics' backs the 2 read-only abilities in inc/abilities-analytics.php
	// (get-analytics-summary, get-analytics-events) — that file is required
	// directly from signal-and-noise-tools.php rather than through this
	// orchestrator's require list, which is how this registration got missed.
	// WP 6.9.0 added a _doing_it_wrong in WP_Abilities_Registry::register()
	// when an ability cites a category that was never registered; both
	// analytics abilities were firing it on every request until now.
	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'analytics' ) ) {
		wp_register_ability_category( 'analytics', array(
			'label'       => 'Analytics',
			'description' => 'Read-only analytics reads (range totals, custom events) for AI agents.',
		) );
	}
} );

// 'content' is a generic slug other registrants share, so S&N's is the
// fallback: priority 20 runs after the AI plugin (11) and core (10), and the
// guard skips it when either already registered the slug.
add_action( 'wp_abilities_api_categories_init', function() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}
	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'content' ) ) {
		wp_register_ability_category( 'content', array(
			'label'       => 'Content',
			'description' => 'Per-post content artifacts (OG cards, schema, etc.).',
		) );
	}
}, 20 );
