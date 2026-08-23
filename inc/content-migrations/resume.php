<?php
/**
 * Signal & Noise — content migration: the Resume page body.
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
 * Registered migrations: sn_migrate_resume_body
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
