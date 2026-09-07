<?php
/** Native primitive rendering, isolation and rich report preservation. */
namespace SignalNoise\OpenStationHost\Analytics {
	function capture( array $get, ?callable $paint = null ) {
		ob_start();
		try { $paint(); return ob_get_contents(); } finally { ob_end_clean(); }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
	function __( $s, $domain = '' ) { return $s; }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $s ) { return esc_html( $s ); }
	function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
	function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
	function esc_url( $s ) { return esc_attr( $s ); }
	function wp_kses_post( $s ) { return $s; }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function number_format_i18n( $n, $d = 0 ) { return number_format( $n, $d ); }
	function sanitize_title( $s ) { return strtolower( str_replace( ' ', '-', $s ) ); }
	function add_filter( $name, $fn, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][$name][] = $fn; }
	function remove_filter( $name, $fn, $priority = 10 ) { $GLOBALS['hooks'][$name] = array_filter( $GLOBALS['hooks'][$name] ?? array(), fn( $f ) => $f !== $fn ); }
	function apply_filters( $name, $value, ...$args ) { foreach ( $GLOBALS['hooks'][$name] ?? array() as $fn ) { $value = $fn( $value, ...$args ); } return $value; }
	function add_query_arg( $args ) { return '/?page=sn-analytics&' . http_build_query( $args ); }
	require dirname( __DIR__ ) . '/inc/openstation-kit.php';
	require dirname( __DIR__ ) . '/inc/openstation-kit-display.php';
	require dirname( __DIR__ ) . '/inc/analytics-panels.php';
	require dirname( __DIR__ ) . '/inc/analytics-render-tables.php';
	require dirname( __DIR__ ) . '/inc/analytics-render-distribution.php';
	require dirname( __DIR__ ) . '/apps/sn-analytics/parts/native-surface.php';
	function report() {
		snt_an_panel_open( 'Overview', array( 'header_meta' => '<span>Last 7 days · Human traffic</span>' ) );
		snt_an_kpi_row( array(
			array( 'l' => 'Views', 'n' => '1,204', 'delta' => array( 'pct' => 12, 'dir' => 'up', 'previous' => 1075 ) ),
			array( 'l' => 'Visits', 'n' => '843', 'sub' => 'Human traffic' ),
			array( 'l' => 'Now', 'n' => '3', 'live' => true ),
			array( 'l' => 'Scroll / view', 'n' => '42%', 'sub' => 'Measured per pageview' ),
			array( 'l' => 'Time / view', 'n' => '1m 12s', 'sub' => 'Measured per pageview' ),
			array( 'l' => 'Engaged', 'n' => '61%', 'sub' => 'Human traffic' ),
		), array( 'basis_label' => 'previous period' ) );
		snt_an_trend_svg( array( 82, 134, 106, 215, 162, 241, 264 ), array( 'head' => 'Views per day', 'axis' => array( 'Sep 1', 'Sep 7' ), 'overlay_series' => array( 72, 104, 96, 140, 132, 210, 221 ) ) );
		snt_an_panel_close();
		snt_an_cols_open();
		snt_analytics_render_dim_table( 'Top pages', array_map( fn( $i ) => array( 'value' => '/notes/' . $i, 'views' => 120 - $i, 'visits' => 70 - $i ), range( 1, 12 ) ), '', array(), 'path', 5 );
		snt_analytics_render_dim_table( 'Sources', array( array( 'value' => 'Search & discovery', 'views' => 420, 'visits' => 310 ), array( 'value' => '<img src=x onerror=alert(1)>', 'views' => 14, 'visits' => 9 ) ), '', array(), 'source' );
		snt_an_cols_close();
		snt_an_panel_open( 'Diagnostics', array( 'collapsible' => true, 'collapsed' => true ) );
		echo '<p>Read failures remain distinct from an empty range.</p>';
		snt_an_panel_close();
	}
	$classic = \SignalNoise\OpenStationHost\Analytics\capture( array(), 'report' );
	$native = \SignalNoise\OpenStationHost\Analytics\native_tables( \SignalNoise\OpenStationHost\Analytics\native_capture( array(), 'report' ) );
	if ( in_array( '--fixture', $argv ?? array(), true ) ) { echo $native; exit; }
	$pass = 0; $fail = 0;
	function ok( $yes, $label ) { global $pass, $fail; $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; }
	ok( substr_count( $native, '<os-section ' ) === 4, 'every report panel uses os-section' );
	ok( substr_count( $native, '<os-stat ' ) === 6, 'resolved metric cards use os-stat' );
	ok( ! str_contains( $native, 'postbox' ) && ! str_contains( $native, 'class="sn-kpi"' ), 'native primitives omit classic chrome' );
	ok( str_contains( $native, 'value="1m 12s"' ) && str_contains( $native, 'value="42%"' ), 'time and percentage units survive unchanged' );
	ok( str_contains( $native, 'previous period' ) && str_contains( $native, '+12%' ), 'comparison value and basis metadata survive' );
	ok( str_contains( $native, 'caption="live"' ), 'live metric remains labelled' );
	ok( str_contains( $native, '<svg' ) && str_contains( $native, 'stroke-dasharray' ), 'chart and comparison geometry remain present' );
	ok( substr_count( $native, '<snt-analytics-table>' ) === 2, 'tabular reports use native table hosts' );
	ok( substr_count( $native, '<template><table' ) === 2, 'escaped rich cells stay inert until native table rendering' );
	ok( str_contains( $native, '/notes/12' ), 'clamping preserves all rows for expansion' );
	ok( str_contains( $native, 'View all 12' ) && str_contains( $native, 'data-visible="5"' ), 'native expansion retains classic visible row count' );
	ok( str_contains( $native, 'sn_drill=path' ), 'row drill links remain attached to their cells' );
	ok( str_contains( $native, '&lt;img' ) && ! str_contains( $native, '<img src=x' ), 'untrusted row labels remain escaped' );
	ok( str_contains( $native, '<details class="snt-native-disclosure">' ), 'collapsed diagnostics use a closed native disclosure' );
	ok( str_contains( $classic, 'postbox sn-an-postbox' ) && ! str_contains( $classic, '<os-section' ), 'classic request retains classic presentation' );
	ok( $classic === \SignalNoise\OpenStationHost\Analytics\capture( array(), 'report' ), 'native capture leaves no presentation hooks on later classic requests' );
	try { \SignalNoise\OpenStationHost\Analytics\native_capture( array(), function () { throw new \RuntimeException( 'fixture' ); } ); } catch ( \RuntimeException $e ) {}
	ok( apply_filters( 'snt_analytics_surface', null, 'panel-close', array() ) === null, 'exception path removes native presentation hook' );
	$percentiles = \SignalNoise\OpenStationHost\Analytics\native_capture( array(), function () {
		snt_analytics_render_percentiles( 'Read time', array( array( 'label' => 'p50', 'value' => 72000 ) ), 'time' );
	} );
	ok( str_contains( $percentiles, '<os-stat' ) && str_contains( $percentiles, 'label="P50"' ) && ! str_contains( $percentiles, 'sn-an-pctl-chip' ), 'percentile values use native stats after shared time formatting' );
	$missing = \SignalNoise\OpenStationHost\Analytics\native_stats( array( array( 'l' => 'Unavailable', 'n' => '—', 'sub' => '0' ) ), array() );
	ok( str_contains( $missing, 'value="—"' ) && str_contains( $missing, 'caption="0"' ), 'unknown readings and zero captions remain exact' );
	echo "Result: $pass passed, $fail failed.\n";
	exit( $fail ? 1 : 0 );
}
