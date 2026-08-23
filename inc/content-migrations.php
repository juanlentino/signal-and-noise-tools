<?php
/**
 * Signal & Noise Tools — content seed migrations.
 *
 * One-shot DB seed scripts for the Provenance pillar and Notes
 * content surface. Each migration is gated by an SN_*_MIGR_OPT
 * option flag (defined in content-surfaces.php). Migrations run
 * exactly once per environment; idempotent re-runs are no-ops.
 *
 * v9.81.0: the whole set is SPENT on the live site, so the individual
 * admin_init hooks collapsed behind ONE master sentinel — see
 * sn_run_content_migrations() at the bottom of this file. The live
 * Now/Uses page-sync engine that used to share this file moved verbatim
 * to inc/page-sync-engine.php.
 *
 * Body loaders read HTML from inc/seed-content/ — moved from theme
 * to plugin alongside this file in Phase 3.
 *
 * Moved from theme inc/notes-and-provenance.php in Phase 3
 * (theme v8.4.0 / plugin v1.3.0, 2026-05-16). Original ordering preserved.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Absolute path to a bundled seed-content file.
 *
 * The body loaders used to build this with a bare __DIR__, which is position-
 * DEPENDENT: moving a loader from inc/ into inc/content-migrations/ silently
 * changes the path, file_exists() goes false, and the loader returns '' — an
 * empty page body, with no error raised anywhere. That happened during the
 * v12.21.3 split and survived a full green sweep, because no suite asserted a
 * loader returns content; it was caught only when a body-content assertion in
 * tests/provenance-verify-page.php failed one subject later.
 *
 * Resolving from THIS file, which stays in inc/, makes every loader safe to move
 * to any depth. tests/content-migrations-registry-coverage.php now resolves every
 * seed reference in the layer against its real directory and asserts the file is
 * on disk, so the silent-empty case cannot return.
 *
 * @param string $name Seed file name, e.g. 'about-body.html'.
 * @return string Absolute path (not guaranteed to exist).
 */
function sn_content_seed_file( $name ) {
	return __DIR__ . '/seed-content/' . $name;
}

// The migrations live one directory down, one file per subject. This file
// stays: it is required BY PATH from the plugin bootstrap and from nine test
// suites, and it keeps the SPINE — the registry, the master runner, the master
// sentinel const, and the single admin_init registration.
//
// __DIR__ rather than SNT_PATH: several suites require this file without the
// plugin bootstrap, so that constant is not guaranteed to be defined.
require_once __DIR__ . '/content-migrations/provenance-body.php';
require_once __DIR__ . '/content-migrations/provenance-readtimes.php';
require_once __DIR__ . '/content-migrations/provenance-split.php';
require_once __DIR__ . '/content-migrations/provenance-cards.php';
require_once __DIR__ . '/content-migrations/verify-page.php';
require_once __DIR__ . '/content-migrations/as-substrate.php';
require_once __DIR__ . '/content-migrations/over-detection.php';
require_once __DIR__ . '/content-migrations/about.php';
require_once __DIR__ . '/content-migrations/contact.php';
require_once __DIR__ . '/content-migrations/services.php';
require_once __DIR__ . '/content-migrations/resume.php';
require_once __DIR__ . '/content-migrations/music.php';
require_once __DIR__ . '/content-migrations/now-uses.php';
require_once __DIR__ . '/content-migrations/accessibility.php';
require_once __DIR__ . '/content-migrations/personal.php';

// ── BODY LOADERS ─────────────────────────────────────────────────












// ── MIGRATIONS (one-shot, idempotent per SN_*_MIGR_OPT flag) ───────


/**
 * Back-fill the native Excerpt on the five theme-authored Pages whose create
 * paths seed one (sn_seed_page_excerpts()) but which predate that excerpt on
 * production — the /notes index and the four provenance-family Pages. The
 * editorial Pages (about/contact/services/resume/music/now/uses/accessibility/
 * personal) each got their own body+excerpt back-fill; these five never did, so
 * on long-lived installs they ship descriptionless and the SEO layer has no
 * summary to emit (surfaced by the Analytics "N pages ship without a meta
 * description" recommendation).
 *
 * Runs once, guarded by a dedicated flag. Only writes an Excerpt that is
 * genuinely empty, so an owner-written summary is never clobbered and a page
 * that already carries one is a no-op. Pages not seeded yet are skipped — a
 * fresh install creates them WITH the excerpt via sn_ensure_* — and the flag is
 * still set so we stop scanning every admin_init.
 */
