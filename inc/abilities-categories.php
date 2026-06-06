<?php
/**
 * Signal & Noise Tools — Abilities API category registrations.
 *
 * Extracted from inc/abilities-registration.php by the v4.1.3 split
 * (audit B-11). Registers the SN-owned ability categories on the
 * `wp_abilities_api_categories_init` action so subsequent ability
 * registrations (in the per-feature inc/abilities-*.php files) can
 * cite a registered category. (5 since v4.1.3; `tools` added v4.6.0.)
 *
 * Per upstream source, the registry checks `wp_has_ability_category()`
 * and silently bails on `wp_register_ability()` if the category isn't
 * found — so this file MUST be required before any of the per-feature
 * abilities files, which the orchestrator inc/abilities-registration.php
 * guarantees.
 *
 * X-02 (audit, v4.1.1): every category registration is guarded by
 * `wp_has_ability_category()`. The theme also registers `content`,
 * `diagnostics`, and `ai-generation` (with its own guards) — WP loads
 * themes before plugins, so without these guards the plugin's second
 * registration would fire `_doing_it_wrong` on every request on debug
 * installs. The guards make registrations idempotent and preserve the
 * theme's category metadata as canonical when both register.
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

	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'content' ) ) {
		wp_register_ability_category( 'content', array(
			'label'       => 'Content',
			'description' => 'Per-post content artifacts (OG cards, schema, etc.).',
		) );
	}

	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'diagnostics' ) ) {
		wp_register_ability_category( 'diagnostics', array(
			'label'       => 'Diagnostics',
			'description' => 'Read-only inspection of the theme + plugin pair\'s state.',
		) );
	}

	// v2.5.0: 2 new categories ahead of registering 7 new abilities.
	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'updates' ) ) {
		wp_register_ability_category( 'updates', array(
			'label'       => 'Updates',
			'description' => 'Theme + plugin update detection + force-check.',
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
	// check silently bails on those wp_register_ability() calls in real WP.
	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'tools' ) ) {
		wp_register_ability_category( 'tools', array(
			'label'       => 'Tools',
			'description' => 'Structural/deterministic site tools — block migrations, pattern adoption.',
		) );
	}
} );
