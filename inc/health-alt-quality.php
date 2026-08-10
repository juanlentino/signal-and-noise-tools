<?php
/**
 * Signal & Noise Tools -- alt-text parsing and quality predicates.
 *
 * Pure functions, no WordPress calls, no database: everything here takes a
 * string and returns a verdict, so tests/health-check-missing-alt.php can drive
 * it directly. Split out of inc/health-check-missing-alt.php in v10.77.0 when
 * the check grew inline-SVG coverage and alt QUALITY on top of alt PRESENCE.
 *
 * THE CENTRAL FACT, because it is the thing everyone gets wrong: an inline
 * <svg> HAS NO alt ATTRIBUTE. Its accessible name comes from a direct-child
 * <title>, or from aria-label / aria-labelledby; aria-hidden="true" or
 * role="presentation|none" marks it decorative. Code that greps for `alt=` on
 * an <svg> reports every SVG as broken, and "fixing" one by adding alt="" is
 * invalid markup that changes nothing for a screen reader.
 *
 * @package SignalNoiseTools
 * @since 10.77.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find inline <svg> elements that expose no accessible name and are not marked
 * decorative.
 *
 * Multi-line by construction: SVG spans lines and carries children, so the
 * single-tag `<img>` regex does not transfer. The `s` modifier makes `.` cross
 * newlines.
 *
 * Known limits, stated rather than hidden: a self-closing `<svg/>` (which
 * renders nothing) and an <svg> nested inside another <svg> are not modelled —
 * the non-greedy close match would mis-scope the latter.
 *
 * @param string $content Post content.
 * @return array List of short hints identifying each unnamed SVG, in document order.
 */
function sn_health_extract_inline_svgs_without_name( $content ) {
	$content = (string) $content;
	if ( '' === trim( $content ) ) {
		return array();
	}
	if ( ! preg_match_all( '#<svg\b([^>]*)>(.*?)</svg\s*>#is', $content, $matches, PREG_SET_ORDER ) ) {
		return array();
	}

	$out     = array();
	$ordinal = 0;
	foreach ( $matches as $m ) {
		++$ordinal;
		$attrs = (string) $m[1];
		$inner = (string) $m[2];
		if ( 'unnamed' !== sn_health_svg_accessible_name_status( $attrs, $inner ) ) {
			continue;
		}
		$out[] = sn_health_svg_hint( $ordinal, $attrs );
	}
	return $out;
}

/**
 * Classify one <svg> as named, decorative, or unnamed.
 *
 * @param string $attrs Raw attribute text from the root <svg> open tag.
 * @param string $inner Markup between <svg> and </svg>.
 * @return string 'named' | 'decorative' | 'unnamed'
 */
function sn_health_svg_accessible_name_status( $attrs, $inner ) {
	$attrs = (string) $attrs;

	// Decorative: deliberately hidden from assistive technology.
	if ( preg_match( '/\baria-hidden\s*=\s*["\']?\s*true/i', $attrs ) ) {
		return 'decorative';
	}
	if ( preg_match( '/\brole\s*=\s*["\']\s*(?:presentation|none)\s*["\']/i', $attrs ) ) {
		return 'decorative';
	}

	// Named by attribute. An EMPTY value names nothing.
	foreach ( array( 'aria-label', 'aria-labelledby' ) as $attr ) {
		if ( preg_match( '/\b' . preg_quote( $attr, '/' ) . '\s*=\s*["\']([^"\']*)["\']/i', $attrs, $am )
			&& '' !== trim( $am[1] ) ) {
			return 'named';
		}
	}

	// Named by a DIRECT-CHILD <title>. Deliberately NOT any descendant <title>:
	// a <title> inside a <g> or a sprite <symbol> names that child, not the root.
	if ( sn_health_svg_has_direct_child_title( (string) $inner ) ) {
		return 'named';
	}

	return 'unnamed';
}

/**
 * True when $inner contains a <title> at nesting depth 0 with non-empty text.
 *
 * Walks tags tracking depth rather than regexing for `<title>` anywhere, which
 * would accept a nested one and silently pass a genuinely unnamed icon.
 *
 * @param string $inner Markup between <svg> and </svg>.
 * @return bool
 */
