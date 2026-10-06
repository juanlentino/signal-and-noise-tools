<?php
/**
 * Signal & Noise Tools: /resume drafts (data layer).
 *
 * Owner direction 2026-09-30: saving the resume form never changes the live
 * page. Save writes a DRAFT (one autoload=no option, normalized exactly as a
 * live save is); Publish makes the draft live through sn_resume_doc_save(),
 * regenerating the PDF when one has been generated before; Revert swaps the
 * live document with the one Publish replaced, keeping each document's own
 * `updated` date, so Revert twice returns.
 *
 * Pure apart from the options API: the Page sync, the PDF generator and the
 * route purge are reached through function_exists() guards, so the suite
 * (tests/resume-draft.php) runs this file without WordPress.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RESUME_DRAFT_OPTION = 'sn_resume_draft';
const SN_RESUME_PREV_OPTION  = 'sn_resume_doc_prev';

/**
 * A stored document, normalized, carrying its own `updated` stamp. Null when
 * the option is absent or refused by normalize.
 *
 * @param string $option Option name.
 * @return array|null
 */
function sn_resume_stored_doc( $option ) {
	$stored = get_option( $option );
	if ( ! is_array( $stored ) ) {
		return null;
	}
	$doc = sn_resume_doc_normalize( $stored );
	if ( null === $doc ) {
		return null;
	}
	$doc['updated'] = sn_resume_text( $stored['updated'] ?? '' );
	return $doc;
}

/** The draft, or null when there is none (never the seed). @return array|null */
function sn_resume_draft_get() {
	return sn_resume_stored_doc( SN_RESUME_DRAFT_OPTION );
}

/**
 * Whether ANY draft is stored, readable or not. Gates Discard, so a draft that
 * normalize refuses (a rule tightened after it was saved) can still be cleared.
 *
 * @return bool
 */
function sn_resume_draft_exists() {
	return is_array( get_option( SN_RESUME_DRAFT_OPTION ) );
}

/** The document the last Publish replaced, or null. @return array|null */
function sn_resume_prev_get() {
	return sn_resume_stored_doc( SN_RESUME_PREV_OPTION );
}

/**
 * Store a draft. A refused document (normalize returns null) is not stored and
 * any earlier draft stands. Never touches the live document or the Page.
 *
 * @param mixed $doc Candidate document (the posted form).
 * @return bool False when refused.
 */
function sn_resume_draft_save( $doc ) {
	$doc = sn_resume_doc_normalize( $doc );
	if ( null === $doc ) {
		return false;
	}
	$doc['updated'] = function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	update_option( SN_RESUME_DRAFT_OPTION, $doc, false );
	return true;
}

/** Drop the draft and any Page autosave built from it. @return void */
function sn_resume_draft_delete() {
	delete_option( SN_RESUME_DRAFT_OPTION );
	sn_resume_autosave_delete();
}

/**
 * Whether two documents carry the same content, `updated` ignored.
 *
 * @param array|null $a Document.
 * @param array|null $b Document.
 * @return bool
 */
function sn_resume_doc_same( $a, $b ) {
	if ( ! is_array( $a ) || ! is_array( $b ) ) {
		return false;
	}
	unset( $a['updated'], $b['updated'] );
	return $a === $b;
}

/**
 * The editor's status line, one sentence, shared by both surfaces.
 *
 * @return string
 */
function sn_resume_draft_status() {
	$draft = sn_resume_draft_get();
	if ( null === $draft ) {
		return sn_resume_draft_exists() ? 'Draft could not be read; discard it.' : 'No draft; showing the live résumé.';
	}
	$when = '' !== $draft['updated'] ? ' ' . $draft['updated'] : '';
	return sn_resume_doc_same( $draft, sn_resume_doc_get() )
		? 'Draft saved' . $when . '; it matches the live résumé.'
		: 'Draft saved' . $when . '; differs from live.';
}

/**
 * After the live document changed: rebuild the PDF when one exists (the
 * generator re-syncs the Page and purges every cache), else purge /resume.
 *
 * @return bool False when the PDF rebuild failed.
 */
function sn_resume_refresh_outputs() {
	$pdf_option = defined( 'SN_RESUME_PDF_OPTION' ) ? SN_RESUME_PDF_OPTION : 'sn_resume_pdf';
	if ( is_array( get_option( $pdf_option ) ) && function_exists( 'sn_resume_pdf_generate' ) ) {
		return ! is_wp_error( sn_resume_pdf_generate() );
	}
	if ( function_exists( 'sn_content_route_purge' ) ) {
		sn_content_route_purge( '/resume' );
	}
	return true;
}

/**
 * Publish the draft. Outcomes: 'none' (no draft), 'identical' (draft equals
 * live; the draft is dropped, nothing else happens), 'failed' (the save wrote
 * nothing; the draft stays), 'published', 'published_pdf_failed' (live, but
 * the PDF rebuild failed; the draft is still dropped because it IS live).
 *
 * @return string
 */
function sn_resume_draft_publish() {
	$draft = sn_resume_draft_get();
	if ( null === $draft ) {
		return 'none';
	}
	if ( sn_resume_doc_same( $draft, sn_resume_doc_get() ) ) {
		sn_resume_draft_delete();
		return 'identical';
	}
	$before = get_option( SN_RESUME_DOC_OPTION );
	if ( ! sn_resume_doc_save( $draft ) ) {
		return 'failed';
	}
	// Snapshot only a document that was really stored: before the first save
	// the live page was the seed, and there is nothing to revert to.
	if ( is_array( $before ) ) {
		update_option( SN_RESUME_PREV_OPTION, $before, false );
	}
	$pdf_ok = sn_resume_refresh_outputs();
	sn_resume_draft_delete();
	return $pdf_ok ? 'published' : 'published_pdf_failed';
}

/**
 * Swap the live document with the previous one. The restored document keeps
 * its ORIGINAL `updated` date (written directly, not through the stamping
 * save). Outcomes: 'none', 'reverted', 'reverted_pdf_failed'.
 *
 * @return string
 */
function sn_resume_revert() {
	$prev = sn_resume_prev_get();
	if ( null === $prev ) {
		return 'none';
	}
	$current = get_option( SN_RESUME_DOC_OPTION );
	update_option( SN_RESUME_DOC_OPTION, $prev, false );
	if ( is_array( $current ) ) {
		update_option( SN_RESUME_PREV_OPTION, $current, false );
	} else {
		delete_option( SN_RESUME_PREV_OPTION );
	}
	if ( function_exists( 'sn_resume_sync_page' ) ) {
		sn_resume_sync_page();
	}
	return sn_resume_refresh_outputs() ? 'reverted' : 'reverted_pdf_failed';
}

/** Delete the /resume Page's autosave (the Preview page copy), if any. @return void */
function sn_resume_autosave_delete() {
	if ( ! function_exists( 'get_page_by_path' ) || ! function_exists( 'wp_get_post_autosave' ) ) {
		return;
	}
	$page = get_page_by_path( defined( 'SN_RESUME_SLUG' ) ? SN_RESUME_SLUG : 'resume' );
	$auto = $page ? wp_get_post_autosave( (int) $page->ID ) : false;
	if ( $auto ) {
		wp_delete_post_revision( (int) $auto->ID );
	}
}
