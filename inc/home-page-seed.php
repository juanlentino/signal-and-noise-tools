<?php
/**
 * Signal & Noise Tools: seed the Home page (the static front page) with its hero.
 *
 * Until theme 14.3 the hero lived in the theme's templates/front-page.html and
 * the front-page Page (Settings → Reading → Homepage) stayed empty: 0 words,
 * never rendered. A Site Editor edit to the hero line was a wp_template
 * override, and the theme deletes those on every activation and every Purge
 * All Caches, so the owner's edits kept vanishing (2026-09-23). The theme now
 * renders the Page's content, like About or Services; this seeds that Page
 * once, from inc/seed-content/home-body.html, the same markup the template had
 * with its raw-HTML wrapper turned into a Group so the editor can hold it.
 *
 * Create-once, never-clobber, retry-safe, the contract of the spent seeds in
 * inc/content-migrations/, but on its own admin_init hook: the master sentinel
 * over that set is already stamped on the live site, so a new registry entry
 * would never run.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_HOME_PAGE_SEEDED_OPT = 'sn_home_page_seeded_v1';

/**
 * The frozen hero markup ('' when the seed file is missing).
 *
 * @return string
 */
function sn_load_home_body() {
	$file = sn_content_seed_file( 'home-body.html' );
	return file_exists( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin-shipped seed file.
}

/**
 * Seed the front-page Page when it is empty. Never touches a Page with
 * content; does nothing (and does not flag) while there is no static front
 * page or the seed file is missing, so it retries on the next admin load.
 */
function sn_seed_home_page() {
	if ( get_option( SN_HOME_PAGE_SEEDED_OPT ) ) {
		return;
	}
	if ( 'page' !== get_option( 'show_on_front' ) ) {
		return;
	}
	$page = get_post( (int) get_option( 'page_on_front' ) );
	if ( ! $page || 'page' !== $page->post_type ) {
		return;
	}
	if ( '' !== trim( (string) $page->post_content ) ) {
		update_option( SN_HOME_PAGE_SEEDED_OPT, time(), false ); // Owner content: never overwrite.
		return;
	}
	$body = sn_load_home_body();
	if ( '' === trim( $body ) ) {
		return;
	}
	$updated = wp_update_post(
		wp_slash(
			array(
				'ID'           => $page->ID,
				'post_content' => $body,
			)
		),
		true
	);
	if ( is_wp_error( $updated ) || ! $updated ) {
		return; // Retry on the next admin load.
	}
	update_option( SN_HOME_PAGE_SEEDED_OPT, time(), false );
}
add_action( 'admin_init', 'sn_seed_home_page' );
