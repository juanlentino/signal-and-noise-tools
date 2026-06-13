<?php
/**
 * Tests for snt_analytics_render_controls in inc/analytics-admin-render.php.
 * Run: php tests/analytics-controls-render.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_RANGES', array( 7, 30, 90, 365 ) );

function add_query_arg( $args, $base = '' ) { return $base . '?' . http_build_query( $args ); }
function remove_query_arg( $keys, $url = '' ) { return $url; }
function admin_url( $p = '' ) { return 'https://x/wp-admin/' . $p; }
function esc_attr( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr__( $s, $d = '' ) { return $s; }

require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

function capture_controls( $range, $class ) { ob_start(); snt_analytics_render_controls( $range, $class ); return ob_get_clean(); }

echo "\nGroup: controls render\n";
$html = capture_controls( 90, 'human' );
ok( strpos( $html, '>7d<' )  !== false, 'renders 7d' );
ok( strpos( $html, '>1y<' )  !== false, 'renders 365 as "1y"' );
ok( strpos( $html, '>All<' ) !== false, 'renders All button' );
ok( strpos( $html, 'sn_range=365' ) !== false, '365 link present' );
ok( strpos( $html, 'sn_range=all' ) !== false, 'all link present' );
$html_all = capture_controls( 'all', 'human' );
ok( substr_count( $html_all, 'is-active' ) >= 1, 'All selected marks an active control' );
ok( strpos( $html_all, 'is-active' ) !== false, 'active class emitted for All' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
