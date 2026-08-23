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
require_once __DIR__ . '/content-migrations/excerpts.php';
require_once __DIR__ . '/content-migrations/notes-template.php';

// ── BODY LOADERS ─────────────────────────────────────────────────












// ── MIGRATIONS (one-shot, idempotent per SN_*_MIGR_OPT flag) ───────
























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
