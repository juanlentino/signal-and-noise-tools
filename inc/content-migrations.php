<?php
/**
 * Signal & Noise Tools — content seed migrations.
 *
 * One-shot DB seed scripts for the Provenance pillar and Notes
 * content surface. Each migration is gated by an SN_*_MIGR_OPT
 * option flag (defined in content-surfaces.php). Migrations run
 * exactly once per environment; idempotent re-runs are no-ops.
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

// ── BODY LOADERS ─────────────────────────────────────────────────

/**
 * Load the seeded Provenance body markup from disk.
 * Empty string fallback if the seed file is missing — the template will
 * just render an empty post-content area, no fatal.
 */
function sn_load_provenance_body() {
	$body_file = __DIR__ . '/seed-content/provenance-body.html';
	return file_exists( $body_file ) ? file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded long-form essay markup from disk. Mirrors
 * sn_load_provenance_body — same fallback semantics.
 */
function sn_load_over_detection_body() {
	$body_file = __DIR__ . '/seed-content/over-detection-body.html';
	return file_exists( $body_file ) ? file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded second long-form essay markup from disk. Mirrors
 * sn_load_over_detection_body — same fallback semantics.
 */
function sn_load_as_substrate_body() {
	$body_file = __DIR__ . '/seed-content/as-substrate-body.html';
	return file_exists( $body_file ) ? file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded "Verify a Note" body markup from disk. Mirrors
 * sn_load_as_substrate_body — same fallback semantics. The body carries the
 * [sn_provenance_verify] shortcode, so the_content() renders the live steps.
 */
function sn_load_verify_body() {
	$body_file = __DIR__ . '/seed-content/verify-body.html';
	return file_exists( $body_file ) ? file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded /about body markup from disk. Mirrors
 * sn_load_provenance_body — same empty-string fallback semantics.
 */
function sn_load_about_body() {
	$body_file = __DIR__ . '/seed-content/about-body.html';
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded /contact body markup from disk. Mirrors
 * sn_load_about_body() — same empty-string fallback semantics.
 */
function sn_load_contact_body() {
	$body_file = __DIR__ . '/seed-content/contact-body.html';
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded /services body markup from disk. Mirrors
 * sn_load_about_body() — same empty-string fallback semantics.
 */
function sn_load_services_body() {
	$body_file = __DIR__ . '/seed-content/services-body.html';
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded /resume hero + PDF-viewer-open markup (the part of the
 * page-resume.html template that sits ABOVE wp:post-content). Mirrors
 * sn_load_about_body() — same empty-string fallback semantics.
 */
function sn_load_resume_above() {
	$body_file = __DIR__ . '/seed-content/resume-above.html';
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded /resume PDF-viewer-close markup (the part of the
 * page-resume.html template that sits BELOW wp:post-content). Mirrors
 * sn_load_resume_above() — same empty-string fallback semantics.
 */
function sn_load_resume_below() {
	$body_file = __DIR__ . '/seed-content/resume-below.html';
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded /music hero + Spotify-embeds-open markup (the part of the
 * page-music.html template that sits ABOVE wp:post-content). Mirrors
 * sn_load_about_body() — same empty-string fallback semantics.
 */
function sn_load_music_above() {
	$body_file = __DIR__ . '/seed-content/music-above.html';
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded /music discography-shortcode + Spotify-embeds-close +
 * Muso-credits markup (the part of the page-music.html template that sits
 * BELOW wp:post-content). Mirrors sn_load_music_above() — same empty-string
 * fallback semantics.
 */
function sn_load_music_below() {
	$body_file = __DIR__ . '/seed-content/music-below.html';
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

// ── MIGRATIONS (one-shot, idempotent per SN_*_MIGR_OPT flag) ───────

/**
 * One-time migration for sites upgrading from v6.1.0 (where the
 * Provenance Page was created with an empty body and all visible content
 * lived in the template). Populates the existing Page's body from the
 * seed file so it becomes editable from Pages → Provenance.
 *
 * Safety:
 *   - Runs at most once per site (guarded by a dedicated option flag).
 *   - Only writes when the existing body is genuinely empty — never
 *     overwrites prose someone has already added.
 *   - The flag is set even on no-op paths so we don't keep checking.
 */
add_action( 'admin_init', 'sn_migrate_provenance_body' );

function sn_migrate_provenance_body() {
	if ( get_option( SN_PROV_BODY_MIGRATED_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		// Page doesn't exist yet — sn_ensure_provenance_page() will seed
		// the body when it runs. Mark migrated so we don't keep checking.
		update_option( SN_PROV_BODY_MIGRATED_OPT, time(), true );
		return;
	}

	if ( '' !== trim( $page->post_content ) ) {
		// Body already has content — could be edits we shouldn't touch.
		update_option( SN_PROV_BODY_MIGRATED_OPT, time(), true );
		return;
	}

	$body = sn_load_provenance_body();
	if ( '' === $body ) {
		// Seed file missing — leave the Page alone, do not mark migrated
		// so we retry on next admin_init in case the file lands later.
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => $body,
	) );

	update_option( SN_PROV_BODY_MIGRATED_OPT, time(), true );
}

/**
 * One-time migration flipping /about from file-authored to CMS-authored:
 * seeds the existing (empty) About Page's body from the seed file, plus a
 * native Excerpt so the SEO layer reads a real excerpt instead of the theme's
 * hardcoded description map. Same safety as sn_migrate_provenance_body():
 * runs once, only writes when the field is genuinely empty.
 */
add_action( 'admin_init', 'sn_migrate_about_body' );

function sn_migrate_about_body() {
	if ( get_option( SN_ABOUT_BODY_MIGRATED_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_ABOUT_SLUG );
	if ( ! $page ) {
		// Page doesn't exist yet — the seed flow creates it later. Mark
		// migrated so we don't keep checking.
		update_option( SN_ABOUT_BODY_MIGRATED_OPT, time(), true );
		return;
	}

	if ( '' !== trim( (string) $page->post_content ) ) {
		// Body already has content — could be edits we shouldn't touch.
		update_option( SN_ABOUT_BODY_MIGRATED_OPT, time(), true );
		return;
	}

	$body = sn_load_about_body();
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
		$update['post_excerpt'] = 'Music producer, mix engineer, and creative strategist based in Buenos Aires. The person behind the work, the studio, and the notes.';
	}

	wp_update_post( $update );
	update_option( SN_ABOUT_BODY_MIGRATED_OPT, time(), true );
}

/**
 * One-time migration flipping /contact from file-authored to CMS-authored:
 * seeds the existing (empty) Contact Page's body from the seed file, plus a
 * native Excerpt so the SEO layer reads a real excerpt instead of the theme's
 * hardcoded description map. Same safety as sn_migrate_about_body(): runs
 * once, only writes when the field is genuinely empty.
 */
add_action( 'admin_init', 'sn_migrate_contact_body' );

function sn_migrate_contact_body() {
	if ( get_option( SN_CONTACT_BODY_MIGRATED_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_CONTACT_SLUG );
	if ( ! $page ) {
		// Page doesn't exist yet — the seed flow creates it later. Mark
		// migrated so we don't keep checking.
		update_option( SN_CONTACT_BODY_MIGRATED_OPT, time(), true );
		return;
	}

	if ( '' !== trim( (string) $page->post_content ) ) {
		// Body already has content — could be edits we shouldn't touch.
		update_option( SN_CONTACT_BODY_MIGRATED_OPT, time(), true );
		return;
	}

	$body = sn_load_contact_body();
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
		$update['post_excerpt'] = 'How to reach Juan Lentino: remote mixing, mastering, and songwriting, or in-studio production at Panacea in Buenos Aires. Direct, no forms, no noise.';
	}

	wp_update_post( $update );
	update_option( SN_CONTACT_BODY_MIGRATED_OPT, time(), true );
}

/**
 * One-time migration flipping /services from file-authored to CMS-authored:
 * seeds the existing (empty) Services Page's body from the seed file, plus a
 * native Excerpt so the SEO layer reads a real excerpt instead of the theme's
 * hardcoded description map. Same safety as sn_migrate_about_body(): runs
 * once, only writes when the field is genuinely empty.
 */
add_action( 'admin_init', 'sn_migrate_services_body' );

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
add_action( 'admin_init', 'sn_migrate_resume_body' );

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
		$update['post_excerpt'] = '20+ years building studios, developing artists, and scaling creative businesses across the U.S. and Latin America — production, strategy, and mentorship. GRAMMY and Latin GRAMMY voting member.';
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
add_action( 'admin_init', 'sn_migrate_music_body' );

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
 * One-time refinements migration for the Provenance pillar:
 *
 *   1. Inject the inline TOC paragraph (between the hero and the first
 *      separator) if it isn't already present.
 *   2. Add `displayType: "modified"` to the byline's wp:post-date block
 *      so the date reads "last updated" rather than "first published" —
 *      more honest for a permanent reference essay that gets iterated on.
 *
 * Both edits are surgical, defensive, and idempotent: each is skipped
 * when the marker is missing or the change is already applied. Prose
 * paragraphs are never touched. Safe to re-run; in practice runs once
 * per site (guarded by SN_PROV_REFINE_MIGR_OPT).
 */
add_action( 'admin_init', 'sn_migrate_provenance_refinements' );

function sn_migrate_provenance_refinements() {
	if ( get_option( SN_PROV_REFINE_MIGR_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		// Page doesn't exist yet — nothing to migrate. Mark done so we
		// don't keep scanning on every admin_init.
		update_option( SN_PROV_REFINE_MIGR_OPT, time(), true );
		return;
	}

	$body     = $page->post_content;
	$original = $body;

	// 1. Inject TOC after the hero group close, before the first separator.
	if ( false === strpos( $body, 'sn-provenance-toc' ) ) {
		$hero_start = strpos( $body, '<!-- wp:group {"className":"sn-provenance-hero"' );
		if ( false !== $hero_start ) {
			$hero_close_marker = '<!-- /wp:group -->';
			$hero_close        = strpos( $body, $hero_close_marker, $hero_start );
			if ( false !== $hero_close ) {
				$insert_at = $hero_close + strlen( $hero_close_marker );
				$body      = substr( $body, 0, $insert_at )
					. "\n\n" . sn_provenance_toc_block_markup() . "\n"
					. substr( $body, $insert_at );
			}
		}
	}

	// 2. Add displayType:"modified" to the byline's wp:post-date.
	if ( false === strpos( $body, '"displayType":"modified"' ) ) {
		$body = preg_replace(
			'/<!-- wp:post-date \{"format":"F j, Y",/',
			'<!-- wp:post-date {"format":"F j, Y","displayType":"modified",',
			$body,
			1
		);
	}

	if ( $body !== $original ) {
		wp_update_post( array(
			'ID'           => $page->ID,
			'post_content' => $body,
		) );
	}

	update_option( SN_PROV_REFINE_MIGR_OPT, time(), true );
}

/**
 * One-time migration that injects the reading-time block into the
 * existing Provenance byline. Mirrors the seed file change in 6.3.1.
 *
 * Idempotent — bails if the byline already contains the reading-time
 * marker (paste-by-hand defensive). Gated by SN_PROV_BYLINE_RT_MIGR_OPT
 * so it only runs once per install.
 */
add_action( 'admin_init', 'sn_migrate_provenance_byline_reading_time' );

function sn_migrate_provenance_byline_reading_time() {
	if ( get_option( SN_PROV_BYLINE_RT_MIGR_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		update_option( SN_PROV_BYLINE_RT_MIGR_OPT, time(), true );
		return;
	}

	$body     = $page->post_content;
	$original = $body;

	// Skip if the reading-time block is already present (paste-by-hand defensive).
	if ( false === strpos( $body, 'sn-provenance-byline-reading-time' ) ) {
		// Anchor on the byline's wp:post-date opener and the next ` /-->`
		// (the tag is self-closing). strpos avoids the nested-{} pitfall
		// the regex form hits once the 6.2.6 migration adds a style object.
		$start = strpos( $body, '<!-- wp:post-date {"format":"F j, Y"' );
		if ( false !== $start ) {
			$end_marker = ' /-->';
			$end        = strpos( $body, $end_marker, $start );
			if ( false !== $end ) {
				$insert_at = $end + strlen( $end_marker );
				$body      = substr( $body, 0, $insert_at )
					. "\n\n\t" . sn_provenance_byline_reading_time_markup()
					. substr( $body, $insert_at );
			}
		}
	}

	if ( $body !== $original ) {
		wp_update_post( array(
			'ID'           => $page->ID,
			'post_content' => $body,
		) );
	}

	update_option( SN_PROV_BYLINE_RT_MIGR_OPT, time(), true );
}

/**
 * One-time migration that splits the existing /provenance pillar page
 * into a lean two-paper index (parent: /provenance) and a long-form
 * essay (child: /provenance/over-detection). The essay prose itself
 * is never edited — it's lifted verbatim from the existing live page
 * body and moved into the new child page.
 *
 * Algorithm:
 *   1. Locate the essay's hero block (`sn-provenance-hero` className) in
 *      the existing /provenance body. This anchor is stable across the
 *      seed-file shape and the prior unreleased "prepend cards" shape,
 *      so the same code handles both starting states.
 *   2. Everything from that anchor to end-of-body = the essay. Hand it
 *      to a new child page at /provenance/over-detection.
 *   3. Overwrite the parent /provenance body with the cards-only index.
 *
 * Safety:
 *   - If the hero anchor is missing (page was hand-edited away from
 *     seed shape), bail WITHOUT setting the flag, so a future run after
 *     manual recovery can complete the split.
 *   - If a /provenance/over-detection page already exists, leave its
 *     body untouched (admin may have edited prose there) — only the
 *     parent body is rewritten.
 *   - Gated by SN_PROV_SPLIT_MIGR_OPT, runs at most once per install.
 */
add_action( 'admin_init', 'sn_migrate_provenance_split' );

function sn_migrate_provenance_split() {
	if ( get_option( SN_PROV_SPLIT_MIGR_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		// Page doesn't exist yet — sn_ensure_provenance_page() will seed
		// it cleanly. Mark migrated so we don't keep scanning.
		update_option( SN_PROV_SPLIT_MIGR_OPT, time(), true );
		return;
	}

	$body        = $page->post_content;
	$hero_anchor = '<!-- wp:group {"className":"sn-provenance-hero"';
	$hero_pos    = strpos( $body, $hero_anchor );

	// If the hero marker is missing the body has been hand-edited away
	// from the seed shape. Bail without flagging — the migration can
	// re-run after the admin restores the marker (or manually splits).
	if ( false === $hero_pos ) {
		return;
	}

	$essay = trim( substr( $body, $hero_pos ) );

	// Create the child page if it doesn't already exist. We never
	// overwrite an existing child body — admin may have edited the prose
	// there, and our migration job is structural (move), not editorial.
	$child = get_page_by_path( SN_PROVENANCE_SLUG . '/' . SN_OVER_DETECTION_SLUG );
	if ( ! $child ) {
		wp_insert_post( array(
			'post_title'    => 'Provenance Over Detection',
			'post_name'     => SN_OVER_DETECTION_SLUG,
			'post_parent'   => (int) $page->ID,
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'post_content'  => $essay,
			'post_excerpt'  => "A short read on why the industry needs to prove what's human, not chase what isn't.",
			'page_template' => 'page-provenance',
		), false );
	}

	// Replace the parent body with the cards-only index. Title also
	// updates so the WP admin reflects the new role of the page.
	wp_update_post( array(
		'ID'           => $page->ID,
		'post_title'   => 'On Provenance',
		'post_content' => sn_provenance_papers_index_markup(),
	) );

	update_option( SN_PROV_SPLIT_MIGR_OPT, time(), true );
}

/**
 * One-time migration that creates the second long-form essay
 * (/provenance/as-substrate) on installs whose `SN_SEED_FLAG_OPTION` was
 * already set before this page existed — the main seed flow short-
 * circuits on those sites, so the new ensure-call needs its own gate.
 *
 * Idempotent on multiple axes: bails if the dedicated flag is set, and
 * `sn_ensure_as_substrate_page()` itself bails if the child page exists.
 */
add_action( 'admin_init', 'sn_migrate_as_substrate_seed' );

function sn_migrate_as_substrate_seed() {
	if ( get_option( SN_AS_SUBSTRATE_SEED_OPT ) ) {
		return;
	}

	$parent = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $parent ) {
		// Parent page doesn't exist yet — sn_seed_content_surfaces will
		// create both in the same pass on its next admin_init firing.
		// Mark migrated so we don't keep scanning.
		update_option( SN_AS_SUBSTRATE_SEED_OPT, time(), true );
		return;
	}

	sn_ensure_as_substrate_page();
	update_option( SN_AS_SUBSTRATE_SEED_OPT, time(), true );
}

/**
 * One-time migration that creates the public "Verify a Note" how-to page
 * (/provenance/verify) on installs whose `SN_SEED_FLAG_OPTION` was already set
 * before this page existed — the main seed flow short-circuits on those sites,
 * so the new ensure-call needs its own gate. Without this the byline panel's
 * "Verify it yourself" link 404s on every already-seeded production site.
 *
 * Idempotent on multiple axes: bails if the dedicated flag is set, and
 * `sn_ensure_verify_page()` itself bails if the child page exists.
 */
add_action( 'admin_init', 'sn_migrate_verify_page_seed' );

function sn_migrate_verify_page_seed() {
	if ( get_option( SN_PROV_VERIFY_PAGE_MIGR_OPT ) ) {
		return;
	}

	$parent = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $parent ) {
		// Parent page doesn't exist yet — sn_seed_content_surfaces will
		// create both in the same pass on its next admin_init firing.
		// Mark migrated so we don't keep scanning.
		update_option( SN_PROV_VERIFY_PAGE_MIGR_OPT, time(), true );
		return;
	}

	sn_ensure_verify_page();
	update_option( SN_PROV_VERIFY_PAGE_MIGR_OPT, time(), true );
}

/**
 * One-time migration that updates Card 2 of the /provenance pillar
 * index to include the read-time meta and the "Read the long-form on
 * this site →" affordance pointing at /provenance/as-substrate/. Lives
 * separately from the seed-flow because production sites already have
 * SN_PROV_SPLIT_MIGR_OPT set from v6.5.4 and that flow won't re-run.
 *
 * Strategy: full-body rewrite via sn_provenance_papers_index_markup().
 * The pillar page is a generated index — its body shouldn't be hand-
 * edited, and the index function is the single source of truth for the
 * cards. A defensive sanity check on the SSRN abstract_id 6730343
 * anchor (the unique marker for v6.5.4's Card 2 shape) gates the
 * rewrite: if the marker is missing, the admin has hand-edited away
 * from the seed shape, so we bail WITHOUT setting the flag. That way a
 * future run can complete the migration after manual recovery.
 *
 * Idempotent: bails (and flags) if the body already contains the
 * /provenance/as-substrate/ longform URL — that's the unique marker
 * for the post-migration Card 2 shape, so seeing it means the work is
 * already done.
 */
add_action( 'admin_init', 'sn_migrate_provenance_card2_longform' );

function sn_migrate_provenance_card2_longform() {
	if ( get_option( SN_PROV_CARD2_LF_MIGR_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		// Pillar page doesn't exist yet — sn_seed_content_surfaces will
		// create it cleanly. Mark migrated so we don't keep scanning.
		update_option( SN_PROV_CARD2_LF_MIGR_OPT, time(), true );
		return;
	}

	$body = $page->post_content;

	// Already migrated — body has the new longform affordance.
	if ( false !== strpos( $body, '/provenance/as-substrate/' ) ) {
		update_option( SN_PROV_CARD2_LF_MIGR_OPT, time(), true );
		return;
	}

	// Defensive: only proceed if the v6.5.4 Card 2 shape is present
	// (the SSRN abstract_id 6730343 anchor is the unique marker). If
	// absent, the admin has hand-edited away from the seed — bail
	// WITHOUT flagging so the migration can complete after recovery.
	if ( false === strpos( $body, 'abstract_id=6730343' ) ) {
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => sn_provenance_papers_index_markup(),
	) );

	update_option( SN_PROV_CARD2_LF_MIGR_OPT, time(), true );
}

/**
 * One-time migration that switches the /provenance pillar's two card
 * meta lines from hardcoded reading times ("4 min read" / "5 min read")
 * to dynamic `[sn_reading_time slug="..."]` shortcodes pointing at the
 * respective child long-forms. Without this, the pillar drifts every
 * time the prose evolves — the live drift between the pillar's "4 min"
 * and the over-detection byline's "5 min" was the trigger for this
 * migration.
 *
 * Strategy mirrors `sn_migrate_provenance_card2_longform()`: full-body
 * rewrite via `sn_provenance_papers_index_markup()`, gated on a unique
 * marker (the SSRN abstract_id 6730343 anchor on Card 2). If the
 * pillar body has been hand-edited away from seed shape, bail WITHOUT
 * setting the flag so a future run can complete after recovery.
 *
 * Self-idempotent: bails (and flags) if the body already contains the
 * shortcode token `[sn_reading_time slug=` — the unique marker for the
 * post-migration shape.
 */
add_action( 'admin_init', 'sn_migrate_provenance_card_readtimes_dynamic' );

function sn_migrate_provenance_card_readtimes_dynamic() {
	if ( get_option( SN_PROV_RT_DYNAMIC_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		update_option( SN_PROV_RT_DYNAMIC_OPT, time(), true );
		return;
	}

	$body = $page->post_content;

	// Already migrated — body uses the dynamic shortcode form.
	if ( false !== strpos( $body, '[sn_reading_time slug=' ) ) {
		update_option( SN_PROV_RT_DYNAMIC_OPT, time(), true );
		return;
	}

	// Defensive: only proceed if the v6.5.4 / Card-2-longform-migration
	// shape is present (SSRN abstract_id 6730343 anchor for Card 2).
	if ( false === strpos( $body, 'abstract_id=6730343' ) ) {
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => sn_provenance_papers_index_markup(),
	) );

	update_option( SN_PROV_RT_DYNAMIC_OPT, time(), true );
}

/**
 * One-time migration that adds № 01 / № 02 catalog-number markers
 * to the /provenance pillar cards, bringing them visually in line
 * with the /notes pillar treatment. Without this, the
 * sn_provenance_papers_index_markup() update only takes effect on
 * fresh installs — production sites already have the prior shape
 * locked in via earlier migrations' flags.
 *
 * Strategy mirrors `sn_migrate_provenance_card_readtimes_dynamic()`:
 * full-body rewrite via the index function, gated on the SSRN
 * abstract_id 6730343 anchor for Card 2. If absent, admin has hand-
 * edited away from seed shape — bail WITHOUT flagging so a future
 * run can complete after recovery.
 *
 * Self-idempotent: bails (and flags) if the body already contains
 * `sn-catalog-number` — the unique marker for the post-migration
 * shape.
 */
add_action( 'admin_init', 'sn_migrate_provenance_catalog_numbers' );

function sn_migrate_provenance_catalog_numbers() {
	if ( get_option( SN_PROV_CATALOG_NUMBERS_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		update_option( SN_PROV_CATALOG_NUMBERS_OPT, time(), true );
		return;
	}

	$body = $page->post_content;

	// Already migrated — body has the catalog-number markers.
	if ( false !== strpos( $body, 'sn-catalog-number' ) ) {
		update_option( SN_PROV_CATALOG_NUMBERS_OPT, time(), true );
		return;
	}

	// Defensive: only proceed if the v6.5.4 / Card-2-longform shape
	// is present (SSRN abstract_id 6730343 anchor for Card 2). If
	// absent, the admin has hand-edited away from seed — bail
	// WITHOUT flagging so the migration can complete after recovery.
	if ( false === strpos( $body, 'abstract_id=6730343' ) ) {
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => sn_provenance_papers_index_markup(),
	) );

	update_option( SN_PROV_CATALOG_NUMBERS_OPT, time(), true );
}

/**
 * One-time migration that strips `displayType:"modified"` from the
 * as-substrate page's wp:post-date block, defaulting it to publish-
 * date display.
 *
 * Why: WordPress core's render_block_core_post_date() returns null
 * when displayType is "modified" AND post_modified equals post_date.
 * Newly-inserted posts have those equal, so the byline date renders
 * empty until the first edit. As-substrate is evergreen — by maintainer
 * convention it never gets edited — so under "modified" it would
 * permanently show no date. Switching to publish-date display (the
 * block default) always renders the post_date set at creation.
 *
 * Idempotent: bails (and flags) if the body already lacks
 * `displayType":"modified` — the only marker the migration needs to
 * detect previous completion. Defensive: if the str_replace finds no
 * match (e.g., admin edited the post-date block separately), bails
 * WITHOUT flagging so the migration can complete after recovery.
 */
add_action( 'admin_init', 'sn_migrate_as_substrate_post_date_displaytype' );

function sn_migrate_as_substrate_post_date_displaytype() {
	if ( get_option( SN_AS_DATE_DISPLAYTYPE_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG . '/' . SN_AS_SUBSTRATE_SLUG );
	if ( ! $page ) {
		update_option( SN_AS_DATE_DISPLAYTYPE_OPT, time(), true );
		return;
	}

	$body = $page->post_content;

	// Already migrated — no displayType:"modified" left in the body.
	if ( false === strpos( $body, '"displayType":"modified"' ) ) {
		update_option( SN_AS_DATE_DISPLAYTYPE_OPT, time(), true );
		return;
	}

	// Strip the displayType attribute precisely (it sits between the
	// `format` attribute and `style`, which is the seeded order).
	$new = str_replace(
		'"format":"F j, Y","displayType":"modified",',
		'"format":"F j, Y",',
		$body
	);

	if ( $new === $body ) {
		// Pattern didn't match — admin has touched the post-date block.
		// Bail without flagging so a future run can complete after recovery.
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => $new,
	) );

	update_option( SN_AS_DATE_DISPLAYTYPE_OPT, time(), true );
}

/**
 * One-time migration that replaces the over-detection page's hardcoded
 * eyebrow read-time (`A short read · 4 min`) with the dynamic
 * `[sn_reading_time]` shortcode, eliminating the within-page drift
 * between the eyebrow and the byline.
 *
 * Background: v6.5.3 introduced the eyebrow with a hardcoded "4 min"
 * estimate. v6.5.4's seed simplified it to "A short read" only — but
 * the live page wasn't migrated, so production still shows the stale
 * value. The over-detection page's prose has grown since then; the
 * byline (dynamic shortcode) reads "5 min read" while the eyebrow
 * still reads "4 min". This migration syncs the live eyebrow to the
 * shortcode form (matching the as-substrate seed shape).
 *
 * Idempotent: bails (and flags) if the body already contains the
 * literal `A short read · [sn_reading_time]` token. Defensive: if the
 * regex finds no `A short read · N min` pattern (admin already
 * customised the eyebrow), bails WITHOUT flagging.
 */
add_action( 'admin_init', 'sn_migrate_over_detection_eyebrow_dynamic' );

function sn_migrate_over_detection_eyebrow_dynamic() {
	if ( get_option( SN_OD_EYEBROW_DYN_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG . '/' . SN_OVER_DETECTION_SLUG );
	if ( ! $page ) {
		update_option( SN_OD_EYEBROW_DYN_OPT, time(), true );
		return;
	}

	$body = $page->post_content;

	// Already migrated — eyebrow uses the shortcode form.
	if ( false !== strpos( $body, 'A short read · [sn_reading_time]' ) ) {
		update_option( SN_OD_EYEBROW_DYN_OPT, time(), true );
		return;
	}

	// Replace `A short read · N min[ read]` (case-insensitive on min/read,
	// `/u` for the literal middot). Limit to 1 replacement — the eyebrow
	// is the only place this pattern appears.
	$new = preg_replace(
		'/A short read\s*·\s*\d+\s*min(\s*read)?/u',
		'A short read · [sn_reading_time]',
		$body,
		1
	);

	if ( $new === $body || null === $new ) {
		// Pattern didn't match — admin has already changed the eyebrow.
		// Bail without flagging so a future run can complete after recovery.
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => $new,
	) );

	update_option( SN_OD_EYEBROW_DYN_OPT, time(), true );
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
add_action( 'admin_init', 'sn_migrate_clear_notes_template_override' );

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
 * Render one dossier <section> for /now or /uses: the section-head (Bebas
 * label + mono count badge) and the hairline-row <ul>. Reproduces the theme's
 * original virtual-route markup verbatim so now.css/uses.css (and any
 * Site-Editor global styles targeting these classes) render it identically.
 *
 * @param string $prefix     'now' | 'uses' (drives the sn-{prefix}-* classes).
 * @param int    $index      Section index (for the aria id).
 * @param string $label      Section label (raw; escaped here).
 * @param string $items_html Pre-rendered, already-escaped <li> markup.
 * @param int    $count      Item count for the mono badge.
 * @return string
 */
function sn_dossier_section_html( $prefix, $index, $label, $items_html, $count ) {
	$p  = 'sn-' . $prefix;
	$id = $p . '-h-' . (int) $index;
	return '<section class="' . $p . '-section" aria-labelledby="' . esc_attr( $id ) . '">'
		. '<div class="' . $p . '-section-head">'
		. '<h2 class="' . $p . '-section-label" id="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</h2>'
		. '<span class="' . $p . '-section-count">' . esc_html( sprintf( '%02d', (int) $count ) ) . '</span>'
		. '</div>'
		. '<ul class="' . $p . '-list">' . $items_html . '</ul>'
		. '</section>';
}

/**
 * Render the /now dossier body (hero + sections) as a core/html block for
 * post_content. Reproduces the theme's /now route markup (sn-now-* classes) so
 * now.css renders it identically; the text box stays the editor, so the body
 * is generated HTML, not hand-edited blocks. The "Updated" line uses the given
 * date (stamped at save time). Returns '' when no section has items.
 *
 * @param array<int,array{label:string,items:array<int,string>}> $sections
 * @param string $updated Display date for the "Updated" line.
 * @return string
 */
function sn_now_dossier_html( $sections, $updated ) {
	if ( empty( $sections ) || ! is_array( $sections ) ) {
		return '';
	}

	$sections_html = '';
	foreach ( array_values( $sections ) as $i => $section ) {
		$label = (string) ( $section['label'] ?? '' );
		if ( '' === trim( $label ) ) {
			continue;
		}
		$items_html = '';
		$count      = 0;
		foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
			$item = (string) $item;
			if ( '' === trim( $item ) ) {
				continue;
			}
			$items_html .= '<li class="sn-now-item"><span class="sn-now-item-text">' . esc_html( $item ) . '</span></li>';
			++$count;
		}
		if ( 0 === $count ) {
			continue;
		}
		$sections_html .= sn_dossier_section_html( 'now', $i, $label, $items_html, $count );
	}

	if ( '' === $sections_html ) {
		return '';
	}

	$hero = '<header class="sn-now-hero">'
		. '<p class="sn-now-eyebrow">Now &middot; What I&rsquo;m focused on</p>'
		. '<h1 class="sn-now-headline">Now.</h1>'
		. '<p class="sn-now-dek">A public answer to &ldquo;what are you doing these days?&rdquo; &mdash; the projects, writing, and inputs that have my attention right now.</p>'
		. '<p class="sn-now-meta">Updated ' . esc_html( $updated ) . '</p>'
		. '</header>';

	return "<!-- wp:html -->\n<div class=\"sn-now-page\">" . $hero . $sections_html . "</div>\n<!-- /wp:html -->";
}

/**
 * Build the /now Page body from parsed text-box sections. Returns '' when the
 * sections produce no usable content, so callers never blank the page. The
 * "Updated" line is stamped with the current site-timezone date at build time
 * (the automatic replacement for the old sn_now_updated stamp).
 *
 * @param array<int,array{label:string,items:array<int,string>}> $sections
 * @return string
 */
function sn_now_build_body( $sections ) {
	$updated = function_exists( 'wp_date' ) ? (string) wp_date( 'F j, Y' ) : gmdate( 'F j, Y' );
	return sn_now_dossier_html( $sections, $updated );
}

/**
 * Create-or-update the /now Page with the given body. Creates it (published,
 * bound to page-now, with a seeded Excerpt) when absent; otherwise replaces
 * post_content (the text box is the canonical editor, so a regenerate is a full
 * replace) and seeds the Excerpt only when still empty. Returns the Page ID, or
 * 0 on failure / empty body.
 *
 * @param string $body Full post_content (the core/html dossier block).
 * @return int
 */
function sn_now_upsert_page( $body ) {
	if ( '' === trim( (string) $body ) ) {
		return 0;
	}

	$excerpt = 'What Juan Lentino is focused on right now: current projects, writing, and inputs. Updated whenever it changes.';
	$page    = get_page_by_path( SN_NOW_SLUG );

	if ( $page ) {
		$update = array(
			'ID'           => $page->ID,
			'post_content' => $body,
		);
		if ( '' === trim( (string) $page->post_excerpt ) ) {
			$update['post_excerpt'] = $excerpt;
		}
		wp_update_post( $update );
		return (int) $page->ID;
	}

	$new_id = wp_insert_post(
		array(
			'post_title'    => 'Now',
			'post_name'     => SN_NOW_SLUG,
			'post_parent'   => 0,
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'post_content'  => $body,
			'post_excerpt'  => $excerpt,
			'page_template' => 'page-now',
		),
		false
	);

	return is_int( $new_id ) && $new_id > 0 ? $new_id : 0;
}

/**
 * Regenerate the /now Page from the current Content → Now Page text box. Wired
 * to the editor's save (sn_now_page_save), so the plain-text box stays the
 * authoring surface while the Page is the rendered artifact + SEO/URL surface.
 * No-op (never blanks the page) when the text box has no usable sections.
 */
function sn_now_sync_page() {
	if ( ! function_exists( 'sn_now_page_sections' ) ) {
		return;
	}
	$body = sn_now_build_body( sn_now_page_sections() );
	if ( '' !== $body ) {
		sn_now_upsert_page( $body );
	}
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
add_action( 'admin_init', 'sn_migrate_now_page' );

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
 * Render the /uses dossier body (hero + gear sections) as a core/html block
 * for post_content. Reproduces the theme's /about/uses route markup
 * (sn-uses-* classes, a name plus an optional note per item) so uses.css
 * renders it identically. The meta line is the total item count. Returns ''
 * when no group has items.
 *
 * @param array<int,array{label:string,items:array<int,array{name:string,note:string}>}> $groups
 * @return string
 */
function sn_uses_dossier_html( $groups ) {
	if ( empty( $groups ) || ! is_array( $groups ) ) {
		return '';
	}

	$sections_html = '';
	$total         = 0;
	foreach ( array_values( $groups ) as $i => $group ) {
		$label = (string) ( $group['label'] ?? '' );
		if ( '' === trim( $label ) ) {
			continue;
		}
		$items_html = '';
		$count      = 0;
		foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
			$name = (string) ( is_array( $item ) ? ( $item['name'] ?? '' ) : $item );
			if ( '' === trim( $name ) ) {
				continue;
			}
			$note        = is_array( $item ) ? (string) ( $item['note'] ?? '' ) : '';
			$items_html .= '<li class="sn-uses-item"><span class="sn-uses-item-name">' . esc_html( $name ) . '</span>';
			if ( '' !== trim( $note ) ) {
				$items_html .= '<span class="sn-uses-item-note">' . esc_html( $note ) . '</span>';
			}
			$items_html .= '</li>';
			++$count;
		}
		if ( 0 === $count ) {
			continue;
		}
		$total         += $count;
		$sections_html .= sn_dossier_section_html( 'uses', $i, $label, $items_html, $count );
	}

	if ( '' === $sections_html ) {
		return '';
	}

	$meta = $total . ' ' . ( 1 === $total ? 'item' : 'items' );
	$hero = '<header class="sn-uses-hero">'
		. '<p class="sn-uses-eyebrow">Uses &middot; The kit behind the work</p>'
		. '<h1 class="sn-uses-headline">Uses.</h1>'
		. '<p class="sn-uses-dek">The hardware and software I actually reach for &mdash; the studio, the instruments, and the tools that keep the signal clean.</p>'
		. '<p class="sn-uses-meta">' . esc_html( $meta ) . '</p>'
		. '</header>';

	return "<!-- wp:html -->\n<div class=\"sn-uses-page\">" . $hero . $sections_html . "</div>\n<!-- /wp:html -->";
}

/**
 * The current /uses Page body from the Content → Uses Page text box (parsed
 * groups → dossier HTML). '' when nothing usable is saved.
 *
 * @return string
 */
function sn_uses_current_body() {
	if ( ! function_exists( 'sn_uses_page_get' ) || ! function_exists( 'sn_uses_parse_groups' ) ) {
		return '';
	}
	$page   = sn_uses_page_get();
	$groups = $page ? sn_uses_parse_groups( $page['raw'] ) : array();
	return sn_uses_dossier_html( $groups );
}

/**
 * Create-or-update the /about/uses CHILD Page with the given body. Creates it
 * as a child of the About Page (published, bound to page-uses, Excerpt seeded)
 * when absent; otherwise replaces post_content (the text box is canonical) and
 * seeds the Excerpt only when still empty. Returns the Page ID, or 0 on empty
 * body / the About parent not existing yet (retry-safe).
 *
 * @param string $body Full post_content (the core/html dossier block).
 * @return int
 */
function sn_uses_upsert_page( $body ) {
	if ( '' === trim( (string) $body ) ) {
		return 0;
	}

	$excerpt = 'The hardware, software, and instruments behind the work: what Juan Lentino actually uses, grouped and listed.';
	$page    = get_page_by_path( SN_ABOUT_SLUG . '/' . SN_USES_SLUG );

	if ( $page ) {
		$update = array(
			'ID'           => $page->ID,
			'post_content' => $body,
		);
		if ( '' === trim( (string) $page->post_excerpt ) ) {
			$update['post_excerpt'] = $excerpt;
		}
		wp_update_post( $update );
		return (int) $page->ID;
	}

	$parent = get_page_by_path( SN_ABOUT_SLUG );
	if ( ! $parent ) {
		return 0; // About parent not ready — retry on the next admin_init.
	}

	$new_id = wp_insert_post(
		array(
			'post_title'    => 'Uses',
			'post_name'     => SN_USES_SLUG,
			'post_parent'   => (int) $parent->ID,
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'post_content'  => $body,
			'post_excerpt'  => $excerpt,
			'page_template' => 'page-uses',
		),
		false
	);

	return is_int( $new_id ) && $new_id > 0 ? $new_id : 0;
}

/**
 * Regenerate the /about/uses Page from the current Content → Uses Page text
 * box. Wired to the editor's save (sn_uses_page_save). No-op when the box has
 * no usable groups (never blanks the page).
 */
function sn_uses_sync_page() {
	$body = sn_uses_current_body();
	if ( '' !== $body ) {
		sn_uses_upsert_page( $body );
	}
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
add_action( 'admin_init', 'sn_migrate_uses_page' );

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
	$f = __DIR__ . '/seed-content/uses-default.txt';
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
add_action( 'admin_init', 'sn_migrate_now_uses_dossier_repair' );

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
	$body_file = __DIR__ . '/seed-content/accessibility-body.html';
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * Load the frozen /contact/personal body markup from disk. Mirrors
 * sn_load_about_body() — same empty-string fallback semantics.
 *
 * @return string
 */
function sn_load_personal_body() {
	$body_file = __DIR__ . '/seed-content/personal-body.html';
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
add_action( 'admin_init', 'sn_migrate_accessibility_page' );

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
add_action( 'admin_init', 'sn_migrate_personal_page' );

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
