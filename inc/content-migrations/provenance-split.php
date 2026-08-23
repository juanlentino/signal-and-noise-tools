<?php
/**
 * Signal & Noise — content migration: the Provenance two-paper split.
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
 * Registered migrations: sn_migrate_provenance_split
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time migration that splits the existing /provenance pillar page
 * into a lean two-paper index (parent: /provenance) and a long-form
 * essay (child: /provenance/over-detection). The essay prose itself
 * is never edited — it's lifted verbatim from the existing live page
 * body and moved into the new child page.
 *
 * Algorithm:
 *   1. Locate the essay's hero block (`sn-provenance-hero` className) in
 *      the existing /provenance body. This anchor is stable across the
 *      seed-file shape and the prior unreleased "prepend cards" shape,
 *      so the same code handles both starting states.
 *   2. Everything from that anchor to end-of-body = the essay. Hand it
 *      to a new child page at /provenance/over-detection.
 *   3. Overwrite the parent /provenance body with the cards-only index.
 *
 * Safety:
 *   - If the hero anchor is missing (page was hand-edited away from
 *     seed shape), bail WITHOUT setting the flag, so a future run after
 *     manual recovery can complete the split.
 *   - If a /provenance/over-detection page already exists, leave its
 *     body untouched (admin may have edited prose there) — only the
 *     parent body is rewritten.
 *   - Gated by SN_PROV_SPLIT_MIGR_OPT, runs at most once per install.
 */
function sn_migrate_provenance_split() {
	if ( get_option( SN_PROV_SPLIT_MIGR_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		// Page doesn't exist yet — sn_ensure_provenance_page() will seed
		// it cleanly. Mark migrated so we don't keep scanning.
		update_option( SN_PROV_SPLIT_MIGR_OPT, time(), true );
		return;
	}

	$body        = $page->post_content;
	$hero_anchor = '<!-- wp:group {"className":"sn-provenance-hero"';
	$hero_pos    = strpos( $body, $hero_anchor );

	// If the hero marker is missing the body has been hand-edited away
	// from the seed shape. Bail without flagging — the migration can
	// re-run after the admin restores the marker (or manually splits).
	if ( false === $hero_pos ) {
		return;
	}

	$essay = trim( substr( $body, $hero_pos ) );

	// Create the child page if it doesn't already exist. We never
	// overwrite an existing child body — admin may have edited the prose
	// there, and our migration job is structural (move), not editorial.
	$child = get_page_by_path( SN_PROVENANCE_SLUG . '/' . SN_OVER_DETECTION_SLUG );
	if ( ! $child ) {
		wp_insert_post( array(
			'post_title'    => 'Provenance Over Detection',
			'post_name'     => SN_OVER_DETECTION_SLUG,
			'post_parent'   => (int) $page->ID,
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'post_content'  => $essay,
			'post_excerpt'  => sn_seed_page_excerpts()[ SN_PROVENANCE_SLUG . '/' . SN_OVER_DETECTION_SLUG ],
			'page_template' => 'page-provenance',
		), false );
	}

	// Replace the parent body with the cards-only index. Title also
	// updates so the WP admin reflects the new role of the page.
	wp_update_post( array(
		'ID'           => $page->ID,
		'post_title'   => 'On Provenance',
		'post_content' => sn_provenance_papers_index_markup(),
	) );

	update_option( SN_PROV_SPLIT_MIGR_OPT, time(), true );
}
