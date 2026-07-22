<?php
/**
 * Signal & Noise Tools — one-time pillar meta seed (v9.79.1).
 *
 * v9.79.0 shipped the pillar curation keys expecting the owner to hand-
 * enter the three known designations in the post-settings panel; the
 * live rail fell back to hub-children date order instead (owner-caught
 * 2026-07-21, the panel was never filled). This seeds the three essays
 * ONCE: flag + designation, never overwriting a value that already
 * exists on a page (owner edits always win).
 *
 * admin_init + option sentinel, not an activation hook: install hooks
 * cannot observe the deploy path (the WP updater replaces the plugin
 * dir without firing activation), so seeding rides the first wp-admin
 * visit by a user who can edit_pages. A cap-less visit returns without
 * burning the sentinel so a later editor visit retries. Sentinel is a
 * plain option, autoload=no (transients are flush-volatile here).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_PILLAR_SEED_OPTION = 'sn_pillar_meta_seeded';

/**
 * The three known essays and the owner's designations for them
 * (over-detection = 1.00, cheap-option = 1.01, as-substrate = 2.00).
 *
 * One-shot SEED data, not runtime derivation: the rail itself reads
 * only the per-Page meta, and a future 3.00 needs no code anywhere.
 *
 * @return array<string, string> page path => designation.
 */
function sn_pillar_seed_map() {
	return array(
		'provenance/over-detection' => '1.00',
		'provenance/cheap-option'   => '1.01',
		'provenance/as-substrate'   => '2.00',
	);
}

/**
 * Seed once. Skips any page carrying EITHER pillar key already (no
 * partial writes: a page the owner touched is theirs), password-
 * protected pages (the theme's rail gates them out), and missing
 * pages. The sentinel is set even when pages were skipped: the seed
 * is a deliberate one-shot, not a reconciler.
 */
function sn_pillar_meta_seed() {
	if ( get_option( SN_PILLAR_SEED_OPTION ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	foreach ( sn_pillar_seed_map() as $path => $designation ) {
		$page = get_page_by_path( $path );
		if ( ! $page || 'publish' !== (string) ( $page->post_status ?? '' ) || '' !== (string) ( $page->post_password ?? '' ) ) {
			continue;
		}
		if ( '' !== (string) get_post_meta( $page->ID, '_sn_pillar', true )
			|| '' !== (string) get_post_meta( $page->ID, '_sn_pillar_designation', true ) ) {
			continue;
		}
		update_post_meta( $page->ID, '_sn_pillar', '1' );
		update_post_meta( $page->ID, '_sn_pillar_designation', $designation );
	}
	update_option( SN_PILLAR_SEED_OPTION, '9.79.1', false );
}
add_action( 'admin_init', 'sn_pillar_meta_seed' );
