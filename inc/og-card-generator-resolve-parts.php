<?php
/**
 * Signal & Noise Tools: per-post OG/Twitter card generator, the card's inputs (the upload folder, the eligibility test, the card delete, the image URL for a post, the dek source, the footer, the title fit).
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
 * Resolve the upload dir for OG cards. Creates the subdirectory on
 * first call. Returns false if WP can't determine an upload dir at all
 * (e.g. permissions error in a misconfigured install).
 *
 * @return array{path: string, url: string}|false
 */
function sn_og_upload_dir() {
	$up = wp_upload_dir();
	if ( ! empty( $up['error'] ) ) {
		return false;
	}
	$path = $up['basedir'] . '/' . SN_OG_DIRNAME;
	$url  = $up['baseurl'] . '/' . SN_OG_DIRNAME;
	if ( ! file_exists( $path ) && ! wp_mkdir_p( $path ) ) {
		return false;
	}
	return array( 'path' => $path, 'url' => $url );
}

/**
 * Guard: may this post carry a generated content card?
 *
 * A card bakes the post title and up to ~36 words of post_content (via
 * sn_og_card_dek_source()) into a PNG served publicly and WITHOUT auth at
 * wp-content/uploads/sn-og/post-{ID}.png. A password-protected post is still
 * post_status='publish', so the status-keyed generation/serving gates let it
 * through — and its protected title + dek would leak to anyone as visible
 * pixels, bypassing the password. This predicate withholds the card for
 * exactly those posts.
 *
 * Mirrors the D1/D2 content-credential gate in sn_prov_credential()
 * (inc/provenance-credential.php): that withholds the *metadata* credential
 * for a protected/unpublished Note; this withholds the rendered *pixels*.
 *
 * Extracted as a named predicate so the gate is CLI-testable without GD/fonts
 * (same rationale as sn_og_card_dek_source() / sn_resolve_og_image_url()) and
 * so every card path — generation, serving, save hook, purge — shares one
 * source of truth.
 *
 * @since 9.25.2
 * @param WP_Post|object|null $post
 * @return bool True when a card may be generated/served; false to withhold it.
 */
function sn_og_card_allowed_for_post( $post ) {
	if ( ! $post ) {
		return false;
	}
	// Read post_password defensively via ?? so a partial post object (e.g. a
	// stdClass fixture lacking the property, as some CLI tests pass) doesn't
	// emit an "Undefined property" warning. A real WP_Post always carries the
	// property, so production behaviour is identical; a missing/empty value
	// means "no password" → card allowed, which preserves the security gate.
	return '' === (string) ( $post->post_password ?? '' );
}

/**
 * Delete the cached OG card PNG for a post, if one exists. Used to remove a
 * card that must no longer be served — e.g. when a post becomes
 * password-protected (the wp_after_insert_post hook) or the one-time
 * protected-card purge migration. Best-effort and quiet: returns true only
 * when a file was actually removed.
 *
 * @since 9.25.2
 * @param int $post_id
 * @return bool
 */
function sn_og_delete_card( $post_id ) {
	$dir = sn_og_upload_dir();
	if ( ! $dir ) {
		return false;
	}
	$path = $dir['path'] . '/post-' . (int) $post_id . '.png';
	if ( ! file_exists( $path ) ) {
		return false;
	}
	wp_delete_file( $path );
	return ! file_exists( $path );
}

/**
 * Return the OG image URL for a post, with a cache-buster query string
 * tied to post-modified time. Featured image wins; otherwise the
 * generated card; null if neither is available so callers can fall
 * back to their default.
 *
 * NON-BLOCKING CONTRACT (architectural, post-incident):
 *
 * This function is called inside `wp_head` for every singular page
 * render. It MUST NOT do any unbounded synchronous work — no GD
 * rendering, no network calls, no large file I/O. On cache miss it
 * returns `null` and lets the caller fall back to the site default.
 * That's a fine fallback (the site logo card is perfectly serviceable
 * for social shares).
 *
 * Why: prior versions of this function called `sn_generate_og_card()`
 * synchronously on cache miss. A latent UTF-8 truncation bug in the
 * generator caused infinite loops on certain post excerpts, which
 * blocked the request path, pinned a PHP-FPM worker at 100% CPU, and
 * cascaded across the worker pool until the entire site was
 * unresponsive (incident 2026-05-07, see CHANGELOG entry of the same
 * date). The fix to the truncation bug shipped as `e006841` — but the
 * deeper lesson was structural: an OG card is decorative, and
 * decorative work must never block essential work. This function now
 * encodes that as a contract.
 *
 * Cards are generated via two non-blocking paths instead:
 *   1. `wp_after_insert_post` hook (admin save context, well-bounded)
 *   2. `sn_migrate_backfill_og_cards()` one-time admin_init migration
 *      that proactively fills any missing cards
 *
 * If something slips past both — a post that pre-dates the og-image
 * module and hasn't been re-saved since — the page just shows the
 * site default OG. Acceptable degradation; no hangs possible.
 *
 * @param int|WP_Post $post
 * @return string|null
 */
