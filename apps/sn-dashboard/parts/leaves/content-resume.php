<?php
/**
 * S&N Dashboard — Content → Resume Page, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/resume-page.php,
 * `sn_admin_render_resume_section()`) is the STRUCTURED editor for /resume:
 * one form (`sn_action=resume_draft_save`: Save stores a draft, see
 * content-resume-draft.php) over nine sections (Hero, Stats, Experience,
 * Earlier career, Education, Affiliations, Publications, Skills, PDF only),
 * plus the Resume PDF form (`sn_action=resume_pdf_generate`), and a hard
 * failure state when sn_resume_doc_get() has neither a stored document nor a
 * readable seed. Same reader, same names, same handler; the kit's parts
 * instead of wp-admin's. Here the nine sections are one table and one edit
 * region (content-resume-ledger.php, content-resume-sections.php, no folds
 * since 2026-10-11). Row painters: content-resume-parts.php.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/content-resume-parts.php';
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
 * PDF only: fields the generated PDF uses and /resume never shows (the sync
 * engine does not read `pdf`). Same names, labels and placeholders as the
 * classic form.
 *
 * @param array $pdf The pdf block.
 * @return string
 */
function resume_pdf( array $pdf ) {
	return resume_text( 'resume[pdf][headline]', __( 'Headline', 'signal-and-noise-tools' ), $pdf['headline'] ?? '', 'Music Business Development & Strategic Partnerships Leader' )
		. resume_text( 'resume[pdf][tagline]', __( 'Tagline', 'signal-and-noise-tools' ), $pdf['tagline'] ?? '', 'Artist & Label Relations | Latin American & U.S. Markets' )
		. \snt_kit_field( 'textarea', 'resume[pdf][summary]', __( 'Professional summary', 'signal-and-noise-tools' ), (string) ( $pdf['summary'] ?? '' ), array( 'rows' => 4, 'hint' => __( 'Blank: the PDF uses the Summary from Hero.', 'signal-and-noise-tools' ) ) )
		. resume_pair(
			resume_text( 'resume[pdf][location]', __( 'Location', 'signal-and-noise-tools' ), $pdf['location'] ?? '', 'Orlando, FL' ),
			resume_text( 'resume[pdf][phone]', __( 'Phone', 'signal-and-noise-tools' ), $pdf['phone'] ?? '', '(000) 000-0000' )
		)
		// A CHECKBOX, never os-switch: os-form reads only checkboxes as booleans;
		// a switch always submits its static value '1', which would publish the
		// phone on every save (see security-login-defense.php).
		. \snt_kit_field( 'checkbox', 'resume[pdf][phone_public]', __( 'Include the phone in the public PDF', 'signal-and-noise-tools' ), ! empty( $pdf['phone_public'] ), array( 'value' => '1', 'hint' => __( 'Off: the public PDF (the /resume Download link) leaves the phone out, and only the private copy carries it. The web page never shows it either way.', 'signal-and-noise-tools' ) ) )
		. resume_text( 'resume[pdf][email]', __( 'Email', 'signal-and-noise-tools' ), $pdf['email'] ?? '', 'name@example.com' )
		. resume_text( 'resume[pdf][website]', __( 'Website', 'signal-and-noise-tools' ), $pdf['website'] ?? '', 'https://juanlentino.com (blank: this site)' )
		. resume_lines( 'resume[pdf][competencies]', $pdf['competencies'] ?? array(), __( 'Core competencies: one per line', 'signal-and-noise-tools' ), 'Strategic Partnerships & Deal Negotiation', 6 )
		. resume_lines( 'resume[pdf][toolkit]', $pdf['toolkit'] ?? array(), __( 'Technical toolkit: one per line', 'signal-and-noise-tools' ), 'Pro Tools', 4 );
}

/**
 * The Resume PDF form: its own action and nonce, never the resume document.
 *
 * @return string
 */
function resume_pdf_generate() {
	$meta = get_option( defined( 'SN_RESUME_PDF_OPTION' ) ? SN_RESUME_PDF_OPTION : 'sn_resume_pdf' );
	if ( is_array( $meta ) && ! empty( $meta['url'] ) ) {
		// 2026-10-12 audit: the classic's facts (size, SHA-256, where the
		// Download link points) restored; "1 pages" made singular.
		$pages  = (int) ( $meta['pages'] ?? 0 );
		$status = '<p class="snt-prose">' . \snt_kit_esc( sprintf( /* translators: 1: timestamp, 2: pages, 3: file size */ _n( 'Generated %1$s: %2$s page, %3$s, SHA-256', 'Generated %1$s: %2$s pages, %3$s, SHA-256', $pages, 'signal-and-noise-tools' ), \sn_resume_pdf_when( $meta ), number_format_i18n( $pages ), size_format( (int) ( $meta['bytes'] ?? 0 ) ) ) )
			. ' ' . \snt_kit_code( substr( (string) ( $meta['sha256'] ?? '' ), 0, 12 ), false ) . '. '
			. \snt_kit_link( __( 'Open the PDF', 'signal-and-noise-tools' ), \sn_resume_pdf_link( '' ) ) . '. ' . \snt_kit_esc( __( 'The /resume Download link points here.', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$status = '<p class="snt-prose">' . \snt_kit_esc( __( 'Not generated yet: the /resume Download link still uses the PDF URL. Generating builds the PDF from the published resume and switches the link to it.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	if ( function_exists( 'snt_pdf_engine_status_line' ) ) {
		$status .= '<p class="snt-prose">' . \snt_kit_esc( \snt_pdf_engine_status_line() ) . '</p>';
	}
	$private = '<p class="snt-prose">' . \snt_kit_esc( __( 'A private copy always includes the phone: built on demand for you, never saved on the server, so it has no public URL.', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_section(
		__( 'Resume PDF', 'signal-and-noise-tools' ),
		\snt_kit_form( 'resume_pdf_generate', $status, array( 'submit' => __( 'Generate PDF', 'signal-and-noise-tools' ) ) )
		// A DOOR, not a form: a window replays a form's handler and a streamed
		// PDF never reaches the reader (content-resume-draft.php).
		. $private . ( function_exists( 'sn_resume_action_url' ) ? '<p class="snt-prose">' . \snt_kit_door( __( 'Download private copy (with phone)', 'signal-and-noise-tools' ), \sn_resume_action_url( 'resume_pdf_private' ) ) . '</p>' : '' )
	);
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/resume'] = __NAMESPACE__ . '\\paint_content_resume';
		return $painters;
	}
);
