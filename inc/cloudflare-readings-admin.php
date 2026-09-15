<?php
/**
 * Signal & Noise Tools — the Cloudflare monitor's readings on the classic
 * leaves where their questions are asked (15.3.0): Edge, 7 days as a card at
 * the top of Measurement › Analytics; Firewall, 24 hours as Security ›
 * Firewall. Both paint what inc/cloudflare-monitor.php and
 * inc/cloudflare-firewall-events.php stored; painting never fetches. The
 * native leaves paint the same record (connections-cloudflare-monitor.php).
 *
 * @package SignalNoiseTools
 * @since 15.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stored monitor record, or null.
 *
 * @return array<string,mixed>|null
 */
function sn_cf_readings_record() {
	return function_exists( 'sn_cf_monitor_read' ) ? sn_cf_monitor_read() : null;
}

/**
 * The Refresh form both classic leaves share: when the record was read, the
 * button, the rate-limit note.
 *
 * @param array<string,mixed>|null $record Stored record.
 * @return void
 */
function sn_cf_readings_refresh_form( $record ) {
	$configured = function_exists( 'sn_cf_is_configured' ) && sn_cf_is_configured();
	echo '<form method="post" class="sn-card sn-card--narrow">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	if ( is_array( $record ) && ! empty( $record['configured'] ) ) {
		echo '<p class="sn-field-helper">' . esc_html( sprintf( 'Read %s. Refresh reads the token, the edge and the firewall again.', wp_date( 'Y-m-d H:i T', (int) $record['fetched_at'] ) ) ) . '</p>';
	} else {
		echo '<p class="sn-field-helper">The monitor has not run yet. It runs daily; press Refresh to run it now.</p>';
	}
	echo '<button type="submit" name="sn_action" value="cf_monitor_refresh" class="button"' . ( $configured ? '' : ' disabled' ) . '>Refresh now</button>';
	echo '</form>';
}

/**
 * Edge, 7 days: the five figures and the 5xx split, classic. Echoes nothing
 * when the monitor never ran or is unconfigured, so the Analytics hub stays
 * as it was on a site without Cloudflare.
 *
 * @return void
 */
