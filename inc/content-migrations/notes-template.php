<?php
/**
 * Signal & Noise — content migration: clearing the Notes template override.
 *
 * Split out of inc/content-migrations.php in v12.21.3, which had grown to
 * 1,442 lines. These are SPENT one-shot seeds: each burns a sentinel option and
 * never runs again, and the whole set sits behind the master sentinel
 * SN_CONTENT_MIGRATIONS_MASTER_OPT.
 *
 * Nothing about the contract changed. sn_content_migrations_registry() still
 * reaches these BY NAME, which is why the move is invisible to the runner — and
 * why a DROPPED function would be silent rather than fatal (the runner guards
 * each call with function_exists()). tests/content-migrations-registry-coverage.php
 * is what turns that silence into a build failure.
 *
 * Registered migrations: sn_migrate_clear_notes_template_override
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time migration that removes any wp_template database override
 * of the `page-notes` template for this theme.
 *
 * Background: WordPress 6.x allows admins to edit block-theme
 * templates via the Site Editor (Appearance → Editor). When that
 * happens, WP creates a `wp_template` custom post that OVERRIDES
 * the .html file in the theme directory. After the override exists,
 * the file becomes irrelevant for template resolution — WP always
 * serves the DB version, even across theme updates. This is by
 * design (so admin edits aren't lost when a theme updates) but it's
 * surprising when the theme author updates a template file expecting
 * the change to take effect and instead the DB override silently
 * keeps serving the old version.
 *
 * That's exactly what happened with the `/notes` two-pillar-card
 * update in commit cbe3ee5: the theme file changed, the deploy
 * worked, but a DB override (created at some earlier point — possibly
 * just by opening the Site Editor on this template) kept WP serving
 * the prior single-card layout.
 *
 * Fix: delete any wp_template post that overrides `page-notes` for
 * this theme. WP then falls back to the theme file, which carries
 * the latest content. Future admin edits via Site Editor would
 * re-create a DB record — this migration is one-shot and won't
 * interfere with future intentional customizations (a new flag would
 * be required to clear those, by design).
 *
 * Idempotent: gated by SN_NOTES_TPL_OVERRIDE_CLEARED_OPT, runs at
 * most once per install. Defensive on `wp_template` post type
 * existence (some WP setups may not have block-theme support
 * registered when this fires).
 */
function sn_migrate_clear_notes_template_override() {
	if ( get_option( SN_NOTES_TPL_OVERRIDE_CLEARED_OPT ) ) {
		return;
	}

	if ( ! post_type_exists( 'wp_template' ) ) {
		// Block-theme support not registered yet; mark done so we
		// don't keep retrying. If WP later registers it on a future
		// admin pageload, the migration won't undo whatever state
		// exists then — but the admin would have to manually clear
		// any override via Site Editor anyway.
		update_option( SN_NOTES_TPL_OVERRIDE_CLEARED_OPT, time(), true );
		return;
	}

	$template_ids = get_posts( array(
		'post_type'      => 'wp_template',
		'post_status'    => 'any',
		'name'           => 'page-notes',
		'tax_query'      => array(
			array(
				'taxonomy' => 'wp_theme',
				'field'    => 'name',
				'terms'    => 'signal-and-noise',
			),
		),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );

	foreach ( $template_ids as $template_id ) {
		// Force-delete (skip trash) — block templates aren't useful
		// in trash and would just clutter Pages → Trash.
		wp_delete_post( (int) $template_id, true );
	}

	update_option( SN_NOTES_TPL_OVERRIDE_CLEARED_OPT, time(), true );
}
