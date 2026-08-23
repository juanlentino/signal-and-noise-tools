<?php
/**
 * Signal & Noise — content migration: the "Provenance as Substrate" essay seed and its date display type.
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
 * Registered migrations: sn_migrate_as_substrate_seed,
 * sn_migrate_as_substrate_post_date_displaytype
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the seeded second long-form essay markup from disk. Mirrors
 * sn_load_over_detection_body — same fallback semantics.
 */
function sn_load_as_substrate_body() {
	$body_file = sn_content_seed_file( 'as-substrate-body.html' );
	return file_exists( $body_file ) ? file_get_contents( $body_file ) : '';
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
