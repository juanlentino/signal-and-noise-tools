<?php
/**
 * Signal & Noise Tools — /resume page-sync engine (LIVE, per-save).
 *
 * The rendered-artifact layer behind the Content → Resume Page structured
 * form: renders the canonical resume document (inc/resume-page.php) into the
 * /resume Page body and upserts it, mirroring the /now and /uses engines in
 * inc/page-sync-engine.php. The body is ONE wp:html freeform block that
 * reproduces the previously hand-authored page markup verbatim (same
 * sn-resume-* and wp-block-* / preset classes and inline styles, so the theme
 * renders it identically) — wp:html has no validation semantics, so the
 * generated body can never trigger the editor block recovery the hand-edited
 * page had already drifted into. Section headings and eyebrows are layout
 * chrome and live here, not in the document.
 *
 * @package SignalNoiseTools
 * @since 10.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Open a constrained content band: the wp:group wrapper the live page used,
 * as plain HTML. Spacing values are theme spacing-preset slugs.
 *
 * @param string $size Max content width ('960px' | '1400px').
 * @param string $top  Top spacing preset slug (e.g. '60').
 * @param string $bottom Bottom spacing preset slug.
 * @return string
 */
function sn_resume_band_open( $size, $top, $bottom ) {
	$style = 'padding-top:var(--wp--preset--spacing--' . $top . ');padding-right:var(--wp--preset--spacing--40);'
		. 'padding-bottom:var(--wp--preset--spacing--' . $bottom . ');padding-left:var(--wp--preset--spacing--40);'
		. 'max-width:' . $size . ';margin-left:auto;margin-right:auto';
	return '<div class="wp-block-group has-void-background-color has-background sn-resume-band" style="' . $style . '">';
}

/** Eyebrow + section heading pair. @param string $eyebrow @param string $title @return string */
function sn_resume_section_head( $eyebrow, $title ) {
	return '<p class="sn-catalog-eyebrow">' . esc_html( $eyebrow ) . '</p>'
		. '<h2 class="wp-block-heading" style="font-size:clamp(2rem, 5vw, 3.5rem);line-height:1.05">' . esc_html( $title ) . '</h2>';
}

/** A role: title line + bullet list. Bullets are pre-sanitized HTML fragments. @param array $role @return string */
function sn_resume_role_html( $role ) {
	$out = '<p class="sn-resume-title">' . esc_html( (string) ( $role['title'] ?? '' ) ) . '</p>';
	$bullets = (array) ( $role['bullets'] ?? array() );
	if ( ! empty( $bullets ) ) {
		$out .= '<ul class="wp-block-list sn-resume-list">';
		foreach ( $bullets as $bullet ) {
			$out .= '<li>' . $bullet . '</li>'; // kses'd at normalize — the one HTML-bearing field.
		}
		$out .= '</ul>';
	}
	return $out;
}

