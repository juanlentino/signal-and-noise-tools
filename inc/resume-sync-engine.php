<?php
/**
 * Signal & Noise Tools — /resume page-sync engine (LIVE, per-save).
 *
 * The rendered-artifact layer behind the Content → Resume Page structured
 * form: renders the canonical resume document (inc/resume-page.php) into the
 * /resume Page body and upserts it, mirroring the /now and /uses engines in
 * inc/page-sync-engine.php.
 *
 * v10.33.1: the body is REAL serialized core-block markup — the same block
 * structure the hand-authored page had (wp:group bands, wp:columns rails,
 * wp:details fold, wp:file, wp:table) with the credentials-section delimiter
 * scramble corrected. The v10.33.0 wp:html body LOST THE LAYOUT on the live
 * site: block themes enqueue core block styles per-block only when that block
 * renders, so a wp:html body enqueues none of the columns/file/separator/
 * table CSS and none of the layout-support container styles. Real block
 * markup restores both. Drift-proofing now rests on generation, not on the
 * block type: every save re-emits canonical markup, each JSON attribute blob
 * is a fixed literal, and the test suite decodes every blob and balances
 * every delimiter pair — nothing is ever hand-edited.
 *
 * Section headings and eyebrows are layout chrome and live here, not in the
 * document. Bullets are pre-sanitized HTML fragments (kses at normalize);
 * every other value is escaped here at assembly.
 *
 * @package SignalNoiseTools
 * @since 10.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A constrained content band: the wp:group wrapper the page design uses.
 * Spacing values are theme spacing-preset slugs; padding order in the inline
 * style matches the editor's serializer (top, right, bottom, left).
 *
 * @param string $size   Content width (uniform since v10.33.3 — owner
 *                       direction, no outlier bands; widened to 1320px in
 *                       v10.35.0 for the split-hero composition).
 * @param string $top    Top spacing preset slug (e.g. '60').
 * @param string $bottom Bottom spacing preset slug.
 * @param string $inner  Serialized inner blocks.
 * @return string
 */
function sn_resume_band( $size, $top, $bottom, $inner ) {
	$attrs = '{"style":{"spacing":{"padding":{"top":"var:preset|spacing|' . $top . '","bottom":"var:preset|spacing|' . $bottom . '","left":"var:preset|spacing|40","right":"var:preset|spacing|40"}}},"backgroundColor":"void","layout":{"type":"constrained","contentSize":"' . $size . '"}}';
	$style = 'padding-top:var(--wp--preset--spacing--' . $top . ');padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--' . $bottom . ');padding-left:var(--wp--preset--spacing--40)';
	return '<!-- wp:group ' . $attrs . ' -->' . "\n"
		. '<div class="wp-block-group has-void-background-color has-background" style="' . $style . '">' . "\n"
		. $inner
		. '</div>' . "\n" . '<!-- /wp:group -->' . "\n\n";
}

/**
 * A className'd paragraph block. $html is pre-escaped/pre-sanitized content
 * (rich-text may carry <br>, <a>, <strong>).
 *
 * @param string $class className attribute ('' for a plain paragraph).
 * @param string $html  Inner rich-text HTML.
 * @return string
 */
function sn_resume_para( $class, $html ) {
	$attrs = '' !== $class ? ' {"className":"' . $class . '"}' : '';
	$cls   = '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '';
	return '<!-- wp:paragraph' . $attrs . ' -->' . "\n" . '<p' . $cls . '>' . $html . '</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n\n";
}

/** Eyebrow + clamp-sized section h2. @param string $eyebrow @param string $title @return string */
function sn_resume_section_head( $eyebrow, $title ) {
	return sn_resume_para( 'sn-catalog-eyebrow', esc_html( $eyebrow ) )
		. '<!-- wp:heading {"style":{"typography":{"fontSize":"clamp(2rem, 5vw, 3.5rem)","lineHeight":"1.05"}}} -->' . "\n"
		. '<h2 class="wp-block-heading" style="font-size:clamp(2rem, 5vw, 3.5rem);line-height:1.05">' . esc_html( $title ) . '</h2>' . "\n"
		. '<!-- /wp:heading -->' . "\n\n";
}