function sn_og_image_url_for_post( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return null;
	}

	if ( has_post_thumbnail( $post ) ) {
		$thumb = get_the_post_thumbnail_url( $post, 'large' );
		if ( $thumb ) {
			return $thumb;
		}
	}

	// Security: never surface the generated content card for a
	// password-protected post — it bakes the protected title + dek into public
	// pixels served without auth. The featured image above is author-chosen and
	// safe; this only withholds the auto-generated card. Guards pre-existing
	// cards on disk too (the backfill migration already generated one for every
	// published post, protected ones included), so this — not just the
	// generation gate — is what closes the live leak.
	if ( ! sn_og_card_allowed_for_post( $post ) ) {
		return null;
	}

	$dir = sn_og_upload_dir();
	if ( ! $dir ) {
		return null;
	}

	$filename = 'post-' . $post->ID . '.png';
	$path     = $dir['path'] . '/' . $filename;

	// NO synchronous generation here — see the function header.
	// On cache miss we fall through and return null so the caller can
	// use the default OG image. The backfill migration handles
	// pre-existing posts; the wp_after_insert_post hook handles new
	// content; both run outside the request path.
	if ( ! file_exists( $path ) ) {
		return null;
	}

	$bust = (int) get_post_modified_time( 'U', true, $post );
	return $dir['url'] . '/' . $filename . '?v=' . $bust;
}

/**
 * Render and cache an OG card for a post. Returns true on success,
 * false on any failure (missing GD, missing fonts, write error). The
 * function is intentionally quiet — OG card generation isn't critical
 * path, and failure modes naturally cascade to the default fallback.
 *
 * @param int $post_id
 * @return bool
 */
/**
 * Resolve the card's dek/excerpt line. Precedence: post_excerpt →
 * content-derived trim → sn_seo_singular_description theme fallback (v9.3.0,
 * so contentless template Pages get a real dek from curated copy) → blank.
 * Extracted from the generator so the chain is CLI-testable.
 *
 * @since 9.3.0
 * @param WP_Post|object $post
 * @return string
 */
function sn_og_card_dek_source( $post ) {
	// Unreleased: a PAGE's card says what its meta description says
	// (override, then excerpt, then the route copy), so the two can never
	// disagree. The resume card printed a stale "Twenty-plus years" excerpt
	// under a "15+ years" title while the description, an override, said 15+.
	// Notes keep their excerpt: that is the dek they were written with.
	if ( 'page' === ( $post->post_type ?? '' ) && function_exists( 'sn_seo_description_for_post' ) ) {
		$description = trim( (string) sn_seo_description_for_post( $post ) );
		if ( '' !== $description ) {
			return $description;
		}
	}
	$excerpt = trim( (string) $post->post_excerpt );
	if ( '' === $excerpt ) {
		$content = (string) $post->post_content;

		// v13.109.2: drop a LEADING heading before deriving. Without this the
		// card's dek opened with the page's own title, printed a second time
		// directly under the title the card already shows in 88px Bebas —
		// "ON PROVENANCE / On Provenance Two papers, three long-form essays…"
		// — and those wasted words pushed the real sentence into the ellipsis.
		//
		// It only bites on PAGES, and only with an empty excerpt. A note takes
		// its title from post_title and its content starts with prose, so this
		// fallback never had a heading to trip over. A page template that
		// renders post-content ALONE — templates/page-provenance.html has no
		// post-title block — must carry its <h1> inside the content, and then
		// the first 36 words start with it. Both conditions were needed, which
		// is why it surfaced only when that page's excerpt was cleared.
		//
		// Leading only: a heading further down is a section title inside the
		// prose and stripping every one of them would splice unrelated
		// sentences together.
		$content = preg_replace( '#^\s*(?:<!--\s*wp:heading[^>]*-->)?\s*<h[1-6][^>]*>.*?</h[1-6]>\s*(?:<!--\s*/wp:heading\s*-->)?#is', '', $content, 1 );

		$cleaned = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', ' ', $content );
		$cleaned = wp_strip_all_tags( strip_shortcodes( $cleaned ) );
		$excerpt = wp_trim_words( $cleaned, 36, '…' );
	}
	if ( '' === trim( $excerpt ) ) {
		$excerpt = (string) apply_filters( 'sn_seo_singular_description', '', $post );
	}
	return $excerpt;
}

/**
 * The card's footer line: reading time for a note, nothing for a page. A
 * resume or a landing page is not read start to finish, and "6 MIN READ" on
 * the resume card read as a typo (Unreleased).
 *
 * @param object $post
 * @return string
 */
function sn_og_card_footer( $post ) {
	if ( 'page' === ( $post->post_type ?? '' ) ) {
		return '';
	}
	$minutes = function_exists( 'sn_get_reading_time' ) ? sn_get_reading_time( $post ) : 1;
	return strtoupper( $minutes . ' MIN READ' );
}

/**
 * Auto-size the title so a long one doesn't collide with the dek. Tries each
 * [size, line_height] in $ladder (largest first) and returns the first whose
 * wrapped block's last baseline clears $limit; falls back to the smallest step
 * (then the wrap ellipsizes) when even that overflows. Pure — $wrap( $size )
 * returns the wrapped lines at that size, so the sizing decision is testable
 * without GD.
 *
 * @since 9.25.2
 * @param array<int,array{0:int,1:int}> $ladder    [ [size, line_height], ... ], largest first.
 * @param callable                      $wrap      fn(int $size): string[]
 * @param int                           $title_top First title baseline (y).
 * @param int                           $limit     Max allowed last-line baseline (y).
 * @return array{size:int,lh:int,lines:array<int,string>}
 */
function sn_og_fit_title( array $ladder, callable $wrap, $title_top, $limit ) {
	$chosen = array( 'size' => 88, 'lh' => 100, 'lines' => array() );
	foreach ( $ladder as $step ) {
		$size   = (int) $step[0];
		$lh     = (int) $step[1];
		$lines  = (array) $wrap( $size );
		$n      = max( 1, count( $lines ) );
		$bottom = (int) $title_top + ( $n - 1 ) * $lh;
		$chosen = array( 'size' => $size, 'lh' => $lh, 'lines' => $lines );
		if ( $bottom <= (int) $limit ) {
			return $chosen;
		}
	}
	return $chosen; // Nothing cleared the limit — use the smallest ladder step.
}

