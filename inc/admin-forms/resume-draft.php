<?php
/**
 * Signal & Noise: the /resume draft controls on the classic leaf
 * (Content → Resume Page), beside the editor in inc/admin-forms/resume-page.php.
 *
 * Each control is its own form with its own nonce, so none can post the
 * document. The two previews are GET links to admin-post.php carrying their
 * nonce, opened in a new tab: one ends on WordPress's page preview, the other
 * is a download, and neither belongs in the admin tab. Publish, Discard and
 * Revert are POST forms behind a confirm. Painted only when they can act: the
 * draft controls with a draft, Revert with a previous version.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A GET link that runs one admin-post action (its nonce in the URL). The page,
 * tab and sub are named outright rather than read from the request: a native
 * window paints this leaf without the classic page's query, and the dispatcher
 * refuses a request that names no Signal & Noise page. Built raw, not through
 * sn_admin_post_url(): wp_nonce_url() returns an HTML-escaped URL (&amp;),
 * which add_query_arg() would mangle into an `amp;_wpnonce` key.
 *
 * @param string $action Handler action (a key of sn_admin_post_handlers()).
 * @return string
 */
function sn_resume_draft_action_url( $action ) {
	$args = array(
		'action'   => 'sn_' . $action,
		'page'     => 'sn-content',
		'tab'      => 'content',
		'sub'      => 'resume',
		'_wpnonce' => wp_create_nonce( 'sn_' . $action ),
	);
	return add_query_arg( $args, admin_url( 'admin-post.php' ) );
}

/**
 * One POST button in its own form.
 *
 * @param string $action  Handler action.
 * @param string $label   Button text.
 * @param string $confirm Confirm dialog text.
 */
function sn_rsm_draft_button( $action, $label, $confirm ) {
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-rsm-draft-form">';
	wp_nonce_field( 'sn_' . $action );
	echo '<button type="submit" name="action" value="sn_' . esc_attr( $action ) . '" class="button" data-snt-confirm="' . esc_attr( $confirm ) . '">' . esc_html( $label ) . '</button>';
	echo '</form>';
}

/**
 * The Draft section: previews, Publish, Discard, Revert.
 */
function sn_admin_render_resume_draft_controls() {
	$has_draft = function_exists( 'sn_resume_draft_get' ) && null !== sn_resume_draft_get();
	$has_prev  = function_exists( 'sn_resume_prev_get' ) && null !== sn_resume_prev_get();
	if ( ! $has_draft && ! $has_prev ) {
		return;
	}
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Draft</h2>';
	if ( $has_draft ) {
		echo '<p class="sn-fieldset-intro">Previews open in a new tab and publish nothing.</p>';
	}
	echo '<div class="sn-fieldset-actions">';
	if ( $has_draft ) {
		echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( sn_resume_draft_action_url( 'resume_preview_page' ) ) . '">Preview page</a> ';
		echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( sn_resume_draft_action_url( 'resume_preview_pdf' ) ) . '">Preview PDF</a> ';
		sn_rsm_draft_button( 'resume_publish', 'Publish', 'Publish the draft to the live /resume page?' );
		sn_rsm_draft_button( 'resume_discard', 'Discard draft', 'Discard the draft? The live page is unchanged.' );
	}
	if ( $has_prev ) {
		sn_rsm_draft_button( 'resume_revert', 'Revert to previous', 'Make the previous version live again? Revert once more to undo.' );
	}
	echo '</div></div>';
}
