<?php
/**
 * Signal & Noise Tools: per-post OG/Twitter card generator, the hooks (the save sync and its hook, the delete hook, the two one-time migrations, the image URL resolvers and their filter).
 *
 * Split from inc/og-card-generator.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reconcile a post's cached card on save. Extracted from the hook closure so
 * the branching is CLI-testable.
 *
 * Order matters, and the security cleanup comes FIRST so it is NOT limited to
 * post/page: a card can be generated for ANY editable post type via the
 * admin-bar quick action, the regenerate-og-card ability, or the AI-title
 * rewrite (all call sn_generate_og_card() with no post_type restriction). So a
 * post of any type that becomes password-protected must have its stale card
 * removed here — scoping the delete to post/page (as auto-generation is) would
 * strand a custom-post-type card on disk, the exact leak this guards against.
 * Auto-(re)generation on save stays post/page only; other types get a card
 * solely through an explicit regen action.
 *
 * @since 9.25.2
 * @param int            $post_id
 * @param WP_Post|object $post
 * @return bool Whether a card was (re)generated.
 */
function sn_og_sync_card_on_save( $post_id, $post ) {
	// Security cleanup — any post type, any status.
	if ( ! sn_og_card_allowed_for_post( $post ) ) {
		sn_og_delete_card( $post_id );
		return false;
	}
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return false;
	}
	if ( 'publish' !== $post->post_status ) {
		return false;
	}
	return (bool) sn_generate_og_card( $post_id );
}

/**
 * Rebuild the cached card whenever a post or page is saved. Skips
 * revisions/autosaves; delegates the rest to sn_og_sync_card_on_save().
 */
add_action( 'wp_after_insert_post', function( $post_id, $post, $update, $post_before ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	sn_og_sync_card_on_save( $post_id, $post );
}, 20, 4 );

// v9.88.0 (hardening gate): permanent delete never reaches the save hook, so
// the public PNG — whose provenance iTXt chunk carries the full post text —
// would outlive the post entirely at a guessable URL.
add_action( 'before_delete_post', 'sn_og_delete_card', 10, 1 );

/**
 * Backfill option flag — set to a unix timestamp once the migration
 * has scanned all published posts/pages and generated any missing
 * cards. Pre-existing content (posts that pre-date this module, or
 * posts that have never been re-saved since v6.3.2) gets cards via
 * this migration so the front-end never has to fall back to the site
 * default OG image for known content.
 */
const SN_OG_BACKFILL_OPT = 'sn_og_backfill_completed_v1';

/**
 * One-time backfill: scan published posts/pages, generate any missing
 * OG cards. Replaces the previous lazy-on-request path that used to
 * sit inside `sn_og_image_url_for_post()` and could block the request
 * if the generator hit a bug.
 *
 * Runs on admin_init at priority 5 (early, before other migrations
 * that might rely on cards). Idempotent: gated by SN_OG_BACKFILL_OPT,
 * runs at most once per install. Sets the flag whether or not any
 * specific generation succeeded — the wp_after_insert_post hook will
 * retry per-post on next save, and we'd rather not re-scan every
 * admin pageload forever.
 *
 * Robustness: each `sn_generate_og_card()` call is independent; a
 * failure on one post (returns false) doesn't abort the rest.
 * `sn_generate_og_card()` itself is best-effort and quiet on failure.
 */
add_action( 'admin_init', 'sn_migrate_backfill_og_cards', 5 );

function sn_migrate_backfill_og_cards() {
	if ( get_option( SN_OG_BACKFILL_OPT ) ) {
		return;
	}

	$dir = sn_og_upload_dir();
	if ( ! $dir ) {
		// Uploads dir unavailable — mark done so we don't loop forever.
		// If the dir becomes available later, new content gets cards via
		// wp_after_insert_post anyway.
		update_option( SN_OG_BACKFILL_OPT, time(), true );
		return;
	}

	$post_ids = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true, // skip SQL_CALC_FOUND_ROWS for speed
	) );

	foreach ( $post_ids as $post_id ) {
		$path = $dir['path'] . '/post-' . (int) $post_id . '.png';
		if ( file_exists( $path ) ) {
			continue;
		}
		// Best-effort. Failures are silent and naturally retry on save.
		sn_generate_og_card( $post_id );
	}

	update_option( SN_OG_BACKFILL_OPT, time(), true );
}

/**
 * Purge option flag — set once the one-time protected-card sweep has run.
 */
const SN_OG_PROTECTED_PURGE_OPT = 'sn_og_protected_purge_completed_v1';

