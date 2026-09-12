<?php
/**
 * Regression test (#1227): snt_ai_alt_apply_impl()'s length cap must be
 * character count, not byte count — a 240-char accented alt text was
 * rejected as ">250" because strlen() counted its ~450 UTF-8 bytes.
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
