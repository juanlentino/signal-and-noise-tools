<?php
/**
 * S&N Analytics — view/events.
 *
 * Classic: snt_analytics_render_view_events() in inc/analytics-view-events.php.
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
function paint_view_events( array $ctx ) {
	$from    = (string) $ctx['from'];
	$to      = (string) $ctx['to'];
	$prop    = (string) ( $ctx['event_prop'] ?? '' );
	$events  = function_exists( 'sn_analytics_top_events' ) ? sn_analytics_top_events( $from, $to, 25 ) : array();
	$props   = function_exists( 'sn_analytics_top_event_props' ) ? sn_analytics_top_event_props( $from, $to, $prop, 50 ) : array();
	$note    = ( ! empty( $events ) || ! empty( $props ) || '' !== $prop )
		? '<p class="snt-hint">' . \snt_kit_esc( __( 'Custom events are not segmented by traffic class: the class filter above does not apply to this view.', 'signal-and-noise-tools' ) ) . '</p>'
		: '';
	$clear   = '' !== $prop ? pick( __( 'Clear property', 'signal-and-noise-tools' ), 'event_prop', '', false ) : '';
	$prop_rows = array();
	foreach ( (array) $props as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$name = (string) ( $row['property'] ?? '' );
		$prop_rows[] = array(
			'value'  => $name . ' · ' . (string) ( $row['value'] ?? '' ),
			'views'  => $row['events'] ?? 0,
			'visits' => $row['visitors'] ?? 0,
			'name'   => $name,
		);
	}
	$prop_links = '';
	if ( '' === $prop ) {
		$seen = array();
		foreach ( $prop_rows as $row ) {
			if ( isset( $seen[ $row['name'] ] ) || '' === $row['name'] ) {
				continue;
			}
			$seen[ $row['name'] ] = true;
			$prop_links .= pick( $row['name'], 'event_prop', $row['name'], false );
		}
	}
	return $note
		. '<div class="snt-grid">'
		. dim_table( __( 'Custom events', 'signal-and-noise-tools' ), $events, __( 'No custom events in this range yet.', 'signal-and-noise-tools' ) )
		. \snt_kit_section(
			__( 'Event properties', 'signal-and-noise-tools' ),
			$clear . $prop_links . dim_table( __( 'Event properties', 'signal-and-noise-tools' ), $prop_rows, __( 'No event properties in this range yet.', 'signal-and-noise-tools' ) )
		)
		. '</div>';
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/events'] = __NAMESPACE__ . '\\paint_view_events';
		return $painters;
	}
);
