<?php
/**
 * Regression test (#1227): snt_ai_meta_desc_impl()'s 'length' field must be
 * character count, not byte count.
 *
 * Same minimal-stub shape as tests/ai-meta-desc-contentless.php.
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
function get_post_meta( $id, $key, $single = false ) { return ''; }
function get_post( $id ) { return null; }

$GLOBALS['__canned'] = 'A crisp description.';
function snt_ai_require_text_generation() { return null; }
function snt_ai_post_signal( $post_id, $words = 1000 ) { return 'TITLE: About'; }
function snt_ai_generate_with_constraints( $content, $sys, $max, $ctx = '' ) { return $GLOBALS['__canned']; }

require __DIR__ . '/../inc/ai-meta-description.php';

echo "Group: #1227 — meta description length is mb_-safe\n";
$GLOBALS['__canned'] = str_repeat( 'É', 100 ); // 100 chars, 200 bytes.
$res = snt_ai_meta_desc_impl( 9 );
ok( is_array( $res ), 'returns an array' );
ok( 100 === ( $res['length'] ?? null ), 'length is 100 (CHARACTERS), not 200 (bytes) — #1227' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
