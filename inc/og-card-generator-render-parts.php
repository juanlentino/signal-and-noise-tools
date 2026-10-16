<?php
/**
 * Signal & Noise Tools: per-post OG/Twitter card generator, the render (sn_generate_og_card, the imagettfbbox seam, the word wrap).
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
	// brutalist treatment so we keep it minimal. None on a page.
	$footer = sn_og_card_footer( $post );
	if ( '' !== $footer ) {
		imagettftext( $im, 18, 0, $pad_x, SN_OG_HEIGHT - 60, $red, $dmmono_path, $footer );
	}

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
 * Thin seam around imagettfbbox() (#1221). imagettfbbox is a real GD
 * extension function, already loaded in every environment that runs this
 * file, so a test cannot redeclare it the way this suite fakes absent WP
 * functions. This wrapper CAN be pre-defined by a test before requiring
 * this file (the function_exists() guard then skips this definition),
 * giving tests a deterministic, controllable measurement without touching
 * real font metrics.
 *
 * @return array{0:int,1:int,2:int,3:int,4:int,5:int,6:int,7:int} imagettfbbox()'s 8-element box.
 */
if ( ! function_exists( 'sn_og_imagettfbbox' ) ) {
	function sn_og_imagettfbbox( $size, $angle, $font, $text ) {
		return imagettfbbox( $size, $angle, $font, $text );
	}
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
		$bbox      = sn_og_imagettfbbox( $size, 0, $font, $candidate );
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
				$core = implode( ' ', array_slice( $words, $i ) );
				$bbox = sn_og_imagettfbbox( $size, 0, $font, $core );
				if ( ( $bbox[2] - $bbox[0] ) <= $max_width ) {
					// #1221: the remaining words already fit on their own —
					// measure $core FIRST and only ellipsize on actual
					// overflow. Appending '…' unconditionally here made a
					// title that fit exactly in $max_lines lines render with
					// a spurious ellipsis, and made an exact-fit remainder
					// shave real characters to make room for a mark it never
					// needed.
					$lines[] = $core;
					return $lines;
				}
				$rest  = $core . '…';
				$bbox  = sn_og_imagettfbbox( $size, 0, $font, $rest );
				$guard = 0;
				while ( ( $bbox[2] - $bbox[0] ) > $max_width
					&& mb_strlen( $core, 'UTF-8' ) > 1
					&& $guard < 1000 ) {
					$core = mb_substr( $core, 0, -1, 'UTF-8' );
					$core = rtrim( $core, ".,;:!? \t" );
					$rest = $core . '…';
					$bbox = sn_og_imagettfbbox( $size, 0, $font, $rest );
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

