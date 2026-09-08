<?php
/** Deterministic readers only; invoked by openstation-app-analytics.php, never web. */
if ( PHP_SAPI !== 'cli' || ! isset( $app ) ) { exit( 1 ); }
function remove_filter( $hook, $cb, $priority = 10 ) {
	$GLOBALS['__filters'][ $hook ][ $priority ] = array_filter( $GLOBALS['__filters'][ $hook ][ $priority ] ?? array(), fn( $f ) => $f !== $cb );
	return true;
}
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_specialchars_decode( $s, $flags = ENT_NOQUOTES ) { return htmlspecialchars_decode( $s, $flags ); }
function remove_query_arg( $keys, $url = '' ) { return $url; }
function sn_analytics_range_totals( $from, $to, $class ) {
	return array( 'views' => 93, 'pageview_visits' => 61, 'unique_visitor_days' => 178, 'viewless_visits' => 117, 'scroll_avg_per_view' => 42, 'time_avg_per_view' => 35000, 'exact_metrics_since' => '2026-01-01' );
}
function sn_analytics_class_totals( $from, $to ) { return array( 'bot' => array( 'views' => 62 ), 'suspect' => array( 'views' => 55 ) ); }
function sn_analytics_realtime( $class ) { return 2; }
function sn_analytics_daily_series( $from, $to, $class, $granularity ) {
	return array_map( fn( $i ) => array( 'day' => '2026-09-0' . $i, 'views' => 8 + $i, 'visits' => 4 + $i ), range( 1, 7 ) );
}
function sn_analytics_period_deltas( $from, $to, $class, $window = null ) { return array(); }
function sn_analytics_engaged_rate_delta( $from, $to, $class, $window = null ) { return array( 'current' => 46 ); }
function sn_analytics_engaged_rate( $from, $to, $class ) { return 46; }
function sn_annotation_overview( $deltas, $engaged ) { return 'Visits and reading depth for the selected window.'; }
function snt_analytics_render_movers_tile( $from, $to, $class, $window, $basis, $range ) { /* Optional reader absent in this fixture. */ }
function sn_analytics_digest( $summary, $signals, $action ) {
	return array( 'digest' => '<p>This period: 93 views, 61 visits (178 visitor-days, 117 of them viewless).</p><p>Investigate changes alongside the selected traffic class.</p>', 'source' => 'fallback' );
}
function sn_session_rollup_read( $from, $to, $class ) {
	return array_map( fn( $i ) => array( 'day' => '2026-09-0' . $i, 'visits' => 12, 'bounce_pct' => 35, 'ppv' => 1.8, 'median_dur' => 41 ), range( 1, 7 ) );
}
function sn_analytics_top_sources( $from, $to, $class, $limit ) { return array( array( 'value' => 'Search', 'views' => 42, 'visits' => 30 ) ); }
function sn_analytics_top_utm_campaigns( $from, $to, $class, $limit ) { return array( array( 'value' => 'Fixture newsletter', 'views' => 18, 'visits' => 12 ) ); }
function sn_analytics_top_dimension( $dim, $from, $to, $class, $limit ) { return array( array( 'value' => 'country' === $dim ? 'US' : 'Mobile', 'views' => 55, 'visits' => 36 ) ); }
$warning = in_array( '--warning', $argv, true );
$GLOBALS['__overview_signals'] = array(
	array( 'tier' => 'predictive', 'kind' => $warning ? 'anomaly' : 'forecast_withheld', 'direction' => $warning ? 'down' : '', 'confidence' => $warning ? 'high' : 'none', 'plain_label' => $warning ? 'Views fell sharply: 71% below the recent baseline. Check acquisition and collection health.' : 'Views: no forecast — the model does not beat a same-value baseline on this history (skill -0.35 over 84 checks).' ),
	array( 'tier' => 'prescriptive', 'kind' => 'recommendation', 'confidence' => 'medium', 'plain_label' => 'Review acquisition sources before changing publishing cadence.' ),
);
foreach ( array( 'analytics-insights', 'analytics-header-region', 'analytics-render-overview', 'analytics-view-body', 'analytics-view-overview' ) as $file ) { require_once SNT_PATH . 'inc/' . $file . '.php'; }
$input = json_decode( getenv( 'SNT_FIXTURE_STATE' ) ?: '{}', true ) ?: array();
$state = new \OpenStation\App\State( $app->state, $input );
$os = new \OpenStation\App\Os();
$os->view = 'main';
$action = getenv( 'SNT_FIXTURE_ACTION' );
if ( $action && isset( $app->actions[ $action ] ) ) {
	$app->actions[ $action ]( $state, $os, json_decode( getenv( 'SNT_FIXTURE_ARGS' ) ?: '{}', true ) ?: array() );
}
ob_start();
call_user_func( $app->view, $state, $os );
$html = ob_get_clean();
if ( in_array( '--json', $argv, true ) ) {
	echo json_encode( array( 'html' => $html, 'state' => $state->all() ) );
} else {
	echo $html;
}
