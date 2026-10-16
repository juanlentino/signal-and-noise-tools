<?php
/**
 * S&N Dashboard: the /resume draft controls, painted from the kit.
 *
 * The classic twin is inc/admin-forms/resume-draft.php. Publish, Discard and
 * Revert are one-button kit forms, each with its own action and nonce. The two
 * previews are DOORS to admin-post.php (their nonce in the URL), not forms: a
 * window replays a form's handler and swallows its exit, so a redirect to the
 * page preview or a PDF download could never reach the reader from a form.
 * A door opens the URL as its own shell window, where both work.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The editor's status line (no draft, draft differs, draft matches).
 *
 * @return string
 */
function resume_draft_status() {
	return function_exists( 'sn_resume_draft_status' ) ? '<p class="snt-prose">' . \snt_kit_esc( \sn_resume_draft_status() ) . '</p>' : '';
}

/**
 * The Draft section: the status line, then the controls that can act.
 * 2026-10-12 audit: the status sat in the editor's lead, nine rows from
 * Publish, and the box vanished with no draft; it is always painted now, so
 * it pairs with the Resume PDF box.
 *
 * @return string
 */
function resume_draft_controls() {
	$has_draft = function_exists( 'sn_resume_draft_get' ) && null !== \sn_resume_draft_get();
	$stored    = function_exists( 'sn_resume_draft_exists' ) && \sn_resume_draft_exists(); // readable or not: Discard clears either.
	$has_prev  = function_exists( 'sn_resume_prev_get' ) && null !== \sn_resume_prev_get();
	$out       = resume_draft_status();
	if ( ! $stored ) {
		$out .= '<p class="snt-prose">' . \snt_kit_esc( __( 'Save draft stores a draft; its previews, Publish and Discard then appear here.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	if ( $has_draft && function_exists( 'sn_resume_action_url' ) ) {
		$out .= '<p class="snt-prose">' . \snt_kit_esc( __( 'Previews open in their own window and publish nothing.', 'signal-and-noise-tools' ) ) . '</p><p class="snt-prose">'
			. \snt_kit_door( __( 'Preview page', 'signal-and-noise-tools' ), \sn_resume_action_url( 'resume_preview_page' ) )
			. ' '
			. \snt_kit_door( __( 'Preview PDF', 'signal-and-noise-tools' ), \sn_resume_action_url( 'resume_preview_pdf' ) )
			. '</p>';
	}
	// 2026-10-16 (the leaf anatomy, docs/kit-leaf-anatomy.md): three lone
	// buttons took a full row each; they are compact inline forms, side by
	// side, each behind the same confirm.
	$buttons = '';
	if ( $has_draft ) {
		$buttons .= \snt_kit_form( 'resume_publish', '', array( 'submit' => __( 'Publish', 'signal-and-noise-tools' ), 'confirm' => __( 'Publish the draft to the live /resume page?', 'signal-and-noise-tools' ), 'inline' => true ) );
	}
	if ( $stored ) {
		$buttons .= \snt_kit_form( 'resume_discard', '', array( 'submit' => __( 'Discard draft', 'signal-and-noise-tools' ), 'confirm' => __( 'Discard the draft? The live page is unchanged.', 'signal-and-noise-tools' ), 'danger' => true, 'inline' => true ) );
	}
	if ( $has_prev ) {
		$buttons .= \snt_kit_form( 'resume_revert', '', array( 'submit' => __( 'Revert to previous', 'signal-and-noise-tools' ), 'confirm' => __( 'Make the previous version live again? Revert once more to undo.', 'signal-and-noise-tools' ), 'inline' => true ) );
	}
	if ( '' !== $buttons ) {
		$out .= \snt_kit_tag( 'os-cluster', array( 'gap' => '8' ), $buttons );
	}
	return \snt_kit_section( __( 'Draft', 'signal-and-noise-tools' ), $out );
}