/**
 * A wp:list of pre-sanitized rich-text items.
 *
 * @param string   $class ul className ('' for none).
 * @param string[] $items Pre-sanitized <li> inner HTML fragments.
 * @return string
 */
function sn_resume_list( $class, $items ) {
	if ( empty( $items ) ) {
		return '';
	}
	$attrs = '' !== $class ? ' {"className":"' . $class . '"}' : '';
	$cls   = '' !== $class ? ' ' . esc_attr( $class ) : '';
	$out   = '<!-- wp:list' . $attrs . ' -->' . "\n" . '<ul class="wp-block-list' . $cls . '">';
	foreach ( $items as $item ) {
		$out .= '<!-- wp:list-item -->' . "\n" . '<li>' . $item . '</li>' . "\n" . '<!-- /wp:list-item -->' . "\n";
	}
	return $out . '</ul>' . "\n" . '<!-- /wp:list -->' . "\n\n";
}

/** A role: sn-resume-title line + its bullet list. @param array $role @return string */
function sn_resume_role_blocks( $role ) {
	return sn_resume_para( 'sn-resume-title', esc_html( (string) ( $role['title'] ?? '' ) ) )
		. sn_resume_list( 'sn-resume-list', (array) ( $role['bullets'] ?? array() ) ); // bullets kses'd at normalize.
}

/**
 * The hero band — a two-column editorial split since v10.35.0, top-aligned
 * since v10.36.1: eyebrow + title + summary in the left column (summary
 * moved under the title in v10.37.3, owner direction), credential ledger +
 * contact rail + PDF in the right. Core columns stack below 782px.
 *
 * @param array $hero Hero document slice.
 * @return string
 */
function sn_resume_hero_blocks( $hero ) {
	// v10.37.4: the eyebrow is a band kicker ABOVE the columns, so both
	// columns start on the same line — title cap left, ledger top rule
	// right (the in-column eyebrow pushed the ledger above the title).
	$left  = '<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(3rem, 8vw, 7rem)","lineHeight":"0.95"}}} -->' . "\n"
		. '<h1 class="wp-block-heading" style="font-size:clamp(3rem, 8vw, 7rem);line-height:0.95">RESUME</h1>' . "\n"
		. '<!-- /wp:heading -->' . "\n\n";

	// v10.37.3 (owner direction): the summary reads under the title in the
	// left column, filling its dead space; the right column opens with the
	// credential ledger.
	if ( '' !== $hero['summary'] ) {
		$left .= '<!-- wp:paragraph {"style":{"typography":{"fontSize":"1rem","lineHeight":"1.8"}},"textColor":"rust","fontFamily":"body"} -->' . "\n"
			. '<p class="has-rust-color has-text-color has-body-font-family" style="font-size:1rem;line-height:1.8">' . esc_html( $hero['summary'] ) . '</p>' . "\n"
			. '<!-- /wp:paragraph -->' . "\n\n";
	}
	$right = sn_resume_list( 'sn-resume-chips', array_map( 'esc_html', $hero['chips'] ) );

	$rail = array();
	if ( '' !== $hero['contact_line'] ) {
		$rail[] = esc_html( $hero['contact_line'] );
	}
	if ( '' !== $hero['linkedin'] ) {
		$label  = preg_replace( '~^https?://(www\.)?~i', '', $hero['linkedin'] );
		$rail[] = '<a href="' . esc_url( $hero['linkedin'] ) . '" rel="noopener">' . esc_html( $label ) . '</a>';
	}
	if ( ! empty( $rail ) ) {
		$right .= sn_resume_para( 'sn-resume-rail', implode( ' · ', $rail ) );
	}

	if ( '' !== $hero['pdf_url'] ) {
		$label  = '' !== $hero['pdf_label'] ? $hero['pdf_label'] : 'Resume (PDF)';
		$url    = esc_url( $hero['pdf_url'] );
		$right .= '<!-- wp:file {"href":"' . $url . '","className":"sn-resume-download"} -->' . "\n"
			. '<div class="wp-block-file sn-resume-download"><a id="wp-block-file--media-sn-resume-pdf" href="' . $url . '">' . esc_html( $label ) . '</a>'
			. '<a href="' . $url . '" class="wp-block-file__button wp-element-button" download aria-describedby="wp-block-file--media-sn-resume-pdf">Download PDF</a></div>' . "\n"
			. '<!-- /wp:file -->' . "\n\n";
	}

	// v10.36.1: TOP-aligned. The right column is far taller than the title
	// block; bottom alignment sank the title to the column floor and left a
	// hole above it on the live page. /notes and /now keep bottom alignment
	// — their side columns are short enough to sit on the title baseline.
	$inner = sn_resume_para( 'sn-catalog-eyebrow', 'Dossier · Background' )
		. '<!-- wp:columns {"verticalAlignment":"top","className":"sn-resume-hero-split"} -->' . "\n"
		. '<div class="wp-block-columns are-vertically-aligned-top sn-resume-hero-split">' . "\n"
		. '<!-- wp:column {"verticalAlignment":"top","width":"55%"} -->' . "\n"
		. '<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:55%">' . "\n"
		. $left
		. '</div>' . "\n" . '<!-- /wp:column -->' . "\n\n"
		. '<!-- wp:column {"verticalAlignment":"top","width":"45%"} -->' . "\n"
		. '<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:45%">' . "\n"
		. $right
		. '</div>' . "\n" . '<!-- /wp:column -->' . "\n"
		. '</div>' . "\n" . '<!-- /wp:columns -->' . "\n\n";

	return sn_resume_band( '1320px', '60', '30', $inner );
}

