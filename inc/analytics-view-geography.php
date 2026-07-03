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
	echo '<div class="sn-geo-split">';
	snt_analytics_render_choropleth( 'World map', sn_analytics_top_dimension( 'country', $from, $to, $class, 250 ), 'No country data in this range yet.' );
	snt_analytics_render_dim_table( 'Countries', sn_analytics_top_dimension( 'country', $from, $to, $class, 10 ), 'No country data in this range.', array(), 'country' );
	echo '</div>';
	echo '<div class="sn-geo-tiles" style="margin-top:20px">';
	snt_analytics_render_dim_table( 'Cities', sn_analytics_top_dimension( 'city', $from, $to, $class, 10 ), 'No city data in this range yet.', array(), 'city' );
	snt_analytics_render_dim_table( 'Regions', sn_analytics_top_dimension( 'region', $from, $to, $class, 10 ), 'No region data in this range yet.', array(), 'region' );
	snt_analytics_render_dim_table( 'Networks', sn_analytics_top_dimension( 'network', $from, $to, $class, 10 ), 'No network data in this range yet.', array(), 'network' );
	snt_analytics_render_dim_table( 'Edge locations', sn_analytics_top_dimension( 'colo', $from, $to, $class, 10 ), 'No edge-location data in this range yet.', array(), 'colo' );
	// v6.27.0: visitor IANA timezone (worker v1.7.0, blob19) — the "when/where
	// my audience reads" signal, finer than country. Empty until the worker ships.
	snt_analytics_render_dim_table( 'Time zones', sn_analytics_top_dimension( 'timezone', $from, $to, $class, 10 ), 'No timezone data yet (needs worker v1.7.0 + traffic).', array(), 'timezone' );
	echo '</div></div>';
	snt_an_flush_empty_fold();
}
