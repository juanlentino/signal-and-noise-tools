<?php
/**
 * The four stored choices that had no control until 2026-11: the read and
 * write doors' switches (sn_mcp_read_enabled, sn_mcp_rw_enabled), the
 * write-door email (sn_mcp_rw_notify) and the related-notes source
 * (ml.related_source).
 *
 * The door switches are absent-means-ON (inc/mcp/mcp-read-guard.php,
 * inc/mcp/mcp-rw-guard.php), so they are stored as 1 or 0, never as a
 * boolean: on an untouched site get_option() reads false, and
 * update_option( $name, false ) would return early and store nothing,
 * leaving the door open behind a "saved" notice. A door the wp-config
 * constant locks (SN_MCP_READ_DISABLED, SN_MCP_RW_DISABLED) is left alone:
 * its checkbox is disabled, posts nothing, and must not write "off" or "on".
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The allowed related-notes sources: semantic first, then lexical. */
const SNT_RELATED_SOURCES = array( 'embeddings', 'tfidf' );

/**
 * Is this door's switch locked by its wp-config constant?
 *
 * @param string $door `read` or `rw`.
 * @return bool
 */
function snt_mcp_door_locked( $door ) {
	$const = 'read' === $door ? 'SN_MCP_READ_DISABLED' : 'SN_MCP_RW_DISABLED';
	return defined( $const ) && constant( $const );
}

/**
 * Is this door's switch on? The guards' own reading: absent means on.
 *
 * @param string $door `read` or `rw`.
 * @return bool
 */
function snt_mcp_door_switch_on( $door ) {
	return (bool) get_option( 'read' === $door ? 'sn_mcp_read_enabled' : 'sn_mcp_rw_enabled', true );
}

/**
 * Settings > AI, the Read door and Write door boxes: each door's switch is its
 * own form, posting `sn_door` (which door) and the checkbox. Only that door is
 * written, so saving one never touches the other.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_mcp_doors_save( $post ) {
	$options = array( 'read' => 'sn_mcp_read_enabled', 'rw' => 'sn_mcp_rw_enabled' );
	$door    = (string) ( $post['sn_door'] ?? '' );
	if ( ! isset( $options[ $door ] ) ) {
		return 'mcp_doors_unknown';
	}
	if ( snt_mcp_door_locked( $door ) ) {
		return 'mcp_doors_locked';
	}
	update_option( $options[ $door ], empty( $post[ 'sn_' . $door . '_door_enabled' ] ) ? 0 : 1, false );
	return 'mcp_doors_saved';
}

/**
 * Settings > Notifications and reports: the write-door email. Default off.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_mcp_rw_notify_save( $post ) {
	update_option( 'sn_mcp_rw_notify', empty( $post['sn_rw_notify'] ) ? 0 : 1, false );
	return 'mcp_rw_notify_saved';
}

/**
 * Settings > Front end, the Reading box: which ranking builds the related
 * lists. Saved by sn_handle_save_theme() with the box's other fields.
 * Allowlisted; an off-list or missing value keeps what is stored. A change
 * queues the coalesced rebuild, so the lists follow it.
 *
 * @param mixed $source The posted value.
 * @return void
 */
function snt_related_source_apply( $source ) {
	$source = (string) $source;
	if ( ! in_array( $source, SNT_RELATED_SOURCES, true ) ) {
		return;
	}
	$before = (string) sn_setting( 'ml.related_source', 'embeddings' );
	sn_setting_update( 'ml.related_source', $source );
	if ( $before !== $source && function_exists( 'snt_ml_schedule_rebuild' ) ) {
		snt_ml_schedule_rebuild();
	}
}
