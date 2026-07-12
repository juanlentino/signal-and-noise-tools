<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }

// Data-accessor stubs (fixtures controlled per-test).
$GLOBALS['__daily'] = array();
function sn_analytics_daily_series( $from, $to, $class = 'human', $g = 'day' ) { return $GLOBALS['__daily']; }
$GLOBALS['__paths'] = array();
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__paths']; }
$GLOBALS['__pathseries'] = array();
function sn_analytics_path_daily_series( $path, $from, $to ) { return $GLOBALS['__pathseries'][ $path ] ?? array(); }
$GLOBALS['__camps'] = array();
function sn_analytics_top_utm_campaigns( $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__camps']; }
$GLOBALS['__campseries'] = array();
function sn_analytics_utm_series( $mode, $vals, $from, $to, $class = 'human', $g = 'day' ) { return $GLOBALS['__campseries']; }

require __DIR__ . '/../inc/analytics-signals.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: statistical helpers\n";
ok( sn_analytics_stat_median( array( 3, 1, 2 ) ) === 2.0, 'median: odd' );
ok( sn_analytics_stat_median( array( 1, 2, 3, 4 ) ) === 2.5, 'median: even' );
ok( null === sn_analytics_stat_median( array() ), 'median: empty → null' );
ok( sn_analytics_stat_mad( array( 1, 1, 1, 1, 100 ) ) === 0.0, 'mad: robust to a lone outlier (median dev 0)' );
// A perfect line y = 2x: Theil–Sen slope = 2.
ok( sn_analytics_stat_theil_sen( array( 0, 2, 4, 6, 8 ) ) === 2.0, 'theil-sen: exact slope of a line' );
// One wild outlier must not move the slope much (median of pairwise slopes).
ok( abs( sn_analytics_stat_theil_sen( array( 0, 2, 4, 6, 999 ) ) - 2.0 ) < 0.001, 'theil-sen: outlier-resistant' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
