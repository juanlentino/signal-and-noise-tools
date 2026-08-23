<?php
/**
 * Signal & Noise — content migration: the Now and Uses pages and their dossier repair.
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
 * Registered migrations: sn_migrate_now_page, sn_migrate_uses_page,
 * sn_migrate_now_uses_dossier_repair
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
