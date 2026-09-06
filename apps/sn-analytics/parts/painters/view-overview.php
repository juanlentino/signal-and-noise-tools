<?php
/**
 * S&N Analytics — view/overview.
 *
 * Classic: snt_analytics_render_view_overview() in inc/analytics-view-overview.php.
 * The landing body: mini tables + doorways onto the other views. KPIs live in
 * chrome/header, as the classic composer splits them.
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
function paint_view_overview( array $ctx ) {
	$from  = (string) $ctx['from'];
	$to    = (string) $ctx['to'];
	$class = (string) $ctx['class'];
	$now   = function_exists( 'sn_analytics_realtime' ) ? sn_analytics_realtime( $class ) : null;
	$today = function_exists( 'sn_analytics_views_today' ) ? sn_analytics_views_today() : null;
	$out   = stats(
		array(
			array( 'l' => __( 'Right now', 'signal-and-noise-tools' ), 'n' => null === $now ? '—' : num( $now ) ),
			array( 'l' => __( 'Views today', 'signal-and-noise-tools' ), 'n' => null === $today ? '—' : num( $today ), 'sub' => __( 'human', 'signal-and-noise-tools' ) ),
		)
	);
	$doors = '<div class="snt-toolbar__group">'
		. view_door( __( 'Content', 'signal-and-noise-tools' ), 'content' )
		. view_door( __( 'Campaigns', 'signal-and-noise-tools' ), 'campaigns' )
		. view_door( __( 'Geography', 'signal-and-noise-tools' ), 'geography' )
		. view_door( __( 'Technology', 'signal-and-noise-tools' ), 'technology' )
		. view_door( __( 'Sessions', 'signal-and-noise-tools' ), 'visits' )
		. '</div>';
	$sources   = function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $from, $to, $class, 5 ) : array();
	$campaigns = function_exists( 'sn_analytics_top_utm_campaigns' ) ? sn_analytics_top_utm_campaigns( $from, $to, $class, 5 ) : array();
	$countries = function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'country', $from, $to, $class, 5 ) : array();
	$devices   = function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'device', $from, $to, $class, 5 ) : array();
	$out      .= $doors . '<div class="snt-grid">';
	$out      .= dim_table( __( 'Sources', 'signal-and-noise-tools' ), $sources, __( 'No referrers in this range.', 'signal-and-noise-tools' ) );
	$out      .= dim_table( __( 'Campaigns', 'signal-and-noise-tools' ), $campaigns, __( 'No campaigns in this range.', 'signal-and-noise-tools' ) );
	$out      .= dim_table( __( 'Geography', 'signal-and-noise-tools' ), $countries, __( 'No country rows in the durable rollup for this range yet.', 'signal-and-noise-tools' ) );
	$out      .= dim_table( __( 'Devices', 'signal-and-noise-tools' ), $devices, __( 'No device rows in the durable rollup for this range yet.', 'signal-and-noise-tools' ) );
	return $out . '</div>';
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/overview'] = __NAMESPACE__ . '\\paint_view_overview';
		return $painters;
	}
);
