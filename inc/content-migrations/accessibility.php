<?php
/**
 * Signal & Noise — content migration: the Accessibility page body.
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
 * Registered migrations: sn_migrate_accessibility_page
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
