<?php
/**
 * Signal & Noise — content migration: the Provenance essay body and its early refinements.
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
 * Registered migrations: sn_migrate_provenance_body,
 * sn_migrate_provenance_refinements
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