/** The hero band: eyebrow, headline, summary, chips, contact rail, PDF block. @param array $hero @return string */
function sn_resume_hero_html( $hero ) {
	$out  = sn_resume_band_open( '960px', '60', '30' );
	$out .= '<p class="sn-catalog-eyebrow">Dossier &middot; Background</p>';
	$out .= '<h1 class="wp-block-heading" style="font-size:clamp(3rem, 7vw, 5.5rem);line-height:1">RESUME</h1>';
	if ( '' !== $hero['summary'] ) {
		$out .= '<p class="has-rust-color has-text-color has-body-font-family" style="font-size:1rem;line-height:1.8">' . esc_html( $hero['summary'] ) . '</p>';
	}
	if ( ! empty( $hero['chips'] ) ) {
		$out .= '<ul class="wp-block-list sn-resume-chips">';
		foreach ( $hero['chips'] as $chip ) {
			$out .= '<li>' . esc_html( $chip ) . '</li>';
		}
		$out .= '</ul>';
	}
	$rail = array();
	if ( '' !== $hero['contact_line'] ) {
		$rail[] = esc_html( $hero['contact_line'] );
	}
	if ( '' !== $hero['linkedin'] ) {
		$label  = preg_replace( '~^https?://(www\.)?~i', '', $hero['linkedin'] );
		$rail[] = '<a href="' . esc_url( $hero['linkedin'] ) . '" rel="noopener">' . esc_html( $label ) . '</a>';
	}
	if ( ! empty( $rail ) ) {
		$out .= '<p class="sn-resume-rail">' . implode( ' &middot; ', $rail ) . '</p>';
	}
	if ( '' !== $hero['pdf_url'] ) {
		$label = '' !== $hero['pdf_label'] ? $hero['pdf_label'] : 'Resume (PDF)';
		$out  .= '<div class="wp-block-file sn-resume-download">'
			. '<a id="sn-resume-pdf" href="' . esc_url( $hero['pdf_url'] ) . '">' . esc_html( $label ) . '</a>'
			. '<a href="' . esc_url( $hero['pdf_url'] ) . '" class="wp-block-file__button wp-element-button" download aria-describedby="sn-resume-pdf">Download PDF</a>'
			. '</div>';
	}
	return $out . '</div>';
}

/** The stats band: one column per {n,label} pair. @param array $stats @return string */
function sn_resume_stats_html( $stats ) {
	if ( empty( $stats ) ) {
		return '';
	}
	$out = sn_resume_band_open( '1400px', '30', '40' ) . '<div class="wp-block-columns sn-resume-stats">';
	foreach ( $stats as $stat ) {
		$out .= '<div class="wp-block-column">'
			. '<p class="sn-resume-stat-n">' . esc_html( $stat['n'] ) . '</p>'
			. '<p class="sn-resume-stat-l">' . esc_html( $stat['label'] ) . '</p>'
			. '</div>';
	}
	return $out . '</div></div>';
}

/** The experience band: rail/main columns per employer + the earlier-career fold. @param array $experience @param array $earlier @return string */
function sn_resume_experience_html( $experience, $earlier ) {
	if ( empty( $experience ) && empty( $earlier['entries'] ) ) {
		return '';
	}
	$out    = sn_resume_band_open( '960px', '40', '40' ) . sn_resume_section_head( '01 · Professional Experience', 'EXPERIENCE' );
	$blocks = array();
	foreach ( $experience as $entry ) {
		$rail = esc_html( $entry['dates'] );
		if ( '' !== $entry['location'] ) {
			$rail .= ( '' !== $rail ? '<br>' : '' ) . esc_html( $entry['location'] );
		}
		$main = '<h3 class="wp-block-heading sn-resume-role">' . esc_html( $entry['org'] ) . '</h3>';
		foreach ( $entry['roles'] as $role ) {
			$main .= sn_resume_role_html( $role );
		}
		$blocks[] = '<div class="wp-block-columns">'
			. '<div class="wp-block-column" style="flex-basis:240px"><p class="sn-resume-rail">' . $rail . '</p></div>'
			. '<div class="wp-block-column">' . $main . '</div>'
			. '</div>';
	}
	$out .= implode( '<hr class="wp-block-separator has-alpha-channel-opacity"/>', $blocks );

	if ( ! empty( $earlier['entries'] ) ) {
		$label = '' !== $earlier['label'] ? $earlier['label'] : 'Earlier career';
		$out  .= '<details class="wp-block-details sn-resume-fold"><summary>' . esc_html( $label ) . '</summary>';
		foreach ( $earlier['entries'] as $entry ) {
			$out .= '<p class="sn-resume-fold-co">' . esc_html( $entry['org'] ) . '</p>';
			foreach ( $entry['roles'] as $role ) {
				$out .= sn_resume_role_html( $role );
			}
		}
		$out .= '</details>';
	}
	return $out . '</div>';
}