/** The stats band: one wp:column per {n,label}. @param array $stats @return string */
function sn_resume_stats_blocks( $stats ) {
	if ( empty( $stats ) ) {
		return '';
	}
	$cols = '';
	foreach ( $stats as $stat ) {
		$cols .= '<!-- wp:column -->' . "\n" . '<div class="wp-block-column">' . "\n"
			. sn_resume_para( 'sn-resume-stat-n', esc_html( $stat['n'] ) )
			. sn_resume_para( 'sn-resume-stat-l', esc_html( $stat['label'] ) )
			. '</div>' . "\n" . '<!-- /wp:column -->' . "\n\n";
	}
	$inner = '<!-- wp:columns {"className":"sn-resume-stats"} -->' . "\n" . '<div class="wp-block-columns sn-resume-stats">' . "\n"
		. $cols . '</div>' . "\n" . '<!-- /wp:columns -->' . "\n\n";
	return sn_resume_band( '1320px', '30', '40', $inner );
}

/** One employer as a rail/main wp:columns pair. @param array $entry @return string */
function sn_resume_employer_blocks( $entry ) {
	$rail = esc_html( $entry['dates'] );
	if ( '' !== $entry['location'] ) {
		$rail .= ( '' !== $rail ? '<br>' : '' ) . esc_html( $entry['location'] );
	}
	$main = '<!-- wp:heading {"level":3,"className":"sn-resume-role"} -->' . "\n"
		. '<h3 class="wp-block-heading sn-resume-role">' . esc_html( $entry['org'] ) . '</h3>' . "\n"
		. '<!-- /wp:heading -->' . "\n\n";
	foreach ( $entry['roles'] as $role ) {
		$main .= sn_resume_role_blocks( $role );
	}
	return '<!-- wp:columns -->' . "\n" . '<div class="wp-block-columns">' . "\n"
		. '<!-- wp:column {"width":"240px"} -->' . "\n" . '<div class="wp-block-column" style="flex-basis:240px">' . "\n"
		. sn_resume_para( 'sn-resume-rail', $rail )
		. '</div>' . "\n" . '<!-- /wp:column -->' . "\n\n"
		. '<!-- wp:column -->' . "\n" . '<div class="wp-block-column">' . "\n"
		. $main
		. '</div>' . "\n" . '<!-- /wp:column -->' . "\n"
		. '</div>' . "\n" . '<!-- /wp:columns -->' . "\n\n";
}

