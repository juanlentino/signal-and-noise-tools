<?php
/**
 * S&N Analytics — chrome/drilldown: top pages for dim=value, plus Clear.
 *
 * Classic: snt_analytics_render_drilldown_panel() in inc/analytics-render-drilldown.php.
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
function paint_chrome_drilldown( array $ctx ) {
	$drill = $ctx['drill'] ?? null;
	if ( ! is_array( $drill ) || ! isset( $drill[0], $drill[1] ) ) {
		return '';
	}
	$dim   = (string) $drill[0];
	$value = (string) $drill[1];
	$labels = array(
		'referrer' => __( 'Referrer', 'signal-and-noise-tools' ),
		'country'  => __( 'Country', 'signal-and-noise-tools' ),
		'device'   => __( 'Device', 'signal-and-noise-tools' ),
		'browser'  => __( 'Browser', 'signal-and-noise-tools' ),
		'os'       => __( 'OS', 'signal-and-noise-tools' ),
		'region'   => __( 'Region', 'signal-and-noise-tools' ),
		'city'     => __( 'City', 'signal-and-noise-tools' ),
		'network'  => __( 'Network', 'signal-and-noise-tools' ),
		'colo'     => __( 'Edge location', 'signal-and-noise-tools' ),
		'protocol' => __( 'Protocol', 'signal-and-noise-tools' ),
		'tls'      => __( 'TLS', 'signal-and-noise-tools' ),
	);
	$label = $labels[ $dim ] ?? ucfirst( $dim );
	$rows  = function_exists( 'sn_analytics_drilldown' )
		? sn_analytics_drilldown( $dim, $value, (string) $ctx['from'], (string) $ctx['to'], (string) $ctx['class'] )
		: array();
	$title = sprintf(
		/* translators: 1: dimension label, 2: drilled value. */
		__( 'Top pages · %1$s = %2$s', 'signal-and-noise-tools' ),
		$label,
		$value
	);
	$clear = pick( '← ' . __( 'Clear drill-down', 'signal-and-noise-tools' ), 'drill', '', false );
	$table_rows = array();
	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$table_rows[] = array(
			'path'   => (string) ( $row['path'] ?? '' ),
			'views'  => num( $row['views'] ?? 0 ),
			'visits' => num( $row['visits'] ?? 0 ),
		);
	}
	$inner = $clear;
	$inner .= array() === $table_rows
		? \snt_kit_empty( '', __( 'No pages for this segment in this range (or it needs live Analytics Engine data).', 'signal-and-noise-tools' ) )
		: \snt_kit_table(
			array(
				array( 'key' => 'path', 'label' => __( 'Page', 'signal-and-noise-tools' ) ),
				array( 'key' => 'views', 'label' => __( 'Views', 'signal-and-noise-tools' ), 'align' => 'end' ),
				array( 'key' => 'visits', 'label' => __( 'Visits', 'signal-and-noise-tools' ), 'align' => 'end' ),
			),
			$table_rows
		);
	return \snt_kit_section( $title, $inner );
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['chrome/drilldown'] = __NAMESPACE__ . '\\paint_chrome_drilldown';
		return $painters;
	}
);
