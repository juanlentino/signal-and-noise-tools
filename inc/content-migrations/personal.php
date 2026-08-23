<?php
/**
 * Signal & Noise — content migration: the Personal page body.
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
 * Registered migrations: sn_migrate_personal_page
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Shares the Phase 2c frozen-seed model — no editor, a one-time CREATE from a
// frozen *-body.html seed — documented in full in accessibility.php.

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
