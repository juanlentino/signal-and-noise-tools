<?php
/**
 * SN Reading's This week and Top pages, moved from SN Traffic (owner
 * 2026-11-14): the same figures as Traffic painted, the rows' shape, and
 * absent (not zero) when the north star is not set up.
 * Run: php tests/desktop-mode-reading-moved.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
require __DIR__ . '/../inc/desktop-mode-reading-moved.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$ns = array( 'configured' => true, 'value' => 4, 'previous' => 5, 'layers' => array( 'return' => array( 'doi_downloads' => array( 'value' => 254, 'window' => '7d' ), 'inquiries' => array( 'value' => 0 ) ) ) );
ok( array(
	array( 'label' => 'Engaged visitor-days · 7d', 'value' => '4 ▼ 1' ),
	array( 'label' => 'DOI downloads · 7d', 'value' => '254' ),
	array( 'label' => 'Inquiries · 7d', 'value' => '0' ),
) === snt_desktop_reading_week_rows( $ns ), 'This week: the three north-star rows Traffic showed, a measured 0 included' );
ok( '4' === snt_desktop_reading_week_rows( array( 'previous' => 4 ) + $ns )[0]['value'], 'no change: no arrow' );
ok( ! isset( snt_desktop_reading_week_rows( $ns )[0]['tone'] ), 'a change of 1: not colored' );
ok( 'up' === ( snt_desktop_reading_week_rows( array( 'value' => 30, 'previous' => 25 ) + $ns )[0]['tone'] ?? '' ), 'at the threshold (5, 20%): green, as Traffic colored it' );
ok( 'down' === ( snt_desktop_reading_week_rows( array( 'value' => 10, 'previous' => 20 ) + $ns )[0]['tone'] ?? '' ), 'a meaningful drop: the down tone' );
ok( ! isset( snt_desktop_reading_week_rows( array( 'value' => 105, 'previous' => 100 ) + $ns )[0]['tone'] ), '5 of 100 (5%): not meaningful, not colored' );
ok( array() === snt_desktop_reading_week_rows( array( 'configured' => false ) ) && array() === snt_desktop_reading_week_rows( null ), 'not set up: no group, never zeros' );
ok( 1 === count( snt_desktop_reading_week_rows( array( 'layers' => array() ) + $ns ) ), 'a layer that is not read adds no row' );

$top = array( array( 'path' => '/resume', 'views' => 18 ), array( 'path' => '/', 'views' => 15 ), array( 'path' => '/services', 'views' => 8 ), array( 'path' => '/x', 'views' => 1 ), 'junk' );
ok( array(
	array( 'label' => '/resume', 'value' => '18' ),
	array( 'label' => '/', 'value' => '15' ),
	array( 'label' => '/services', 'value' => '8' ),
) === snt_desktop_reading_top_rows( $top ), 'Top pages: the top three, path and views' );
ok( array() === snt_desktop_reading_top_rows( null ), 'no rows: none' );

$src = file_get_contents( __DIR__ . '/../assets/desktop-mode-widget-views.js' );
ok( false === strpos( $src, "group( 'This week'" ) && false === strpos( $src, "group( 'Top pages'" ), 'SN Traffic no longer paints them' );
$rd = file_get_contents( __DIR__ . '/../inc/desktop-mode-reading.php' );
ok( false !== strpos( $rd, "snt_desktop_group( 'This week'" ) && false !== strpos( $rd, "sn_analytics_title_estimated( 'Top pages'" ), 'SN Reading does' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
