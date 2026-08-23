<?php
/**
 * Signal & Noise — content migration: the Music page body.
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
 * Registered migrations: sn_migrate_music_body
 *
 * @package SignalNoiseTools
 * @since 12.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the seeded /music hero + Spotify-embeds-open markup (the part of the
 * page-music.html template that sits ABOVE wp:post-content). Mirrors
 * sn_load_about_body() — same empty-string fallback semantics.
 */
function sn_load_music_above() {
	$body_file = sn_content_seed_file( 'music-above.html' );
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded /music discography-shortcode + Spotify-embeds-close +
 * Muso-credits markup (the part of the page-music.html template that sits
 * BELOW wp:post-content). Mirrors sn_load_music_above() — same empty-string
 * fallback semantics.
 */
function sn_load_music_below() {
	$body_file = sn_content_seed_file( 'music-below.html' );
	return file_exists( $body_file ) ? (string) file_get_contents( $body_file ) : '';
}

/**
 * One-time migration flipping /music from template-authored to
 * CMS-authored: the live Page's post_content today holds ONLY the
 * featured-player shortcode content (the theme template renders the hero
 * prose + Spotify-embeds wrapper + discography shortcode + Muso-credits
 * section AROUND it). Mirrors sn_migrate_resume_body() — same merge and
 * safety semantics, gated by SN_MUSIC_BODY_MERGED_OPT and the hero
 * eyebrow sentinel.
 */
function sn_migrate_music_body() {
	if ( get_option( SN_MUSIC_BODY_MERGED_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_MUSIC_SLUG );
	if ( ! $page ) {
		// Page doesn't exist yet — nothing to merge. Mark migrated so we
		// don't keep checking.
		update_option( SN_MUSIC_BODY_MERGED_OPT, time(), true );
		return;
	}

	if ( false !== strpos( (string) $page->post_content, 'Catalog · Discography' ) ) {
		// Already merged — the hero sentinel is present. Never double-merge.
		update_option( SN_MUSIC_BODY_MERGED_OPT, time(), true );
		return;
	}

	$above = sn_load_music_above();
	$below = sn_load_music_below();
	if ( '' === $above || '' === $below ) {
		// Seed file missing — leave the Page alone, do not mark migrated
		// so we retry on next admin_init in case the file lands later.
		return;
	}

	$merged = $above . "\n\n" . (string) $page->post_content . "\n\n" . $below;

	$update = array(
		'ID'           => $page->ID,
		'post_content' => $merged,
	);

	// Only seed the excerpt when it's genuinely empty — never clobber an
	// owner-written excerpt.
	if ( '' === trim( (string) $page->post_excerpt ) ) {
		$update['post_excerpt'] = 'Selected discography: releases produced, mixed, and engineered by Juan Lentino, with credits and streaming links.';
	}

	wp_update_post( $update );
	update_option( SN_MUSIC_BODY_MERGED_OPT, time(), true );
}
