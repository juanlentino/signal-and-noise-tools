<?php
/**
 * Tests for snt_analytics_render_pageroles_table() in inc/analytics-admin-render.php.
 * Behavioral: drives the render over fixtures and asserts on captured HTML.
 * Run: php tests/analytics-pageroles-render.php
 * @since plugin v6.10.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = '' ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = '' ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = '' ) { return $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
$_SERVER['REQUEST_URI'] = '/wp-admin/index.php?page=sn-analytics&sn_view=content';
function add_query_arg( $args, $url = null ) {
	if ( null === $url ) { $url = $_SERVER['REQUEST_URI']; }
	$sep = ( strpos( (string) $url, '?' ) !== false ) ? '&' : '?';
	return $url . $sep . http_build_query( $args );
}
function remove_query_arg( $keys, $url = null ) {
	if ( null === $url ) { $url = $_SERVER['REQUEST_URI']; }
	$parts = explode( '?', (string) $url, 2 );
	if ( ! isset( $parts[1] ) ) { return $url; }
	parse_str( $parts[1], $q );
	foreach ( (array) $keys as $k ) { unset( $q[ $k ] ); }
	return $q ? $parts[0] . '?' . http_build_query( $q ) : $parts[0];
}

require_once __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function capture( $cb ) { ob_start(); $cb(); return (string) ob_get_clean(); }

echo "Analytics page-roles render partial\n\n";

$rows = array(
	array( 'path' => '/',       'views' => 120, 'visits' => 90 ),
	array( 'path' => '/about',  'views' => 44,  'visits' => 30 ),
);

echo "Group: entry panel\n";
$html = capture( function () use ( $rows ) { snt_analytics_render_pageroles_table( $rows, 'entry' ); } );
ok( strpos( $html, 'Entry pages' ) !== false, 'entry: panel heading "Entry pages"' );
ok( strpos( $html, 'arrivals from search' ) !== false, 'entry: caption mentions arrivals from search/links/direct' );
ok( strpos( $html, '/about' ) !== false && strpos( $html, '44' ) !== false, 'entry: row path + views' );
ok( strpos( $html, '>90<' ) !== false, 'entry: visits column' );
ok( strpos( $html, 'class="wp-list-table widefat striped"' ) !== false, 'entry: WP-native widefat table' );
ok( strpos( $html, 'sn-an-table-inside' ) !== false, 'entry: reuses .sn-an-table-inside' );
$html = capture( function () { snt_analytics_render_pageroles_table( array(), 'entry' ); } );
ok( strpos( $html, 'No entry pages' ) !== false, 'entry: empty state copy' );

echo "\nGroup: exit panel\n";
$html = capture( function () use ( $rows ) { snt_analytics_render_pageroles_table( $rows, 'exit' ); } );
ok( strpos( $html, 'Exit pages' ) !== false, 'exit: panel heading "Exit pages"' );
ok( strpos( $html, 'session model' ) !== false, 'exit: caption notes live exit awaits the session model' );
$html = capture( function () { snt_analytics_render_pageroles_table( array(), 'exit' ); } );
ok( strpos( $html, 'No exit pages' ) !== false, 'exit: empty state copy' );

echo "\nGroup: escaping\n";
$html = capture( function () { snt_analytics_render_pageroles_table( array( array( 'path' => '/x"<script>', 'views' => 1, 'visits' => 1 ) ), 'entry' ); } );
ok( strpos( $html, '<script>' ) === false, 'render: path is escaped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
