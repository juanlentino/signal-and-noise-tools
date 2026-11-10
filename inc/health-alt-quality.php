<?php
/**
 * Signal & Noise Tools -- alt-text parsing and quality predicates.
 *
 * Pure functions, no hooks, no database: everything here takes a string and
 * returns a verdict, so tests/health-check-missing-alt.php can drive it
 * directly. The tags and attributes are read by core's HTML API
 * (WP_HTML_Processor, WP_HTML_Tag_Processor), the one piece of WordPress this
 * file stands on; the quality predicates below it are plain string functions.
 * Split out of inc/health-check-missing-alt.php in v10.77.0 when the check
 * grew inline-SVG coverage and alt QUALITY on top of alt PRESENCE.
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

require_once __DIR__ . '/html-token-spans.php'; // snt_html_span_processor(): where each token after an image sits.

/**
 * Find inline <svg> elements that expose no accessible name and are not marked
 * decorative.
 *
 * Read with core's HTML parser (WP_HTML_Processor), which knows what a regex
 * over the bytes could not: where each <svg> really ends, which <title> is its
 * direct child, that an <svg> inside another is its own element, that a
 * self-closing <svg/> has no children, and that markup inside a comment is not
 * markup. Attributes come back whole, so `aria-label="a > b"` is a name and
 * `data-id` is not an id (#1187).
 *
 * When the parser meets markup it does not support (it says so: text loose in
 * a table, misnested formatting tags, a link inside a link) the walk stops
 * there. What it had already settled is returned; an <svg> it could not
 * finish reading is not reported, because "unnamed" would be a guess. $unread
 * is set so the caller can say the body was not read to the end.
 *
 * @param string    $content Post content.
 * @param bool|null $unread  Set to true when the walk stopped early, false otherwise.
 * @return array List of short hints identifying each unnamed SVG, in document order.
 */
function sn_health_extract_inline_svgs_without_name( $content, &$unread = null ) {
	$content = (string) $content;
	$unread  = false;
	if ( false === stripos( $content, '<svg' ) ) {
		return array();
	}
	$html = WP_HTML_Processor::create_fragment( $content );
	if ( null === $html ) {
		$unread = true;
		return array();
	}

	$unnamed     = array(); // ordinal => hint.
	$open        = array(); // the <svg> elements being read, innermost last.
	$ordinal     = 0;
	$title_depth = 0;       // depth of the direct-child <title> being read, 0 when none.
	$title_text  = '';

	while ( $html->next_token() ) {
		$name  = $html->get_token_name();
		$depth = $html->get_current_depth();
		$top   = count( $open ) - 1;

		if ( '#text' === $name ) {
			if ( $title_depth ) {
				$title_text .= $html->get_modifiable_text();
			}
			continue;
		}

		if ( $html->is_tag_closer() ) {
			if ( $title_depth && 'TITLE' === $name && $depth === $title_depth - 1 ) {
				// An EMPTY title names nothing; a later sibling may still carry the name.
				if ( '' !== trim( $title_text ) && 'unnamed' === $open[ $top ]['status'] ) {
					$open[ $top ]['status'] = 'named';
				}
				$title_depth = 0;
			} elseif ( $top >= 0 && 'SVG' === $name && $depth === $open[ $top ]['depth'] - 1 ) {
				$done = array_pop( $open );
				if ( 'unnamed' === $done['status'] ) {
					$unnamed[ $done['ordinal'] ] = $done['hint'];
				}
			}
			continue;
		}

		if ( 'SVG' === $name && 'svg' === $html->get_namespace() ) {
			++$ordinal;
			$svg = array(
				'ordinal' => $ordinal,
				'depth'   => $depth,
				'status'  => sn_health_svg_accessible_name_status( $html ),
				'hint'    => sn_health_svg_hint( $ordinal, $html ),
			);
			if ( $html->expects_closer() ) {
				$open[] = $svg;
			} elseif ( 'unnamed' === $svg['status'] ) {
				$unnamed[ $ordinal ] = $svg['hint']; // <svg/>: no children, so no <title> can name it.
			}
			continue;
		}

		// Named by a DIRECT-CHILD <title>. Deliberately NOT any descendant <title>:
		// a <title> inside a <g> or a sprite <symbol> names that child, not the root.
		if ( 'TITLE' === $name && $top >= 0 && 0 === $title_depth && $depth === $open[ $top ]['depth'] + 1 && $html->expects_closer() ) {
			$title_depth = $depth;
			$title_text  = '';
		}
	}

	$unread = null !== $html->get_last_error();
	ksort( $unnamed );
	return array_values( $unnamed );
}

