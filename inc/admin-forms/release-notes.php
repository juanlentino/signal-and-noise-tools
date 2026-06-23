<?php
/**
 * Signal & Noise — Release Notes admin section (Tools tab → Release Notes sub-tab).
 *
 * Paste a CHANGELOG delta → AI drafts Mimestream-style release notes
 * (sn_action=release_notes_draft). The dispatcher PRG-redirects, so the
 * generated markdown (or an error) is stashed in a short per-user transient by
 * sn_handle_release_notes_draft() and rendered back here in a copyable
 * read-only textarea.
 *
 * Added in v4.11.0 (Task 4).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-user transient key holding the last drafted release notes for redisplay.
 *
 * @since 4.11.0
 * @return string
 */
function sn_release_notes_result_key() {
	return 'sn_release_notes_result_' . get_current_user_id();
}

/**
 * Render the Release Notes section body. Used as the sn_admin_render_section()
 * callback for the 'release-notes' sub-tab.
 *
 * @since 4.11.0
 */
function sn_admin_render_release_notes_section() {
	// v6.39.2: gate the drafter on AI availability, mirroring Insights' Run
	// Analysis button — a click with no provider configured would POST a request
	// that just errors. Disable the button + explain the setup instead.
	$ai_ready = function_exists( 'snt_ai_is_available' ) && snt_ai_is_available();

	$stash  = get_transient( sn_release_notes_result_key() );
	$result = '';
	$error  = '';
	$delta  = '';
	if ( is_array( $stash ) ) {
		$result = isset( $stash['result'] ) ? (string) $stash['result'] : '';
		$error  = isset( $stash['error'] ) ? (string) $stash['error'] : '';
		$delta  = isset( $stash['delta'] ) ? (string) $stash['delta'] : '';
		// One-shot — clear after reading so a refresh doesn't re-show stale output.
		delete_transient( sn_release_notes_result_key() );
	}

	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="release_notes_draft">';

	echo '<h2 class="sn-fieldset-h">AI release-notes drafter</h2>';
	echo '<p class="sn-fieldset-intro">Paste the raw change log delta for a version (or a few bullet points of what changed) and the AI drafts <a href="https://mimestream.com/releases" target="_blank" rel="noopener noreferrer">Mimestream-style</a> release notes — warm, plain-English, grouped into <code>New</code> / <code>Improvements</code> / <code>Fixed</code>. Output is markdown you can paste straight into a release-notes page. One on-demand AI call per draft; input is capped at ~4,000 characters to keep it cheap.</p>';

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn-rn-delta">What changed</label>';
	echo '<textarea id="sn-rn-delta" name="changelog_delta" rows="10" class="large-text code" placeholder="- Added the AI release-notes drafter&#10;- Fixed the command palette guard on WP 6.x&#10;- Tightened the Insights advisor prompt">' . esc_textarea( $delta ) . '</textarea>';
	echo '<p class="sn-field-helper">Don\'t worry about formatting — paste your CHANGELOG bullets or a quick brain-dump. Anything over ~4,000 characters is trimmed before the call.</p>';
	echo '</div>';

	if ( ! $ai_ready ) {
		echo '<p class="sn-field-helper sn-text--err"><strong>AI client not available.</strong> Two setup steps are required: <a href="' . esc_url( admin_url( 'options-general.php?page=ai-wp-admin' ) ) . '">Settings → AI</a> (global enable + per-feature toggles), and <a href="' . esc_url( admin_url( 'options-general.php?page=connectors' ) ) . '">Settings → Connectors</a> (provider + API key). Both must be configured before this can run.</p>';
	}

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" class="button button-primary"' . ( $ai_ready ? '' : ' disabled' ) . '>Draft release notes</button>';
	echo '</div>';

	echo '</form>';

	if ( '' !== $error ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
	}

	if ( '' !== $result ) {
		echo '<h2 class="sn-fieldset-h">Drafted notes</h2>';
		echo '<p class="sn-fieldset-intro">Select all and copy — this is plain markdown.</p>';
		echo '<textarea readonly rows="12" class="large-text code" onclick="this.select()">' . esc_textarea( $result ) . '</textarea>';
	}
}