/**
 * One-time remediation: delete cards already on disk for password-protected
 * published posts of ANY type. The generation/serving gates keep NEW and FUTURE
 * leaks closed, but cards were written before those gates existed — by the
 * backfill (SN_OG_BACKFILL_OPT) for post/page, and by the admin-bar/ability/
 * AI-title regen callers for any editable type. Those PNGs still sit on disk at
 * a guessable URL, so this removes them. Posts protected AFTER this runs are
 * handled by sn_og_sync_card_on_save() on the next save.
 *
 * Runs on admin_init at priority 5, idempotent, at most once per install
 * (gated by SN_OG_PROTECTED_PURGE_OPT), mirroring sn_migrate_backfill_og_cards().
 *
 * @since 9.25.2
 */
add_action( 'admin_init', 'sn_migrate_purge_protected_og_cards', 5 );

function sn_migrate_purge_protected_og_cards() {
	if ( get_option( SN_OG_PROTECTED_PURGE_OPT ) ) {
		return;
	}

	$dir = sn_og_upload_dir();
	if ( ! $dir ) {
		// Uploads dir unavailable — mark done so we don't loop forever. Future
		// protected saves are still handled by the wp_after_insert_post hook.
		update_option( SN_OG_PROTECTED_PURGE_OPT, time(), true );
		return;
	}

	$post_ids = get_posts( array(
		'post_type'      => 'any', // any card-bearing type, not just post/page
		'post_status'    => 'publish',
		'has_password'   => true,  // only password-protected posts
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );

	foreach ( $post_ids as $post_id ) {
		sn_og_delete_card( $post_id );
	}

	update_option( SN_OG_PROTECTED_PURGE_OPT, time(), true );
}

/**
 * Theme's own OG emitter (inc/seo.php) reads through this filter.
 */
/**
 * Resolve the og:image URL for the current post. Precedence: _sn_og_image_url
 * override → featured image / generated card → site default
 * fallback (v9.3.0 seam, defaults to the passed site default) → site default.
 * Named so the chain is CLI-testable (the filter closure just forwards).
 *
 * @since 9.3.0
 * @param string              $default Site-default OG image URL.
 * @param WP_Post|object|null $post
 * @return string
 */
function sn_resolve_og_image_url( $default, $post ) {
	if ( ! $post ) {
		return $default;
	}
	// v1.10.0+: per-post _sn_og_image_url wins over featured image + card.
	if ( function_exists( 'sn_post_settings_get_og_image_url' ) ) {
		$override = sn_post_settings_get_og_image_url( $post->ID );
		if ( '' !== $override ) {
			return $override;
		}
	}
	$url = sn_og_image_url_for_post( $post );
	if ( $url ) {
		return $url;
	}
	// v9.3.0: theme-owned per-route fallback image; defaults to $default.
	// v9.84.0: deprecated ahead of its v10.0.0 removal — shadowed dead code
	// since the D2 generated card; the bespoke path is the _sn_og_image_url meta.
	// v10.0.0: the sn_seo_singular_og_image seam is REMOVED. It was shadowed
	// dead code from the moment the generated per-Page card shipped — the
	// bespoke path is the _sn_og_image_url post-meta override above.
	return (string) $default;
}

/**
 * Filter body for `sn_og_image_url`. Only a SINGULAR view (a single Note/Page,
 * or a static front page) has one authoritative post whose generated card
 * represents the page. On the blog index, tag archives, and search results
 * get_post() returns the loop's first post (often the sticky "Start here"
 * Note), whose card must NOT be borrowed as the page's shared image: that
 * mislabels the /notes index and every search result with a single Note's card
 * while the og:title/alt correctly read "Notes". Non-singular views fall through
 * to the site default. Template Pages (e.g. /colophon) are real Pages, so
 * is_singular() keeps resolving their own card. Named for CLI testability.
 *
 * @since 9.25.4
 * @param string $default Site-default OG image URL.
 * @return string
 */
function sn_og_image_url_for_current_view( $default ) {
	if ( ! is_singular() ) {
		return $default;
	}
	return sn_resolve_og_image_url( $default, get_post() );
}

add_filter( 'sn_og_image_url', 'sn_og_image_url_for_current_view' );

// Note: Phase 10 (plugin v1.6.0) removed three dead `wpseo_*` filter hooks
// that targeted Yoast SEO's filter namespace. The active site runs The SEO
// Framework (`the_seo_framework_*` namespace), so those hooks never fired.
// The OG card URL surfaces through our own `sn_og_image_url` filter, which
// `inc/seo.php` reads when emitting `<meta property="og:image">` directly.