/**
 * Classify the <svg> tag a processor is on by its own attributes: decorative,
 * named, or (so far) unnamed. A direct-child <title> can still name it; the
 * caller reads the children.
 *
 * @param WP_HTML_Tag_Processor $tag A processor paused on an <svg> opening tag.
 * @return string 'named' | 'decorative' | 'unnamed'
 */
function sn_health_svg_accessible_name_status( $tag ) {
	// Decorative: deliberately hidden from assistive technology.
	$hidden = $tag->get_attribute( 'aria-hidden' );
	if ( is_string( $hidden ) && 0 === stripos( ltrim( $hidden ), 'true' ) ) {
		return 'decorative';
	}
	$role = $tag->get_attribute( 'role' );
	if ( is_string( $role ) && in_array( strtolower( trim( $role ) ), array( 'presentation', 'none' ), true ) ) {
		return 'decorative';
	}

	// Named by attribute. An EMPTY value names nothing.
	foreach ( array( 'aria-label', 'aria-labelledby' ) as $attr ) {
		$val = $tag->get_attribute( $attr );
		if ( is_string( $val ) && '' !== trim( $val ) ) {
			return 'named';
		}
	}

	return 'unnamed';
}

/**
 * A short, human-recognisable hint for an SVG that has no src to point at.
 *
 * @param int                   $ordinal 1-based position within the post body.
 * @param WP_HTML_Tag_Processor $tag     A processor paused on the <svg> opening tag.
 * @return string
 */
