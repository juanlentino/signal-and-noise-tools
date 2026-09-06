<?php
/**
 * S&N Analytics — chrome/header: Overview KPIs + trend histogram.
 *
 * Classic: snt_analytics_render_header_region() minus the toolbar (the frame
 * paints chrome/controls separately). Same readers; kit stats + histogram.
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
 * @return array{html:string,totals:array}
 */
function paint_chrome_header( array $ctx ) {
	$from        = (string) ( $ctx['from'] ?? '' );
	$to          = (string) ( $ctx['to'] ?? '' );
	$class       = (string) ( $ctx['class'] ?? 'human' );
	$granularity = (string) ( $ctx['granularity'] ?? 'day' );
	$totals      = function_exists( 'sn_analytics_range_totals' ) ? (array) sn_analytics_range_totals( $from, $to, $class ) : array();
	$now         = function_exists( 'sn_analytics_realtime' ) ? sn_analytics_realtime( $class ) : null;
	$series      = function_exists( 'sn_analytics_daily_series' ) ? (array) sn_analytics_daily_series( $from, $to, $class, $granularity ) : array();
	$known       = static function ( $key ) use ( $totals ) {
		return array_key_exists( $key, $totals ) && null !== $totals[ $key ];
	};
	$caveat      = $known( 'exact_metrics_since' ) && is_string( $totals['exact_metrics_since'] )
		? sprintf( __( 'exact since %s', 'signal-and-noise-tools' ), $totals['exact_metrics_since'] )
		: __( 'no exact data yet', 'signal-and-noise-tools' );

	$cards   = array();
	$cards[] = array( 'l' => __( 'Views', 'signal-and-noise-tools' ), 'n' => num( $totals['views'] ?? 0 ) );
	$cards[] = $known( 'pageview_visits' )
		? array( 'l' => __( 'Visits', 'signal-and-noise-tools' ), 'n' => num( $totals['pageview_visits'] ) )
		: array( 'l' => __( 'Visits', 'signal-and-noise-tools' ), 'n' => '—', 'sub' => $caveat );
	$cards[] = array( 'l' => __( 'Now', 'signal-and-noise-tools' ), 'n' => null === $now ? '—' : num( $now ) );
	$cards[] = $known( 'scroll_avg_per_view' )
		? array( 'l' => __( 'Scroll / view', 'signal-and-noise-tools' ), 'n' => (int) round( (float) $totals['scroll_avg_per_view'] ) . '%' )
		: array( 'l' => __( 'Scroll / view', 'signal-and-noise-tools' ), 'n' => '—', 'sub' => $caveat );
	if ( is_array( $ctx['engaged'] ?? null ) && isset( $ctx['engaged']['current'] ) ) {
		$cards[] = array( 'l' => __( 'Engaged', 'signal-and-noise-tools' ), 'n' => (int) $ctx['engaged']['current'] . '%' );
	} elseif ( function_exists( 'sn_analytics_engaged_rate' ) ) {
		$engaged = sn_analytics_engaged_rate( $from, $to, $class );
		if ( is_numeric( $engaged ) ) {
			$cards[] = array( 'l' => __( 'Engaged', 'signal-and-noise-tools' ), 'n' => (int) $engaged . '%' );
		}
	}

	$html = \snt_kit_section(
		__( 'Overview', 'signal-and-noise-tools' ),
		stats( $cards ) . daily_histogram( $series, __( 'Views per day', 'signal-and-noise-tools' ) )
	);
	return array(
		'html'   => $html,
		'totals' => $totals,
	);
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['chrome/header'] = __NAMESPACE__ . '\\paint_chrome_header';
		return $painters;
	}
);
