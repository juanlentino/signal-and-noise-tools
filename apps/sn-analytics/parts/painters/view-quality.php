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
	// The classic panel's figures (snt_analytics_render_bot_breakdown): the three
	// classes, the top bot networks, and the own tools beside them, in none.
	$totals     = is_array( $breakdown['totals'] ?? null ) ? $breakdown['totals'] : array();
	$break_rows = (int) ( $totals['total'] ?? 0 ) > 0 ? array(
		array( 'label' => __( 'Presumed human', 'signal-and-noise-tools' ), 'views' => (int) ( $totals['human'] ?? 0 ) ),
		array( 'label' => __( 'Suspect', 'signal-and-noise-tools' ), 'views' => (int) ( $totals['suspect'] ?? 0 ) ),
		array( 'label' => __( 'Bot', 'signal-and-noise-tools' ), 'views' => (int) ( $totals['bot'] ?? 0 ) ),
	) : array();
	$own = is_array( $breakdown ) && array_key_exists( 'first_party', $breakdown ) && function_exists( 'sn_lanes_first_party_line' )
		? '<p class="snt-hint">' . \snt_kit_esc( \sn_lanes_first_party_line( $breakdown['first_party'] ) ) . '</p>'
		: '';
	// 2026-11-11: the strict human figure beside the presumed-human one, the classic twin's words.
	$own .= is_array( $breakdown ) && array_key_exists( 'strict', $breakdown ) && function_exists( 'sn_analytics_strict_human_line' )
		? '<p class="snt-hint">' . \snt_kit_esc( \sn_analytics_strict_human_line( $breakdown['strict'] ) ) . '</p>'
		: '';
	return daily_histogram( $trend_rows, __( 'Bot share', 'signal-and-noise-tools' ) )
		. '<div class="snt-report-columns">'
		. dim_table( __( 'Traffic quality', 'signal-and-noise-tools' ), $break_rows, __( 'No quality breakdown in this range yet.', 'signal-and-noise-tools' ) )
		. dim_table( __( 'Top bot networks', 'signal-and-noise-tools' ), $breakdown['top_bot_networks'] ?? array(), __( 'No bot networks in this range.', 'signal-and-noise-tools' ) )
		. distribution_table( __( 'Bot confidence', 'signal-and-noise-tools' ), $confidence, __( 'No bot-confidence scores in this range: needs traffic recorded with Cloudflare Bot Management enabled (scores arrive as 1–99).', 'signal-and-noise-tools' ) )
		. '</div>' . $own;
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/quality'] = __NAMESPACE__ . '\\paint_view_quality';
		return $painters;
	}
);
