<?php
/**
 * Signal & Noise Tools — Now/Uses page-sync engine (LIVE, per-save).
 *
 * The rendered-artifact layer behind the Content → Now Page and Content →
 * Uses Page text boxes: dossier HTML renderers, create-or-update upserts,
 * and the sn_now_sync_page() / sn_uses_sync_page() regenerators wired to
 * the editors' saves (inc/now-page.php / inc/uses-page.php call them via
 * guarded function_exists checks). Every save regenerates the CMS Page
 * body from the canonical text box; an empty box is always a no-op, so
 * the engine never blanks a page.
 *
 * Moved VERBATIM out of inc/content-migrations.php in v9.81.0 — that file
 * now holds only the spent one-shot migrations behind the master sentinel;
 * this engine is the live machinery those migrations originally seeded.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render one dossier <section> for /now or /uses: the section-head (Bebas
 * label + mono count badge) and the hairline-row <ul>. Reproduces the theme's
 * original virtual-route markup verbatim so now.css/uses.css (and any
 * Site-Editor global styles targeting these classes) render it identically.
 *
 * @param string $prefix     'now' | 'uses' (drives the sn-{prefix}-* classes).
 * @param int    $index      Section index (for the aria id).
 * @param string $label      Section label (raw; escaped here).
 * @param string $items_html Pre-rendered, already-escaped <li> markup.
 * @param int    $count      Item count for the mono badge.
 * @return string
 */
function sn_dossier_section_html( $prefix, $index, $label, $items_html, $count ) {
	$p  = 'sn-' . $prefix;
	$id = $p . '-h-' . (int) $index;
	return '<section class="' . $p . '-section" aria-labelledby="' . esc_attr( $id ) . '">'
		. '<div class="' . $p . '-section-head">'
		. '<h2 class="' . $p . '-section-label" id="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</h2>'
		. '<span class="' . $p . '-section-count">' . esc_html( sprintf( '%02d', (int) $count ) ) . '</span>'
		. '</div>'
		. '<ul class="' . $p . '-list">' . $items_html . '</ul>'
		. '</section>';
}

/**
 * Render the /now dossier body (hero + sections) as a core/html block for
 * post_content. Reproduces the theme's /now route markup (sn-now-* classes) so
 * now.css renders it identically; the text box stays the editor, so the body
 * is generated HTML, not hand-edited blocks. The "Updated" line uses the given
 * date (stamped at save time). Returns '' when no section has items.
 *
 * @param array<int,array{label:string,items:array<int,string>}> $sections
 * @param string $updated Display date for the "Updated" line.
 * @return string
 */
function sn_now_dossier_html( $sections, $updated ) {
	if ( empty( $sections ) || ! is_array( $sections ) ) {
		return '';
	}

	$sections_html = '';
	foreach ( array_values( $sections ) as $i => $section ) {
		$label = (string) ( $section['label'] ?? '' );
		if ( '' === trim( $label ) ) {
			continue;
		}
		$items_html = '';
		$count      = 0;
		foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
			$item = (string) $item;
			if ( '' === trim( $item ) ) {
				continue;
			}
			$items_html .= '<li class="sn-now-item"><span class="sn-now-item-text">' . esc_html( $item ) . '</span></li>';
			++$count;
		}
		if ( 0 === $count ) {
			continue;
		}
		$sections_html .= sn_dossier_section_html( 'now', $i, $label, $items_html, $count );
	}

	if ( '' === $sections_html ) {
		return '';
	}

	// v10.36.0 split hero (site-wide direction): title block left, dek +
	// meta right. The theme's now.css lays the two wrappers out as a
	// bottom-aligned grid at >=900px (guarded by :has() so pre-split
	// bodies keep the stack) and stacks them on mobile.
	$hero = '<header class="sn-now-hero">'
		. '<div class="sn-now-hero-title">'
		. '<p class="sn-now-eyebrow">Now &middot; What I&rsquo;m focused on</p>'
		. '<h1 class="sn-now-headline">Now.</h1>'
		. '</div>'
		. '<div class="sn-now-hero-side">'
		. '<p class="sn-now-dek">A public answer to &ldquo;what are you doing these days?&rdquo; &mdash; the projects, writing, and inputs that have my attention right now.</p>'
		. '<p class="sn-now-meta">Updated ' . esc_html( $updated ) . '</p>'
		. '</div>'
		. '</header>';

	return "<!-- wp:html -->\n<div class=\"sn-now-page\">" . $hero . $sections_html . "</div>\n<!-- /wp:html -->";
}

