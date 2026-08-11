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
 * ORDER IS LOAD-BEARING, and the ordering principle is SPECIFICITY. A rule that
 * under-matches silently hands its cases to whatever runs last, which then
 * reports its own reason for someone else's case — that is how /services/ came
 * to be described as "a single word" (v10.81.0).
 *
 * The chain runs: RELATIONSHIP rules first (does the alt repeat text already
 * announced beside it?), then STRING-SHAPE rules (does it echo the filename?),
 * then VOCABULARY. A relationship rule knows more — it has seen the
 * surrounding page — so when both fire, it gives the reader the more useful
 * sentence: "this is announced twice" beats "this looks like a filename".
 *
 * @param string $alt      The alt text as authored.
 * @param string $filename Image filename or URL, for echo detection. Optional.
 * @param string $caption  Caption / figcaption text, for duplicate detection. Optional.
 * @param string $heading  Heading adjacent to the image, raw. Optional.
 * @return string '' when fine, else 'heading_duplicate' | 'caption_duplicate' | 'filename_echo' | 'generic_alt'.
 */
function sn_health_alt_quality_problem( $alt, $filename = '', $caption = '', $heading = '' ) {
	$raw = trim( (string) $alt );
	if ( '' === $raw ) {
		// Empty alt is the COVERAGE pass's business: either a valid decorative
		// image or a missing one. Not a quality verdict.
		return '';
	}

	$norm_alt = sn_health_normalise_alt_text( $raw );

	// 1. Duplicates the heading beside it. EXACT match after folding, because
	//    an image whose alt merely overlaps a nearby heading is ordinary.
	if ( '' !== trim( (string) $heading )
		&& '' !== $norm_alt
		&& $norm_alt === sn_health_normalise_alt_text( (string) $heading ) ) {
		return 'heading_duplicate';
	}

	// 2. Duplicates the caption, which a screen reader already announces.
	if ( '' !== trim( (string) $caption )
		&& $norm_alt === sn_health_normalise_alt_text( (string) $caption ) ) {
		return 'caption_duplicate';
	}

	// 3. Ends in an image extension — an echo regardless of what it echoes.
	if ( preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?)$/i', $raw ) ) {
		return 'filename_echo';
	}

	// 4. Echoes the image's own filename stem.
	if ( '' !== (string) $filename ) {
		$stem = sn_health_alt_filename_stem( (string) $filename );
		if ( '' !== $stem && $norm_alt === sn_health_normalise_alt_text( $stem ) ) {
			return 'filename_echo';
		}
	}

	// 5. Names the CATEGORY rather than the content.
	if ( sn_health_alt_is_generic( $norm_alt ) ) {
		return 'generic_alt';
	}

	return '';
}

/**
 * True when the alt names a category of picture rather than what is in it.
 *
 * This replaced a word-COUNT rule in v10.81.0. "A single word cannot describe a
 * content image" is not an accessibility requirement: WCAG asks for an
 * equivalent alternative, and for a portrait, a logo or a planet one word is
 * the complete and correct one. Counting words flagged every correct short alt
 * on the site while saying nothing about "an image", which is genuinely empty.
 * What fails a screen reader is the vocabulary, at any length.
 *
 * @param string $norm_alt Alt already folded by sn_health_normalise_alt_text().
 * @return bool
 */
function sn_health_alt_is_generic( $norm_alt ) {
	$core = (string) $norm_alt;
	$core = preg_replace( '/^(?:a|an|the)\s+/', '', $core );  // "an image".
	$core = preg_replace( '/\s+\d+$/', '', (string) $core );  // "photo 2".
	$core = trim( (string) $core );

	// Words that name the container, never the contents. Deliberately short:
	// every addition here silences a real finding, so it earns its place only
	// when the word tells a screen-reader user nothing on ANY image.
	$generic = array(
		'image', 'images', 'img', 'photo', 'photos', 'photograph',
		'picture', 'pictures', 'pic', 'graphic', 'graphics',
		'icon', 'logo', 'banner', 'thumbnail', 'screenshot',
		'illustration', 'figure', 'chart', 'diagram', 'graph',
		'untitled', 'placeholder', 'media', 'file', 'attachment',
		'alt', 'alt text', 'no alt', 'description', 'image of', 'photo of',
	);
	return in_array( $core, $generic, true );
}

/**
 * The comparable stem of a filename: basename, no query string, no extension,
 * and no trailing suffix that a pipeline added rather than a human.
 *
 * Suffixes STACK -- hero-min-1024x576.png -- so stripping runs to a fixed point
 * rather than once. Missing one is not cosmetic: an unstripped suffix makes the
 * stem differ from the alt, the echo rule misses, and the finding falls through
 * to whatever rule sits last, which then reports the wrong defect.
 *
 * @param string $filename Filename or URL.
 * @return string
 */
function sn_health_alt_filename_stem( $filename ) {
	$path = (string) $filename;
	$path = preg_replace( '/[?#].*$/', '', $path );
	$base = basename( (string) $path );
	$base = preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $base );

	// -1024x576 (WP generated size) and -scaled (WP large-upload original) come
	// from WordPress; -min / @2x / -optimized come from build pipelines. Every
	// image on this site carries -min, which is how the gap surfaced.
	$generated = '/(?:-\d{2,5}x\d{2,5}|-scaled|-min|-optimi[sz]ed|-compressed|@[2-4]x)$/i';
	do {
		$previous = $base;
		$base     = (string) preg_replace( $generated, '', (string) $base );
	} while ( $base !== $previous && '' !== $base );

	return (string) $base;
}

