<?php
/**
 * The handlers for the four stored choices that had no control until 2026-11
 * (inc/admin-post-actions/mcp-doors.php), against an option store that keeps
 * core's update_option() rule: a value identical to the stored one (or to the
 * `false` a missing row reads as) returns early and stores nothing.
 *
 * That rule is the reason the door switches store 1 and 0: on a site that
 * never touched them, update_option( 'sn_mcp_rw_enabled', false ) would store
 * nothing and the door would stay open behind a "saved" notice.
 *
 * Run: php tests/settings-switches-save.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['__options'] = array(); $GLOBALS['__settings'] = array(); $GLOBALS['__rebuilds'] = 0;
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
/** Core's shape: compare with the stored value read with no default (false when absent); identical means no write. */
function update_option( $k, $v, $autoload = null ) {
	$old = array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : false;
	if ( $v === $old || serialize( $v ) === serialize( $old ) ) { return false; }
	$GLOBALS['__options'][ $k ] = is_scalar( $v ) && ! is_bool( $v ) ? (string) $v : $v; // a stored scalar reads back as a string.
	return true;
}
function sn_setting( $k, $d = null ) { return $GLOBALS['__settings'][ $k ] ?? $d; }
function sn_setting_update( $k, $v ) { $GLOBALS['__settings'][ $k ] = $v; return true; }
function snt_ml_schedule_rebuild() { $GLOBALS['__rebuilds']++; }
require __DIR__ . '/../inc/admin-post-actions/mcp-doors.php';
require __DIR__ . '/../inc/mcp/mcp-rw-guard.php';

// ── The door switches, from an untouched site.
ok( snt_mcp_door_switch_on( 'read' ) && snt_mcp_door_switch_on( 'rw' ) && ! sn_mcp_rw_kill_switch_engaged(), 'untouched: both doors read as on (absent means on), and the write guard agrees' );
ok( 'mcp_doors_unknown' === sn_handle_mcp_doors_save( array( 'sn_read_door_enabled' => '1' ) ) && 'absent' === get_option( 'sn_mcp_read_enabled', 'absent' ), 'a save that names no door writes nothing' );
ok( 'mcp_doors_unknown' === sn_handle_mcp_doors_save( array( 'sn_door' => 'remote' ) ), 'and an off-list door is refused' );
ok( 'mcp_doors_saved' === sn_handle_mcp_doors_save( array( 'sn_door' => 'read' ) ), 'the read door box saved unchecked answers saved' );
ok( '0' === get_option( 'sn_mcp_read_enabled', 'absent' ) && 'absent' === get_option( 'sn_mcp_rw_enabled', 'absent' ), 'it stored off for the read door only (0, not false: core skips a false that equals a missing row); the write door is untouched' );
sn_handle_mcp_doors_save( array( 'sn_door' => 'rw' ) );
ok( ! snt_mcp_door_switch_on( 'read' ) && ! snt_mcp_door_switch_on( 'rw' ) && sn_mcp_rw_kill_switch_engaged(), 'the write door box saved unchecked: both off, and the write guard refuses' );
sn_handle_mcp_doors_save( array( 'sn_door' => 'rw', 'sn_rw_door_enabled' => '1' ) );
ok( ! snt_mcp_door_switch_on( 'read' ) && snt_mcp_door_switch_on( 'rw' ) && ! sn_mcp_rw_kill_switch_engaged(), 'checking the write door reopens it and leaves the read door off' );

// ── The write-door email: default off, saved both ways.
ok( false === (bool) get_option( 'sn_mcp_rw_notify', false ), 'the write-door email defaults to off' );
ok( 'mcp_rw_notify_saved' === sn_handle_mcp_rw_notify_save( array( 'sn_rw_notify' => '1' ) ) && true === (bool) get_option( 'sn_mcp_rw_notify', false ), 'checked, it turns on' );
sn_handle_mcp_rw_notify_save( array() );
ok( '0' === get_option( 'sn_mcp_rw_notify', 'absent' ) && false === (bool) get_option( 'sn_mcp_rw_notify', false ), 'unchecked, it turns off' );

// ── The related-notes ranking (saved by the Front end form): allowlisted, a change queues one rebuild.
snt_related_source_apply( 'evil' );
ok( ! isset( $GLOBALS['__settings']['ml.related_source'] ) && 0 === $GLOBALS['__rebuilds'], 'an off-list ranking is refused: nothing stored, no rebuild' );
snt_related_source_apply( 'tfidf' );
ok( 'tfidf' === $GLOBALS['__settings']['ml.related_source'] && 1 === $GLOBALS['__rebuilds'], 'tfidf is stored and the lists are rebuilt' );
snt_related_source_apply( 'tfidf' );
ok( 1 === $GLOBALS['__rebuilds'], 'saving the same ranking again queues no rebuild' );

// ── The Front end form's save carries the ranking to the same rule.
function sn_settings_snapshot() { return array(); }
function sn_settings_purge_if_changed( $b ) { return false; }
require __DIR__ . '/../inc/admin-post-actions/theme-ai.php';
sn_handle_save_theme( array( 'sn_related_source' => 'embeddings' ) );
ok( 'embeddings' === $GLOBALS['__settings']['ml.related_source'] && 2 === $GLOBALS['__rebuilds'], 'the Front end save stores the ranking and queues a rebuild' );
sn_handle_save_theme( array( 'sn_related_source' => 'evil' ) );
ok( 'embeddings' === $GLOBALS['__settings']['ml.related_source'], 'an off-list ranking in that save keeps what is stored' );

// ── Locked doors: the constant wins and the handler leaves the option alone (last: constants stay defined).
$GLOBALS['__options']['sn_mcp_rw_enabled'] = '1';
define( 'SN_MCP_RW_DISABLED', true );
ok( snt_mcp_door_locked( 'rw' ) && ! snt_mcp_door_locked( 'read' ), 'SN_MCP_RW_DISABLED locks the write door only' );
ok( 'mcp_doors_locked' === sn_handle_mcp_doors_save( array( 'sn_door' => 'rw' ) ) && '1' === $GLOBALS['__options']['sn_mcp_rw_enabled'], 'the locked write door is left as it was, and the answer says so' );
sn_handle_mcp_doors_save( array( 'sn_door' => 'read', 'sn_read_door_enabled' => '1' ) );
ok( snt_mcp_door_switch_on( 'read' ) && '1' === $GLOBALS['__options']['sn_mcp_rw_enabled'], 'the read door still saves while the write door is locked' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
