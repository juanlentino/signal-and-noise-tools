<?php
/**
 * Signal & Noise Tools — v10.36.0 split-hero one-shot migration.
 *
 * Site-wide hero direction (2026-08-03): heroes become a two-column,
 * bottom-aligned editorial split on a uniform 1320px band. This one-shot
 * finishes the rollout for surfaces the per-save sync engines don't own:
 *
 *   1. The four hand-authored CMS pages (About, Services, Music, Contact):
 *      each page's leading hero band is swapped for the corresponding
 *      inc/seed-content/split-hero-*.html seed — but ONLY when the current
 *      band md5-matches the frozen hash captured from the live body on
 *      2026-08-03. Any owner edit since means no match → that page is
 *      skipped forever (never clobbers, mirrors the never-clobber guards
 *      in inc/content-migrations.php).
 *   2. /resume, /now, /uses: regenerated once via their sync engines so
 *      the split-hero markup shipped in v10.35.0/v10.36.0 reaches the
 *      stored bodies without waiting for the next form save.
 *
 * Runs on its own admin_init hook — the v9.81.0 master sentinel is spent
 * on live, so joining sn_content_migrations_registry() would never fire.
 * Retry-safe: the flag is withheld while any of the four pages is absent.
 *
 * @package SignalNoiseTools
 * @since 10.36.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_SPLIT_HERO_MIGR_OPT = 'sn_split_hero_migrated_v1';

/**
 * The frozen guard set: page path → [md5 of the live hero band as of
 * 2026-08-03, seed filename]. The hero band is the body's leading
 * wp:group through its first closer (all four verified flat — no nested
 * groups).
 *
 * @return array<string, array{hash:string, seed:string}>
 */
function sn_split_hero_targets() {
	return array(
		'about'    => array(
			'hash' => '5d9349eaa2c597eaaabc58414f0f33c5',
			'seed' => 'split-hero-about.html',
		),
		'services' => array(
			'hash' => '39c39c1071ca3ced37345acf7225f32f',
			'seed' => 'split-hero-services.html',
		),
		'music'    => array(
			'hash' => '63dcaaccb8f44d0e630bcbd00238dc3d',
			'seed' => 'split-hero-music.html',
		),
		'contact'  => array(
			'hash' => 'b85da89bb2fcb0dbd81a56fe3d99d408',
			'seed' => 'split-hero-contact.html',
		),
	);
}

/**
 * Swap one page's leading hero band for the seed markup when the band
 * md5-matches the frozen hash. Returns true when the page no longer
 * needs work (replaced now, already replaced, or hash-mismatch skip),
 * false only when the page itself is missing (retry later).
 *
 * @param string $path Page path for get_page_by_path().
 * @param string $hash Frozen md5 of the pre-split hero band.
 * @param string $seed Seed filename under inc/seed-content/.
 * @return bool
 */
function sn_split_hero_swap_page( $path, $hash, $seed ) {
	$page = get_page_by_path( $path );
	if ( ! $page ) {
		return false;
	}

	$content = (string) $page->post_content;
	$closer  = '<!-- /wp:group -->';
	$end     = strpos( $content, $closer );
	if ( false === $end || 0 !== strpos( $content, '<!-- wp:group' ) ) {
		return true; // Unexpected shape — permanent skip, never guess.
	}

	$band = substr( $content, 0, $end + strlen( $closer ) );
	if ( md5( $band ) !== $hash ) {
		return true; // Owner-edited (or already migrated) — permanent skip.
	}

	$file = __DIR__ . '/seed-content/' . $seed;
	$new  = file_exists( $file ) ? trim( (string) file_get_contents( $file ) ) : '';
	if ( '' === $new ) {
		return true; // Missing seed — skip rather than blank a hero.
	}

	wp_update_post(
		array(
			'ID'           => $page->ID,
			'post_content' => $new . substr( $content, $end + strlen( $closer ) ),
		)
	);
	return true;
}

/**
 * The one-shot body: swap the four CMS heroes, then regenerate the three
 * engine-owned pages. Idempotent behind its own flag.
 */
function sn_migrate_split_hero() {
	if ( get_option( SN_SPLIT_HERO_MIGR_OPT ) ) {
		return;
	}

	$complete = true;
	foreach ( sn_split_hero_targets() as $path => $target ) {
		if ( ! sn_split_hero_swap_page( $path, $target['hash'], $target['seed'] ) ) {
			$complete = false; // Page absent — retry next admin_init.
		}
	}

	// Engine-owned pages: one regenerate each so the split hero lands
	// without waiting for the next form save. All three are no-op-safe
	// (they never blank a page when their document/box is empty).
	if ( function_exists( 'sn_resume_sync_page' ) ) {
		sn_resume_sync_page();
	}
	if ( function_exists( 'sn_now_sync_page' ) ) {
		sn_now_sync_page();
	}
	if ( function_exists( 'sn_uses_sync_page' ) ) {
		sn_uses_sync_page();
	}

	if ( $complete ) {
		update_option( SN_SPLIT_HERO_MIGR_OPT, time(), false );
	}
}
add_action( 'admin_init', 'sn_migrate_split_hero' );
