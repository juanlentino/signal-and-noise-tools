<?php
/**
 * Standalone test: the document title keeps core's page number (2026-11-10).
 *
 * The document_title_parts callback returned a one-key array, so the "Page N"
 * part core builds for a paged view or a <!--nextpage--> page never reached
 * the <title>: every page of a paginated post carried the same title. It now
 * keeps that part. Every unpaged title is the same string as before.
 *
 * The title is joined the way wp_get_document_title() joins it:
 * implode( " $sep ", array_filter( $parts ) ), $sep from document_title_separator.
 *
 * Run: php tests/seo-title-page-part.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$GLOBALS['__f']    = array();
$GLOBALS['__view'] = 'page'; // page | note | search | 404
$GLOBALS['__seo']  = '';     // the written search title (_sn_seo_title).
function add_action() {}
function add_filter( $tag, $cb, $prio = 10 ) { $GLOBALS['__f'][ $tag ][] = $cb; }
function apply_filters( $tag, $value ) { return $value; }
function run( $tag, $value ) {
	foreach ( $GLOBALS['__f'][ $tag ] ?? array() as $cb ) {
		$value = $cb( $value );
	}
	return $value;
}
function is_front_page() { return false; }
function is_home() { return false; }
function is_tag() { return false; }
function is_page( $slug = '' ) { return false; }
function is_404() { return '404' === $GLOBALS['__view']; }
function is_singular() { return in_array( $GLOBALS['__view'], array( 'page', 'note' ), true ); }
function get_queried_object() { return (object) array( 'ID' => 5, 'post_type' => 'note' === $GLOBALS['__view'] ? 'post' : 'page', 'post_excerpt' => 'An excerpt.' ); }
function sn_post_settings_get_seo_title( $id ) { return $GLOBALS['__seo']; }
function get_the_title( $post ) { return 'Real Title'; }
function get_permalink( $post ) { return 'https://example.test/x/'; }
function wp_strip_all_tags( $s ) { return $s; }
function get_bloginfo( $k ) { return 'Juan Lentino'; }
function sn_setting( $k, $d = '' ) { return 'identity.site_name' === $k ? 'Juan Lentino' : $d; }

require __DIR__ . '/../inc/seo.php';

/** The <title> text for a view, joined as core joins it. $page is core's own "Page N" part. */
function title_for( $view, $page = '', $seo = '' ) {
	$GLOBALS['__view'] = $view;
	$GLOBALS['__seo']  = $seo;
	$core              = array(
		'title' => 'search' === $view ? 'Search Results' : ( '404' === $view ? 'Page not found' : 'Real Title' ),
		'page'  => $page,
		'site'  => 'Juan Lentino',
	);
	$sep   = run( 'document_title_separator', '-' );
	$parts = run( 'document_title_parts', $core );
	return implode( " $sep ", array_filter( $parts ) );
}

echo "Unpaged titles are the strings they were\n";
ok( 'Real Title — Juan Lentino' === title_for( 'page' ), 'a page: "Page — Site"' );
ok( 'A written search title' === title_for( 'page', '', 'A written search title' ), 'a page with a written title: that title alone' );
ok( 'Real Title' === title_for( 'note' ), 'a note: its title alone' );
ok( 'Page not found — Juan Lentino' === title_for( '404' ), 'the 404: "Page not found — Site"' );
ok( 'Search Results - Juan Lentino' === title_for( 'search' ), "a view the plugin titles nothing on: core's parts and core's separator, untouched" );

echo "Paged views gain core's page part\n";
ok( 'Real Title — Juan Lentino — Page 2' === title_for( 'page', 'Page 2' ), 'page 2 of a paginated page says so' );
ok( 'Real Title — Page 3' === title_for( 'note', 'Page 3' ), 'page 3 of a paginated note says so' );
ok( 'A written search title — Page 2' === title_for( 'page', 'Page 2', 'A written search title' ), 'a written title keeps the page part too' );
ok( 'Search Results - Page 2 - Juan Lentino' === title_for( 'search', 'Page 2' ), "a view the plugin titles nothing on stays core's, page part and all" );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