/** The experience band + the earlier-career wp:details fold. @param array $experience @param array $earlier @return string */
function sn_resume_experience_blocks( $experience, $earlier ) {
	if ( empty( $experience ) && empty( $earlier['entries'] ) ) {
		return '';
	}
	$inner  = sn_resume_section_head( '01 · Professional Experience', 'EXPERIENCE' );
	$blocks = array();
	foreach ( $experience as $entry ) {
		$blocks[] = sn_resume_employer_blocks( $entry );
	}
	$separator = '<!-- wp:separator -->' . "\n" . '<hr class="wp-block-separator has-alpha-channel-opacity"/>' . "\n" . '<!-- /wp:separator -->' . "\n\n";
	$inner    .= implode( $separator, $blocks );

	if ( ! empty( $earlier['entries'] ) ) {
		$label  = '' !== $earlier['label'] ? $earlier['label'] : 'Earlier career';
		$inner .= '<!-- wp:details {"className":"sn-resume-fold"} -->' . "\n"
			. '<details class="wp-block-details sn-resume-fold"><summary>' . esc_html( $label ) . '</summary>' . "\n";
		foreach ( $earlier['entries'] as $entry ) {
			$inner .= sn_resume_para( 'sn-resume-fold-co', esc_html( $entry['org'] ) );
			foreach ( $entry['roles'] as $role ) {
				$inner .= sn_resume_role_blocks( $role );
			}
		}
		$inner .= '</details>' . "\n" . '<!-- /wp:details -->' . "\n\n";
	}
	return sn_resume_band( '1320px', '40', '40', $inner );
}

/** A titled-lines column (Education / Affiliations). @param string $heading @param array $entries @return string */
function sn_resume_titled_lines_blocks( $heading, $entries ) {
	$out = '<!-- wp:column -->' . "\n" . '<div class="wp-block-column">' . "\n"
		. '<!-- wp:heading {"level":3,"className":"sn-resume-title"} -->' . "\n"
		. '<h3 class="wp-block-heading sn-resume-title">' . esc_html( $heading ) . '</h3>' . "\n"
		. '<!-- /wp:heading -->' . "\n\n";
	foreach ( $entries as $entry ) {
		$html = '<strong>' . esc_html( $entry['title'] ) . '</strong>';
		foreach ( $entry['lines'] as $line ) {
			$html .= '<br>' . esc_html( $line );
		}
		$out .= sn_resume_para( '', $html );
	}
	return $out . '</div>' . "\n" . '<!-- /wp:column -->' . "\n\n";
}

/** The credentials band — correctly nested this time. @param array $education @param array $affiliations @return string */
function sn_resume_credentials_blocks( $education, $affiliations ) {
	if ( empty( $education ) && empty( $affiliations ) ) {
		return '';
	}
	$inner = sn_resume_section_head( '02 · Education & Credentials', 'CREDENTIALS' )
		. '<!-- wp:columns -->' . "\n" . '<div class="wp-block-columns">' . "\n"
		. sn_resume_titled_lines_blocks( 'Education', $education )
		. sn_resume_titled_lines_blocks( 'Affiliations & Certifications', $affiliations )
		. '</div>' . "\n" . '<!-- /wp:columns -->' . "\n\n";
	return sn_resume_band( '1320px', '40', '40', $inner );
}

/** The publications band. @param array $publications @return string */
function sn_resume_publications_blocks( $publications ) {
	if ( empty( $publications ) ) {
		return '';
	}
	$inner = sn_resume_section_head( '03 · Research', 'PUBLICATIONS' );
	foreach ( $publications as $pub ) {
		$title = '' !== $pub['url']
			? '<a href="' . esc_url( $pub['url'] ) . '" rel="noopener">' . esc_html( $pub['title'] ) . '</a>'
			: esc_html( $pub['title'] );
		$inner .= '<!-- wp:group {"className":"sn-resume-pub"} -->' . "\n"
			. '<div class="wp-block-group sn-resume-pub">' . "\n"
			. sn_resume_para( 'sn-resume-pub-meta', esc_html( $pub['meta'] ) )
			. '<!-- wp:heading {"level":3,"className":"sn-resume-pub-title"} -->' . "\n"
			. '<h3 class="wp-block-heading sn-resume-pub-title">' . $title . '</h3>' . "\n"
			. '<!-- /wp:heading -->' . "\n"
			. '</div>' . "\n" . '<!-- /wp:group -->' . "\n\n";
	}
	return sn_resume_band( '1320px', '40', '40', $inner );
}

