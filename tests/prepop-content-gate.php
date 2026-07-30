<?php
/**
 * Tests for snt_prepop_passes_content_gate() — the extracted word-count gate
 * that now also admits contentless template Pages. (plugin v9.3.0)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

// ai-prepopulate.php registers hooks at load — stub add_action so the require
// is inert. The predicate itself needs wp_strip_all_tags + snt_ai_post_signal.
function add_action() { return true; }
function wp_strip_all_tags( $s ) { return $s; }
$GLOBALS['__signal'] = 'TITLE: About'; // snt_ai_post_signal yield for empty body
function snt_ai_post_signal( $post_id, $words = 1000 ) { return $GLOBALS['__signal']; }


// v10.24.0: snt_word_count() is a real runtime dependency (pure module).
require_once __DIR__ . '/../inc/word-count.php';

require __DIR__ . '/../inc/ai-prepopulate.php';

$min = 50;

echo "Group: normal content\n";
$post = (object) array( 'ID' => 1, 'post_content' => str_repeat( 'word ', 60 ) );
ok( true === snt_prepop_passes_content_gate( $post, $min ), 'long body passes' );

echo "\nGroup: thin non-empty draft\n";
$post = (object) array( 'ID' => 2, 'post_content' => 'only three words here' );
ok( false === snt_prepop_passes_content_gate( $post, $min ), 'thin non-empty draft is still rejected' );

echo "\nGroup: contentless template Page\n";
$GLOBALS['__signal'] = 'TITLE: About';
$post = (object) array( 'ID' => 3, 'post_content' => '' );
ok( true === snt_prepop_passes_content_gate( $post, $min ), 'empty body + non-empty signal passes' );

echo "\nGroup: empty body, no signal (truly nothing)\n";
$GLOBALS['__signal'] = '';
$post = (object) array( 'ID' => 4, 'post_content' => '   ' );
ok( false === snt_prepop_passes_content_gate( $post, $min ), 'empty body + empty signal is rejected' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
