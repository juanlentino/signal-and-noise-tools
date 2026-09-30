<?php
/**
 * Signal & Noise: admin POST handlers for /resume drafts (inc/resume-draft.php).
 *
 * Save writes a draft and nothing goes live. Preview page and Preview PDF show
 * the draft without publishing it; Publish, Discard and Revert are the only
 * actions that change what visitors see. Every handler follows the dispatcher
 * contract (fn( array $post ): string returning a ?sn_flash=… code); the two
 * previews end in a redirect or a download and exit on success.
 *
 * Actions served: resume_draft_save, resume_preview_page, resume_preview_pdf,
 * resume_publish, resume_discard, resume_revert
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * resume_draft_save: the editor form. Same parsing as the old resume_save
 * (the posted resume[…] arrays are the document shape); only the destination
 * changed, the draft slot instead of the live option.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_resume_draft_save( $post ) {
	if ( ! function_exists( 'sn_resume_draft_save' ) ) {
		return 'resume_failed';
	}
	$resume = isset( $post['resume'] ) && is_array( $post['resume'] ) ? (array) wp_unslash( $post['resume'] ) : array();
	return sn_resume_draft_save( $resume ) ? 'resume_draft_saved' : 'resume_draft_refused';
}

/**
 * resume_preview_page: write the draft's Page body as an autosave of the
 * /resume Page and open WordPress's own preview of it (the preview_id +
 * preview_nonce pair post_preview() builds, without which a published Page
 * previews its live content). Nothing is published.
 *
 * @param array $post Raw $_POST (unused).
 * @return string Flash code on failure; redirects and exits on success.
 */
function sn_handle_resume_preview_page( $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- dispatcher signature.
	$draft = function_exists( 'sn_resume_draft_get' ) ? sn_resume_draft_get() : null;
	if ( null === $draft ) {
		return 'resume_no_draft';
	}
	$body = function_exists( 'sn_resume_body_html' ) ? sn_resume_body_html( $draft ) : '';
	$page = get_page_by_path( defined( 'SN_RESUME_SLUG' ) ? SN_RESUME_SLUG : 'resume' );
	if ( '' === $body || ! $page || ( function_exists( 'snt_generated_page_guard' ) && ! snt_generated_page_guard( 'resume', $body ) ) ) {
		return 'resume_preview_failed';
	}
	if ( ! function_exists( 'wp_create_post_autosave' ) ) {
		require_once ABSPATH . 'wp-admin/includes/post.php';
	}
	$saved = wp_create_post_autosave(
		wp_slash(
			array(
				'post_ID'      => (int) $page->ID,
				'post_type'    => 'page',
				'post_title'   => $page->post_title,
				'post_excerpt' => $page->post_excerpt,
				'post_content' => $body,
			)
		)
	);
	if ( is_wp_error( $saved ) ) {
		return 'resume_preview_failed';
	}
	$args = array(
		'preview_id'    => (int) $page->ID,
		'preview_nonce' => wp_create_nonce( 'post_preview_' . (int) $page->ID ),
	);
	wp_safe_redirect( (string) get_preview_post_link( $page, $args ) );
	exit;
}

/**
 * resume_preview_pdf: stream the draft's PDF (public phone rule), never stored.
 *
 * @param array $post Raw $_POST (unused).
 * @return string Flash code on failure; streams and exits on success.
 */
function sn_handle_resume_preview_pdf( $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- dispatcher signature.
	if ( ! function_exists( 'sn_resume_draft_get' ) || null === sn_resume_draft_get() ) {
		return 'resume_no_draft';
	}
	if ( function_exists( 'sn_resume_pdf_draft_stream' ) ) {
		sn_resume_pdf_draft_stream();
	}
	return 'resume_pdf_failed';
}

/**
 * resume_publish: make the draft live (inc/resume-draft.php does the work).
 *
 * @param array $post Raw $_POST (unused).
 * @return string Flash code.
 */
function sn_handle_resume_publish( $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- dispatcher signature.
	if ( ! function_exists( 'sn_resume_draft_publish' ) ) {
		return 'resume_failed';
	}
	$codes = array(
		'none'                 => 'resume_no_draft',
		'identical'            => 'resume_nothing_to_publish',
		'failed'               => 'resume_publish_failed',
		'published'            => 'resume_published',
		'published_pdf_failed' => 'resume_published_pdf_failed',
	);
	return $codes[ sn_resume_draft_publish() ] ?? 'resume_failed';
}

/**
 * resume_discard: drop the draft and its Page autosave. The live page stands.
 *
 * @param array $post Raw $_POST (unused).
 * @return string Flash code.
 */
function sn_handle_resume_discard( $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- dispatcher signature.
	if ( ! function_exists( 'sn_resume_draft_delete' ) ) {
		return 'resume_failed';
	}
	sn_resume_draft_delete();
	return 'resume_draft_discarded';
}

/**
 * resume_revert: swap the live document with the one the last Publish replaced.
 *
 * @param array $post Raw $_POST (unused).
 * @return string Flash code.
 */
function sn_handle_resume_revert( $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- dispatcher signature.
	if ( ! function_exists( 'sn_resume_revert' ) ) {
		return 'resume_failed';
	}
	$codes = array(
		'none'                => 'resume_no_prev',
		'reverted'            => 'resume_reverted',
		'reverted_pdf_failed' => 'resume_reverted_pdf_failed',
	);
	return $codes[ sn_resume_revert() ] ?? 'resume_failed';
}
