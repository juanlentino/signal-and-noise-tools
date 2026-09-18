<?php
/**
 * 16.1.2: the /notes/tags/ glossary gets a canonical and a description.
 * The route is a theme 404 the theme clears, so every branch of
 * sn_seo_meta_for_current_view() missed it (Bing, 2026-09-18).
 */
define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['__tags'] = true;
function sn_notes_is_tags_request() { return $GLOBALS['__tags']; }
function is_front_page() { return false; }
function is_page( $s = '' ) { return false; }
function is_home() { return false; }
function is_tag() { return false; }
function is_singular( $t = null ) { return false; }
function is_404() { return true; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function sn_setting( $k, $d = '' ) { return $d; }
function is_wp_error( $x ) { return false; }
function wp_count_terms( $a ) { return 23; }
function add_action() {} function add_filter() {} function apply_filters( $t, $v ) { return $v; }
function wp_strip_all_tags( $s ) { return $s; }
function get_bloginfo( $k ) { return 'X'; }
require __DIR__ . '/../inc/seo.php';

list( $title, $desc, $url ) = sn_seo_meta_for_current_view();
ok( '' === $title, 'the theme owns the <title>; the view supplies none' );
ok( 'https://x.test/notes/tags/' === $url, 'the canonical is /notes/tags/' );
ok( 'The 23 tags on the notes, each with what it gathers and how many notes carry it.' === $desc, 'the description carries the live tag count' );
ok( 'Every tag on the notes, with what it gathers and how many notes carry it.' === sn_seo_tags_glossary_description( 0 ), 'no countable tags: a sentence without a number, never "The 0 tags"' );
$GLOBALS['__tags'] = false;
list( $title, $desc, $url ) = sn_seo_meta_for_current_view();
ok( '' === $url && '' === $desc, 'off the route, nothing changes' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
