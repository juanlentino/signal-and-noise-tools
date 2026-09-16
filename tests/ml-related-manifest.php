<?php
/**
 * inc/ml-related-manifest.php: the related-notes manifest (15.5.0).
 *
 * Three answers kept distinct: never built, nothing related, matches; the
 * rows carry title, url, score and shared tags; the manifest is emitted on a
 * singular note only, as a data-shaped script that cannot close its own tag.
 *
 * Run: php tests/ml-related-manifest.php
 *
 * @since 15.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$GLOBALS['__related'] = null; $GLOBALS['__singular'] = 'post'; $GLOBALS['__id'] = 7; $GLOBALS['__actions'] = array();
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = $cb; }
function snt_ml_related_for_post( $id, $limit ) { $GLOBALS['__limit'] = $limit; return $GLOBALS['__related']; }
function wp_get_post_tags( $id, $a = array() ) { return array( 7 => array( 'provenance', 'c2pa' ), 8 => array( 'provenance' ), 9 => array( 'music' ) )[ $id ] ?? array(); }
function get_the_title( $id ) { return array( 8 => 'Falsifiability &amp; the line</script>', 9 => 'Detection scales' )[ $id ] ?? ''; }
function get_permalink( $id ) { return "https://x.test/notes/$id/"; }
function is_singular( $t = '' ) { return $GLOBALS['__singular'] === $t; }
function get_the_ID() { return $GLOBALS['__id']; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
require_once __DIR__ . '/../inc/ml-related-manifest.php';

$m = sn_related_manifest( 7 );
ok( false === $m['built'] && array() === $m['related'] && 7 === $m['post_id'], 'never built: built false, no rows (null from the kernel)' );
$GLOBALS['__related'] = array();
$m = sn_related_manifest( 7 );
ok( true === $m['built'] && array() === $m['related'], 'nothing related: built true, empty rows (an answer, not an absence)' );
$GLOBALS['__related'] = array( array( 'post_id' => 8, 'score' => 0.81234567 ), array( 'post_id' => 9, 'score' => 0.2 ), array( 'post_id' => 0, 'score' => 1 ) );
$m = sn_related_manifest( 7 );
ok( 2 === count( $m['related'] ) && 8 === $m['related'][0]['id'] && 0.8123 === $m['related'][0]['score'], 'rows in the kernel\'s order, scores rounded, a zero id skipped' );
ok( 'Falsifiability & the line</script>' === $m['related'][0]['title'] && 'https://x.test/notes/8/' === $m['related'][0]['url'], 'titles decoded, urls absolute' );
ok( array( 'provenance' ) === $m['related'][0]['shared_tags'] && array() === $m['related'][1]['shared_tags'], 'shared tags are the intersection with the note\'s own' );
ok( 5 === $GLOBALS['__limit'], 'asks the kernel for the manifest limit' );

echo "\nGroup: emission\n";
ob_start(); sn_related_manifest_emit(); $out = ob_get_clean();
ok( 0 === strpos( $out, '<script type="application/json" id="sn-related">' ) && false !== strpos( $out, '<\\/script>' ) && false === strpos( $out, '</script>"' ), 'emitted as a data-shaped script; a </script> in a title is escaped' );
ok( is_array( json_decode( substr( $out, strlen( '<script type="application/json" id="sn-related">' ), -strlen( "</script>\n" ) ), true ) ), 'the payload parses back' );
$GLOBALS['__singular'] = 'page';
ob_start(); sn_related_manifest_emit(); $out = ob_get_clean();
ok( '' === $out, 'a page carries no manifest (the bridge reads the absence as "not a note")' );
ok( in_array( 'sn_related_manifest_emit', $GLOBALS['__actions']['wp_head'] ?? array(), true ), 'hooked on wp_head' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
