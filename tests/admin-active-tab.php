<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }

// The two collaborators the resolver calls. Modelled on their REAL shapes:
// valid_tabs returns a flat list of slugs; tab_for_slug maps a ?page= slug.
function sn_admin_page_valid_tabs() { return array( 'dashboard', 'site', 'content', 'connections', 'measurement', 'ai', 'security', 'integrity' ); }
function sn_admin_page_tab_for_slug( $slug ) {
	$map = array( 'sn-theme-options' => 'dashboard', 'sn-content' => 'content', 'sn-security' => 'security' );
	return $map[ $slug ] ?? '';
}

require __DIR__ . '/../inc/admin-page.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "admin — active tab resolution\n\n";

// 1. Explicit ?tab= wins.
$_GET = array( 'tab' => 'security', 'page' => 'sn-theme-options' );
ok( 'security' === sn_admin_page_active_tab(), 'an explicit ?tab= wins over the page slug' );

// 2. THE CASE THAT MATTERS: no ?tab= at all. The Dashboard is normally
//    reached this way, so a naive $_GET['tab'] check would miss it entirely.
$_GET = array( 'page' => 'sn-theme-options' );
ok( 'dashboard' === sn_admin_page_active_tab(), 'NO ?tab= STILL RESOLVES TO DASHBOARD via the page slug' );

// 3. A slug that maps elsewhere.
$_GET = array( 'page' => 'sn-security' );
ok( 'security' === sn_admin_page_active_tab(), 'another slug resolves to its own tab' );

// 4. Nothing at all.
$_GET = array();
ok( 'dashboard' === sn_admin_page_active_tab(), 'no page and no tab defaults to dashboard' );

// 5. An unknown tab falls back rather than passing through.
$_GET = array( 'tab' => 'bogus' );
ok( 'dashboard' === sn_admin_page_active_tab(), 'an unknown tab falls back to dashboard, never passes through' );

// 6. Injection attempt — the value is sanitised and then allowlisted.
$_GET = array( 'tab' => '<script>alert(1)</script>' );
ok( 'dashboard' === sn_admin_page_active_tab(), 'a hostile tab value cannot escape the allowlist' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
