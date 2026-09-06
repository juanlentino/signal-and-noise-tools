<?php
/**
 * S&N Analytics — view/quality.
 *
 * Classic: snt_analytics_render_view_quality() in inc/analytics-view-quality.php.
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
function paint_view_quality( array $ctx ) {
	$from        = (string) $ctx['from'];
	$to          = (string) $ctx['to'];
	$class       = (string) $ctx['class'];
	$granularity = (string) $ctx['granularity'];
	$series      = function_exists( 'sn_analytics_class_series' ) ? (array) sn_analytics_class_series( $from, $to, $granularity ) : array();
	$breakdown   = function_exists( 'sn_analytics_bot_breakdown' ) ? sn_analytics_bot_breakdown( $from, $to ) : array();
	$confidence  = function_exists( 'sn_analytics_distribution' ) ? sn_analytics_distribution( 'botscore', $from, $to, $class ) : array();
	$trend_rows  = array();
	foreach ( $series as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$trend_rows[] = array(
			'day'   => (string) ( $row['day'] ?? '' ),
			'views' => (int) ( $row['bot'] ?? $row['views'] ?? 0 ),
		);
	}
	$break_rows = array();
	foreach ( (array) $breakdown as $row ) {
		if ( is_array( $row ) ) {
			$break_rows[] = $row;
		} elseif ( is_scalar( $row ) ) {
			$break_rows[] = array( 'label' => (string) $row, 'views' => 0 );
		}
	}
	return daily_histogram( $trend_rows, __( 'Bot share', 'signal-and-noise-tools' ) )
		. dim_table( __( 'Traffic quality', 'signal-and-noise-tools' ), $break_rows, __( 'No quality breakdown in this range yet.', 'signal-and-noise-tools' ) )
		. distribution_table( __( 'Bot confidence', 'signal-and-noise-tools' ), $confidence, __( 'No bot-confidence scores in this range: needs traffic recorded with Cloudflare Bot Management enabled (scores arrive as 1–99).', 'signal-and-noise-tools' ) );
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/quality'] = __NAMESPACE__ . '\\paint_view_quality';
		return $painters;
	}
);