/**
 * Build the /now Page body from parsed text-box sections. Returns '' when the
 * sections produce no usable content, so callers never blank the page. The
 * "Updated" line is stamped with the current site-timezone date at build time
 * (the automatic replacement for the old sn_now_updated stamp).
 *
 * @param array<int,array{label:string,items:array<int,string>}> $sections
 * @return string
 */
function sn_now_build_body( $sections ) {
	$updated = function_exists( 'wp_date' ) ? (string) wp_date( 'F j, Y' ) : gmdate( 'F j, Y' );
	return sn_now_dossier_html( $sections, $updated );
}

/**
 * Create-or-update the /now Page with the given body. Creates it (published,
 * bound to page-now, with a seeded Excerpt) when absent; otherwise replaces
 * post_content (the text box is the canonical editor, so a regenerate is a full
 * replace) and seeds the Excerpt only when still empty. Returns the Page ID, or
 * 0 on failure / empty body.
 *
 * @param string $body Full post_content (the core/html dossier block).
 * @return int
 */
function sn_now_upsert_page( $body ) {
	if ( '' === trim( (string) $body ) ) {
		return 0;
	}

	$excerpt = 'What Juan Lentino is focused on right now: current projects, writing, and inputs. Updated whenever it changes.';
	$page    = get_page_by_path( SN_NOW_SLUG );

	if ( $page ) {
		$update = array(
			'ID'           => $page->ID,
			'post_content' => $body,
		);
		if ( '' === trim( (string) $page->post_excerpt ) ) {
			$update['post_excerpt'] = $excerpt;
		}
		wp_update_post( $update );
		return (int) $page->ID;
	}

	$new_id = wp_insert_post(
		array(
			'post_title'    => 'Now',
			'post_name'     => SN_NOW_SLUG,
			'post_parent'   => 0,
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'post_content'  => $body,
			'post_excerpt'  => $excerpt,
			'page_template' => 'page-now',
		),
		false
	);

	return is_int( $new_id ) && $new_id > 0 ? $new_id : 0;
}

/**
 * Regenerate the /now Page from the current Content → Now Page text box. Wired
 * to the editor's save (sn_now_page_save), so the plain-text box stays the
 * authoring surface while the Page is the rendered artifact + SEO/URL surface.
 * No-op (never blanks the page) when the text box has no usable sections.
 */
function sn_now_sync_page() {
	if ( ! function_exists( 'sn_now_page_sections' ) ) {
		return;
	}
	$body = sn_now_build_body( sn_now_page_sections() );
	if ( '' !== $body ) {
		sn_now_upsert_page( $body );
	}
}

/**
 * Render the /uses dossier body (hero + gear sections) as a core/html block
 * for post_content. Reproduces the theme's /about/uses route markup
 * (sn-uses-* classes, a name plus an optional note per item) so uses.css
 * renders it identically. The meta line is the total item count. Returns ''
 * when no group has items.
 *
 * @param array<int,array{label:string,items:array<int,array{name:string,note:string}>}> $groups
 * @return string
 */
