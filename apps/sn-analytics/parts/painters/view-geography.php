<?php
/**
 * S&N Analytics — view/geography.
 *
 * Classic: snt_analytics_render_view_geography() in inc/analytics-view-geography.php.
 * The choropleth SVG has no kit counterpart; the country table is the readout.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Analytics\Painters;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * @param array<string,mixed> $ctx Frame context.
 * @return string
 */
function paint_view_geography( array $ctx ) {
	$from  = (string) $ctx['from'];
	$to    = (string) $ctx['to'];
	$class = (string) $ctx['class'];
	$read  = static function ( $dim, $limit = 10 ) use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( $dim, $from, $to, $class, $limit ) : array();
	};
	$countries = $read( 'country', 250 );
	$top       = is_array( $countries ) ? array_slice( $countries, 0, 10 ) : $countries;
	$out       = dim_table( __( 'Countries', 'signal-and-noise-tools' ), $top, __( 'No country data in this range.', 'signal-and-noise-tools' ), 'country' );
	$out      .= '<div class="snt-grid">';
	$out      .= dim_table( __( 'Cities', 'signal-and-noise-tools' ), $read( 'city' ), __( 'No city data in this range yet.', 'signal-and-noise-tools' ), 'city' );
	$out      .= dim_table( __( 'Regions', 'signal-and-noise-tools' ), $read( 'region' ), __( 'No region data in this range yet.', 'signal-and-noise-tools' ), 'region' );
	$out      .= dim_table( __( 'Networks', 'signal-and-noise-tools' ), $read( 'network' ), __( 'No network data in this range yet.', 'signal-and-noise-tools' ), 'network' );
	$out      .= dim_table( __( 'Edge locations', 'signal-and-noise-tools' ), $read( 'colo' ), __( 'No edge-location data in this range yet.', 'signal-and-noise-tools' ), 'colo' );
	$out      .= dim_table( __( 'Time zones', 'signal-and-noise-tools' ), $read( 'timezone' ), __( 'No timezone data yet (needs worker v1.7.0 + traffic).', 'signal-and-noise-tools' ), 'timezone' );
	return $out . '</div>';
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/geography'] = __NAMESPACE__ . '\\paint_view_geography';
		return $painters;
	}
);
