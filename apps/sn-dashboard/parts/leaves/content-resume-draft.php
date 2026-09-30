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
 * The Draft section, painted only when a control can act.
 *
 * @return string
 */
function resume_draft_controls() {
	$has_draft = function_exists( 'sn_resume_draft_get' ) && null !== \sn_resume_draft_get();
	$has_prev  = function_exists( 'sn_resume_prev_get' ) && null !== \sn_resume_prev_get();
	if ( ! $has_draft && ! $has_prev ) {
		return '';
	}
	$out = '';
	if ( $has_draft && function_exists( 'sn_resume_draft_action_url' ) ) {
		$out .= '<p class="snt-prose">' . \snt_kit_esc( __( 'Previews open in their own window and publish nothing.', 'signal-and-noise-tools' ) ) . '</p><p class="snt-prose">'
			. \snt_kit_door( __( 'Preview page', 'signal-and-noise-tools' ), \sn_resume_draft_action_url( 'resume_preview_page' ) )
			. ' '
			. \snt_kit_door( __( 'Preview PDF', 'signal-and-noise-tools' ), \sn_resume_draft_action_url( 'resume_preview_pdf' ) )
			. '</p>';
	}
	if ( $has_draft ) {
		$out .= \snt_kit_form( 'resume_publish', '', array( 'submit' => __( 'Publish', 'signal-and-noise-tools' ), 'confirm' => __( 'Publish the draft to the live /resume page?', 'signal-and-noise-tools' ) ) )
			. \snt_kit_form( 'resume_discard', '', array( 'submit' => __( 'Discard draft', 'signal-and-noise-tools' ), 'confirm' => __( 'Discard the draft? The live page is unchanged.', 'signal-and-noise-tools' ), 'danger' => true ) );
	}
	if ( $has_prev ) {
		$out .= \snt_kit_form( 'resume_revert', '', array( 'submit' => __( 'Revert to previous', 'signal-and-noise-tools' ), 'confirm' => __( 'Make the previous version live again? Revert once more to undo.', 'signal-and-noise-tools' ) ) );
	}
	return \snt_kit_section( __( 'Draft', 'signal-and-noise-tools' ), $out );
}
