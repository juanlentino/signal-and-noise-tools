<?php
/**
 * Signal & Noise Tools — Analytics view: Technology (v8.5.0 extraction).
 *
 * Five dimension tables (browsers, OS, devices, protocols, TLS) with per-row
 * trend sparklines, in the shared 2-col grid. Moved verbatim from the
 * dispatcher's switch (inc/analytics-admin.php) — composition only; the
 * tables render through inc/analytics-admin-render.php.
 *
 * @package SignalNoiseTools
 * @since 8.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Technology view body.
 *
 * @param string $from        Window start (Y-m-d).
 * @param string $to          Window end (Y-m-d).
 * @param string $class       Traffic class.
 * @param string $granularity 'day' | 'week' | 'month'.
 */
function snt_analytics_render_view_technology( $from, $to, $class, $granularity ) {
	echo '<div class="sn-an-grid">';
	$brow_rows = sn_analytics_top_dimension( 'browser', $from, $to, $class, 10 );
	$brow_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $brow_rows );
	$brow_ser  = sn_analytics_dimension_series( 'browser', $brow_vals, $from, $to, $class, $granularity );
	snt_analytics_render_dim_table( 'Browsers', $brow_rows, 'No browser data in this range yet.', $brow_ser, 'browser' );
	$os_rows = sn_analytics_top_dimension( 'os', $from, $to, $class, 10 );
	$os_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $os_rows );
	$os_ser  = sn_analytics_dimension_series( 'os', $os_vals, $from, $to, $class, $granularity );
	snt_analytics_render_dim_table( 'Operating systems', $os_rows, 'No OS data in this range yet.', $os_ser, 'os' );
	$dev_rows = sn_analytics_top_dimension( 'device', $from, $to, $class, 10 );
	$dev_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $dev_rows );
	$dev_ser  = sn_analytics_dimension_series( 'device', $dev_vals, $from, $to, $class, $granularity );
	snt_analytics_render_dim_table( 'Devices', $dev_rows, 'No device data in this range.', $dev_ser, 'device' );
	$pro_rows = sn_analytics_top_dimension( 'protocol', $from, $to, $class, 10 );
	$pro_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $pro_rows );
	$pro_ser  = sn_analytics_dimension_series( 'protocol', $pro_vals, $from, $to, $class, $granularity );
	snt_analytics_render_dim_table( 'Protocols', $pro_rows, 'No protocol data in this range yet.', $pro_ser, 'protocol' );
	$tls_rows = sn_analytics_top_dimension( 'tls', $from, $to, $class, 10 );
	$tls_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $tls_rows );
	$tls_ser  = sn_analytics_dimension_series( 'tls', $tls_vals, $from, $to, $class, $granularity );
	snt_analytics_render_dim_table( 'TLS versions', $tls_rows, 'No TLS data in this range yet.', $tls_ser, 'tls' );
	echo '</div>';
}
