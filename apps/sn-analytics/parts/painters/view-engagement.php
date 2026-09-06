<?php
/**
 * S&N Analytics — view/engagement.
 *
 * Classic: snt_analytics_render_view_engagement() in inc/analytics-view-engagement.php.
 * The hour/day heatmap has no kit counterpart; the distributions are the readout.
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
function paint_view_engagement( array $ctx ) {
	$from = (string) $ctx['from'];
	$to   = (string) $ctx['to'];
	$class = (string) $ctx['class'];
	$dist  = static function ( $metric ) use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_distribution' ) ? sn_analytics_distribution( $metric, $from, $to, $class ) : array();
	};
	$out   = '<div class="snt-grid">';
	$out  .= distribution_table( __( 'Scroll depth', 'signal-and-noise-tools' ), $dist( 'scroll' ), __( 'No scroll-depth data in this range yet.', 'signal-and-noise-tools' ) );
	$out  .= distribution_table( __( 'Time on page', 'signal-and-noise-tools' ), $dist( 'time' ), __( 'No time-on-page data in this range yet.', 'signal-and-noise-tools' ) );
	$out  .= distribution_table( __( 'Connection RTT', 'signal-and-noise-tools' ), $dist( 'rtt' ), __( 'No TCP round-trips in this range. HTTP/3 connections carry no RTT, so only HTTP/1–2 visitors are measured (needs worker v1.7.0 + traffic).', 'signal-and-noise-tools' ) );
	$out  .= '</div>';
	$cwv_empty = __( 'No field Core Web Vitals yet: needs the web-vitals beacon (theme v10.14.0) + worker v1.8.0 + traffic.', 'signal-and-noise-tools' );
	$out      .= '<div class="snt-grid">';
	$out      .= distribution_table( __( 'LCP (field)', 'signal-and-noise-tools' ), $dist( 'lcp' ), $cwv_empty );
	$out      .= distribution_table( __( 'INP (field)', 'signal-and-noise-tools' ), $dist( 'inp' ), $cwv_empty );
	$out      .= distribution_table( __( 'CLS (field)', 'signal-and-noise-tools' ), $dist( 'cls' ), $cwv_empty );
	return $out . '</div>';
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/engagement'] = __NAMESPACE__ . '\\paint_view_engagement';
		return $painters;
	}
);
