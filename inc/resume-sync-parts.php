<?php
/**
 * Signal & Noise Tools: /resume body parts shared by the single body and the
 * two-lane body (inc/resume-sync-engine.php, inc/resume-page-lanes.php): the
 * contact rail, the Download button, section numbers and receipt marks.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hero's contact rail: the contact line, LinkedIn, then any extra links.
 *
 * @param array    $hero  Hero document slice.
 * @param string[] $extra More URLs, shown by host and path.
 * @return string
 */
function sn_resume_hero_rail_blocks( $hero, $extra = array() ) {
	$rail = array();
	if ( '' !== $hero['contact_line'] ) {
		$rail[] = esc_html( $hero['contact_line'] );
	}
	foreach ( array_merge( array( $hero['linkedin'] ), $extra ) as $link ) {
		if ( '' !== (string) $link ) {
			$label  = preg_replace( '~^https?://(www\.)?~i', '', (string) $link );
			$rail[] = '<a href="' . esc_url( $link ) . '" rel="noopener">' . esc_html( $label ) . '</a>';
		}
	}
	return $rail ? sn_resume_para( 'sn-resume-rail', implode( "\u{00A0}· ", $rail ) ) : ''; // the dot ends its line, never starts the next.
}

/**
 * The hero's Download PDF button.
 *
 * The generated PDF (inc/resume-pdf/generate.php) wins once it exists, with
 * ?v=<hash> for cache busting; until then the hand-set URL from the form.
 * The Spanish render brings its own PDF URL (inc/resume-es.php). A lane passes
 * its own address and $resolve false.
 *
 * @param string $href    hero.pdf_url, or a lane's PDF address.
 * @param bool   $resolve Whether the generated music PDF may replace $href.
 * @return string
 */
function sn_resume_download_blocks( $href, $resolve = true ) {
	$pdf_href = ( $resolve && 'en' === sn_resume_lang() && function_exists( 'sn_resume_pdf_link' ) ) ? sn_resume_pdf_link( $href ) : $href;
	if ( '' === $pdf_href ) {
		return '';
	}
	// Button only (owner, 2026-09-25): the filename link beside it repeated
	// the page's own RESUME title. core/file saves no filename anchor and
	// no aria-describedby when fileName is empty, so this is its valid shape.
	$url = esc_url( $pdf_href );
	return '<!-- wp:file {"href":"' . $url . '","className":"sn-resume-download"} -->' . "\n"
		. '<div class="wp-block-file sn-resume-download"><a href="' . $url . '" class="wp-block-file__button wp-element-button" download>' . sn_resume_l( 'Download PDF', 'Descargar PDF' ) . '</a></div>' . "\n"
		. '<!-- /wp:file -->' . "\n\n";
}

/**
 * A section eyebrow with its number replaced ("01 · Research" as "03 · Research").
 *
 * @param string   $label Eyebrow with its usual number.
 * @param int|null $n     The number in this body, or null for the usual one.
 * @return string
 */
function sn_resume_numbered( $label, $n ) {
	return null === $n ? $label : (string) preg_replace( '/^\d{2}/', sprintf( '%02d', $n ), $label );
}

/**
 * A receipt: a quiet link beside an entry to the page that backs it (the
 * issuer's verification page, the paper, the code). English page only; no URL,
 * no mark. The PDFs carry none.
 *
 * @param string $url     The receipt URL.
 * @param string $kind    'verify', 'paper' or 'code'.
 * @param string $context What it backs, for the link's accessible name.
 * @return string
 */
function sn_resume_receipt( $url, $kind, $context ) {
	if ( '' === (string) $url || 'en' !== sn_resume_lang() ) {
		return '';
	}
	return ' <a class="sn-resume-receipt" href="' . esc_url( $url ) . '" rel="noopener" aria-label="' . esc_attr( ucfirst( $kind ) . ': ' . strip_tags( (string) $context ) ) . '">' . esc_html( $kind ) . '</a>';
}

/**
 * The hero's two-column split, top-aligned (v10.36.1): the right column is far
 * taller than the title block, and bottom alignment sank the title. The
 * two-lane head uses the same 55/45 split bottom-aligned, under its own class.
 *
 * @param string $left  Serialized left-column blocks.
 * @param string $right Serialized right-column blocks.
 * @param string $align 'top' or 'bottom' (core's verticalAlignment).
 * @param string $class The columns' class.
 * @return string
 */
function sn_resume_hero_split_blocks( $left, $right, $align = 'top', $class = 'sn-resume-hero-split' ) {
	return '<!-- wp:columns {"verticalAlignment":"' . $align . '","className":"' . $class . '"} -->' . "\n"
		. '<div class="wp-block-columns are-vertically-aligned-' . $align . ' ' . $class . '">' . "\n"
		. '<!-- wp:column {"verticalAlignment":"' . $align . '","width":"55%"} -->' . "\n"
		. '<div class="wp-block-column is-vertically-aligned-' . $align . '" style="flex-basis:55%">' . "\n"
		. $left
		. '</div>' . "\n" . '<!-- /wp:column -->' . "\n\n"
		. '<!-- wp:column {"verticalAlignment":"' . $align . '","width":"45%"} -->' . "\n"
		. '<div class="wp-block-column is-vertically-aligned-' . $align . '" style="flex-basis:45%">' . "\n"
		. $right
		. '</div>' . "\n" . '<!-- /wp:column -->' . "\n"
		. '</div>' . "\n" . '<!-- /wp:columns -->' . "\n\n";
}

/**
 * The hero summary paragraph, rust, under the title or headline.
 *
 * @param string $summary
 * @return string
 */
function sn_resume_summary_blocks( $summary ) {
	if ( '' === (string) $summary ) {
		return '';
	}
	return '<!-- wp:paragraph {"style":{"typography":{"fontSize":"1rem","lineHeight":"1.8"}},"textColor":"rust","fontFamily":"body"} -->' . "\n"
		. '<p class="has-rust-color has-text-color has-body-font-family" style="font-size:1rem;line-height:1.8">' . esc_html( $summary ) . '</p>' . "\n"
		. '<!-- /wp:paragraph -->' . "\n\n";
}