/**
 * Fold a string to its comparable core: lowercase, punctuation and separators
 * collapsed to single spaces. Lets "hero-image-2.png", "Hero Image 2" and
 * "hero image 2" compare equal.
 *
 * The target of the comparison is what a screen reader SAYS, which is why the
 * first two steps exist:
 *
 *   - DECODE ENTITIES. post_content stores them, so a heading arrives as
 *     "OPERATIONS &amp; AI STRATEGY". Fold that undecoded and the `&amp;`
 *     becomes the word "amp" — a string nobody ever hears, silently compared
 *     against one that never contains it. (Same family as the color-drift
 *     entity trap: decode before you regex post_content.)
 *   - MAP "&" TO "and". These are one spoken word, so "OPERATIONS & AI
 *     STRATEGY" and "Operations and AI Strategy" ARE the same announcement.
 *     Deliberately NOT general stop-word stripping: "&" ≡ "and" is an
 *     equivalence, whereas dropping "and" entirely is fuzzy matching, and a
 *     fuzzy filename comparison would start flagging descriptive alt text.
 *
 * @param string $text
 * @return string
 */
function sn_health_normalise_alt_text( $text ) {
	$out = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$out = str_replace( '&', ' and ', $out );
	$out = strtolower( $out );
	$out = preg_replace( '/[^a-z0-9]+/', ' ', $out );
	return trim( (string) preg_replace( '/\s+/', ' ', (string) $out ) );
}

/**
 * The heading a screen reader announces immediately after this image, if any.
 *
 * The heading is a SIBLING of the <figure>, never a descendant, so the scan
 * steps past the enclosing </figure> before it starts looking.
 *
 * WRITTEN AGAINST STORED BLOCK MARKUP, which is the only shape this ever sees.
 * Between </figure> and <h3> on a /services/ card sit FIVE `<!-- wp:* -->`
 * delimiters and one <p>. Those comments are Gutenberg's serialization
 * boundaries, not content — a lookahead that counts raw tags spends its whole
 * budget on them and finds nothing, while testing perfectly against the
 * RENDERED HTML, where they do not exist.
 *
 * Bounded twice, because the cost of over-reaching is a false positive on a
 * page whose author did nothing wrong: at most $max_skips text-bearing
 * elements, and the scan ends outright at the next image.
 *
 * @param string $content   Post content.
 * @param int    $offset    Byte offset just past the <img> tag.
 * @param int    $max_skips Text-bearing elements tolerated before the heading.
 * @return string Raw heading text (entities intact), or ''.
 */
function sn_health_heading_after_offset( $content, $offset, $max_skips = 2 ) {
	$rest = substr( (string) $content, (int) $offset );

	// Step past the figure that wraps THIS image — but only when the next
	// </figure> really is ours. A bare <img> has none, and stepping to the first
	// one anywhere ahead teleports the scan into an unrelated card and reads its
	// heading. An intervening <figure means the close belongs to that one, the
	// same scoping guard sn_health_caption_after_offset() uses.
	if ( preg_match( '#^(.*?)</figure\s*>#is', $rest, $fm )
		&& false === stripos( $fm[1], '<figure' ) ) {
		$rest = substr( $rest, strlen( $fm[0] ) );
	}

	// Block delimiters carry nothing a reader hears.
	$rest = (string) preg_replace( '#<!--.*?-->#s', '', $rest );

	// A DISTANCE bound as well as an element bound. Layout wrappers do not spend
	// the element budget (they announce nothing), so on wrapper-heavy markup the
	// scan can otherwise cross a whole template region — measured running this
	// over the rendered page, where the header logo reached a heading inside
	// <main>. Exact matching meant no false finding, but "bounded" has to mean
	// bounded. A card's figure-to-heading gap is a few hundred bytes.
	$rest = substr( $rest, 0, 2000 );

	$skips  = 0;
	$offset = 0;
	$length = strlen( $rest );

	while ( $offset < $length
		&& preg_match( '#<(/?)([a-z][a-z0-9]*)\b([^>]*)>#i', $rest, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		$tag    = strtolower( $m[2][0] );
		$offset = $m[0][1] + strlen( $m[0][0] );

		// Closing tags are structure being exited, not content being read.
		if ( '/' === $m[1][0] ) {
			continue;
		}

		if ( preg_match( '/^h[1-6]$/', $tag ) ) {
			if ( preg_match( '#^(.*?)</' . $tag . '\s*>#is', substr( $rest, $offset ), $hm ) ) {
				return trim( strip_tags( $hm[1] ) );
			}
			return '';
		}

		// The next image owns whatever heading follows it. Stop rather than
		// attribute a sibling card's heading to this one.
		if ( 'img' === $tag || 'figure' === $tag ) {
			return '';
		}

		// Layout wrappers announce nothing, so they do not spend the budget.
		if ( in_array( $tag, array( 'div', 'section', 'article', 'main' ), true ) ) {
			continue;
		}

		if ( ++$skips > (int) $max_skips ) {
			return '';
		}
	}
	return '';
}

/**
 * Extract inline <img> tags that DO have an alt attribute, with the src, the
 * nearest enclosing <figcaption> and the heading that follows, so quality can be
 * judged in context rather than from the alt string alone.
 *
 * @param string $content Post content.
 * @return array List of array{src:string, alt:string, caption:string, heading:string}.
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
			'heading' => sn_health_heading_after_offset( $content, $tag_end ),
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
