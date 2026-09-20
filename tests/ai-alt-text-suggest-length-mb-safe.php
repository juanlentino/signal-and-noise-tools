<?php
/**
 * Regression test (#1227): snt_ai_alt_apply_impl()'s length cap must be
 * character count, not byte count — a 240-char accented alt text was
 * rejected as ">250" because strlen() counted its ~450 UTF-8 bytes.
 *
 * Second group (#1621): a landed write is recorded into OpenStation's
 * realtime layer, so the Media Library window learns of it; a refused one
 * is not. Same harness, the only standalone one that runs the real impl.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $data;
		public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->data = $d; } }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function add_action() { return true; }
function current_user_can( $cap, $id = 0 ) { return true; }
$GLOBALS['__attachment'] = (object) array( 'ID' => 5, 'post_type' => 'attachment' );
function get_post( $id ) { return $GLOBALS['__attachment']; }
function update_post_meta( $id, $key, $val ) { return true; }
class wpdb_stub { public $last_error = ''; }
$GLOBALS['wpdb'] = new wpdb_stub();
function snt_ai_require_text_generation() { return null; }
$GLOBALS['__recorded'] = array();
function openstation_content_changes_record( $type, $id, $action ) { $GLOBALS['__recorded'][] = array( $type, (int) $id, $action ); return true; }

require __DIR__ . '/../inc/ai-alt-text-suggest.php';

echo "Group: #1227 — alt-text apply length cap is mb_-safe\n";

// 240 accented characters (SNT_AI_ALT_APPLY_MAX_LENGTH is 250) — a
// byte-based strlen() would see ~480 bytes and wrongly reject this.
$accented_240 = str_repeat( 'É', 240 );
$res          = snt_ai_alt_apply_impl( 5, $accented_240 );
ok( is_array( $res ), '240 accented CHARACTERS (well under the 250 cap) is accepted, not rejected on byte length' );

// 251 characters must still be rejected.
$accented_251 = str_repeat( 'É', 251 );
$res2         = snt_ai_alt_apply_impl( 5, $accented_251 );
ok( is_wp_error( $res2 ) && 'snt_ai_alt_too_long' === $res2->code, '251 CHARACTERS is still correctly rejected as too long' );

echo "\nGroup: #1621, a landed alt-text write is recorded for the station's windows\n";
// The realtime layer has no post-meta publisher, so before this the Media
// Library window never learned of an alt-text apply, not even on a heartbeat.
ok( array( array( 'attachment', 5, 'updated' ) ) === $GLOBALS['__recorded'], 'the accepted write called openstation_content_changes_record( attachment, 5, updated ) once; the refused one added nothing' );
$GLOBALS['__recorded'] = array();
$res3 = snt_ai_alt_apply_impl( 5, '' );
ok( is_wp_error( $res3 ) && array() === $GLOBALS['__recorded'], 'an empty alt text is refused and records nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
