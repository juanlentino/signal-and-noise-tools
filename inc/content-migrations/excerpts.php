<?php
/**
 * Signal & Noise — content migration: the seed-page excerpt backfill.
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
 * Registered migrations: sn_migrate_seed_page_excerpts
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Back-fill the native Excerpt on the five theme-authored Pages whose create
 * paths seed one (sn_seed_page_excerpts()) but which predate that excerpt on
 * production — the /notes index and the four provenance-family Pages. The
 * editorial Pages (about/contact/services/resume/music/now/uses/accessibility/
 * personal) each got their own body+excerpt back-fill; these five never did, so
 * on long-lived installs they ship descriptionless and the SEO layer has no
 * summary to emit (surfaced by the Analytics "N pages ship without a meta
 * description" recommendation).
 *
 * Runs once, guarded by a dedicated flag. Only writes an Excerpt that is
 * genuinely empty, so an owner-written summary is never clobbered and a page
 * that already carries one is a no-op. Pages not seeded yet are skipped — a
 * fresh install creates them WITH the excerpt via sn_ensure_* — and the flag is
 * still set so we stop scanning every admin_init.
 */
function sn_migrate_seed_page_excerpts() {
	if ( get_option( SN_SEED_EXCERPTS_BACKFILL_OPT ) ) {
		return;
	}

	foreach ( sn_seed_page_excerpts() as $path => $excerpt ) {
		$page = get_page_by_path( $path );
		if ( ! is_object( $page ) ) {
			continue;
		}
		if ( '' !== trim( (string) ( $page->post_excerpt ?? '' ) ) ) {
			continue;
		}
		wp_update_post( array(
			'ID'           => (int) $page->ID,
			'post_excerpt' => $excerpt,
		) );
	}

	update_option( SN_SEED_EXCERPTS_BACKFILL_OPT, time(), true );
}
