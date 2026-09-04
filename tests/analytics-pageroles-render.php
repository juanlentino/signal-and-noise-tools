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

require_once __DIR__ . '/../inc/analytics-panels.php'; // v8.5.0: renderers emit chrome via the panel primitive
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
ok( strpos( $html, 'class="widefat striped"' ) !== false, 'entry: WP-native widefat table' );
ok( strpos( $html, 'sn-an-table-inside' ) !== false, 'entry: reuses .sn-an-table-inside' );
unset( $GLOBALS['sn_an_empty_panels'] );
$html = capture( function () { snt_analytics_render_pageroles_table( array(), 'entry' ); } );
ok( '' === $html, 'entry: empty rows fold instead of rendering inline (D4 §4)' );
$noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted ) && 'Entry pages' === $noted[0]['title'] && false !== strpos( $noted[0]['why'], 'No entry pages' ), 'entry: empty state copy carried as the fold why' );

echo "\nGroup: exit panel\n";
$html = capture( function () use ( $rows ) { snt_analytics_render_pageroles_table( $rows, 'exit' ); } );
ok( strpos( $html, 'Exit pages' ) !== false, 'exit: panel heading "Exit pages"' );
// v9.66.0: exits are FED LIVE by the nightly session rollup bridge — the old
// "await the session model" caption is now false and must not render.
ok( strpos( $html, 'session model' ) === false, 'exit: stale "awaits the session model" caption is gone (exits are live since v9.66.0)' );
ok( strpos( $html, 'last page of each visit' ) !== false, 'exit: caption states the live unit (last page of each visit, nightly)' );
unset( $GLOBALS['sn_an_empty_panels'] );
$html = capture( function () { snt_analytics_render_pageroles_table( array(), 'exit' ); } );
ok( '' === $html, 'exit: empty rows fold instead of rendering inline (D4 §4)' );
$noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted ) && 'Exit pages' === $noted[0]['title'] && false !== strpos( $noted[0]['why'], 'No exit pages' ), 'exit: empty state copy carried as the fold why' );

echo "\nGroup: escaping\n";
$html = capture( function () { snt_analytics_render_pageroles_table( array( array( 'path' => '/x"<script>', 'views' => 1, 'visits' => 1 ) ), 'entry' ); } );
ok( strpos( $html, '<script>' ) === false, 'render: path is escaped' );

echo "\nGroup: v9.68.1. NULL rows (the accessor's failed-read verdict) fold with the read-failure copy\n";
unset( $GLOBALS['sn_an_empty_panels'] );
$html = capture( function () { snt_analytics_render_pageroles_table( null, 'entry' ); } );
ok( '' === $html, 'entry failed: renders no panel markup (folds like empty)' );
$noted_n = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted_n ) && 'Entry pages' === $noted_n[0]['title'], 'entry failed: registers its title in the fold' );
ok( 'Entry pages could not be read (read failure: not an empty window).' === ( $noted_n[0]['why'] ?? '' ),
	'entry failed: the fold why is the shared read-failure sentence: never the empty copy' );
unset( $GLOBALS['sn_an_empty_panels'] );
capture( function () { snt_analytics_render_pageroles_table( null, 'exit' ); } );
$noted_x = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted_x ) && 'Exit pages' === $noted_x[0]['title']
	&& 'Exit pages could not be read (read failure: not an empty window).' === ( $noted_x[0]['why'] ?? '' ),
	'exit failed: same treatment under the exit title' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
