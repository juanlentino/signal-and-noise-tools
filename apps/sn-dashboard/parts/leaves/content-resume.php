<?php
/**
 * S&N Dashboard — Content → Resume Page, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/resume-page.php,
 * `sn_admin_render_resume_section()`) is the STRUCTURED editor for /resume:
 * one form (`sn_action=resume_draft_save`: Save stores a draft, see
 * content-resume-draft.php) over eleven sections (Hero, Stats, Experience,
 * Earlier career, Education, Affiliations, Publications, Skills, What I've
 * Built, Résumé settings, Contact), plus the Resume PDF form
 * (`sn_action=resume_pdf_generate`), and a hard failure state when
 * sn_resume_doc_get_raw() has neither a stored document nor a readable seed.
 * Same reader, same names, same handler; the kit's parts instead of
 * wp-admin's. Here the eleven sections are one table and one edit
 * region (content-resume-ledger.php, content-resume-sections.php, no folds
 * since 2026-10-11). Row painters: content-resume-parts.php; the variant
 * controls: content-resume-variants.php.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/content-resume-parts.php';
require_once __DIR__ . '/content-resume-variants.php';
require_once __DIR__ . '/content-resume-draft.php';
require_once __DIR__ . '/content-resume-edit.php';
require_once __DIR__ . '/content-resume-sections.php';
require_once __DIR__ . '/content-resume-ledger.php';

/**
 * The intro: which page this edits, and whether the form has taken it over.
 *
 * @param array $doc The document.
 * @return string
 */
function resume_intro( array $doc ) {
	$link = \snt_kit_link( '/resume', home_url( '/resume' ) );
	if ( '' !== (string) ( $doc['updated'] ?? '' ) ) {
		return '<p class="snt-prose">'
			. sprintf( /* translators: %s: the /resume link */ \snt_kit_esc( __( 'This is the editor for the live %s page. Saving stores a draft; nothing goes live until you publish it.', 'signal-and-noise-tools' ) ), $link )
			. ' ' . \snt_kit_esc( __( 'Last published:', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( (string) $doc['updated'], false ) . '.'
			. '</p>';
	}
	return '<p class="snt-prose">'
		. sprintf( /* translators: %s: the /resume link */ \snt_kit_esc( __( 'This is the editor for the live %s page, prefilled from the current published content. Saving stores a draft; the first publish takes over the page body, and from then on this editor is the canonical one.', 'signal-and-noise-tools' ) ), $link )
		. '</p>';
}

/**
 * The Hero section: summary, chips, contact/LinkedIn, PDF URL/label.
 *
 * @param array $hero The hero block.
 * @return string
 */
function resume_hero( array $hero ) {
	return \snt_kit_field( 'textarea', 'resume[hero][summary]', __( 'Summary', 'signal-and-noise-tools' ), (string) ( $hero['summary'] ?? '' ), array( 'rows' => 3 ) )
		. resume_lines( 'resume[hero][chips]', $hero['chips'] ?? array(), __( 'Credential chips: one per line', 'signal-and-noise-tools' ), __( 'Credential or membership', 'signal-and-noise-tools' ), 4 )
		. resume_pair(
			resume_text( 'resume[hero][contact_line]', __( 'Contact line', 'signal-and-noise-tools' ), $hero['contact_line'] ?? '', 'City, State' ),
			resume_text( 'resume[hero][linkedin]', __( 'LinkedIn URL', 'signal-and-noise-tools' ), $hero['linkedin'] ?? '', 'https://www.linkedin.com/in/…' )
		)
		. resume_text( 'resume[hero][pdf_url]', __( 'PDF URL', 'signal-and-noise-tools' ), $hero['pdf_url'] ?? '', 'https://…/Resume.pdf' );
}

/**
 * Contact: the email the PDF's contact line prints, and the phone it never
 * prints. The other pdf.* keys stay in the document, carried over on save
 * (sn_resume_draft_carry_over()). Same names, labels and placeholders as the
 * classic form.
 *
 * @param array $pdf The pdf block.
 * @return string
 */
function resume_pdf( array $pdf ) {
	return resume_text( 'resume[pdf][email]', __( 'Email', 'signal-and-noise-tools' ), $pdf['email'] ?? '', 'name@example.com' )
		. \snt_kit_field( 'text', 'resume[pdf][phone]', __( 'Phone', 'signal-and-noise-tools' ), (string) ( $pdf['phone'] ?? '' ), array( 'placeholder' => '(000) 000-0000', 'hint' => __( 'Never printed: not on /resume, not in any PDF. It is kept only so the PDF check can refuse a file that carries it.', 'signal-and-noise-tools' ) ) );
}

/**
 * The Resume PDF form: its own action and nonce, never the resume document.
 *
 * @return string
 */
function resume_pdf_generate() {
	$meta   = get_option( defined( 'SN_RESUME_PDF_OPTION' ) ? SN_RESUME_PDF_OPTION : 'sn_resume_pdf' );
	$one    = function_exists( 'sn_resume_pdf_is_one' ) && \sn_resume_pdf_is_one(); // no music PDF: the résumé PDF is in the lines below.
	$status = '';
	if ( ! $one && is_array( $meta ) && ! empty( $meta['url'] ) ) {
		// 2026-10-12 audit: the classic's facts (size, SHA-256, where the
		// Download link points) restored; "1 pages" made singular.
		$pages  = (int) ( $meta['pages'] ?? 0 );
		$status = '<p class="snt-prose">' . \snt_kit_esc( sprintf( /* translators: 1: timestamp, 2: pages, 3: file size */ _n( 'Generated %1$s: %2$s page, %3$s, SHA-256', 'Generated %1$s: %2$s pages, %3$s, SHA-256', $pages, 'signal-and-noise-tools' ), \sn_resume_pdf_when( $meta ), number_format_i18n( $pages ), size_format( (int) ( $meta['bytes'] ?? 0 ) ) ) )
			. ' ' . \snt_kit_code( substr( (string) ( $meta['sha256'] ?? '' ), 0, 12 ), false ) . '. '
			. \snt_kit_link( __( 'Open the PDF', 'signal-and-noise-tools' ), \sn_resume_pdf_link( '' ) ) . '. ' . \snt_kit_esc( __( 'The /resume Download link points here.', 'signal-and-noise-tools' ) ) . '</p>';
	} elseif ( ! $one ) {
		$status = '<p class="snt-prose">' . \snt_kit_esc( __( 'Not generated yet: the /resume Download link still uses the PDF URL. Generating builds the PDF from the published resume and switches the link to it.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	if ( function_exists( 'snt_pdf_engine_status_line' ) ) {
		$status .= '<p class="snt-prose">' . \snt_kit_esc( \snt_pdf_engine_status_line() ) . '</p>';
	}
	foreach ( function_exists( 'sn_resume_pdf_variant_lines' ) ? \sn_resume_pdf_variant_lines() : array() as $line ) {
		$status .= '<p class="snt-prose">' . \snt_kit_esc( $line['text'] ) . ( '' !== $line['url'] ? ' ' . \snt_kit_link( __( 'Open it', 'signal-and-noise-tools' ), $line['url'] ) . '.' : '' ) . '</p>';
	}
	return \snt_kit_section(
		__( 'Resume PDF', 'signal-and-noise-tools' ),
		\snt_kit_form( 'resume_pdf_generate', $status, array( 'submit' => __( 'Generate PDF', 'signal-and-noise-tools' ) ) )
	);
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/resume'] = __NAMESPACE__ . '\\paint_content_resume';
		return $painters;
	}
);
