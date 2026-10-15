<?php
/**
 * Tests: the bilingual storefront (inc/bilingual*.php). A Spanish page is a
 * Page whose `_sn_en_counterpart` names its English page; everything else is
 * read off that row.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; } else { $fail++; echo "  FAIL - $label\n"; }
}

// A tiny site: 383 front page, 10 about, 11 music; 20 inicio, 21 musica (Spanish).
$GLOBALS['meta']   = array( 20 => 383, 21 => 11 );
$GLOBALS['status'] = array( 383 => 'publish', 10 => 'publish', 11 => 'publish', 20 => 'publish', 21 => 'draft' );
$GLOBALS['names']  = array( 383 => 'home', 10 => 'about', 11 => 'music', 20 => 'inicio', 21 => 'musica' );
$GLOBALS['q']      = 11; // queried id
$GLOBALS['hooks']  = array();
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['hooks'][ $h ][] = $c; }
function add_filter( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['hooks'][ $h ][] = $c; }
function get_post_meta( $id, $k, $s ) { return $GLOBALS['meta'][ $id ] ?? ''; }
function get_post_type( $id ) { return isset( $GLOBALS['status'][ $id ] ) ? 'page' : false; }
function get_post_status( $id ) { return $GLOBALS['status'][ $id ] ?? false; }
function get_post_field( $f, $id ) { return 'post_name' === $f ? ( $GLOBALS['names'][ $id ] ?? '' ) : ( $GLOBALS['content'][ $id ] ?? '' ); }
function get_posts( $a ) {
	$out = array();
	foreach ( $GLOBALS['meta'] as $id => $en ) {
		if ( (int) $en === (int) $a['meta_value'] && ( 'any' === $a['post_status'] || $a['post_status'] === ( $GLOBALS['status'][ $id ] ?? '' ) ) ) { $out[] = $id; }
	}
	return $out;
}
function get_option( $k, $d = false ) { return 'page_on_front' === $k ? 383 : $d; }
function get_queried_object_id() { return $GLOBALS['q']; }
function get_queried_object() { return (object) array( 'ID' => $GLOBALS['q'], 'post_name' => $GLOBALS['names'][ $GLOBALS['q'] ] ); }
function is_singular( $t = '' ) { return true; }
function is_page( $slug = '' ) { return '' === $slug || $GLOBALS['names'][ $GLOBALS['q'] ] === $slug; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function get_permalink( $id ) { return 'https://x.test/' . $GLOBALS['names'][ $id ] . '/'; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function sn_setting( $k, $d = '' ) { return $d; }
function post_password_required( $id ) { return ! empty( $GLOBALS['pw'][ $id ] ); }
function apply_filters( $t, $v ) { return 'the_content' === $t ? '<p>' . $v . '</p>' : $v; }

require __DIR__ . '/../inc/bilingual.php';

echo "Group: pairing\n";
ok( 383 === sn_lang_en_counterpart( 20 ), '/inicio/ pairs to the front page' );
ok( 0 === sn_lang_en_counterpart( 11 ), 'an English page has no English counterpart' );
$GLOBALS['meta'][30] = 30; $GLOBALS['status'][30] = 'publish';
ok( 0 === sn_lang_en_counterpart( 30 ), 'a page naming itself is not paired' );
$GLOBALS['meta'][31] = 999;
ok( 0 === sn_lang_en_counterpart( 31 ), 'a page naming a non-page is not paired' );
ok( 20 === sn_lang_es_counterpart( 383 ), 'the front page finds its published Spanish page' );
ok( 0 === sn_lang_es_counterpart( 11 ), 'a DRAFT translation is no counterpart (/musica/ is a draft)' );

echo "\nGroup: the viewed page\n";
$GLOBALS['q'] = 11;
ok( 'en' === sn_lang_current() && array() === sn_lang_pair_urls(), '/music/ with a draft translation: English, no pair' );
$GLOBALS['q'] = 383;
ok( array( 'en' => 'https://x.test/', 'es' => 'https://x.test/inicio/' ) === sn_lang_pair_urls(), 'the front page pairs to /inicio/, the front page as the bare home URL' );
$GLOBALS['q'] = 20;
ok( 'es' === sn_lang_current(), '/inicio/ is Spanish' );
ok( array( 'en' => 'https://x.test/', 'es' => 'https://x.test/inicio/' ) === sn_lang_pair_urls(), '/inicio/ returns the same pair (reciprocal)' );

echo "\nGroup: head\n";
$h = sn_lang_hreflang_html( sn_lang_pair_urls() );
ok( 3 === substr_count( $h, 'rel="alternate"' ), 'three alternates' );
ok( false !== strpos( $h, 'hreflang="en" href="https://x.test/"' ) && false !== strpos( $h, 'hreflang="es" href="https://x.test/inicio/"' ) && false !== strpos( $h, 'hreflang="x-default" href="https://x.test/"' ), 'en, es, and x-default pointing at English' );
ok( '' === sn_lang_hreflang_html( array() ), 'no pair, no alternates' );
ok( 'lang="es" dir="ltr"' === sn_lang_language_attributes( 'lang="en-US" dir="ltr"' ), '<html lang> becomes es on a Spanish page' );
ok( 'es_419' === sn_lang_og_locale() && 'es' === sn_lang_schema_language(), 'og:locale es_419 and inLanguage es on a Spanish page' );
$GLOBALS['q'] = 383;
ok( 'lang="en-US"' === sn_lang_language_attributes( 'lang="en-US"' ), 'an English page keeps its lang' );
ok( 'en_US' === sn_lang_og_locale() && 'en-US' === sn_lang_schema_language(), 'an English page keeps en_US' );

echo "\nGroup: slug gates and template\n";
$GLOBALS['status'][21] = 'publish'; $GLOBALS['q'] = 21;
ok( 'music' === sn_lang_effective_slug( get_queried_object() ), '/musica/ answers "music" to slug checks' );
ok( true === sn_lang_is_page( 'music' ) && false === sn_lang_is_page( 'services' ), 'sn_lang_is_page( music ) is true on /musica/, services is not' );
ok( array( 'page-musica.php', 'page-music.php', 'page-21.php', 'page.php' ) === sn_lang_page_template_hierarchy( array( 'page-musica.php', 'page-21.php', 'page.php' ) ), '/musica/ takes page-music after its own slug template' );
$GLOBALS['q'] = 20;
ok( 'front-page.php' === sn_lang_page_template_hierarchy( array( 'page-inicio.php', 'page.php' ) )[1], '/inicio/ takes the front-page template' );
$GLOBALS['q'] = 11;
ok( array( 'page-music.php', 'page.php' ) === sn_lang_page_template_hierarchy( array( 'page-music.php', 'page.php' ) ), 'an English page\'s hierarchy is untouched' );

echo "\nGroup: English fallback\n";
$GLOBALS['q'] = 21; $GLOBALS['content'] = array( 11 => 'English body', 21 => '' );
ok( '<div lang="en"><p>English body</p></div>' === sn_lang_content_fallback( '', array() ), 'an empty Spanish page shows the English body, marked lang="en"' );
$GLOBALS['content'][21] = 'Cuerpo';
ok( 'X' === sn_lang_content_fallback( 'X', array() ), 'a written Spanish body renders as itself' );
$GLOBALS['content'][21] = '';
ok( 'X' === sn_lang_content_fallback( 'X', array(), (object) array( 'context' => array( 'postId' => 999 ) ) ), 'a post-content block for another post (a query loop) is left alone' );
$GLOBALS['status'][11] = 'private';
ok( 'X' === sn_lang_content_fallback( 'X', array() ), 'a private English page lends no body' );
$GLOBALS['status'][11] = 'publish'; $GLOBALS['pw'][11] = true;
ok( 'X' === sn_lang_content_fallback( 'X', array() ), 'a password-protected English page lends no body' );
$GLOBALS['pw'] = array();
$GLOBALS['q'] = 11;
ok( 'X' === sn_lang_content_fallback( 'X', array() ), 'an English page never falls back' );

echo "\nGroup: seed map\n";
ok( array( 'inicio', 'sobre-mi', 'musica', 'servicios' ) === array_keys( sn_lang_seed_map() ), 'the four Spanish slugs' );
ok( array( '', 'about', 'music', 'services' ) === array_column( sn_lang_seed_map(), 1 ), 'paired to home, about, music, services' );
$src = (string) file_get_contents( __DIR__ . '/../inc/bilingual-seed.php' );
ok( false !== strpos( $src, "'post_status'  => 'draft'" ), 'seeded as drafts: nothing goes public until the owner publishes' );
ok( false !== strpos( $src, "'slug taken'" ) && false !== strpos( $src, "'redirect exists'" ), 'a taken slug or a redirect is skipped, never overwritten' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
