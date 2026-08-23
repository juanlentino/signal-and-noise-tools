<?php
/**
 * Signal & Noise — content migration: the About page body.
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
 * Registered migrations: sn_migrate_about_body
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the seeded /about body markup from disk. Mirrors
 * sn_load_provenance_body — same empty-string fallback semantics.
 */
function sn_load_about_body() {
	$body_file = sn_content_seed_file( 'about-body.html' );
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * One-time migration flipping /about from file-authored to CMS-authored:
 * seeds the existing (empty) About Page's body from the seed file, plus a
 * native Excerpt so the SEO layer reads a real excerpt instead of the theme's
 * hardcoded description map. Same safety as sn_migrate_provenance_body():
 * runs once, only writes when the field is genuinely empty.
 */
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
