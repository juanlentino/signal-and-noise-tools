<?php
/**
 * S&N Analytics — view/campaigns.
 *
 * Classic: snt_analytics_render_view_campaigns() in inc/analytics-view-campaigns.php.
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
function paint_view_campaigns( array $ctx ) {
	$from      = (string) $ctx['from'];
	$to        = (string) $ctx['to'];
	$class     = (string) $ctx['class'];
	$campaigns = function_exists( 'sn_analytics_top_utm_campaigns' ) ? sn_analytics_top_utm_campaigns( $from, $to, $class, 25 ) : array();
	$sources   = function_exists( 'sn_analytics_top_utm_sources' ) ? sn_analytics_top_utm_sources( $from, $to, $class, 25 ) : array();
	if ( empty( $campaigns ) && empty( $sources ) ) {
		return \snt_kit_empty(
			__( 'No campaign visits in this range', 'signal-and-noise-tools' ),
			__( 'Campaign attribution aggregates visits whose landing URL carried utm_source, utm_medium, or utm_campaign tags. Tag links with ?utm_source=…&utm_medium=…&utm_campaign=… to track them cookielessly.', 'signal-and-noise-tools' ),
			'chart-area'
		);
	}
	$intro     = '<p class="snt-prose">' . \snt_kit_esc( __( 'Campaign attribution: visits whose landing URL carried utm_source / utm_medium / utm_campaign tags. Cookieless: only the five named utm_* params are read, never the raw query string.', 'signal-and-noise-tools' ) ) . '</p>';
	return $intro
		. '<div class="snt-grid">'
		. dim_table( __( 'Campaigns', 'signal-and-noise-tools' ), $campaigns, __( 'No campaigns in this range. Tag a link with ?utm_source=…&utm_medium=…&utm_campaign=… and campaign visits will appear here.', 'signal-and-noise-tools' ) )
		. dim_table( __( 'Source / Medium', 'signal-and-noise-tools' ), $sources, __( 'No campaign sources in this range.', 'signal-and-noise-tools' ) )
		. '</div>';
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/campaigns'] = __NAMESPACE__ . '\\paint_view_campaigns';
		return $painters;
	}
);
