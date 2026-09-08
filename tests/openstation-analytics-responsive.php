<?php
/** Native signal semantics; classic keeps its existing HTML and all information. */
require __DIR__ . '/lib/os-leaf-harness.php';
require SNT_PATH . 'inc/analytics-insights.php';
require SNT_PATH . 'apps/sn-analytics/parts/native-surface.php';
require SNT_PATH . 'apps/sn-analytics/parts/view.php';
require SNT_PATH . 'apps/sn-analytics/parts/state.php';
function snt_os_host_capture( $cb, array $get = array(), array $post = array() ) { ob_start(); $cb(); return ob_get_clean(); }
function remove_filter( $hook, $cb, $p = 10 ) { $GLOBALS['__filters'][ $hook ][ $p ] = array_filter( $GLOBALS['__filters'][ $hook ][ $p ] ?? array(), fn( $f ) => $f !== $cb ); }
$pass = 0; $fail = 0;
function ok( $condition, $message ) { global $pass, $fail; if ( $condition ) { ++$pass; echo "PASS: $message\n"; } else { ++$fail; echo "FAIL: $message\n"; } }
foreach ( array( 'forecast_withheld' => 'none', 'anomaly' => 'high' ) as $kind => $confidence ) {
	$signal = array( 'tier' => 'predictive', 'kind' => $kind, 'confidence' => $confidence, 'direction' => 'anomaly' === $kind ? 'down' : '', 'plain_label' => 'Views: <keep> the entire explanation & model checks.' );
	$classic = snt_analytics_render_signal_chip( $signal );
	$native = \SignalNoise\OpenStationHost\Analytics\native_capture( array(), function () use ( $signal ) { echo snt_analytics_render_signal_chip( $signal ); } );
	ok( str_contains( $native, 'class="snt-signal-status"' ), "$kind has an explicit readable status heading" );
	ok( str_contains( $native, 'Confidence: ' . $confidence ), "$kind labels confidence rather than a stray NONE or HIGH" );
	ok( str_contains( $native, esc_html( $signal['plain_label'] ) ), "$kind retains and escapes the complete explanation" );
	ok( $classic === snt_analytics_render_signal_chip( $signal ), "$kind classic HTML is unchanged after the native capture" );
	ok( str_contains( $native, 'Forecast unavailable' ) === ( 'forecast_withheld' === $kind ), "$kind does not mislabel an anomaly as no forecast" );
}
echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
