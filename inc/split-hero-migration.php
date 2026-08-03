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

const SN_SPLIT_HERO_V2_OPT = 'sn_split_hero_v2_migrated_v1';

/**
 * v10.36.1 composition repair, second one-shot (the v1 flag is spent on
 * live). Owner report from the live v10.36.0 rollout:
 *
 *   1. /resume: bottom alignment sank the title below a tall right column
 *      — the engine now emits top-aligned columns; regenerate once.
 *   2. /contact: three drifting left edges (1320px hero, centered 760px
 *      prose, floating availability). Letterhead grid instead: hero goes
 *      top-aligned (availability = top-right stamp) and the sn-prose-links
 *      band re-bands to 1320px with the prose in the 55% column, locking
 *      its left edge to the title. Both swaps are exact-literal
 *      replacements of the v10.36.0 markup this plugin itself wrote —
 *      any owner edit since → no match → permanent skip, never clobbered.
 */
function sn_migrate_split_hero_v2() {
	if ( get_option( SN_SPLIT_HERO_V2_OPT ) ) {
		return;
	}

	$page = get_page_by_path( 'contact' );
	if ( ! $page ) {
		return; // Retry next admin_init.
	}

	$content = (string) $page->post_content;
	$dir     = __DIR__ . '/seed-content/';
	$pairs   = array(
		array( 'split-hero-contact.html', 'split-hero-contact-hero-v2.html' ),
		// The pre-split sn-prose-links band survives v1 verbatim: frozen here
		// as a seed so the literal lives on disk, not inline.
		array( 'split-hero-contact-prose-v1.html', 'split-hero-contact-prose-v2.html' ),
	);
	foreach ( $pairs as $pair ) {
		$old = file_exists( $dir . $pair[0] ) ? trim( (string) file_get_contents( $dir . $pair[0] ) ) : '';
		$new = file_exists( $dir . $pair[1] ) ? trim( (string) file_get_contents( $dir . $pair[1] ) ) : '';
		if ( '' !== $old && '' !== $new && 1 === substr_count( $content, $old ) ) {
			$content = str_replace( $old, $new, $content );
		}
	}
	if ( $content !== (string) $page->post_content ) {
		wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => $content,
			)
		);
	}

	// /resume: one regenerate so the stored body picks up the top-aligned
	// hero (no-op-safe — never blanks the page).
	if ( function_exists( 'sn_resume_sync_page' ) ) {
		sn_resume_sync_page();
	}

	update_option( SN_SPLIT_HERO_V2_OPT, time(), false );
}
add_action( 'admin_init', 'sn_migrate_split_hero_v2' );

const SN_SPLIT_HERO_V3_OPT = 'sn_split_hero_v3_migrated_v1';

/**
 * v10.36.2 — /contact centered spine (owner direction after rejecting the
 * v2 letterhead): one centered axis on the wide band — centered eyebrow,
 * centered CONTACT, availability line centered under the title — and the
 * prose keeps its original centered reading band, verbatim, widened a
 * touch (760px -> 880px, owner direction) to balance the title mass. Handles both possible live states
 * (v2 installed, or still on v10.36.0), exact-literal swaps only; an
 * owner-edited body skips, never clobbered.
 */
function sn_migrate_split_hero_v3() {
	if ( get_option( SN_SPLIT_HERO_V3_OPT ) ) {
		return;
	}

	$page = get_page_by_path( 'contact' );
	if ( ! $page ) {
		return; // Retry next admin_init.
	}

	$content = (string) $page->post_content;
	$dir     = __DIR__ . '/seed-content/';
	$pairs   = array(
		// From the v2 letterhead state…
		array( 'split-hero-contact-hero-v2.html', 'split-hero-contact-hero-v3.html' ),
		array( 'split-hero-contact-prose-v2.html', 'split-hero-contact-prose-v3.html' ),
		// …or straight from the v10.36.0 state (v2 skipped/not yet run),
		// where the prose still IS the original band (frozen as prose-v1).
		array( 'split-hero-contact.html', 'split-hero-contact-hero-v3.html' ),
		array( 'split-hero-contact-prose-v1.html', 'split-hero-contact-prose-v3.html' ),
	);
	foreach ( $pairs as $pair ) {
		$old = file_exists( $dir . $pair[0] ) ? trim( (string) file_get_contents( $dir . $pair[0] ) ) : '';
		$new = file_exists( $dir . $pair[1] ) ? trim( (string) file_get_contents( $dir . $pair[1] ) ) : '';
		if ( '' !== $old && '' !== $new && 1 === substr_count( $content, $old ) ) {
			$content = str_replace( $old, $new, $content );
		}
	}
	if ( $content !== (string) $page->post_content ) {
		wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => $content,
			)
		);
	}

	update_option( SN_SPLIT_HERO_V3_OPT, time(), false );
}
add_action( 'admin_init', 'sn_migrate_split_hero_v3' );
