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

require_once __DIR__ . '/analytics-panels.php'; // the empty-fold collector this view emits into

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
	snt_analytics_render_dim_table( __( 'Browsers', 'signal-and-noise-tools' ), $brow_rows, __( 'No browser data in this range yet.', 'signal-and-noise-tools' ), $brow_ser, 'browser' );
	$os_rows = sn_analytics_top_dimension( 'os', $from, $to, $class, 10 );
	$os_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $os_rows );
	$os_ser  = sn_analytics_dimension_series( 'os', $os_vals, $from, $to, $class, $granularity );
	snt_analytics_render_dim_table( __( 'Operating systems', 'signal-and-noise-tools' ), $os_rows, __( 'No OS data in this range yet.', 'signal-and-noise-tools' ), $os_ser, 'os' );
	$dev_rows = sn_analytics_top_dimension( 'device', $from, $to, $class, 10 );
	$dev_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $dev_rows );
	$dev_ser  = sn_analytics_dimension_series( 'device', $dev_vals, $from, $to, $class, $granularity );
	snt_analytics_render_dim_table( __( 'Devices', 'signal-and-noise-tools' ), $dev_rows, __( 'No device data in this range.', 'signal-and-noise-tools' ), $dev_ser, 'device' );
	$pro_rows = sn_analytics_top_dimension( 'protocol', $from, $to, $class, 10 );
	$pro_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $pro_rows );
	$pro_ser  = sn_analytics_dimension_series( 'protocol', $pro_vals, $from, $to, $class, $granularity );
	snt_analytics_render_dim_table( __( 'Protocols', 'signal-and-noise-tools' ), $pro_rows, __( 'No protocol data in this range yet.', 'signal-and-noise-tools' ), $pro_ser, 'protocol' );
	$tls_rows = sn_analytics_top_dimension( 'tls', $from, $to, $class, 10 );
	$tls_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $tls_rows );
	$tls_ser  = sn_analytics_dimension_series( 'tls', $tls_vals, $from, $to, $class, $granularity );
	snt_analytics_render_dim_table( __( 'TLS versions', 'signal-and-noise-tools' ), $tls_rows, __( 'No TLS data in this range yet.', 'signal-and-noise-tools' ), $tls_ser, 'tls' );
	echo '</div>';
	snt_an_flush_empty_fold();
}
