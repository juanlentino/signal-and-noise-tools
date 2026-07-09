<?php
/**
 * Behavioral test: snt_ai_meta_desc_impl() no longer returns a 422 for a
 * contentless Page once it draws on snt_ai_post_signal(). (plugin v9.3.0)
 *
 * Requires ONLY inc/ai-meta-description.php (not the bootstrap), so the AI
 * collaborators — including snt_ai_post_signal — are safely stubbed here.
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

// AI collaborators: gate open, non-empty signal (a contentless Page), canned text.
function snt_ai_require_text_generation() { return null; }
function snt_ai_post_signal( $post_id, $words = 1000 ) { return 'TITLE: About'; }
function snt_ai_generate_with_constraints( $content, $sys, $max ) { return 'A crisp description.'; }

require __DIR__ . '/../inc/ai-meta-description.php';

echo "Group: contentless Page meta description\n";
$res = snt_ai_meta_desc_impl( 9, true );
ok( is_array( $res ), 'returns an array, not a WP_Error, when body is empty but a signal exists' );
ok( isset( $res['description'] ) && '' !== $res['description'], 'produces a non-empty description' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
