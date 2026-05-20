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
function sn_generate_og_card( $post_id ) {
	if ( ! function_exists( 'imagettftext' ) ) {
		return false;
	}
	$post = get_post( $post_id );
	if ( ! $post ) {
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
	$title       = (string) apply_filters( 'sn_og_card_title', $post->post_title, $post->ID );
	$title_lines = sn_og_wrap_lines( $title, 88, $bebas_path, $max_width, 3 );
	$y           = 250;
	foreach ( $title_lines as $line ) {
		imagettftext( $im, 88, 0, $pad_x, $y, $black, $bebas_path, $line );
		$y += 100;
	}

	// Excerpt: prefer post_excerpt; otherwise derive from cleaned content.
	$excerpt = trim( (string) $post->post_excerpt );
	if ( '' === $excerpt ) {
		$cleaned = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', ' ', $post->post_content );
		$cleaned = wp_strip_all_tags( strip_shortcodes( $cleaned ) );
		$excerpt = wp_trim_words( $cleaned, 36, '…' );
	}
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
 * Rebuild the cached card whenever a post or page is saved. Skips
 * revisions/autosaves and unpublished content.
 */
add_action( 'wp_after_insert_post', function( $post_id, $post, $update, $post_before ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	sn_generate_og_card( $post_id );
}, 20, 4 );

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
 * Theme's own OG emitter (inc/seo.php) reads through this filter.
 */
add_filter( 'sn_og_image_url', function( $default ) {
	$post = get_post();
	if ( ! $post ) {
		return $default;
	}
	// v1.10.0+: per-post _sn_og_image_url wins over featured image
	// and auto-generated card. Explicit beats implicit.
	if ( function_exists( 'sn_post_settings_get_og_image_url' ) ) {
		$override = sn_post_settings_get_og_image_url( $post->ID );
		if ( '' !== $override ) {
			return $override;
		}
	}
	$url = sn_og_image_url_for_post( $post );
	return $url ? $url : $default;
} );

// Note: Phase 10 (plugin v1.6.0) removed three dead `wpseo_*` filter hooks
// that targeted Yoast SEO's filter namespace. The active site runs The SEO
// Framework (`the_seo_framework_*` namespace), so those hooks never fired.
// The OG card URL surfaces through our own `sn_og_image_url` filter, which
// `inc/seo.php` reads when emitting `<meta property="og:image">` directly.
