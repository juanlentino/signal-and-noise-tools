<?php
/**
 * Regression test (#1227): snt_ai_excerpt_impl()'s 'length' field must be
 * character count, not byte count — strlen() over-reports a multibyte
 * excerpt (a 75-word accented excerpt read as hundreds of "characters").
 *
 * Same minimal-stub shape as tests/ai-meta-desc-contentless.php: requires
 * ONLY inc/ai-excerpt.php, with the AI collaborators stubbed.
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
function snt_word_count( $s ) { return str_word_count( (string) $s ); }

$GLOBALS['__canned'] = 'A crisp excerpt.';
function snt_ai_require_text_generation() { return null; }
function snt_ai_post_signal( $post_id, $words = 1000 ) { return 'TITLE: About'; }
function snt_ai_generate_with_constraints( $content, $sys, $max, $ctx = '' ) { return $GLOBALS['__canned']; }

require __DIR__ . '/../inc/ai-excerpt.php';

echo "Group: #1227 — excerpt length is mb_-safe\n";
$GLOBALS['__canned'] = str_repeat( 'É', 100 ); // 100 chars, 200 bytes.
$res = snt_ai_excerpt_impl( 9 );
ok( is_array( $res ), 'returns an array' );
ok( 100 === ( $res['length'] ?? null ), 'length is 100 (CHARACTERS), not 200 (bytes) — #1227' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
