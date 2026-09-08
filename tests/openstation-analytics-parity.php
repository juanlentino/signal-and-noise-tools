<?php
/** Canonical Analytics dispatch parity. Run: php tests/openstation-analytics-parity.php */
namespace SignalNoise\OpenStationHost\Analytics {
	function capture( array $get, ?callable $paint = null ) {
		$GLOBALS['captured_get'] = $get;
		ob_start();
		$paint();
		return ob_get_clean();
	}
	function page_slug() { return 'sn-analytics'; }
}
namespace {
	define( 'ABSPATH', __DIR__ );
	function snt_os_host_keep_forms( $html, ...$args ) { return $html; }
	function snt_os_host_rewrite( $html, ...$args ) { return $html; }
	function snt_os_analytics_keep_actions() { return array( 'analytics_export' ); }
	function sn_analytics_posts_bundle() { return array( 'subject' => 'lifetime' ); }
	function sn_analytics_posts_lifecycle() { return array( 'refresh' => 'queue' ); }
	function snt_analytics_render_posts_view( $bundle ) { echo json_encode( array( 'posts', $bundle ) ); }
	function snt_analytics_render_lifecycle_section( $bundle ) { echo json_encode( array( 'lifecycle', $bundle ) ); }
	function snt_analytics_render_view_overview( ...$args ) { echo json_encode( array( 'overview', $args ) ); }
	function snt_analytics_render_view_content( ...$args ) { echo json_encode( array( 'content', $args ) ); }
	function snt_analytics_render_view_campaigns( ...$args ) { echo json_encode( array( 'campaigns', $args ) ); }
	function snt_analytics_render_view_technology( ...$args ) { echo json_encode( array( 'technology', $args ) ); }
	function snt_analytics_render_view_geography( ...$args ) { echo json_encode( array( 'geography', $args ) ); }
	function snt_analytics_render_view_engagement( ...$args ) { echo json_encode( array( 'engagement', $args ) ); }
	function snt_analytics_render_view_sessions( ...$args ) { echo json_encode( array( 'visits', $args ) ); }
	function snt_analytics_render_view_quality( ...$args ) { echo json_encode( array( 'quality', $args ) ); }
	function snt_analytics_render_view_search( ...$args ) { echo json_encode( array( 'search', $args ) ); }
	function snt_analytics_render_view_events( ...$args ) { echo json_encode( array( 'events', $args ) ); }
	function snt_edge_render_view( ...$args ) { echo json_encode( array( 'edge', $args ) ); }
	function sn_login_defense_render_body( ...$args ) { echo json_encode( array( 'login-defense', $args ) ); }
	function snt_analytics_render_insights_band( ...$args ) { echo '<details>full digest</details>'; }
	function sn_login_defense_render_header() { echo 'checked blocked throttled block_rate trend'; }
	function snt_analytics_render_header_region( ...$args ) { echo json_encode( $args ); return array( 'views' => 92 ); }
	require dirname( __DIR__ ) . '/inc/analytics-view-body.php';
	require dirname( __DIR__ ) . '/apps/sn-analytics/parts/canonical.php';
	$pass = 0; $fail = 0;
	function ok( $yes, $message ) { global $pass, $fail; $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS: ' : 'FAIL: ' ) . $message . "\n"; }
	$ctx = array( 'view' => 'overview', 'from' => '2026-08-01', 'to' => '2026-08-31', 'class' => 'suspect', 'granularity' => 'day', 'range' => 'custom', 'compare' => 'yoy', 'get' => array( 'sn_event_prop' => 'host', 'sn_lg_range' => '30' ) );
	foreach ( array( 'overview', 'content', 'campaigns', 'posts', 'technology', 'geography', 'engagement', 'visits', 'quality', 'search', 'events', 'edge', 'login-defense' ) as $view ) {
		$ctx['view'] = $view;
		ob_start();
		snt_analytics_render_view_body( $view, $ctx['from'], $ctx['to'], $ctx['class'], $ctx['granularity'], $ctx['range'], $ctx['compare'] );
		$classic = ob_get_clean();
		$native = \SignalNoise\OpenStationHost\Analytics\canonical_piece( 'view/' . $view, $ctx );
		ok( $classic === $native['html'] && strpos( $classic, $view ) !== false, "$view uses the complete classic renderer" );
	}
	ok( $GLOBALS['captured_get'] === $ctx['get'], 'property and login range reach canonical capture' );
	$ctx['view'] = 'posts';
	$posts = \SignalNoise\OpenStationHost\Analytics\canonical_piece( 'view/posts', $ctx );
	ok( strpos( $posts['html'], 'lifetime' ) !== false && strpos( $posts['html'], 'queue' ) !== false, 'Posts includes both lifetime report and lifecycle queue' );
	$header = \SignalNoise\OpenStationHost\Analytics\canonical_piece( 'chrome/header', $ctx );
	$header_args = json_decode( $header['html'], true );
	ok( $header['facts']['totals']['views'] === 92 && $header_args[7] === false, 'header retains totals and suppresses duplicate classic controls' );
	ok( count( $header_args ) === 9 && $header_args[8] === true, 'native header explicitly places descriptive annotations after metrics/chart' );
	$insights = \SignalNoise\OpenStationHost\Analytics\canonical_piece( 'chrome/insights', $ctx );
	ok( strpos( $insights['html'], '<details>' ) !== false, 'insights retain the expandable full digest' );
	$login = \SignalNoise\OpenStationHost\Analytics\canonical_piece( 'chrome/login-header', $ctx );
	ok( strpos( $login['html'], 'block_rate trend' ) !== false, 'login retains canonical metrics and trend' );
	echo "Result: $pass passed, $fail failed.\n";
	exit( $fail ? 1 : 0 );
}
