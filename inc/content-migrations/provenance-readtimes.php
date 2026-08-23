<?php
/**
 * Signal & Noise — content migration: Provenance byline and card reading times.
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
 * Registered migrations: sn_migrate_provenance_byline_reading_time,
 * sn_migrate_provenance_card_readtimes_dynamic
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time migration that injects the reading-time block into the
 * existing Provenance byline. Mirrors the seed file change in 6.3.1.
 *
 * Idempotent — bails if the byline already contains the reading-time
 * marker (paste-by-hand defensive). Gated by SN_PROV_BYLINE_RT_MIGR_OPT
 * so it only runs once per install.
 */
function sn_migrate_provenance_byline_reading_time() {
	if ( get_option( SN_PROV_BYLINE_RT_MIGR_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		update_option( SN_PROV_BYLINE_RT_MIGR_OPT, time(), true );
		return;
	}

	$body     = $page->post_content;
	$original = $body;

	// Skip if the reading-time block is already present (paste-by-hand defensive).
	if ( false === strpos( $body, 'sn-provenance-byline-reading-time' ) ) {
		// Anchor on the byline's wp:post-date opener and the next ` /-->`
		// (the tag is self-closing). strpos avoids the nested-{} pitfall
		// the regex form hits once the 6.2.6 migration adds a style object.
		$start = strpos( $body, '<!-- wp:post-date {"format":"F j, Y"' );
		if ( false !== $start ) {
			$end_marker = ' /-->';
			$end        = strpos( $body, $end_marker, $start );
			if ( false !== $end ) {
				$insert_at = $end + strlen( $end_marker );
				$body      = substr( $body, 0, $insert_at )
					. "\n\n\t" . sn_provenance_byline_reading_time_markup()
					. substr( $body, $insert_at );
			}
		}
	}

	if ( $body !== $original ) {
		wp_update_post( array(
			'ID'           => $page->ID,
			'post_content' => $body,
		) );
	}

	update_option( SN_PROV_BYLINE_RT_MIGR_OPT, time(), true );
}

/**
 * One-time migration that switches the /provenance pillar's two card
 * meta lines from hardcoded reading times ("4 min read" / "5 min read")
 * to dynamic `[sn_reading_time slug="..."]` shortcodes pointing at the
 * respective child long-forms. Without this, the pillar drifts every
 * time the prose evolves — the live drift between the pillar's "4 min"
 * and the over-detection byline's "5 min" was the trigger for this
 * migration.
 *
 * Strategy mirrors `sn_migrate_provenance_card2_longform()`: full-body
 * rewrite via `sn_provenance_papers_index_markup()`, gated on a unique
 * marker (the SSRN abstract_id 6730343 anchor on Card 2). If the
 * pillar body has been hand-edited away from seed shape, bail WITHOUT
 * setting the flag so a future run can complete after recovery.
 *
 * Self-idempotent: bails (and flags) if the body already contains the
 * shortcode token `[sn_reading_time slug=` — the unique marker for the
 * post-migration shape.
 */
function sn_migrate_provenance_card_readtimes_dynamic() {
	if ( get_option( SN_PROV_RT_DYNAMIC_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		update_option( SN_PROV_RT_DYNAMIC_OPT, time(), true );
		return;
	}

	$body = $page->post_content;

	// Already migrated — body uses the dynamic shortcode form.
	if ( false !== strpos( $body, '[sn_reading_time slug=' ) ) {
		update_option( SN_PROV_RT_DYNAMIC_OPT, time(), true );
		return;
	}

	// Defensive: only proceed if the v6.5.4 / Card-2-longform-migration
	// shape is present (SSRN abstract_id 6730343 anchor for Card 2).
	if ( false === strpos( $body, 'abstract_id=6730343' ) ) {
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => sn_provenance_papers_index_markup(),
	) );

	update_option( SN_PROV_RT_DYNAMIC_OPT, time(), true );
}