function sn_uses_dossier_html( $groups ) {
	if ( empty( $groups ) || ! is_array( $groups ) ) {
		return '';
	}

	$sections_html = '';
	$total         = 0;
	foreach ( array_values( $groups ) as $i => $group ) {
		$label = (string) ( $group['label'] ?? '' );
		if ( '' === trim( $label ) ) {
			continue;
		}
		$items_html = '';
		$count      = 0;
		foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
			$name = (string) ( is_array( $item ) ? ( $item['name'] ?? '' ) : $item );
			if ( '' === trim( $name ) ) {
				continue;
			}
			$note        = is_array( $item ) ? (string) ( $item['note'] ?? '' ) : '';
			$items_html .= '<li class="sn-uses-item"><span class="sn-uses-item-name">' . esc_html( $name ) . '</span>';
			if ( '' !== trim( $note ) ) {
				$items_html .= '<span class="sn-uses-item-note">' . esc_html( $note ) . '</span>';
			}
			$items_html .= '</li>';
			++$count;
		}
		if ( 0 === $count ) {
			continue;
		}
		$total         += $count;
		$sections_html .= sn_dossier_section_html( 'uses', $i, $label, $items_html, $count );
	}

	if ( '' === $sections_html ) {
		return '';
	}

	$meta = $total . ' ' . ( 1 === $total ? 'item' : 'items' );
	// v10.36.0 split hero — same title/side wrappers as /now (see there).
	$hero = '<header class="sn-uses-hero">'
		. '<div class="sn-uses-hero-title">'
		. '<p class="sn-uses-eyebrow">Uses &middot; The kit behind the work</p>'
		. '<h1 class="sn-uses-headline">Uses.</h1>'
		. '</div>'
		. '<div class="sn-uses-hero-side">'
		. '<p class="sn-uses-dek">The hardware and software I actually reach for &mdash; the studio, the instruments, and the tools that keep the signal clean.</p>'
		. '<p class="sn-uses-meta">' . esc_html( $meta ) . '</p>'
		. '</div>'
		. '</header>';

	return "<!-- wp:html -->\n<div class=\"sn-uses-page\">" . $hero . $sections_html . "</div>\n<!-- /wp:html -->";
}

/**
 * The current /uses Page body from the Content → Uses Page text box (parsed
 * groups → dossier HTML). '' when nothing usable is saved.
 *
 * @return string
 */
function sn_uses_current_body() {
	if ( ! function_exists( 'sn_uses_page_get' ) || ! function_exists( 'sn_uses_parse_groups' ) ) {
		return '';
	}
	$page   = sn_uses_page_get();
	$groups = $page ? sn_uses_parse_groups( $page['raw'] ) : array();
	return sn_uses_dossier_html( $groups );
}

/**
 * Create-or-update the /about/uses CHILD Page with the given body. Creates it
 * as a child of the About Page (published, bound to page-uses, Excerpt seeded)
 * when absent; otherwise replaces post_content (the text box is canonical) and
 * seeds the Excerpt only when still empty. Returns the Page ID, or 0 on empty
 * body / the About parent not existing yet (retry-safe).
 *
 * @param string $body Full post_content (the core/html dossier block).
 * @return int
 */
function sn_uses_upsert_page( $body ) {
	if ( '' === trim( (string) $body ) ) {
		return 0;
	}

	$excerpt = 'The hardware, software, and instruments behind the work: what Juan Lentino actually uses, grouped and listed.';
	$page    = get_page_by_path( SN_ABOUT_SLUG . '/' . SN_USES_SLUG );

	if ( $page ) {
		$update = array(
			'ID'           => $page->ID,
			'post_content' => $body,
		);
		if ( '' === trim( (string) $page->post_excerpt ) ) {
			$update['post_excerpt'] = $excerpt;
		}
		wp_update_post( $update );
		return (int) $page->ID;
	}

	$parent = get_page_by_path( SN_ABOUT_SLUG );
	if ( ! $parent ) {
		return 0; // About parent not ready — retry on the next admin_init.
	}

	$new_id = wp_insert_post(
		array(
			'post_title'    => 'Uses',
			'post_name'     => SN_USES_SLUG,
			'post_parent'   => (int) $parent->ID,
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'post_content'  => $body,
			'post_excerpt'  => $excerpt,
			'page_template' => 'page-uses',
		),
		false
	);

	return is_int( $new_id ) && $new_id > 0 ? $new_id : 0;
}

/**
 * Regenerate the /about/uses Page from the current Content → Uses Page text
 * box. Wired to the editor's save (sn_uses_page_save). No-op when the box has
 * no usable groups (never blanks the page).
 */
function sn_uses_sync_page() {
	$body = sn_uses_current_body();
	if ( '' !== $body ) {
		sn_uses_upsert_page( $body );
	}
}
