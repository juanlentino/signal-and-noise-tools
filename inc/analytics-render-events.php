<?php
/**
 * Signal & Noise — Analytics custom-event panels: the events leaderboard and the
 * event-property breakdown (with its Lane-A ?sn_event_prop drill-down). Durable
 * reads (no AE, no traffic-class dimension). Native wp-admin markup via the panel
 * primitive. Extracted from analytics-admin-render.php (v8.9.x split).
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // panel chrome + empty-fold collector

/**
 * Custom-events leaderboard panel (event name → events / visitors). Durable read
 * (wp_sn_analytics_events, shipped v6.2.0). Custom events carry NO traffic-class
 * dimension, so the global Human/Suspect/Bot control does not apply here — the
 * Events view renders an explicit note to that effect (see snt_analytics_render_dashboard).
 *
 * @param array $rows [{name,events,visitors}]
 */
function snt_analytics_render_events_table( $rows ) {
	if ( empty( $rows ) ) {
		snt_an_note_empty( __( 'Custom events', 'signal-and-noise-tools' ), __( 'No custom events in this range yet.', 'signal-and-noise-tools' ) );
		return;
	}
	snt_an_panel_open( __( 'Custom events', 'signal-and-noise-tools' ), array( 'inside_class' => 'inside sn-an-table-inside' ) );
	echo '<table class="widefat striped"><thead><tr>'
		. '<th scope="col" class="manage-column column-primary">' . esc_html__( 'Event', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="manage-column num">' . esc_html__( 'Events', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="manage-column num">' . esc_html__( 'Visitors', 'signal-and-noise-tools' ) . '</th>'
		. '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr>'
			. '<td class="column-primary"><strong>' . esc_html( (string) $r['name'] ) . '</strong></td>'
			. '<td class="num" data-colname="Events">' . esc_html( number_format_i18n( (int) $r['events'] ) ) . '</td>'
			. '<td class="num" data-colname="Visitors">' . esc_html( number_format_i18n( (int) $r['visitors'] ) ) . '</td>'
			. '</tr>';
	}
	echo '</tbody></table>';
	snt_an_panel_close();
}

/**
 * Custom-event property breakdown (property · value → events / visitors) with a
 * Lane-A drill-down: each property is a link to ?sn_event_prop=<name> (a server
 * reload that filters this panel to one property). Durable read
 * (wp_sn_analytics_event_props) — property+value are co-present in one table, so
 * this filter is a genuine durable query (unlike cross-tab dimension drill-down,
 * which needs the AE source). When filtered, the Property column collapses to a
 * heading + Clear link.
 *
 * @param array  $rows        [{property,value,events,visitors}]
 * @param string $active_prop The ?sn_event_prop filter, or '' for all properties.
 */
function snt_analytics_render_event_props_table( $rows, $active_prop = '' ) {
	$filtered = ( '' !== (string) $active_prop );
	// D4 §4: an UNFILTERED empty folds into the view's collector. A FILTERED
	// empty (?sn_event_prop active) is the carve-out — it stays an OPEN panel
	// so the active-property heading + Clear escape hatch survive (folding
	// would strand the user with no in-UI way back to the unfiltered table).
	if ( empty( $rows ) && ! $filtered ) {
		snt_an_note_empty( __( 'Event properties', 'signal-and-noise-tools' ), __( 'No event properties in this range yet.', 'signal-and-noise-tools' ) );
		return;
	}
	snt_an_panel_open( __( 'Event properties', 'signal-and-noise-tools' ), array( 'inside_class' => 'inside sn-an-table-inside' ) );
	if ( $filtered ) {
		$clear = remove_query_arg( 'sn_event_prop', add_query_arg( array() ) );
		echo '<p class="sn-an-subh sn-an-subh--panel">' . esc_html__( 'Property:', 'signal-and-noise-tools' ) . ' <strong>' . esc_html( (string) $active_prop ) . '</strong> · '
			. '<a href="' . esc_url( $clear ) . '">' . esc_html__( 'Clear', 'signal-and-noise-tools' ) . '</a></p>';
	}
	if ( empty( $rows ) ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">' . esc_html__( 'No event properties in this range yet.', 'signal-and-noise-tools' ) . '</p>';
		snt_an_panel_close();
		return;
	}
	echo '<table class="widefat striped"><thead><tr>';
	if ( ! $filtered ) {
		echo '<th scope="col" class="manage-column">' . esc_html__( 'Property', 'signal-and-noise-tools' ) . '</th>';
	}
	echo '<th scope="col" class="manage-column column-primary">' . esc_html__( 'Value', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="manage-column num">' . esc_html__( 'Events', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="manage-column num">' . esc_html__( 'Visitors', 'signal-and-noise-tools' ) . '</th>'
		. '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr>';
		if ( ! $filtered ) {
			$prop = (string) $r['property'];
			$url  = add_query_arg( array( 'sn_event_prop' => $prop ) );
			echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( $prop ) . '</a></td>';
		}
		echo '<td class="column-primary">' . esc_html( (string) $r['value'] ) . '</td>'
			. '<td class="num" data-colname="Events">' . esc_html( number_format_i18n( (int) $r['events'] ) ) . '</td>'
			. '<td class="num" data-colname="Visitors">' . esc_html( number_format_i18n( (int) $r['visitors'] ) ) . '</td>'
			. '</tr>';
	}
	echo '</tbody></table>';
	snt_an_panel_close();
}
