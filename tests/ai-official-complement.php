<?php
/**
 * Tests for inc/ai-official-complement.php: the bulk summary action is gone
 * and a note advertises one Markdown copy.
 *
 * @package SignalNoiseTools
 */
namespace WordPress\AI\Experiments\Summarization { class Summarization { public function handle_bulk_action( $r ) { return $r; } public function other() {} } }
namespace WordPress\AI\Experiments\Markdown_Feeds { class Markdown_Feeds { public function add_discovery_links() { echo 'OFFICIAL'; } } }
namespace {
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$GLOBALS['wp_filter'] = array(); $GLOBALS['singular_post'] = true;
function add_filter( $h, $cb, $p = 10, $a = 1 ) { if ( ! isset( $GLOBALS['wp_filter'][ $h ] ) ) { $GLOBALS['wp_filter'][ $h ] = (object) array( 'callbacks' => array() ); } $GLOBALS['wp_filter'][ $h ]->callbacks[ $p ][] = array( 'function' => $cb ); return true; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { return add_filter( $h, $cb, $p, $a ); }
function remove_filter( $h, $cb, $p = 10 ) { foreach ( $GLOBALS['wp_filter'][ $h ]->callbacks[ $p ] ?? array() as $i => $c ) { if ( $c['function'] === $cb ) { unset( $GLOBALS['wp_filter'][ $h ]->callbacks[ $p ][ $i ] ); return true; } } return false; }
function is_singular( $t = '' ) { return $GLOBALS['singular_post']; }
function hooked( $h ) { $n = array(); foreach ( $GLOBALS['wp_filter'][ $h ]->callbacks ?? array() as $p => $cbs ) { foreach ( $cbs as $c ) { $n[] = $p . ':' . ( is_array( $c['function'] ) ? $c['function'][1] : ( is_string( $c['function'] ) ? $c['function'] : 'closure' ) ); } } return $n; }
require __DIR__ . '/../inc/ai-official-complement.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( array( 'trash' => 'Trash' ) === snt_ai_no_bulk_summary( array( 'trash' => 'Trash', 'wpai_generate_summary' => 'Generate Summary' ) ), 'the bulk menu loses Generate Summary and nothing else' );

$sum = new \WordPress\AI\Experiments\Summarization\Summarization();
add_filter( 'handle_bulk_actions-edit-post', array( $sum, 'handle_bulk_action' ), 10, 3 );
add_filter( 'handle_bulk_actions-edit-post', array( $sum, 'other' ), 10 );
add_filter( 'handle_bulk_actions-edit-post', 'someone_else', 10 );
ok( 10 === snt_ai_unhook_official( 'handle_bulk_actions-edit-post', 'WordPress\\AI\\Experiments\\Summarization\\Summarization', 'handle_bulk_action' ) && array( '10:other', '10:someone_else' ) === hooked( 'handle_bulk_actions-edit-post' ), 'the bulk handler is unhooked by class and method; its other methods and other plugins stay' );
ok( null === snt_ai_unhook_official( 'handle_bulk_actions-edit-post', 'WordPress\\AI\\Experiments\\Summarization\\Summarization', 'handle_bulk_action' ) && null === snt_ai_unhook_official( 'never_hooked', 'X', 'y' ), 'not found is null, with or without the hook' );

$md = new \WordPress\AI\Experiments\Markdown_Feeds\Markdown_Feeds();
$GLOBALS['wp_filter']['wp_head']->callbacks[10] = array( array( 'function' => array( $md, 'add_discovery_links' ) ) );
snt_ai_notes_one_markdown_alternate();
ok( in_array( '10:snt_ai_markdown_feed_link', hooked( 'wp_head' ), true ) && ! in_array( '10:add_discovery_links', hooked( 'wp_head' ), true ), 'on a note: the official tags are unhooked and the feed link alone takes their place, same priority' );

$GLOBALS['wp_filter']['wp_head']->callbacks[10] = array( array( 'function' => array( $md, 'add_discovery_links' ) ) );
$GLOBALS['singular_post'] = false;
snt_ai_notes_one_markdown_alternate();
ok( array( '0:snt_ai_notes_one_markdown_alternate', '10:add_discovery_links' ) === hooked( 'wp_head' ), 'on a page or an archive nothing is touched' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
}
