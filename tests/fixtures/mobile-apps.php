<?php
/** CLI-only local responsive fixtures; no WordPress or production data. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require dirname( __DIR__ ) . '/lib/os-leaf-harness.php';
if ( ( $argv[1] ?? '' ) === 'home' ) {
	function snt_dashboard_tab_data() { return array( 'attention' => array( array( 'text' => 'Fixture: review a long operational item before the next publication', 'href' => 'https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=monitoring&sub=health' ) ) ); }
	class WP_Query {
		public $posts;
		public function __construct( $args ) {
			$this->posts = array_map( fn( $i ) => (object) array( 'ID' => $i, 'post_type' => 'post', 'post_status' => 'pending', 'post_title' => 'Fixture article with a long title for responsive testing ' . $i, 'post_modified_gmt' => '2026-09-01 12:00:00' ), range( 1, 6 ) );
		}
	}
	require SNT_PATH . 'apps/sn-dashboard/parts/leaves/dashboard.php';
	echo '<div class="snt-app" data-os-app="sn-dashboard" data-snt-tab="dashboard" data-snt-layout="dashboard"><div class="snt-dashboard-body"><div class="snt-leaf">';
	echo \SignalNoise\OpenStationHost\Dashboard\Leaves\paint_dashboard( array() );
	echo '</div></div></div>';
} else {
	require SNT_PATH . 'apps/sn-analytics/parts/painters/chrome-controls.php';
	echo '<div class="snt-app os-app-list" data-os-app="sn-analytics"><div class="snt-report-scroll">';
	echo \SignalNoise\OpenStationHost\Analytics\Painters\paint_chrome_controls( array( 'range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-07' ) );
	echo '<div class="os-app-list__body snt-report-body"><div class="snt-view">';
	// Independent fixture uses the real canonical report and native painters.
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/openstation-analytics-native-surface.php' ) . ' --fixture' );
	require SNT_PATH . 'apps/sn-analytics/parts/native-surface.php';
	$matrix = '<table><caption>Fixture grouped matrix</caption><thead><tr><th colspan="8">Grouped diagnostic measurements</th></tr><tr>';
	foreach ( range( 1, 8 ) as $i ) { $matrix .= '<th>Measurement ' . $i . '</th>'; }
	$matrix .= '</tr></thead><tbody><tr>';
	foreach ( range( 1, 8 ) as $i ) { $matrix .= '<td>123456789.' . $i . '</td>'; }
	$matrix .= '</tr></tbody></table>';
	echo \SignalNoise\OpenStationHost\Analytics\native_tables( $matrix );
	echo '</div></div></div></div>';
}
