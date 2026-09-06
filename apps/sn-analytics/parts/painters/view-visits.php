<?php
/**
 * S&N Analytics — view/visits (Sessions).
 *
 * Classic: snt_analytics_render_view_sessions() in inc/analytics-view-sessions.php.
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
function paint_view_visits( array $ctx ) {
	$from  = (string) $ctx['from'];
	$to    = (string) $ctx['to'];
	$class = (string) $ctx['class'];
	if ( ! function_exists( 'sn_analytics_fetch_session_events' ) ) {
		return \snt_kit_empty( __( 'Sessions', 'signal-and-noise-tools' ), __( 'Session analytics need live Analytics Engine data for this window.', 'signal-and-noise-tools' ) );
	}
	$data = sn_analytics_fetch_session_events( $from, $to, $class );
	if ( empty( $data['configured'] ) ) {
		return \snt_kit_empty( __( 'Sessions', 'signal-and-noise-tools' ), __( 'Session analytics need live Analytics Engine data for this window.', 'signal-and-noise-tools' ) );
	}
	$visits  = function_exists( 'sn_pageview_visits' ) ? sn_pageview_visits( $data['summaries'] ?? array() ) : array();
	$metrics = function_exists( 'sn_session_metrics' ) ? sn_session_metrics( $visits ) : array();
	$paths   = function_exists( 'sn_session_paths' ) ? sn_session_paths( $visits, 15 ) : array();
	$trend   = function_exists( 'sn_session_rollup_read' ) ? sn_session_rollup_read( $from, $to, $class ) : array();
	$cards   = array();
	foreach ( array(
		'sessions'    => __( 'Sessions', 'signal-and-noise-tools' ),
		'bounce_pct'  => __( 'Bounce', 'signal-and-noise-tools' ),
		'ppv'         => __( 'Pages / visit', 'signal-and-noise-tools' ),
		'median_dur'  => __( 'Median duration', 'signal-and-noise-tools' ),
	) as $key => $label ) {
		if ( isset( $metrics[ $key ] ) ) {
			$n = $metrics[ $key ];
			if ( 'bounce_pct' === $key ) {
				$n = (int) round( (float) $n ) . '%';
			} elseif ( is_float( $n ) ) {
				$n = function_exists( 'number_format_i18n' ) ? number_format_i18n( $n, 1 ) : (string) $n;
			} else {
				$n = num( $n );
			}
			$cards[] = array( 'l' => $label, 'n' => $n );
		}
	}
	$path_rows = array();
	foreach ( (array) $paths as $row ) {
		if ( is_array( $row ) ) {
			$path_rows[] = array(
				'value'  => (string) ( $row['path'] ?? $row['value'] ?? '' ),
				'views'  => $row['sessions'] ?? $row['views'] ?? 0,
				'visits' => $row['visits'] ?? null,
			);
		}
	}
	$hist_rows = array();
	foreach ( (array) $trend as $row ) {
		if ( is_array( $row ) ) {
			$hist_rows[] = array( 'day' => (string) ( $row['day'] ?? '' ), 'views' => (int) ( $row['sessions'] ?? $row['visits'] ?? $row['views'] ?? 0 ) );
		}
	}
	return stats( $cards )
		. daily_histogram( $hist_rows, __( 'Sessions', 'signal-and-noise-tools' ) )
		. dim_table( __( 'Paths', 'signal-and-noise-tools' ), $path_rows, __( 'No session paths in this range yet.', 'signal-and-noise-tools' ) );
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/visits'] = __NAMESPACE__ . '\\paint_view_visits';
		return $painters;
	}
);