function sn_migrate_seed_page_excerpts() {
	if ( get_option( SN_SEED_EXCERPTS_BACKFILL_OPT ) ) {
		return;
	}

	foreach ( sn_seed_page_excerpts() as $path => $excerpt ) {
		$page = get_page_by_path( $path );
		if ( ! is_object( $page ) ) {
			continue;
		}
		if ( '' !== trim( (string) ( $page->post_excerpt ?? '' ) ) ) {
			continue;
		}
		wp_update_post( array(
			'ID'           => (int) $page->ID,
			'post_excerpt' => $excerpt,
		) );
	}

	update_option( SN_SEED_EXCERPTS_BACKFILL_OPT, time(), true );
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






// ── PHASE 2c: /accessibility + /contact/personal (frozen-seed prose pages) ──
//
// Unlike /now and /about/uses (edited from Content text boxes), these two prose
// pages have no editor — their content lived inline in the theme's render files.
// So they use the Phase-1 frozen-seed model: a one-time migration CREATES the
// Page from a frozen *-body.html seed, seeds a native Excerpt, and never runs
// again. After the flip they are edited in the block editor (Gutenberg). No text
// box means no empty-box failure mode (the 2b /uses regression does not apply).





// ── MASTER SENTINEL (v9.81.0) ────────────────────────────────────────
//
// Every migration above is SPENT on the live site — each has burned its
// individual flag long ago. Instead of 24 admin_init hooks re-checking 24
// options on every wp-admin pageload, ONE master sentinel short-circuits
// the whole set. Each spent body stays callable and keeps its own flag, so
// a fresh install still runs the full ordered set idempotently; the master
// flag is only stamped once every individual flag is present (several
// migrations are retry-safe and deliberately withhold their flag until
// their preconditions land — the master respects that and keeps retrying).

const SN_CONTENT_MIGRATIONS_MASTER_OPT = 'sn_content_migrations_complete_v1';

/**
 * The spent one-shot migrations, in their original hook order, mapped to
 * the per-migration sentinel option each one burns. The pillar meta seed
 * (inc/pillar-meta-seed.php, v9.79.1) joins the set under the same master.
 *
 * @return array<string, string> callback => sentinel option name.
 */
function sn_content_migrations_registry() {
	return array(
		'sn_migrate_provenance_body'                    => SN_PROV_BODY_MIGRATED_OPT,
		'sn_migrate_seed_page_excerpts'                 => SN_SEED_EXCERPTS_BACKFILL_OPT,
		'sn_migrate_about_body'                         => SN_ABOUT_BODY_MIGRATED_OPT,
		'sn_migrate_contact_body'                       => SN_CONTACT_BODY_MIGRATED_OPT,
		'sn_migrate_services_body'                      => SN_SERVICES_BODY_MIGRATED_OPT,
		'sn_migrate_resume_body'                        => SN_RESUME_BODY_MERGED_OPT,
		'sn_migrate_music_body'                         => SN_MUSIC_BODY_MERGED_OPT,
		'sn_migrate_provenance_refinements'             => SN_PROV_REFINE_MIGR_OPT,
		'sn_migrate_provenance_byline_reading_time'     => SN_PROV_BYLINE_RT_MIGR_OPT,
		'sn_migrate_provenance_split'                   => SN_PROV_SPLIT_MIGR_OPT,
		'sn_migrate_as_substrate_seed'                  => SN_AS_SUBSTRATE_SEED_OPT,
		'sn_migrate_verify_page_seed'                   => SN_PROV_VERIFY_PAGE_MIGR_OPT,
		'sn_migrate_provenance_card2_longform'          => SN_PROV_CARD2_LF_MIGR_OPT,
		'sn_migrate_provenance_card_readtimes_dynamic'  => SN_PROV_RT_DYNAMIC_OPT,
		'sn_migrate_provenance_catalog_numbers'         => SN_PROV_CATALOG_NUMBERS_OPT,
		'sn_migrate_as_substrate_post_date_displaytype' => SN_AS_DATE_DISPLAYTYPE_OPT,
		'sn_migrate_over_detection_eyebrow_dynamic'     => SN_OD_EYEBROW_DYN_OPT,
		'sn_migrate_clear_notes_template_override'      => SN_NOTES_TPL_OVERRIDE_CLEARED_OPT,
		'sn_migrate_now_page'                           => SN_NOW_PAGE_MIGRATED_OPT,
		'sn_migrate_uses_page'                          => SN_USES_PAGE_MIGRATED_OPT,
		'sn_migrate_now_uses_dossier_repair'            => SN_NOW_USES_DOSSIER_REPAIR_OPT,
		'sn_migrate_accessibility_page'                 => SN_A11Y_PAGE_MIGRATED_OPT,
		'sn_migrate_personal_page'                      => SN_PERSONAL_PAGE_MIGRATED_OPT,
		'sn_pillar_meta_seed'                          => 'sn_pillar_meta_seeded',
	);
}

/**
 * The one admin_init entry point for the whole spent set. On a site where
 * the set has completed (master flag present) this is a single option read.
 * Otherwise it runs each still-unflagged migration (each body re-checks its
 * own flag, preserving per-migration idempotence), then stamps the master
 * only when every individual flag is present.
 */
function sn_run_content_migrations() {
	if ( get_option( SN_CONTENT_MIGRATIONS_MASTER_OPT ) ) {
		return;
	}

	$complete = true;
	foreach ( sn_content_migrations_registry() as $callback => $flag ) {
		if ( ! get_option( $flag ) && function_exists( $callback ) ) {
			$callback();
		}
		if ( ! get_option( $flag ) ) {
			$complete = false; // Retry-safe migration still waiting — no master.
		}
	}

	if ( $complete ) {
		update_option( SN_CONTENT_MIGRATIONS_MASTER_OPT, time(), false );
	}
}
add_action( 'admin_init', 'sn_run_content_migrations' );
