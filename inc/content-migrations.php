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

// ── BODY LOADERS ─────────────────────────────────────────────────







/**
 * Load the seeded /services body markup from disk. Mirrors
 * sn_load_about_body() — same empty-string fallback semantics.
 */
function sn_load_services_body() {
	$body_file = sn_content_seed_file( 'services-body.html' );
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded /resume hero + PDF-viewer-open markup (the part of the
 * page-resume.html template that sits ABOVE wp:post-content). Mirrors
 * sn_load_about_body() — same empty-string fallback semantics.
 */
function sn_load_resume_above() {
	$body_file = sn_content_seed_file( 'resume-above.html' );
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded /resume PDF-viewer-close markup (the part of the
 * page-resume.html template that sits BELOW wp:post-content). Mirrors
 * sn_load_resume_above() — same empty-string fallback semantics.
 */
function sn_load_resume_below() {
	$body_file = sn_content_seed_file( 'resume-below.html' );
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded /music hero + Spotify-embeds-open markup (the part of the
 * page-music.html template that sits ABOVE wp:post-content). Mirrors
 * sn_load_about_body() — same empty-string fallback semantics.
 */
function sn_load_music_above() {
	$body_file = sn_content_seed_file( 'music-above.html' );
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded /music discography-shortcode + Spotify-embeds-close +
 * Muso-credits markup (the part of the page-music.html template that sits
 * BELOW wp:post-content). Mirrors sn_load_music_above() — same empty-string
 * fallback semantics.
 */
function sn_load_music_below() {
	$body_file = sn_content_seed_file( 'music-below.html' );
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

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
 * One-time migration flipping /services from file-authored to CMS-authored:
 * seeds the existing (empty) Services Page's body from the seed file, plus a
 * native Excerpt so the SEO layer reads a real excerpt instead of the theme's
 * hardcoded description map. Same safety as sn_migrate_about_body(): runs
 * once, only writes when the field is genuinely empty.
 */
function sn_migrate_services_body() {
	if ( get_option( SN_SERVICES_BODY_MIGRATED_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_SERVICES_SLUG );
	if ( ! $page ) {
		// Page doesn't exist yet — the seed flow creates it later. Mark
		// migrated so we don't keep checking.
		update_option( SN_SERVICES_BODY_MIGRATED_OPT, time(), true );
		return;
	}

	if ( '' !== trim( (string) $page->post_content ) ) {
		// Body already has content — could be edits we shouldn't touch.
		update_option( SN_SERVICES_BODY_MIGRATED_OPT, time(), true );
		return;
	}

	$body = sn_load_services_body();
	if ( '' === $body ) {
		// Seed file missing — leave the Page alone, do not mark migrated
		// so we retry on next admin_init in case the file lands later.
		return;
	}

	$update = array(
		'ID'           => $page->ID,
		'post_content' => $body,
	);

	// Only seed the excerpt when it's genuinely empty — never clobber an
	// owner-written excerpt.
	if ( '' === trim( (string) $page->post_excerpt ) ) {
		$update['post_excerpt'] = 'What Juan Lentino offers: production, mixing, mastering, songwriting, plus operations, AI strategy, and artist development, in-studio at Panacea or remote.';
	}

	wp_update_post( $update );
	update_option( SN_SERVICES_BODY_MIGRATED_OPT, time(), true );
}

/**
 * One-time migration flipping /resume from template-authored to
 * CMS-authored: the live Page's post_content today holds ONLY the PDF
 * viewer's post-content anchor content (the theme template renders the
 * hero prose + PDF-viewer wrapper AROUND it). This migration merges that
 * surrounding prose INTO post_content — above + existing + below — so the
 * page keeps rendering identically while the prose becomes editable from
 * Pages → Resume. The theme separately slims page-resume.html to a bare
 * frame once this has shipped.
 *
 * Safety:
 *   - Runs at most once per site (guarded by SN_RESUME_BODY_MERGED_OPT).
 *   - Guards on a content sentinel (the hero eyebrow text) so a page whose
 *     body already contains the merged prose is never merged twice.
 *   - If either seed file is missing, bails WITHOUT setting the flag so a
 *     future admin_init retries once the seed lands.
 *   - Never overwrites an existing excerpt — only seeds one when empty.
 */
function sn_migrate_resume_body() {
	if ( get_option( SN_RESUME_BODY_MERGED_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_RESUME_SLUG );
	if ( ! $page ) {
		// Page doesn't exist yet — nothing to merge. Mark migrated so we
		// don't keep checking.
		update_option( SN_RESUME_BODY_MERGED_OPT, time(), true );
		return;
	}

	if ( false !== strpos( (string) $page->post_content, 'Dossier · Background' ) ) {
		// Already merged — the hero sentinel is present. Never double-merge.
		update_option( SN_RESUME_BODY_MERGED_OPT, time(), true );
		return;
	}

	$above = sn_load_resume_above();
	$below = sn_load_resume_below();
	if ( '' === $above || '' === $below ) {
		// Seed file missing — leave the Page alone, do not mark migrated
		// so we retry on next admin_init in case the file lands later.
		return;
	}

	$merged = $above . "\n\n" . (string) $page->post_content . "\n\n" . $below;

	$update = array(
		'ID'           => $page->ID,
		'post_content' => $merged,
	);

	// Only seed the excerpt when it's genuinely empty — never clobber an
	// owner-written excerpt.
	if ( '' === trim( (string) $page->post_excerpt ) ) {
		$update['post_excerpt'] = '20+ years building studios, developing artists, and scaling creative businesses across the U.S. and Latin America: production, strategy, and mentorship. GRAMMY and Latin GRAMMY voting member.';
	}

	wp_update_post( $update );
	update_option( SN_RESUME_BODY_MERGED_OPT, time(), true );
}

/**
 * One-time migration flipping /music from template-authored to
 * CMS-authored: the live Page's post_content today holds ONLY the
 * featured-player shortcode content (the theme template renders the hero
 * prose + Spotify-embeds wrapper + discography shortcode + Muso-credits
 * section AROUND it). Mirrors sn_migrate_resume_body() — same merge and
 * safety semantics, gated by SN_MUSIC_BODY_MERGED_OPT and the hero
 * eyebrow sentinel.
 */
function sn_migrate_music_body() {
	if ( get_option( SN_MUSIC_BODY_MERGED_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_MUSIC_SLUG );
	if ( ! $page ) {
		// Page doesn't exist yet — nothing to merge. Mark migrated so we
		// don't keep checking.
		update_option( SN_MUSIC_BODY_MERGED_OPT, time(), true );
		return;
	}

	if ( false !== strpos( (string) $page->post_content, 'Catalog · Discography' ) ) {
		// Already merged — the hero sentinel is present. Never double-merge.
		update_option( SN_MUSIC_BODY_MERGED_OPT, time(), true );
		return;
	}

	$above = sn_load_music_above();
	$below = sn_load_music_below();
	if ( '' === $above || '' === $below ) {
		// Seed file missing — leave the Page alone, do not mark migrated
		// so we retry on next admin_init in case the file lands later.
		return;
	}

	$merged = $above . "\n\n" . (string) $page->post_content . "\n\n" . $below;

	$update = array(
		'ID'           => $page->ID,
		'post_content' => $merged,
	);

	// Only seed the excerpt when it's genuinely empty — never clobber an
	// owner-written excerpt.
	if ( '' === trim( (string) $page->post_excerpt ) ) {
		$update['post_excerpt'] = 'Selected discography: releases produced, mixed, and engineered by Juan Lentino, with credits and streaming links.';
	}

	wp_update_post( $update );
	update_option( SN_MUSIC_BODY_MERGED_OPT, time(), true );
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

/**
 * One-time migration: flip /now from a postless virtual route to a real CMS
 * Page, populating it from the current Content → Now Page text box. Ongoing
 * edits flow through sn_now_sync_page() on save; this performs the initial
 * carry-over.
 *
 * Retry-safe: does nothing (and does NOT set the flag) until the hero seed and
 * real text-box content are both present. Never clobbers an existing,
 * owner-edited Page.
 */
function sn_migrate_now_page() {
	if ( get_option( SN_NOW_PAGE_MIGRATED_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_NOW_SLUG );

	// Existing, owner-edited Page — never touch it, but stop checking.
	if ( $page && '' !== trim( (string) $page->post_content ) ) {
		update_option( SN_NOW_PAGE_MIGRATED_OPT, time(), true );
		return;
	}

	$body = function_exists( 'sn_now_page_sections' ) ? sn_now_build_body( sn_now_page_sections() ) : '';

	// Retry-safe: wait for real text-box content before creating and flagging.
	if ( '' === $body ) {
		return;
	}

	sn_now_upsert_page( $body );
	update_option( SN_NOW_PAGE_MIGRATED_OPT, time(), true );
}


/**
 * One-time migration: flip /about/uses from a postless virtual route to a real
 * CMS child Page, populating it from the current Content → Uses Page text box.
 * Ongoing edits flow through sn_uses_sync_page() on save; this performs the
 * initial carry-over.
 *
 * Retry-safe: does nothing (and does NOT set the flag) until the text box has
 * content AND the About parent Page exists. Never clobbers an existing,
 * owner-edited Page.
 */
function sn_migrate_uses_page() {
	if ( get_option( SN_USES_PAGE_MIGRATED_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_ABOUT_SLUG . '/' . SN_USES_SLUG );

	// Existing, owner-edited Page — never touch it, but stop checking.
	if ( $page && '' !== trim( (string) $page->post_content ) ) {
		update_option( SN_USES_PAGE_MIGRATED_OPT, time(), true );
		return;
	}

	$body = sn_uses_current_body();
	if ( '' === $body ) {
		return; // Text box not ready — retry.
	}

	if ( 0 === sn_uses_upsert_page( $body ) ) {
		return; // About parent not ready — retry.
	}

	update_option( SN_USES_PAGE_MIGRATED_OPT, time(), true );
}

/**
 * Load the frozen /uses default gear list (## Label / - name | note text). Used
 * to re-seed the Uses text box on installs where the box was never saved and the
 * content had lived only in the theme's (now-removed) uses-data.php default.
 *
 * @return string
 */
function sn_load_uses_default() {
	$f = sn_content_seed_file( 'uses-default.txt' );
	return file_exists( $f ) ? (string) file_get_contents( $f ) : '';
}

/**
 * v9.20.1 one-time repair for two Phase-2-flip regressions:
 *   1. /now Pages created by v9.19.0 hold generic core-block content; v9.20.0's
 *      never-clobber migration guard skipped upgrading them to the dossier
 *      markup. Force a regenerate from the (populated) text box.
 *   2. /about/uses was never created where the Uses text box was empty (its
 *      content had lived in the theme's removed uses-data.php default). Re-seed
 *      the box from the frozen default, which creates the Page via the save sync.
 *
 * Idempotent (own flag). Safe: the /now sync is a full regenerate from the
 * canonical text box (a no-op when the box is empty, so it never blanks a page),
 * and the Uses box is only seeded when it is genuinely empty (never clobbers a
 * saved box).
 */
function sn_migrate_now_uses_dossier_repair() {
	if ( get_option( SN_NOW_USES_DOSSIER_REPAIR_OPT ) ) {
		return;
	}

	// 1. /now: regenerate from the box (upgrades v9.19.0 core-block content to
	//    the dossier markup; a no-op when the box is empty, never blanks).
	if ( function_exists( 'sn_now_sync_page' ) ) {
		sn_now_sync_page();
	}

	// 2. /uses: seed the box from the frozen default when empty (which creates
	//    the Page via sn_uses_page_save's sync); otherwise just regenerate.
	if ( function_exists( 'sn_uses_page_get' ) && function_exists( 'sn_uses_page_save' ) ) {
		if ( null === sn_uses_page_get() ) {
			$seed = sn_load_uses_default();
			if ( '' !== trim( $seed ) ) {
				sn_uses_page_save( $seed );
			}
		} elseif ( function_exists( 'sn_uses_sync_page' ) ) {
			sn_uses_sync_page();
		}
	}

	update_option( SN_NOW_USES_DOSSIER_REPAIR_OPT, time(), true );
}

// ── PHASE 2c: /accessibility + /contact/personal (frozen-seed prose pages) ──
//
// Unlike /now and /about/uses (edited from Content text boxes), these two prose
// pages have no editor — their content lived inline in the theme's render files.
// So they use the Phase-1 frozen-seed model: a one-time migration CREATES the
// Page from a frozen *-body.html seed, seeds a native Excerpt, and never runs
// again. After the flip they are edited in the block editor (Gutenberg). No text
// box means no empty-box failure mode (the 2b /uses regression does not apply).

/**
 * Load the frozen /accessibility body markup from disk. Mirrors
 * sn_load_about_body() — same empty-string fallback semantics.
 *
 * @return string
 */
function sn_load_accessibility_body() {
	$body_file = sn_content_seed_file( 'accessibility-body.html' );
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * Load the frozen /contact/personal body markup from disk. Mirrors
 * sn_load_about_body() — same empty-string fallback semantics.
 *
 * @return string
 */
function sn_load_personal_body() {
	$body_file = sn_content_seed_file( 'personal-body.html' );
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * One-time migration: flip /accessibility from a postless virtual route to a
 * real top-level CMS Page, seeded from the frozen accessibility-body.html.
 *
 * Create-once and never-clobber: creates the Page when absent, seeds an existing
 * EMPTY Page in place, and never touches an owner-edited (non-empty) Page. A
 * native Excerpt is seeded only when the field is empty (the SEO layer reads it
 * once the route-meta handler is retired theme-side). Retry-safe: does nothing
 * and does NOT flag while the seed file is missing.
 */
function sn_migrate_accessibility_page() {
	if ( get_option( SN_A11Y_PAGE_MIGRATED_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_A11Y_SLUG );

	// Existing, owner-edited Page — never touch it, but stop checking.
	if ( $page && '' !== trim( (string) $page->post_content ) ) {
		update_option( SN_A11Y_PAGE_MIGRATED_OPT, time(), true );
		return;
	}

	$body = sn_load_accessibility_body();
	if ( '' === trim( $body ) ) {
		return; // Seed file missing — retry on the next admin_init.
	}

	$excerpt = 'Accessibility statement for juanlentino.com: WCAG 2.1 AA target, measures in place, known limitations, and how to report problems.';

	if ( $page ) {
		// Page exists but is empty — seed it in place.
		$update = array(
			'ID'           => $page->ID,
			'post_content' => $body,
		);
		if ( '' === trim( (string) $page->post_excerpt ) ) {
			$update['post_excerpt'] = $excerpt;
		}
		wp_update_post( $update );
	} else {
		wp_insert_post(
			array(
				'post_title'    => 'Accessibility',
				'post_name'     => SN_A11Y_SLUG,
				'post_parent'   => 0,
				'post_status'   => 'publish',
				'post_type'     => 'page',
				'post_content'  => $body,
				'post_excerpt'  => $excerpt,
				'page_template' => 'page-accessibility',
			),
			false
		);
	}

	update_option( SN_A11Y_PAGE_MIGRATED_OPT, time(), true );
}

/**
 * One-time migration: flip /contact/personal from a postless virtual route to a
 * real CHILD CMS Page (under /contact), seeded from the frozen personal-body.html.
 *
 * Same create-once/never-clobber semantics as sn_migrate_accessibility_page(),
 * plus child parenting: the Page is inserted with post_parent = the Contact Page
 * ID (mirrors sn_uses_upsert_page). Retry-safe: does nothing and does NOT flag
 * while the seed file is missing OR the Contact parent does not exist yet.
 */
function sn_migrate_personal_page() {
	if ( get_option( SN_PERSONAL_PAGE_MIGRATED_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_CONTACT_SLUG . '/' . SN_PERSONAL_SLUG );

	// Existing, owner-edited Page — never touch it, but stop checking.
	if ( $page && '' !== trim( (string) $page->post_content ) ) {
		update_option( SN_PERSONAL_PAGE_MIGRATED_OPT, time(), true );
		return;
	}

	$body = sn_load_personal_body();
	if ( '' === trim( $body ) ) {
		return; // Seed file missing — retry.
	}

	$excerpt = 'Why requests for synchronous time (coffees, calls, meetings) are a no, and the structural reason behind it.';

	if ( $page ) {
		// Child Page exists but is empty — seed it in place.
		$update = array(
			'ID'           => $page->ID,
			'post_content' => $body,
		);
		if ( '' === trim( (string) $page->post_excerpt ) ) {
			$update['post_excerpt'] = $excerpt;
		}
		wp_update_post( $update );
		update_option( SN_PERSONAL_PAGE_MIGRATED_OPT, time(), true );
		return;
	}

	$parent = get_page_by_path( SN_CONTACT_SLUG );
	if ( ! $parent ) {
		return; // Contact parent not ready — retry on the next admin_init.
	}

	wp_insert_post(
		array(
			'post_title'    => 'Personal',
			'post_name'     => SN_PERSONAL_SLUG,
			'post_parent'   => (int) $parent->ID,
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'post_content'  => $body,
			'post_excerpt'  => $excerpt,
			'page_template' => 'page-personal',
		),
		false
	);

	update_option( SN_PERSONAL_PAGE_MIGRATED_OPT, time(), true );
}

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
