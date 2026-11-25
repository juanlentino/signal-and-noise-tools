<?php
/**
 * The classic controls for the four stored choices (handlers:
 * inc/admin-post-actions/mcp-doors.php), and the words both twins share.
 *
 * - Settings > AI, the Read door and Write door boxes: each door's switch,
 *   its own form (`mcp_doors_save` with `sn_door`), first in that door's
 *   box, the way the Remote door box holds its switch. A door its wp-config
 *   constant locks paints disabled, with the constant named.
 * - Settings > Front end, the Reading box: the related-notes ranking, a field
 *   of the `save_theme` form beside Related notes shown.
 * - Settings > Notifications and reports: the write-door email
 *   (`mcp_rw_notify_save`), with the other email switches.
 *
 * The native twin is apps/sn-dashboard/parts/leaves/settings-switches.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The words, once, for both twins.
 *
 * @return array<string,string>
 */
function snt_switches_text() {
	return array(
		'read_label'   => __( 'Read door enabled', 'signal-and-noise-tools' ),
		'read_hint'    => __( 'Off, the read door answers no MCP client, whatever credential it brings.', 'signal-and-noise-tools' ),
		'rw_label'     => __( 'Write door enabled', 'signal-and-noise-tools' ),
		'rw_hint'      => __( 'Off, the write door refuses every call, bound credential or not.', 'signal-and-noise-tools' ),
		'related_lbl'  => __( 'Rank related notes by', 'signal-and-noise-tools' ),
		'related_hint' => __( 'Embeddings rank by meaning and need Workers AI set up (Settings › Accounts and connections); until it is, the lists use shared words. A change queues a rebuild of the lists, which runs on the next scheduled-task run.', 'signal-and-noise-tools' ),
		'emb'          => __( 'Meaning (embeddings)', 'signal-and-noise-tools' ),
		'tfidf'        => __( 'Shared words', 'signal-and-noise-tools' ),
		'notify_h'     => __( 'Write-door alerts', 'signal-and-noise-tools' ),
		'notify_label' => __( 'Email the admin address each time the write door is used', 'signal-and-noise-tools' ),
		'notify_hint'  => __( 'Calls within a minute of an email are counted in the next one.', 'signal-and-noise-tools' ),
	);
}

/**
 * The line a locked door shows instead of its hint.
 *
 * @param string $door `read` or `rw`.
 * @return string
 */
function snt_switches_locked_text( $door ) {
	/* translators: %s: the wp-config constant's name. */
	return sprintf( __( 'Locked off by %s in wp-config.php; only removing it reopens this door.', 'signal-and-noise-tools' ), 'read' === $door ? 'SN_MCP_READ_DISABLED' : 'SN_MCP_RW_DISABLED' );
}

/**
 * One classic checkbox row.
 *
 * @param string $door `read` or `rw`.
 * @return void
 */
function snt_switches_render_door( $door ) {
	$t      = snt_switches_text();
	$locked = snt_mcp_door_locked( $door );
	echo '<div class="sn-field"><label><input type="checkbox" name="sn_' . esc_attr( $door ) . '_door_enabled" value="1"' . checked( ! $locked && snt_mcp_door_switch_on( $door ), true, false ) . disabled( $locked, true, false ) . '> ' . esc_html( $t[ $door . '_label' ] ) . '</label>';
	echo '<p class="sn-field-helper">' . esc_html( $locked ? snt_switches_locked_text( $door ) : $t[ $door . '_hint' ] ) . '</p></div>';
}

/**
 * Settings > AI: one door's switch, its own form, for that door's box.
 *
 * @param string $door `read` or `rw`.
 * @return void
 */
function sn_admin_render_mcp_door_switch( $door ) {
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '">';
	wp_nonce_field( 'sn_mcp_doors_save' );
	echo '<input type="hidden" name="action" value="sn_mcp_doors_save"><input type="hidden" name="sn_door" value="' . esc_attr( $door ) . '">';
	snt_switches_render_door( $door );
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button">' . esc_html__( 'Save', 'signal-and-noise-tools' ) . '</button></div></form>';
}

/**
 * Settings > Front end, the Reading box: the ranking select, a field of the
 * save_theme form.
 *
 * @return void
 */
function sn_admin_render_related_source_field() {
	$t   = snt_switches_text();
	$cur = (string) sn_setting( 'ml.related_source', 'embeddings' );
	echo '<div class="sn-field"><label class="sn-field-label" for="sn_related_source">' . esc_html( $t['related_lbl'] ) . '</label><select id="sn_related_source" name="sn_related_source">';
	foreach ( array( 'embeddings' => $t['emb'], 'tfidf' => $t['tfidf'] ) as $v => $label ) {
		echo '<option value="' . esc_attr( $v ) . '"' . selected( $cur, $v, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select><p class="sn-field-helper">' . esc_html( $t['related_hint'] ) . '</p></div>';
}

/**
 * Settings > Notifications and reports: the write-door email box.
 *
 * @return void
 */
function sn_admin_render_mcp_rw_notify() {
	$t = snt_switches_text();
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-fieldset">';
	wp_nonce_field( 'sn_mcp_rw_notify_save' );
	echo '<input type="hidden" name="action" value="sn_mcp_rw_notify_save"><h2 class="sn-fieldset-h">' . esc_html( $t['notify_h'] ) . '</h2>';
	echo '<label><input type="checkbox" name="sn_rw_notify" value="1"' . checked( (bool) get_option( 'sn_mcp_rw_notify', false ), true, false ) . '> ' . esc_html( $t['notify_label'] ) . '</label>';
	echo '<p class="sn-field-helper">' . esc_html( $t['notify_hint'] ) . '</p>';
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'signal-and-noise-tools' ) . '</button></div></form>';
}
