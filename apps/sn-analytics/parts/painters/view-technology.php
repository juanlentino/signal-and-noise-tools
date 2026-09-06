<?php
/**
 * S&N Analytics — view/technology.
 *
 * Classic: snt_analytics_render_view_technology() in inc/analytics-view-technology.php.
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
function paint_view_technology( array $ctx ) {
	$from  = (string) $ctx['from'];
	$to    = (string) $ctx['to'];
	$class = (string) $ctx['class'];
	$read  = static function ( $dim ) use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( $dim, $from, $to, $class, 10 ) : array();
	};
	$out   = '<div class="snt-grid">';
	$out  .= dim_table( __( 'Browsers', 'signal-and-noise-tools' ), $read( 'browser' ), __( 'No browser data in this range yet.', 'signal-and-noise-tools' ), 'browser' );
	$out  .= dim_table( __( 'Operating systems', 'signal-and-noise-tools' ), $read( 'os' ), __( 'No OS data in this range yet.', 'signal-and-noise-tools' ), 'os' );
	$out  .= dim_table( __( 'Devices', 'signal-and-noise-tools' ), $read( 'device' ), __( 'No device data in this range.', 'signal-and-noise-tools' ), 'device' );
	$out  .= dim_table( __( 'Protocols', 'signal-and-noise-tools' ), $read( 'protocol' ), __( 'No protocol data in this range yet.', 'signal-and-noise-tools' ), 'protocol' );
	$out  .= dim_table( __( 'TLS versions', 'signal-and-noise-tools' ), $read( 'tls' ), __( 'No TLS data in this range yet.', 'signal-and-noise-tools' ), 'tls' );
	return $out . '</div>';
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/technology'] = __NAMESPACE__ . '\\paint_view_technology';
		return $painters;
	}
);