/** The skills band: category/items wp:table. @param array $skills @return string */
function sn_resume_skills_blocks( $skills ) {
	if ( empty( $skills ) ) {
		return '';
	}
	$rows = '';
	foreach ( $skills as $row ) {
		$rows .= '<tr><td>' . esc_html( $row['category'] ) . '</td><td>' . esc_html( $row['items'] ) . '</td></tr>';
	}
	$inner = sn_resume_section_head( '04 · Capabilities', 'SKILLS' )
		. '<!-- wp:table {"hasFixedLayout":false,"className":"sn-resume-skills"} -->' . "\n"
		. '<figure class="wp-block-table sn-resume-skills"><table><tbody>' . $rows . '</tbody></table></figure>' . "\n"
		. '<!-- /wp:table -->' . "\n\n";
	return sn_resume_band( '1320px', '40', '80', $inner );
}

/**
 * Render the full /resume Page body (serialized core-block markup) from a
 * canonical document. Returns '' when the document is unusable, so callers
 * never blank the page.
 *
 * @param array|null $doc Canonical document (sn_resume_doc_normalize() shape).
 * @return string
 */
function sn_resume_body_html( $doc ) {
	if ( ! is_array( $doc ) || ( empty( $doc['experience'] ) && empty( $doc['publications'] ) ) ) {
		return '';
	}
	return rtrim(
		sn_resume_hero_blocks( $doc['hero'] )
		. sn_resume_stats_blocks( $doc['stats'] )
		. sn_resume_experience_blocks( $doc['experience'], $doc['earlier'] )
		. sn_resume_credentials_blocks( $doc['education'], $doc['affiliations'] )
		. sn_resume_publications_blocks( $doc['publications'] )
		. sn_resume_skills_blocks( $doc['skills'] )
	) . "\n";
}

/**
 * Create-or-update the /resume Page with the given body. Replaces
 * post_content on the existing Page (the form is the canonical editor);
 * seeds the Excerpt only when still empty; creates the Page bound to the
 * resume slug + template when absent. Returns the Page ID, or 0 on
 * failure / empty body.
 *
 * @param string $body Full post_content (serialized blocks).
 * @return int
 */
function sn_resume_upsert_page( $body ) {
	if ( '' === trim( (string) $body ) ) {
		return 0;
	}

	$slug    = defined( 'SN_RESUME_SLUG' ) ? SN_RESUME_SLUG : 'resume';
	$excerpt = 'Twenty years in the room where the music actually gets made, then twenty more figuring out how to keep the business standing after the session ends. This resume tracks that arc — production, strategy, mentorship — across the U.S. and Latin America.';
	$page    = get_page_by_path( $slug );

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
			'post_title'    => 'Resume',
			'post_name'     => $slug,
			'post_parent'   => 0,
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'post_content'  => $body,
			'post_excerpt'  => $excerpt,
			'page_template' => 'page-resume',
		),
		false
	);

	return is_int( $new_id ) && $new_id > 0 ? $new_id : 0;
}

/**
 * Regenerate the /resume Page from the current document (stored option, or
 * the shipped seed before the first save). Wired to sn_resume_doc_save();
 * no-op (never blanks the page) when no usable document exists.
 */
function sn_resume_sync_page() {
	if ( ! function_exists( 'sn_resume_doc_get' ) ) {
		return;
	}
	$body = sn_resume_body_html( sn_resume_doc_get() );
	if ( '' !== $body ) {
		sn_resume_upsert_page( $body );
	}
}
