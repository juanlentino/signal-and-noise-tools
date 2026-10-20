<?php
/**
 * Standalone test: the X-SN-CSP: lax marker (2026-10-20).
 *
 * Cloudflare enforces the strict CSP on every response without the marker, so
 * a missed page breaks and an extra mark only weakens that page. Pins: who is
 * marked (signed-in users, the login screens, wp-admin, form pages, /contact/),
 * who never is (a stranger on any ordinary public page), and the hooks.
 *
 * Run: php tests/csp-lax-marker.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_CSP_LAX_TEST', true );

$GLOBALS['__req'] = array();
function sn_req( $k ) { return $GLOBALS['__req'][ $k ] ?? null; }
function is_user_logged_in() { return (bool) sn_req( 'user' ); }
function is_singular() { return null !== sn_req( 'post' ); }
function get_queried_object() { return sn_req( 'post' ); }
function is_page( $slug = '' ) { return sn_req( 'page' ) === $slug; }
function has_block( $name, $content ) { return false !== strpos( (string) $content, '<!-- wp:' . $name . ' ' ) || false !== strpos( (string) $content, '<!-- wp:' . $name . ' /-->' ); }
function has_shortcode( $content, $tag ) { return false !== strpos( (string) $content, '[' . $tag ); }
function wp_doing_ajax() { return (bool) sn_req( 'ajax' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function post( $content ) { return (object) array( 'post_content' => $content ); }

$root = dirname( __DIR__ );
$file = "$root/inc/csp-lax-marker.php";
if ( ! is_file( $file ) ) {
	ok( false, 'inc/csp-lax-marker.php exists' );
	echo "\nResult: $pass passed, $fail failed.\n";
	exit( 1 );
}
require $file;
// Before any output: in the CLI, headers count as sent once anything is echoed.
$marks = array( sn_csp_mark_lax(), sn_csp_mark_lax() );

echo "Group 1: a stranger on an ordinary public page is never marked\n";
$public = array(
	'home'    => array(),
	'a note'  => array( 'post' => post( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ) ),
	'/resume' => array( 'post' => post( '<!-- wp:signal-noise/resume /-->' ), 'page' => 'resume' ),
	'/cv'     => array( 'post' => post( '' ), 'page' => 'cv' ),
	'/verify' => array( 'post' => post( '' ), 'page' => 'verify' ),
	'/stats'  => array( 'post' => post( '' ), 'page' => 'stats' ),
	'/notes'  => array(),
	'a tag'   => array(),
	'a 404'   => array(),
	'a note that mentions the form in prose' => array( 'post' => post( '<p>allterrain-forms/form and allterrain_form are words here</p>' ) ),
);
foreach ( $public as $label => $req ) {
	$GLOBALS['__req'] = $req;
	ok( '' === sn_csp_lax_reason(), "$label is not marked" );
}

echo "\nGroup 2: the pages that print per-request inline scripts are marked\n";
$GLOBALS['__req'] = array( 'user' => true );
ok( 'logged-in' === sn_csp_lax_reason(), 'any front-end request from a signed-in user (the admin bar)' );
$GLOBALS['__req'] = array( 'user' => true, 'post' => post( '' ), 'page' => 'verify' );
ok( 'logged-in' === sn_csp_lax_reason(), 'even /verify, for a signed-in user' );
$GLOBALS['__req'] = array( 'post' => post( '<!-- wp:allterrain-forms/form {"formId":2717} /-->' ) );
ok( 'form' === sn_csp_lax_reason(), 'a page rendering the AllTerrain Forms block' );
$GLOBALS['__req'] = array( 'post' => post( '[allterrain_form id="2717"]' ) );
ok( 'form' === sn_csp_lax_reason(), 'a page rendering the AllTerrain Forms shortcode' );
$GLOBALS['__req'] = array( 'post' => post( '' ), 'page' => 'contact' );
ok( 'contact' === sn_csp_lax_reason(), '/contact/ by slug, even if its form came from a template' );

echo "\nGroup 3: the marker is sent once\n";
ok( array( true, true ) === $marks, 'marking twice is one mark, and a second call is a no-op' );
ok( 'X-SN-CSP: lax' === SN_CSP_LAX_HEADER, 'the header is exactly what the Cloudflare rule matches (x-sn-csp eq lax)' );

echo "\nGroup 4: the hooks\n";
$src = (string) file_get_contents( $file );
ok( false !== strpos( $src, "add_action( 'template_redirect', 'sn_csp_lax_front', 1 )" ), 'front end decides at template_redirect, after the query (send_headers runs before it)' );
ok( false !== strpos( $src, "add_action( 'login_init', 'sn_csp_mark_lax', 1 )" ), 'every login screen, the hidden door included, is marked at login_init' );
ok( false !== strpos( $src, "add_action( 'admin_init', 'sn_csp_lax_admin', 1 )" ), 'wp-admin is marked' );
ok( false === strpos( $src, "'send_headers'" ) && false === strpos( $src, 'rest_' ), 'REST is not marked' );
$main = (string) file_get_contents( "$root/signal-and-noise-tools.php" );
ok( false !== strpos( $main, "inc/csp-lax-marker.php'" ), 'the module is loaded' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
