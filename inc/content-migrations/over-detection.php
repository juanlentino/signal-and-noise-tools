<?php
/**
 * Signal & Noise — content migration: the "Provenance Over Detection" essay eyebrow.
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
 * Registered migrations: sn_migrate_over_detection_eyebrow_dynamic
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the seeded long-form essay markup from disk. Mirrors
 * sn_load_provenance_body — same fallback semantics.
 */
function sn_load_over_detection_body() {
	$body_file = sn_content_seed_file( 'over-detection-body.html' );
	return file_exists( $body_file ) ? file_get_contents( $body_file ) : '';
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
