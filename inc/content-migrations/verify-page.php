<?php
/**
 * Signal & Noise — content migration: the /verify page seed.
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
 * Registered migrations: sn_migrate_verify_page_seed
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the seeded "Verify a Note" body markup from disk. Mirrors
 * sn_load_as_substrate_body — same fallback semantics. The body carries the
 * [sn_provenance_verify] shortcode, so the_content() renders the live steps.
 */
function sn_load_verify_body() {
	$body_file = sn_content_seed_file( 'verify-body.html' );
	return file_exists( $body_file ) ? file_get_contents( $body_file ) : '';
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
