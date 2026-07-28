<?php
/**
 * Signal & Noise Tools — per-post OG/Twitter card generator.
 *
 * Renders a 1200×630 brutalist text card per post/page using PHP GD.
 * The active theme owns the typography (Bebas Neue Regular for the
 * title, DM Mono Light for the eyebrow/dek/footer) and registers
 * font paths via the `sn_og_font_paths` filter. Cards cache as PNG
 * files in `wp-content/uploads/sn-og/` and rebuild on every save
 * via `wp_after_insert_post`.
 *
 * Resolution order for "what image should Yoast/the theme emit":
 *
 *   1. Featured image, if the post has one — never overridden.
 *   2. Generated card (this module) — if the cache exists, or can be
 *      generated on demand.
 *   3. Fallback to whatever Yoast would have chosen (typically the
 *      site logo, or nothing).
 *
 * Moved from theme inc/og-image.php in Phase 3 (theme v8.4.0 /
 * plugin v1.3.0, 2026-05-16). The only structural change vs the
 * theme version: get_theme_file_path() font lookups replaced with
 * apply_filters('sn_og_font_paths', []) so the rendering pipeline
 * is theme-independent.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_OG_DIRNAME = 'sn-og';
const SN_OG_WIDTH   = 1200;
const SN_OG_HEIGHT  = 630;

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
	$excerpt = trim( (string) $post->post_excerpt );
	if ( '' === $excerpt ) {
		$cleaned = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', ' ', $post->post_content );
		$cleaned = wp_strip_all_tags( strip_shortcodes( $cleaned ) );
		$excerpt = wp_trim_words( $cleaned, 36, '…' );
	}
	if ( '' === trim( $excerpt ) ) {
		$excerpt = (string) apply_filters( 'sn_seo_singular_description', '', $post );
	}
	return $excerpt;
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

function sn_generate_og_card( $post_id ) {
	if ( ! function_exists( 'imagettftext' ) ) {
		return false;
	}
	$post = get_post( $post_id );
	if ( ! $post ) {
		return false;
	}

	// Security: never bake protected content into a public card. Placed inside
	// the shared generator so ALL callers are covered — the save hook, the
	// backfill migration, the rebuild-card ability, the admin-bar action, and
	// the AI-title rewrite. Also stops D2's provenance injection below from
	// running for a post whose credential sn_prov_credential() already withholds.
	if ( ! sn_og_card_allowed_for_post( $post ) ) {
		return false;
	}

	$dir = sn_og_upload_dir();
	if ( ! $dir ) {
		return false;
	}

	// Fonts are theme-owned (the brand owns the typography). Theme registers
	// a listener on this filter that returns absolute paths to its TTF files.
	// If no listener present, generator falls through to imagettftext error
	// handling (returns false; caller falls back to featured image).
	$font_paths  = (array) apply_filters( 'sn_og_font_paths', array() );
	$bebas_path  = $font_paths['bebas'] ?? '';
	$dmmono_path = $font_paths['dmmono'] ?? '';

	if ( ! $bebas_path || ! file_exists( $bebas_path ) || ! $dmmono_path || ! file_exists( $dmmono_path ) ) {
		// No theme-registered fonts — bail. Yoast falls back to featured image.
		return false;
	}

	$im    = imagecreatetruecolor( SN_OG_WIDTH, SN_OG_HEIGHT );
	$white = imagecolorallocate( $im, 255, 255, 255 );
	$black = imagecolorallocate( $im, 0, 0, 0 );
	$gray  = imagecolorallocate( $im, 102, 102, 102 ); // matches "rust" #666666
	$red   = imagecolorallocate( $im, 224, 4, 4 );     // matches "blood" #e00404
	imagefilledrectangle( $im, 0, 0, SN_OG_WIDTH, SN_OG_HEIGHT, $white );

	$pad_x     = 80;
	$right     = SN_OG_WIDTH - $pad_x;
	$max_width = $right - $pad_x;

	// Top accent: red bar + site eyebrow.
	imagefilledrectangle( $im, $pad_x, 80, $pad_x + 60, 84, $red );
	imagettftext( $im, 18, 0, $pad_x, 130, $gray, $dmmono_path, 'JUANLENTINO.COM' );

	// Title: Bebas Neue, big, up to 3 lines.
	//
	// Filterable so plugin v2.4.0+ can substitute an AI-generated punchier
	// variant when _sn_og_card_title post meta is set. Default = post_title,
	// matching pre-v2.4.0 behavior exactly. The og:title HTML meta tag is
	// unaffected — it continues to emit the canonical article title; only
	// the visual title baked into the PNG changes.
	$title     = (string) apply_filters( 'sn_og_card_title', $post->post_title, $post->ID );
	$title_top = 250;
	// v9.25.2: auto-size the title. A 3-line title at 88px used to land its last
	// line on y=250+200=450 — exactly the dek's first baseline — so the two
	// overlapped. Shrink the title one ladder step when it would reach into the
	// dek zone (its last baseline must clear the dek's 450 by 64px). 1-2 line
	// titles are unchanged (88px); only the long ones step down.
	$fit = sn_og_fit_title(
		array( array( 88, 100 ), array( 74, 84 ), array( 62, 72 ) ),
		function ( $size ) use ( $title, $bebas_path, $max_width ) {
			return sn_og_wrap_lines( $title, $size, $bebas_path, $max_width, 3 );
		},
		$title_top,
		( SN_OG_HEIGHT - 180 ) - 64
	);
	$y = $title_top;
	foreach ( $fit['lines'] as $line ) {
		imagettftext( $im, $fit['size'], 0, $pad_x, $y, $black, $bebas_path, $line );
		$y += $fit['lh'];
	}

	// Excerpt/dek: post_excerpt → content-derived → theme fallback (v9.3.0).
	$excerpt = sn_og_card_dek_source( $post );
	$excerpt_lines = sn_og_wrap_lines( $excerpt, 26, $dmmono_path, $max_width, 3 );
	$y             = SN_OG_HEIGHT - 180;
	foreach ( $excerpt_lines as $line ) {
		imagettftext( $im, 26, 0, $pad_x, $y, $gray, $dmmono_path, $line );
		$y += 42;
	}

	// Footer: reading time in red, right-aligned site mark would clutter the
	// brutalist treatment so we keep it minimal.
	$minutes = function_exists( 'sn_get_reading_time' ) ? sn_get_reading_time( $post ) : 1;
	$footer  = strtoupper( $minutes . ' MIN READ' );
	imagettftext( $im, 18, 0, $pad_x, SN_OG_HEIGHT - 60, $red, $dmmono_path, $footer );

	$path = $dir['path'] . '/post-' . (int) $post_id . '.png';
	$ok   = imagepng( $im, $path, 6 );
	imagedestroy( $im );
	if ( $ok && function_exists( 'sn_og_card_inject_provenance' ) ) {
		// v9.25.0: embed the Note's provenance in the card (D2). Decorative work
		// must never break the (non-blocking) save path — swallow any failure.
		try {
			sn_og_card_inject_provenance( $path, (int) $post_id );
		} catch ( \Throwable $e ) {
			// no-op: the card still exists; provenance is simply absent.
		}
	}
	return (bool) $ok;
}

/**
 * Greedy word-wrap that uses imagettfbbox to measure exact pixel widths
 * for the active font and size. Truncates the last line with an
 * ellipsis if the remaining text would overflow.
 *
 * @return string[] Up to $max_lines lines.
 */