function sn_cf_edge_card_render() {
	$record = sn_cf_readings_record();
	if ( ! is_array( $record ) || empty( $record['configured'] ) ) {
		return;
	}
	$z = is_array( $record['zone'] ) ? $record['zone'] : array();
	echo '<div class="sn-fieldset sn-fieldset--wide"><h2 class="sn-fieldset-h">Edge, 7 days</h2>';
	if ( ! empty( $z['available'] ) ) {
		$tt = (array) $z['totals'];
		if ( function_exists( 'sn_admin_glance_grid' ) ) {
			sn_admin_glance_grid( array(
				array( 'label' => 'Requests, 7 days', 'value' => number_format_i18n( (int) $tt['requests'] ) ),
				array( 'label' => 'Served from cache', 'value' => null === ( $tt['cache_share'] ?? null ) ? '—' : number_format_i18n( (float) $tt['cache_share'], 1 ) . '%' ),
				array( 'label' => 'Bytes', 'value' => size_format( (int) $tt['bytes'] ) ),
				array( 'label' => 'Threats', 'value' => number_format_i18n( (int) $tt['threats'] ) ),
				array( 'label' => '5xx at the edge', 'value' => number_format_i18n( (int) $tt['status_5xx'] ) ),
			) );
		}
		$codes = (array) ( $tt['status_5xx_codes'] ?? array() );
		if ( array() !== $codes ) {
			echo '<table class="widefat"><tbody>';
			foreach ( $codes as $code => $n ) {
				echo '<tr><th scope="row">' . esc_html( (string) $code ) . '</th><td>' . esc_html( number_format_i18n( (int) $n ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	} elseif ( ! empty( $z['needs_permission'] ) ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html( 'Zone analytics: ' . ( function_exists( 'sn_cf_monitor_permission_hint' ) ? sn_cf_monitor_permission_hint() : '' ) ) . '</p></div>';
	} else {
		echo '<div class="notice notice-error inline"><p>' . esc_html( 'Zone analytics could not be read: ' . (string) ( $z['error'] ?? '' ) ) . '</p></div>';
	}
	sn_cf_readings_refresh_form( $record );
	echo '</div>';
}

/** Security → Firewall, classic: what Cloudflare stopped before WordPress ran. */
function sn_admin_firewall_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$record = sn_cf_readings_record();
	echo '<p class="sn-prose">What Cloudflare stopped before WordPress ran, from the zone\'s firewall log, read daily with the monitor.</p>';
	if ( ! is_array( $record ) || empty( $record['configured'] ) ) {
		echo '<p class="sn-field-helper">' . ( is_array( $record ) ? 'Not configured: set the Cloudflare token under Connections › Credentials.' : 'The monitor has not run yet.' ) . '</p>';
		sn_cf_readings_refresh_form( $record );
		return;
	}
	$f = is_array( $record['firewall'] ) ? $record['firewall'] : array();
	if ( ! empty( $f['available'] ) ) {
		echo '<div class="sn-fieldset sn-fieldset--wide"><h2 class="sn-fieldset-h">' . esc_html( number_format_i18n( (int) $f['events'] ) . ' events, 24 hours' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Action</th><th>Events</th></tr></thead><tbody>';
		foreach ( (array) $f['by_action'] as $action => $n ) {
			echo '<tr><td>' . esc_html( (string) $action ) . '</td><td>' . esc_html( number_format_i18n( (int) $n ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! empty( $f['top_rules'] ) ) {
			echo '<h3 class="sn-fieldset-h">Top rules</h3><table class="widefat striped"><thead><tr><th>Rule</th><th>Action</th><th>Events</th></tr></thead><tbody>';
			foreach ( (array) $f['top_rules'] as $r ) {
				echo '<tr><td class="sn-mono">' . esc_html( trim( (string) $r['source'] . ' ' . (string) $r['rule'] ) ?: '(unnamed)' ) . '</td><td>' . esc_html( (string) $r['action'] ) . '</td><td>' . esc_html( number_format_i18n( (int) $r['count'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$log = function_exists( 'sn_cf_firewall_events_read' ) ? sn_cf_firewall_events_read() : null;
		if ( is_array( $log ) && ! empty( $log['available'] ) && array() !== (array) $log['rows'] ) {
			foreach ( array( 'clientRequestPath' => 'Top paths acted on', 'clientCountryName' => 'Top countries acted on' ) as $field => $heading ) {
				echo '<h3 class="sn-fieldset-h">' . esc_html( $heading ) . '</h3><table class="widefat striped"><tbody>';
				foreach ( sn_cf_firewall_events_top( (array) $log['rows'], $field, 5 ) as $value => $n ) {
					echo '<tr><td class="sn-mono">' . esc_html( (string) $value ) . '</td><td>' . esc_html( number_format_i18n( (int) $n ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			if ( ! empty( $log['truncated'] ) ) {
				echo '<p class="sn-field-helper">The event log had more rows than one page holds; these are a floor.</p>';
			}
		}
		if ( 'raw' === (string) ( $f['dataset'] ?? '' ) ) {
			echo '<p class="sn-field-helper">Read from the raw firewallEventsAdaptive dataset and grouped here; the grouped dataset is not on this zone\'s plan.</p>';
		}
		echo '</div>';
	} elseif ( ! empty( $f['needs_permission'] ) ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html( 'Firewall events: ' . ( function_exists( 'sn_cf_monitor_permission_hint' ) ? sn_cf_monitor_permission_hint( 'firewall' ) : '' ) . ( '' !== (string) ( $f['error'] ?? '' ) ? ' The API said: "' . (string) $f['error'] . '"' : '' ) . ( '' !== (string) ( $f['error_raw'] ?? '' ) ? ' The raw dataset said: "' . (string) $f['error_raw'] . '"' : '' ) ) . '</p></div>';
	} else {
		echo '<div class="notice notice-error inline"><p>' . esc_html( 'Firewall events could not be read: ' . (string) ( $f['error'] ?? '' ) ) . '</p></div>';
	}
	sn_cf_readings_refresh_form( $record );
}
add_action( 'sn_admin_firewall_tab', 'sn_admin_firewall_render' );
