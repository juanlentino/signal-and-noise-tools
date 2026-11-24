<?php
/**
 * Signal & Noise: Settings > AI, the classic section.
 *
 * The third pane of the Settings tab: nine settings, gathered from the three
 * readings they sat on. All three leaves kept their readings and one line
 * saying where their settings went, so sn_admin_subtab_moves() has no line.
 *
 * 1. Models and budget, from AI > Models & Budget: the prose model, the
 *    vision model, the monthly budget, the Jev credit and its renewal day.
 *    ONE form: sn_handle_ai_settings_save() writes all five on every save
 *    (the budget from `?? 0`), so a form carrying fewer would remove the cap.
 * 2. MCP doors, from AI > MCP Clients: the read door's credential, the write
 *    door's credential and the remote door's switch. The three renderers are
 *    CALLED as they are, not retyped, so each keeps its nonce, its hidden
 *    fields, its warnings and its confirmed Unbind.
 * 3. Scheduled reads, from Connections > Cron: the daily run switch. Run now
 *    is an operation and stayed on Cron with the last-run tally.
 * 4. Two boxes for choices that had no control until 2026-11
 *    (inc/admin-forms/mcp-door-switches.php): Related notes (the ranking)
 *    and Door switches (the read and write doors' own switches).
 *
 * Nothing about saving changed: ai_settings_save, bind_mcp_read_credential,
 * bind_mcp_rw_credential, remote_toggle and scheduled_reads_save, the same
 * fields, the same handlers. A form posts back to the address it was painted
 * on (sn_admin_post_url()), so a save from this pane returns to it.
 *
 * The native twin is apps/sn-dashboard/parts/leaves/settings-ai.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pane's address, for the lines the old screens keep.
 *
 * @return string
 */
function snt_settings_ai_url() {
	return function_exists( 'snt_desktop_admin_url' )
		? snt_desktop_admin_url( 'sn-settings', 'ai' )
		: admin_url( 'admin.php?page=sn-theme-options&tab=settings&sub=ai' );
}

/**
 * The sentence an old screen keeps, up to the link. Shared by both twins. It
 * names settings and never a value.
 *
 * @param string $from `models` (AI > Models & Budget), `doors` (AI > MCP Clients) or `reads` (Connections > Cron).
 * @return string
 */
function snt_settings_ai_moved_text( $from ) {
	$texts = array(
		'models' => __( 'The models, the monthly budget and the Jev credit moved to', 'signal-and-noise-tools' ),
		'doors'  => __( 'The remote door switch and the two door credentials moved to', 'signal-and-noise-tools' ),
		'reads'  => __( 'The daily run switch moved to', 'signal-and-noise-tools' ),
	);
	return (string) ( $texts[ $from ] ?? '' );
}

/**
 * That line on a classic screen, with its link to the pane.
 *
 * @param string $from See snt_settings_ai_moved_text().
 * @return void
 */
function snt_settings_ai_moved_line( $from ) {
	echo '<p class="sn-field-helper">' . esc_html( snt_settings_ai_moved_text( $from ) ) . ' <a href="' . esc_url( snt_settings_ai_url() ) . '">'
		. esc_html__( 'Settings › AI', 'signal-and-noise-tools' ) . '</a>.</p>';
}

/**
 * The MCP doors box: the two credential forms and the remote switch, each the
 * renderer AI > MCP Clients called. The classic switch has no words of its
 * own, so the Remote card's state sentence (why it is off, or locked by
 * SN_MCP_REMOTE_DISABLED) is printed above it; that sentence is built escaped
 * by sn_admin_mcp_status_cards(). The door's usage line is a reading and
 * stays on MCP Clients.
 *
 * @return void
 */
function snt_settings_ai_render_doors() {
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'MCP doors', 'signal-and-noise-tools' ) . '</h2>';
	sn_admin_render_mcp_read_binding();
	sn_admin_render_mcp_rw_binding();
	$cards = sn_admin_mcp_status_cards( sn_admin_mcp_status_state() );
	echo '<p class="sn-callout-h">' . esc_html__( 'Remote door', 'signal-and-noise-tools' ) . '</p>';
	echo '<p>' . (string) ( $cards[3]['state_html'] ?? '' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built (sn_admin_mcp_status_cards()).
	sn_admin_render_mcp_remote_toggle();
	echo '</div>';
}

/**
 * The section: three headed boxes side by side, by subject. A box paints only
 * when its module is loaded.
 *
 * @return void
 */
function sn_admin_render_settings_ai_section() {
	echo '<div class="sn-2up">';
	if ( function_exists( 'sn_admin_render_ai_models_form' ) ) {
		sn_admin_render_ai_models_form();
	}
	if ( function_exists( 'sn_admin_render_related_source' ) ) {
		sn_admin_render_related_source();
	}
	if ( function_exists( 'sn_admin_render_mcp_rw_binding' ) && function_exists( 'sn_admin_render_mcp_read_binding' ) && function_exists( 'sn_admin_render_mcp_remote_toggle' ) ) {
		snt_settings_ai_render_doors();
	}
	if ( function_exists( 'sn_admin_render_mcp_door_switches' ) ) {
		sn_admin_render_mcp_door_switches();
	}
	if ( function_exists( 'snt_scheduled_reads_render_settings' ) ) {
		snt_scheduled_reads_render_settings();
	}
	echo '</div>';
}