function sn_og_wrap_lines( $text, $size, $font, $max_width, $max_lines ) {
	$text = trim( (string) $text );
	if ( '' === $text ) {
		return array();
	}
	$words   = preg_split( '/\s+/', $text );
	$lines   = array();
	$current = '';

	foreach ( $words as $i => $word ) {
		$candidate = ( '' === $current ) ? $word : ( $current . ' ' . $word );
		$bbox      = imagettfbbox( $size, 0, $font, $candidate );
		$width     = $bbox[2] - $bbox[0];

		if ( $width <= $max_width ) {
			$current = $candidate;
			continue;
		}
		// Overflow — flush current line and start a new one with this word.
		if ( '' !== $current ) {
			$lines[] = $current;
			if ( count( $lines ) >= $max_lines - 1 ) {
				// Last allowed line: pack remaining words and ellipsize.
				//
				// UTF-8 SAFETY: track $core (text without trailing ellipsis)
				// separately and only construct $rest = $core . '…' for the
				// width measurement. The previous form did
				// `substr($rest, 0, -1)` after appending '…', which is
				// byte-based and stripped only the last byte of the 3-byte
				// UTF-8 ellipsis (E2 80 A6). Each iteration left dangling
				// bytes (E2 80) that rtrim couldn't remove, then re-appended
				// a fresh ellipsis — net effect was the string GREW by 2
				// bytes per iteration, the loop never terminated, and any
				// post whose excerpt needed truncation hung the OG card
				// generator (and therefore the page render) until PHP's
				// max_execution_time killed the process. Using mb_substr on
				// $core keeps the operation character-aware, and rebuilding
				// $rest each iteration prevents any encoding carry-over.
				$core  = implode( ' ', array_slice( $words, $i ) );
				$rest  = $core . '…';
				$bbox  = imagettfbbox( $size, 0, $font, $rest );
				$guard = 0;
				while ( ( $bbox[2] - $bbox[0] ) > $max_width
					&& mb_strlen( $core, 'UTF-8' ) > 1
					&& $guard < 1000 ) {
					$core = mb_substr( $core, 0, -1, 'UTF-8' );
					$core = rtrim( $core, ".,;:!? \t" );
					$rest = $core . '…';
					$bbox = imagettfbbox( $size, 0, $font, $rest );
					$guard++;
				}
				$lines[] = $rest;
				return $lines;
			}
		}
		$current = $word;
	}
	if ( '' !== $current ) {
		$lines[] = $current;
	}
	return array_slice( $lines, 0, $max_lines );
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