function sn_health_svg_has_direct_child_title( $inner ) {
	$depth  = 0;
	$offset = 0;
	$len    = strlen( $inner );

	while ( $offset < $len && preg_match( '#<(/?)([a-z][a-z0-9:-]*)\b([^>]*)>#i', $inner, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		$tag        = strtolower( $m[2][0] );
		$is_close   = ( '/' === $m[1][0] );
		$self_close = ( '/' === substr( rtrim( $m[3][0] ), -1 ) );
		$offset     = $m[0][1] + strlen( $m[0][0] );

		if ( $is_close ) {
			if ( $depth > 0 ) { --$depth; }
			continue;
		}

		if ( 'title' === $tag && 0 === $depth && ! $self_close ) {
			if ( preg_match( '#^(.*?)</title\s*>#is', substr( $inner, $offset ), $tm )
				&& '' !== trim( strip_tags( $tm[1] ) ) ) {
				return true;
			}
			// Empty title — keep scanning; a later sibling may carry the name.
		}

		if ( ! $self_close ) { ++$depth; }
	}
	return false;
}

/**
 * A short, human-recognisable hint for an SVG that has no src to point at.
 *
 * @param int    $ordinal 1-based position within the post body.
 * @param string $attrs   Raw attribute text.
 * @return string
 */
function sn_health_svg_hint( $ordinal, $attrs ) {
	foreach ( array( 'id', 'class' ) as $attr ) {
		if ( preg_match( '/\b' . $attr . '\s*=\s*["\']([^"\']+)["\']/i', $attrs, $m ) ) {
			$val = trim( preg_replace( '/\s+/', ' ', $m[1] ) );
			if ( '' !== $val ) {
				return sprintf( '<svg> #%d (%s="%s")', (int) $ordinal, $attr, substr( $val, 0, 60 ) );
			}
		}
	}
	return sprintf( '<svg> #%d', (int) $ordinal );
}

/* ─────────────────────────────────────────────────────────────────────
 * ALT QUALITY — present but useless
 * ───────────────────────────────────────────────────────────────────── */

/**
 * Classify an alt string that EXISTS but may say nothing.
 *
 * Returns a machine reason, never a fix: quality findings route through the
 * same human acceptance as the coverage sweep and are never applied directly.
 *
 * @param string $alt      The alt text as authored.
 * @param string $filename Image filename or URL, for echo detection. Optional.
 * @param string $caption  Caption / figcaption text, for duplicate detection. Optional.
 * @return string '' when the alt is fine, else 'filename_echo' | 'caption_duplicate' | 'single_word'.
 */
function sn_health_alt_quality_problem( $alt, $filename = '', $caption = '' ) {
	$raw = trim( (string) $alt );
	if ( '' === $raw ) {
		// Empty alt is the COVERAGE pass's business: either a valid decorative
		// image or a missing one. Not a quality verdict.
		return '';
	}

	// 1. Ends in an image extension — an echo regardless of what it echoes.
	if ( preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?)$/i', $raw ) ) {
		return 'filename_echo';
	}

	$norm_alt = sn_health_normalise_alt_text( $raw );

	// 2. Echoes the image's own filename stem.
	if ( '' !== (string) $filename ) {
		$stem = sn_health_alt_filename_stem( (string) $filename );
		if ( '' !== $stem && $norm_alt === sn_health_normalise_alt_text( $stem ) ) {
			return 'filename_echo';
		}
	}

	// 3. Duplicates the caption, which a screen reader already announces.
	if ( '' !== trim( (string) $caption )
		&& $norm_alt === sn_health_normalise_alt_text( (string) $caption ) ) {
		return 'caption_duplicate';
	}

	// 4. A single word cannot describe a content image.
	if ( '' !== $norm_alt && 1 === count( explode( ' ', $norm_alt ) ) ) {
		return 'single_word';
	}

	return '';
}

/**
 * The comparable stem of a filename: basename, no query string, no extension,
 * no WordPress generated-size suffix.
 *
 * @param string $filename Filename or URL.
 * @return string
 */
function sn_health_alt_filename_stem( $filename ) {
	$path = (string) $filename;
	$path = preg_replace( '/[?#].*$/', '', $path );
	$base = basename( $path );
	$base = preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $base );
	// Strip a trailing WP size suffix, e.g. photo-1024x576 -> photo.
	$base = preg_replace( '/-\d{2,5}x\d{2,5}$/', '', $base );
	return (string) $base;
}

/**
 * Fold a string to its comparable core: lowercase, punctuation and separators
 * collapsed to single spaces. Lets "hero-image-2.png", "Hero Image 2" and
 * "hero image 2" compare equal.
 *
 * @param string $text
 * @return string
 */
function sn_health_normalise_alt_text( $text ) {
	$out = strtolower( (string) $text );
	$out = preg_replace( '/[^a-z0-9]+/', ' ', $out );
	return trim( preg_replace( '/\s+/', ' ', (string) $out ) );
}

/**
 * Extract inline <img> tags that DO have an alt attribute, with the src and the
 * nearest enclosing <figcaption> so quality can be judged in context.
 *
 * @param string $content Post content.
 * @return array List of array{src:string, alt:string, caption:string}.
 */
function sn_health_extract_inline_imgs_with_alt( $content ) {
	$content = (string) $content;
	if ( '' === trim( $content ) ) {
		return array();
	}
	if ( ! preg_match_all( '/<img\b([^>]*)>/i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
		return array();
	}

	$out = array();
	foreach ( $matches[1] as $i => $attr_hit ) {
		$attrs = (string) $attr_hit[0];
		if ( ! preg_match( '/\balt\s*=\s*["\']([^"\']*)["\']/i', $attrs, $am ) ) {
			continue; // No alt at all — that is the coverage pass's finding.
		}
		$alt = trim( $am[1] );
		if ( '' === $alt ) {
			continue; // Explicit alt="" is a valid decorative marker.
		}

		$src = '';
		if ( preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $attrs, $sm ) ) {
			$src = $sm[1];
		}

		$tag_end = $matches[0][ $i ][1] + strlen( $matches[0][ $i ][0] );
		$out[]   = array(
			'src'     => $src,
			'alt'     => $alt,
			'caption' => sn_health_caption_after_offset( $content, $tag_end ),
		);
	}
	return $out;
}

/**
 * The <figcaption> belonging to the <img> that ends at $offset, if any.
 *
 * Scoped to the enclosing </figure>: if another <figure> opens before that
 * close, the image is not the caption's sibling and no caption is returned.
 *
 * @param string $content Post content.
 * @param int    $offset  Byte offset just past the <img> tag.
 * @return string Plain-text caption, or ''.
 */
function sn_health_caption_after_offset( $content, $offset ) {
	$rest = substr( (string) $content, (int) $offset );
	if ( ! preg_match( '#^(.*?)</figure\s*>#is', $rest, $fm ) ) {
		return '';
	}
	$between = $fm[1];
	if ( false !== stripos( $between, '<figure' ) ) {
		return '';
	}
	if ( preg_match( '#<figcaption[^>]*>(.*?)</figcaption\s*>#is', $between, $cm ) ) {
		return trim( strip_tags( $cm[1] ) );
	}
	return '';
}
