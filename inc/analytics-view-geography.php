<?php
/**
 * Signal & Noise Tools — Analytics view: Geography (v8.5.0 extraction).
 *
 * Choropleth + countries split, then the tile grid (cities, regions,
 * networks, edge locations, time zones). Moved verbatim from the
 * dispatcher's switch (inc/analytics-admin.php).
 *
 * @package SignalNoiseTools
 * @since 8.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // the empty-fold collector this view emits into

/**
 * Render the Geography view body.
 *
 * @param string $from  Window start (Y-m-d).
 * @param string $to    Window end (Y-m-d).
 * @param string $class Traffic class.
 */
function snt_analytics_render_view_geography( $from, $to, $class ) {
	echo '<div class="sn-geo">';
	// Capture the full country pull so the read, the choropleth, AND the
	// Countries table share ONE query (v9.5.0 read: audience concentrated in
	// a couple of markets; D5 §5: sn_analytics_top_dimension() orders by
	// views DESC in SQL before LIMIT, so the first 10 of this 250-pull are
	// exactly the top 10 by views — identical to a standalone limit-10 pull).
	$countries = sn_analytics_top_dimension( 'country', $from, $to, $class, 250 );
	snt_an_annotation( sn_annotation_geography( $countries ) );
	echo '<div class="sn-geo-split">';
	snt_analytics_render_choropleth( __( 'World map', 'signal-and-noise-tools' ), $countries, __( 'No country data in this range yet.', 'signal-and-noise-tools' ) );
	snt_analytics_render_dim_table( __( 'Countries', 'signal-and-noise-tools' ), array_slice( $countries, 0, 10 ), __( 'No country data in this range.', 'signal-and-noise-tools' ), array(), 'country' );
	echo '</div>';
	// D5 §6: the 20px gutter above this grid lives in CSS (.sn-geo-tiles), not an
	// inline style — assets/analytics/analytics-admin.css collapses it via
	// :empty when all five tiles below fold, so an all-dataless range no longer
	// orphans a bare 20px gap above nothing.
	echo '<div class="sn-geo-tiles">';
	snt_analytics_render_dim_table( __( 'Cities', 'signal-and-noise-tools' ), sn_analytics_top_dimension( 'city', $from, $to, $class, 10 ), __( 'No city data in this range yet.', 'signal-and-noise-tools' ), array(), 'city' );
	snt_analytics_render_dim_table( __( 'Regions', 'signal-and-noise-tools' ), sn_analytics_top_dimension( 'region', $from, $to, $class, 10 ), __( 'No region data in this range yet.', 'signal-and-noise-tools' ), array(), 'region' );
	snt_analytics_render_dim_table( __( 'Networks', 'signal-and-noise-tools' ), sn_analytics_top_dimension( 'network', $from, $to, $class, 10 ), __( 'No network data in this range yet.', 'signal-and-noise-tools' ), array(), 'network' );
	snt_analytics_render_dim_table( __( 'Edge locations', 'signal-and-noise-tools' ), sn_analytics_top_dimension( 'colo', $from, $to, $class, 10 ), __( 'No edge-location data in this range yet.', 'signal-and-noise-tools' ), array(), 'colo' );
	// v6.27.0: visitor IANA timezone (worker v1.7.0, blob19) — the "when/where
	// my audience reads" signal, finer than country. Empty until the worker ships.
	snt_analytics_render_dim_table( __( 'Time zones', 'signal-and-noise-tools' ), sn_analytics_top_dimension( 'timezone', $from, $to, $class, 10 ), __( 'No timezone data yet (needs worker v1.7.0 + traffic).', 'signal-and-noise-tools' ), array(), 'timezone' );
	echo '</div></div>';
	snt_an_flush_empty_fold();
}
