<?php
/**
 * Regression test (#1228): sn_dash_render_trend() must space points by
 * ELAPSED DAYS, not array index. The source series (sn_analytics_daily_series)
 * GROUPs BY day with no zero-fill, so a day with no rows is simply ABSENT —
 * the series can carry real gaps. Index-spacing collapsed a 3-day silence
 * into the same visual width as a 1-day step.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = '' ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $d = '' ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }

require __DIR__ . '/../inc/dash-trend.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function points_of( $svg ) {
	preg_match( '/<path d="M ([^"]+)" class="sn-trend__line"/', $svg, $m );
	if ( ! $m ) { return array(); }
	$pts = array();
	foreach ( explode( ' L ', $m[1] ) as $pair ) {
		list( $x, $y ) = explode( ',', $pair );
		$pts[] = array( (float) $x, (float) $y );
	}
	return $pts;
}

echo "Group: #1228 — trend points space by elapsed days, not array index\n";
// Days 1, 2, then a gap to day 6 (a 4-day silence) — three points, uneven spacing.
$gappy = array(
	array( 'day' => '2026-06-01', 'views' => 10 ),
	array( 'day' => '2026-06-02', 'views' => 20 ),
	array( 'day' => '2026-06-06', 'views' => 30 ),
);
ob_start();
sn_dash_render_trend( $gappy );
$svg = ob_get_clean();
$pts = points_of( $svg );
ok( 3 === count( $pts ), 'fixture: three points render (so this pin cannot be vacuous)' );
$gap1 = $pts[1][0] - $pts[0][0]; // day1 -> day2: 1 day
$gap2 = $pts[2][0] - $pts[1][0]; // day2 -> day6: 4 days
ok( $gap1 > 0 && $gap2 > 0, 'both gaps are positive' );
ok( abs( $gap2 - 4 * $gap1 ) < 0.5, 'the 4-day gap is ~4x the width of the 1-day gap (#1228) — index-spacing would make them EQUAL' );

// Evenly-spaced days must still render evenly (no regression for the common case).
$even = array(
	array( 'day' => '2026-06-01', 'views' => 10 ),
	array( 'day' => '2026-06-02', 'views' => 20 ),
	array( 'day' => '2026-06-03', 'views' => 30 ),
);
ob_start();
sn_dash_render_trend( $even );
$svg2 = ob_get_clean();
$pts2 = points_of( $svg2 );
ok( 3 === count( $pts2 ), 'even fixture renders three points' );
$e1 = $pts2[1][0] - $pts2[0][0];
$e2 = $pts2[2][0] - $pts2[1][0];
ok( abs( $e1 - $e2 ) < 0.1, 'evenly-spaced days still render evenly spaced' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
