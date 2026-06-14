<?php
/**
 * Unit test for snt_analytics_smooth_path() — the shared clamped Catmull-Rom
 * smoothing extracted from render_trend. Run: php tests/analytics-smooth-path.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "\nGroup: snt_analytics_smooth_path\n";
// The exact px/py render_trend plots for views [100,300,200] (max=300, top=8, base=78).
$px = array( 0, 300, 600 );
$py = array( 54.67, 8, 31.33 );
$d  = snt_analytics_smooth_path( $px, $py, 8, 78 );
ok( $d === 'M 0,54.67 C 50,46.89 200,11.89 300,8 C 400,8 550,27.44 600,31.33',
	'known 3-point input → exact clamped bézier (the approved chart\'s golden path)' );
ok( strpos( $d, 'M ' ) === 0, 'starts with a moveto' );
ok( substr_count( $d, ' C ' ) === 2, 'two cubic segments for three points' );
// The clamp is load-bearing: at i=1 the raw c1y is ~4.11 and must clamp UP to top=8.
ok( strpos( $d, 'C 400,8 ' ) !== false, 'control-point Y clamped to [top,base] (4.11 → 8)' );
ok( snt_analytics_smooth_path( array( 0 ), array( 10 ), 2, 16 ) === 'M 0,10', 'single point → bare moveto (no curve, no NaN)' );
ok( snt_analytics_smooth_path( array(), array(), 2, 16 ) === '', 'empty input → empty string' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