function sn_health_svg_hint( $ordinal, $tag ) {
	foreach ( array( 'id', 'class' ) as $attr ) {
		$raw = $tag->get_attribute( $attr );
		if ( is_string( $raw ) ) {
			$val = trim( (string) preg_replace( '/\s+/', ' ', $raw ) );
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
 * Keep a Tag Processor's parsing namespace in step with inline <svg>, so an
 * SVG <title> inside a heading or caption reads as text, as a browser reads it
 * (and a screen reader announces it), rather than as HTML's RCDATA <title>.
 *
 * @param WP_HTML_Tag_Processor $tokens A processor paused on a tag.
 * @param int                   $depth  Open <svg> elements, updated in place.
 * @return void
 */
function sn_health_follow_svg_namespace( $tokens, &$depth ) {
	if ( 'SVG' !== $tokens->get_tag() ) {
		return;
	}
	if ( $tokens->is_tag_closer() ) {
		$depth = max( 0, $depth - 1 );
	} elseif ( ! $tokens->has_self_closing_flag() ) {
		++$depth;
	}
	$tokens->change_parsing_namespace( $depth > 0 ? 'svg' : 'html' );
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
 * Read as core's Tag Processor tokenizes it, so a heading spelled inside a
 * comment, a script, a textarea or an attribute value is not a heading, and a
 * '>' inside an attribute does not end its tag.
 *
 * @param string $content   Post content.
 * @param int    $offset    Byte offset just past the <img> tag.
 * @param int    $max_skips Text-bearing elements tolerated before the heading.
 * @return string Raw heading text (entities intact), or ''.
 */
function sn_health_heading_after_offset( $content, $offset, $max_skips = 2 ) {
	$rest = substr( (string) $content, (int) $offset );

	// Step past the figure that wraps THIS image, but only when the first figure
	// tag ahead is a closer, so it really is ours. A bare <img> has none, and
	// stepping to the first one anywhere ahead teleports the scan into an
	// unrelated card and reads its heading. A <figure> opening first means the
	// close belongs to that one: the same scoping sn_health_caption_after_offset() uses.
	if ( false !== stripos( $rest, '</figure' ) ) {
		$figure = snt_html_span_processor( $rest );
		if ( $figure->next_tag( array( 'tag_name' => 'FIGURE', 'tag_closers' => 'visit' ) ) && $figure->is_tag_closer() ) {
			$rest = substr( $rest, $figure->token_span()[1] );
		}
	}

	// A DISTANCE bound as well as an element bound. Layout wrappers do not spend
	// the element budget (they announce nothing), so on wrapper-heavy markup the
	// scan can otherwise cross a whole template region: measured running this
	// over the rendered page, where the header logo reached a heading inside
	// <main>. Exact matching meant no false finding, but "bounded" has to mean
	// bounded. A card's figure-to-heading gap is a few hundred bytes. Comments
	// do not count toward it: block delimiters carry nothing a reader hears.
	$window = 2000;
	$hidden = 0; // Comment bytes passed so far.
	$skips  = 0;
	$svg    = 0;
	$tokens = snt_html_span_processor( $rest );

	while ( $tokens->next_token() ) {
		list( $start, $end ) = $tokens->token_span();
		$type                = $tokens->get_token_type();
		if ( '#comment' === $type ) {
			$hidden += $end - $start;
			continue;
		}
		if ( $end - $hidden > $window ) {
			return '';
		}

		if ( '#tag' !== $type ) {
			continue;
		}
		sn_health_follow_svg_namespace( $tokens, $svg );
		// Closing tags are structure being exited, not content being read.
		if ( $tokens->is_tag_closer() ) {
			continue;
		}
		$tag = $tokens->get_tag();

		if ( 1 === preg_match( '/^H[1-6]$/', $tag ) ) {
			$text = '';
			while ( $tokens->next_token() ) {
				list( $start, $end ) = $tokens->token_span();
				$type                = $tokens->get_token_type();
				if ( '#comment' === $type ) {
					$hidden += $end - $start;
				} elseif ( '#text' === $type ) {
					$text .= substr( $rest, $start, $end - $start ); // The stored bytes: entities stay as written.
				} elseif ( '#tag' === $type ) {
					sn_health_follow_svg_namespace( $tokens, $svg );
					if ( $tag === $tokens->get_tag() && $tokens->is_tag_closer() ) {
						return $end - $hidden > $window ? '' : trim( $text );
					}
				}
			}
			return '';
		}

		// The next image owns whatever heading follows it. Stop rather than
		// attribute a sibling card's heading to this one.
		if ( 'IMG' === $tag || 'FIGURE' === $tag ) {
			return '';
		}

		// Layout wrappers announce nothing, so they do not spend the budget.
		if ( in_array( $tag, array( 'DIV', 'SECTION', 'ARTICLE', 'MAIN' ), true ) ) {
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

	// Core's Tag Processor, plus where the current tag ends: the caption and
	// heading lookups read the markup after the image.
	$tags = snt_html_span_processor( $content );

	$out = array();
	while ( $tags->next_tag( 'IMG' ) ) {
		// null is no alt at all (the coverage pass's finding); a bare `alt`
		// (true) and alt="" are the decorative marker.
		$alt = $tags->get_attribute( 'alt' );
		$alt = is_string( $alt ) ? trim( $alt ) : '';
		if ( '' === $alt ) {
			continue;
		}
		$end   = $tags->token_span()[1];
		$src   = $tags->get_attribute( 'src' );
		$out[] = array(
			'src'     => is_string( $src ) ? $src : '',
			'alt'     => $alt,
			'caption' => sn_health_caption_after_offset( $content, $end ),
			'heading' => sn_health_heading_after_offset( $content, $end ),
		);
	}
	return $out;
}

/**
 * The <figcaption> belonging to the <img> that ends at $offset, if any.
 *
 * Scoped to the enclosing </figure>: if another <figure> opens before that
 * close, the image is not the caption's sibling and no caption is returned.
 * Read as core's Tag Processor tokenizes it, so a figcaption or a </figure>
 * inside a comment, a script or an attribute value does not count.
 *
 * @param string $content Post content.
 * @param int    $offset  Byte offset just past the <img> tag.
 * @return string Caption text with its tags dropped and entities intact, or ''.
 */
function sn_health_caption_after_offset( $content, $offset ) {
	$rest = substr( (string) $content, (int) $offset );
	if ( false === stripos( $rest, '<figcaption' ) || false === stripos( $rest, '</figure' ) ) {
		return '';
	}

	$tokens  = snt_html_span_processor( $rest );
	$caption = null; // A string from the first <figcaption> on.
	$closed  = false;
	$svg     = 0;
	while ( $tokens->next_token() ) {
		$type = $tokens->get_token_type();
		if ( '#text' === $type ) {
			if ( null !== $caption && ! $closed ) {
				list( $start, $end ) = $tokens->token_span();
				$caption            .= substr( $rest, $start, $end - $start ); // The stored bytes: entities stay as written.
			}
			continue;
		}
		if ( '#tag' !== $type ) {
			continue;
		}
		sn_health_follow_svg_namespace( $tokens, $svg );
		$tag    = $tokens->get_tag();
		$closer = $tokens->is_tag_closer();
		if ( 'FIGURE' === $tag ) {
			return $closer && $closed ? trim( $caption ) : '';
		}
		if ( 'FIGCAPTION' === $tag ) {
			if ( ! $closer && null === $caption ) {
				$caption = '';
			} elseif ( $closer && null !== $caption ) {
				$closed = true;
			}
		}
	}
	return '';
}