/** A titled-lines column (Education / Affiliations). @param string $heading @param array $entries @return string */
function sn_resume_titled_lines_html( $heading, $entries ) {
	$out = '<div class="wp-block-column"><h3 class="wp-block-heading sn-resume-title">' . esc_html( $heading ) . '</h3>';
	foreach ( $entries as $entry ) {
		$out .= '<p><strong>' . esc_html( $entry['title'] ) . '</strong>';
		foreach ( $entry['lines'] as $line ) {
			$out .= '<br>' . esc_html( $line );
		}
		$out .= '</p>';
	}
	return $out . '</div>';
}

/** The credentials band: Education | Affiliations & Certifications. @param array $education @param array $affiliations @return string */
function sn_resume_credentials_html( $education, $affiliations ) {
	if ( empty( $education ) && empty( $affiliations ) ) {
		return '';
	}
	return sn_resume_band_open( '960px', '40', '40' )
		. sn_resume_section_head( '02 · Education & Credentials', 'CREDENTIALS' )
		. '<div class="wp-block-columns">'
		. sn_resume_titled_lines_html( 'Education', $education )
		. sn_resume_titled_lines_html( 'Affiliations & Certifications', $affiliations )
		. '</div></div>';
}

/** The publications band. @param array $publications @return string */
function sn_resume_publications_html( $publications ) {
	if ( empty( $publications ) ) {
		return '';
	}
	$out = sn_resume_band_open( '960px', '40', '40' ) . sn_resume_section_head( '03 · Research', 'PUBLICATIONS' );
	foreach ( $publications as $pub ) {
		$title = '' !== $pub['url']
			? '<a href="' . esc_url( $pub['url'] ) . '" rel="noopener">' . esc_html( $pub['title'] ) . '</a>'
			: esc_html( $pub['title'] );
		$out .= '<div class="wp-block-group sn-resume-pub">'
			. '<p class="sn-resume-pub-meta">' . esc_html( $pub['meta'] ) . '</p>'
			. '<h3 class="wp-block-heading sn-resume-pub-title">' . $title . '</h3>'
			. '</div>';
	}
	return $out . '</div>';
}

/** The skills band: category/items table. @param array $skills @return string */
function sn_resume_skills_html( $skills ) {
	if ( empty( $skills ) ) {
		return '';
	}
	$out = sn_resume_band_open( '960px', '40', '80' )
		. sn_resume_section_head( '04 · Capabilities', 'SKILLS' )
		. '<figure class="wp-block-table sn-resume-skills"><table><tbody>';
	foreach ( $skills as $row ) {
		$out .= '<tr><td>' . esc_html( $row['category'] ) . '</td><td>' . esc_html( $row['items'] ) . '</td></tr>';
	}
	return $out . '</tbody></table></figure></div>';
}

/**
 * Render the full /resume Page body from a canonical document. Returns ''
 * when the document is unusable, so callers never blank the page.
 *
 * @param array|null $doc Canonical document (sn_resume_doc_normalize() shape).
 * @return string
 */
function sn_resume_body_html( $doc ) {
	if ( ! is_array( $doc ) || ( empty( $doc['experience'] ) && empty( $doc['publications'] ) ) ) {
		return '';
	}
	$hero    = $doc['hero'];
	$earlier = $doc['earlier'];
	$html    = '<div class="sn-resume-page">'
		. sn_resume_hero_html( $hero )
		. sn_resume_stats_html( $doc['stats'] )
		. sn_resume_experience_html( $doc['experience'], $earlier )
		. sn_resume_credentials_html( $doc['education'], $doc['affiliations'] )
		. sn_resume_publications_html( $doc['publications'] )
		. sn_resume_skills_html( $doc['skills'] )
		. '</div>';
	return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
}

/**
 * Create-or-update the /resume Page with the given body. Replaces
 * post_content on the existing Page (the form is the canonical editor);
 * seeds the Excerpt only when still empty; creates the Page bound to the
 * resume slug + template when absent. Returns the Page ID, or 0 on
 * failure / empty body.
 *
 * @param string $body Full post_content (the wp:html body).
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
