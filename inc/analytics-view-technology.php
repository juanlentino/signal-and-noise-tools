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
 * One dimension panel: the top-10 rows plus (rows permitting) the batched
 * trend series. v9.68.1: a FAILED rows read (accessor null) skips the series
 * read (there is nothing to key it on — and array_map over null would fatal)
 * and hands null to the renderer, whose read-failure fold owns the copy. A
 * null SERIES (its own failed read) degrades to no sparklines — the rows
 * table still renders.
 *
 * @since 9.68.1
 * @param string $dim         Dimension key (also the drill dim).
 * @param string $title       Panel title.
 * @param string $empty       Empty-window copy.
 * @param string $from        Window start (Y-m-d).
 * @param string $to          Window end (Y-m-d).
 * @param string $class       Traffic class.
 * @param string $granularity 'day' | 'week' | 'month'.
 */
function snt_analytics_tech_dim_panel( $dim, $title, $empty, $from, $to, $class, $granularity ) {
	$rows = sn_analytics_top_dimension( $dim, $from, $to, $class, 10 );
	$ser  = array();
	if ( is_array( $rows ) ) {
		$vals = array_map( static function ( $r ) { return (string) $r['value']; }, $rows );
		$ser  = sn_analytics_dimension_series( $dim, $vals, $from, $to, $class, $granularity );
	}
	snt_analytics_render_dim_table( $title, $rows, $empty, is_array( $ser ) ? $ser : array(), $dim );
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
	snt_analytics_tech_dim_panel( 'browser', __( 'Browsers', 'signal-and-noise-tools' ), __( 'No browser data in this range yet.', 'signal-and-noise-tools' ), $from, $to, $class, $granularity );
	snt_analytics_tech_dim_panel( 'os', __( 'Operating systems', 'signal-and-noise-tools' ), __( 'No OS data in this range yet.', 'signal-and-noise-tools' ), $from, $to, $class, $granularity );
	snt_analytics_tech_dim_panel( 'device', __( 'Devices', 'signal-and-noise-tools' ), __( 'No device data in this range.', 'signal-and-noise-tools' ), $from, $to, $class, $granularity );
	snt_analytics_tech_dim_panel( 'protocol', __( 'Protocols', 'signal-and-noise-tools' ), __( 'No protocol data in this range yet.', 'signal-and-noise-tools' ), $from, $to, $class, $granularity );
	snt_analytics_tech_dim_panel( 'tls', __( 'TLS versions', 'signal-and-noise-tools' ), __( 'No TLS data in this range yet.', 'signal-and-noise-tools' ), $from, $to, $class, $granularity );
	echo '</div>';
	snt_an_flush_empty_fold();
}
