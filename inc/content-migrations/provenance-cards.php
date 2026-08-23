<?php
/**
 * Signal & Noise — content migration: Provenance card longform and catalog numbers.
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
 * Registered migrations: sn_migrate_provenance_card2_longform,
 * sn_migrate_provenance_catalog_numbers
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time migration that updates Card 2 of the /provenance pillar
 * index to include the read-time meta and the "Read the long-form on
 * this site →" affordance pointing at /provenance/as-substrate/. Lives
 * separately from the seed-flow because production sites already have
 * SN_PROV_SPLIT_MIGR_OPT set from v6.5.4 and that flow won't re-run.
 *
 * Strategy: full-body rewrite via sn_provenance_papers_index_markup().
 * The pillar page is a generated index — its body shouldn't be hand-
 * edited, and the index function is the single source of truth for the
 * cards. A defensive sanity check on the SSRN abstract_id 6730343
 * anchor (the unique marker for v6.5.4's Card 2 shape) gates the
 * rewrite: if the marker is missing, the admin has hand-edited away
 * from the seed shape, so we bail WITHOUT setting the flag. That way a
 * future run can complete the migration after manual recovery.
 *
 * Idempotent: bails (and flags) if the body already contains the
 * /provenance/as-substrate/ longform URL — that's the unique marker
 * for the post-migration Card 2 shape, so seeing it means the work is
 * already done.
 */
function sn_migrate_provenance_card2_longform() {
	if ( get_option( SN_PROV_CARD2_LF_MIGR_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		// Pillar page doesn't exist yet — sn_seed_content_surfaces will
		// create it cleanly. Mark migrated so we don't keep scanning.
		update_option( SN_PROV_CARD2_LF_MIGR_OPT, time(), true );
		return;
	}

	$body = $page->post_content;

	// Already migrated — body has the new longform affordance.
	if ( false !== strpos( $body, '/provenance/as-substrate/' ) ) {
		update_option( SN_PROV_CARD2_LF_MIGR_OPT, time(), true );
		return;
	}

	// Defensive: only proceed if the v6.5.4 Card 2 shape is present
	// (the SSRN abstract_id 6730343 anchor is the unique marker). If
	// absent, the admin has hand-edited away from the seed — bail
	// WITHOUT flagging so the migration can complete after recovery.
	if ( false === strpos( $body, 'abstract_id=6730343' ) ) {
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => sn_provenance_papers_index_markup(),
	) );

	update_option( SN_PROV_CARD2_LF_MIGR_OPT, time(), true );
}

/**
 * One-time migration that adds № 01 / № 02 catalog-number markers
 * to the /provenance pillar cards, bringing them visually in line
 * with the /notes pillar treatment. Without this, the
 * sn_provenance_papers_index_markup() update only takes effect on
 * fresh installs — production sites already have the prior shape
 * locked in via earlier migrations' flags.
 *
 * Strategy mirrors `sn_migrate_provenance_card_readtimes_dynamic()`:
 * full-body rewrite via the index function, gated on the SSRN
 * abstract_id 6730343 anchor for Card 2. If absent, admin has hand-
 * edited away from seed shape — bail WITHOUT flagging so a future
 * run can complete after recovery.
 *
 * Self-idempotent: bails (and flags) if the body already contains
 * `sn-catalog-number` — the unique marker for the post-migration
 * shape.
 */
function sn_migrate_provenance_catalog_numbers() {
	if ( get_option( SN_PROV_CATALOG_NUMBERS_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		update_option( SN_PROV_CATALOG_NUMBERS_OPT, time(), true );
		return;
	}

	$body = $page->post_content;

	// Already migrated — body has the catalog-number markers.
	if ( false !== strpos( $body, 'sn-catalog-number' ) ) {
		update_option( SN_PROV_CATALOG_NUMBERS_OPT, time(), true );
		return;
	}

	// Defensive: only proceed if the v6.5.4 / Card-2-longform shape
	// is present (SSRN abstract_id 6730343 anchor for Card 2). If
	// absent, the admin has hand-edited away from seed — bail
	// WITHOUT flagging so the migration can complete after recovery.
	if ( false === strpos( $body, 'abstract_id=6730343' ) ) {
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => sn_provenance_papers_index_markup(),
	) );

	update_option( SN_PROV_CATALOG_NUMBERS_OPT, time(), true );
}
