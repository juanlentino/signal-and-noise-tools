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
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-card sn-card--narrow">';
	wp_nonce_field( 'sn_cf_monitor_refresh' );
	if ( is_array( $record ) && ! empty( $record['configured'] ) ) {
		echo '<p class="sn-field-helper">' . esc_html( sprintf( 'Read %s. Refresh reads the token, the edge and the firewall again.', wp_date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), (int) $record['fetched_at'] ) ) ) . '</p>';
	} else {
		echo '<p class="sn-field-helper">' . esc_html( sn_cf_monitor_refresh_sentence( 'never_run', $configured ) ) . '</p>';
	}
	echo '<button type="submit" name="action" value="sn_cf_monitor_refresh" class="button"' . ( $configured ? '' : ' disabled' ) . '>Refresh now</button>';
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
				array( 'label' => 'Served from cache', 'value' => null === ( $tt['cache_share'] ?? null ) ? __( 'not read', 'signal-and-noise-tools' ) : number_format_i18n( (float) $tt['cache_share'], 1 ) . '%' ),
				array( 'label' => 'Bytes', 'value' => size_format( (int) $tt['bytes'] ) ),
				array( 'label' => 'Threats', 'value' => number_format_i18n( (int) $tt['threats'] ) ),
				// The native tile's toned caption, in the same words (2026-10-12).
				array( 'label' => '5xx at the edge', 'value' => number_format_i18n( (int) $tt['status_5xx'] ), 'pill' => (int) $tt['status_5xx'] > 0 ? array( 'kind' => 'warn', 'text' => __( 'needs a look', 'signal-and-noise-tools' ) ) : null ),
			) );
		}
		$codes = (array) ( $tt['status_5xx_codes'] ?? array() );
		if ( array() !== $codes ) {
			// 2026-10-16 (owner: an unlabeled wall of codes): the columns say what they are.
			echo '<table class="widefat" style="width:auto;min-width:16em"><caption class="screen-reader-text">' . esc_html__( '5xx by status code', 'signal-and-noise-tools' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Status', 'signal-and-noise-tools' ) . '</th><th scope="col">' . esc_html__( 'Count', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
			foreach ( $codes as $code => $n ) {
				echo '<tr><th scope="row">' . esc_html( (string) $code ) . '</th><td>' . esc_html( number_format_i18n( (int) $n ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	} elseif ( ! empty( $z['needs_permission'] ) ) {
		wp_admin_notice( esc_html( 'Zone analytics: ' . ( function_exists( 'sn_cf_monitor_permission_hint' ) ? sn_cf_monitor_permission_hint() : '' ) ), array( 'type' => 'warning', 'additional_classes' => array( 'inline' ) ) );
	} else {
		wp_admin_notice( esc_html( 'Zone analytics could not be read: ' . (string) ( $z['error'] ?? '' ) ), array( 'type' => 'error', 'additional_classes' => array( 'inline' ) ) );
	}
	if ( function_exists( 'sn_cf_workers_card_render' ) ) {
		sn_cf_workers_card_render( $record );
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
		sn_admin_firewall_pinning_render(); // the posture does not wait for Cloudflare.
		return;
	}
	$f = is_array( $record['firewall'] ) ? $record['firewall'] : array();
	if ( ! empty( $f['available'] ) ) {
		echo '<div class="sn-fieldset sn-fieldset--wide"><h2 class="sn-fieldset-h">' . esc_html( number_format_i18n( (int) $f['events'] ) . ' events, 24 hours' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Action</th><th>Events</th></tr></thead><tbody>';
		foreach ( (array) $f['by_action'] as $action => $n ) {
			echo '<tr><td>' . esc_html( sn_cf_action_word( (string) $action ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $n ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! empty( $f['top_rules'] ) ) {
			echo '<h3 class="sn-fieldset-h">Top rules</h3><table class="widefat striped"><thead><tr><th>Rule</th><th>Action</th><th>Events</th></tr></thead><tbody>';
			foreach ( (array) $f['top_rules'] as $r ) {
				$name = function_exists( 'sn_cf_posture_rule_name' ) ? sn_cf_posture_rule_name( (string) $r['rule'] ) : ''; // 15.4.0: by name when the ruleset was read.
				echo '<tr><td' . ( '' !== $name ? '' : ' class="sn-mono"' ) . '>' . esc_html( '' !== $name ? $name : ( trim( (string) $r['source'] . ' ' . (string) $r['rule'] ) ?: '(unnamed)' ) ) . '</td><td>' . esc_html( sn_cf_action_word( (string) $r['action'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $r['count'] ) ) . '</td></tr>';
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
		wp_admin_notice( esc_html( 'Firewall events: ' . ( function_exists( 'sn_cf_monitor_permission_hint' ) ? sn_cf_monitor_permission_hint( 'firewall' ) : '' ) . ( '' !== (string) ( $f['error'] ?? '' ) ? ' The API said: "' . (string) $f['error'] . '"' : '' ) . ( '' !== (string) ( $f['error_raw'] ?? '' ) ? ' The raw dataset said: "' . (string) $f['error_raw'] . '"' : '' ) ), array( 'type' => 'warning', 'additional_classes' => array( 'inline' ) ) );
	} else {
		wp_admin_notice( esc_html( 'Firewall events could not be read: ' . (string) ( $f['error'] ?? '' ) ), array( 'type' => 'error', 'additional_classes' => array( 'inline' ) ) );
	}
	sn_cf_posture_card_render();
	sn_cf_readings_refresh_form( $record );
	sn_admin_firewall_pinning_render();
}
add_action( 'sn_admin_firewall_tab', 'sn_admin_firewall_render' );

/**
 * Outbound pinning, classic (2026-10-12 audit: native-only until then): the
 * SSRF guard's verdict and the two facts it is derived from, the native box's
 * words (security-firewall.php).
 */
function sn_admin_firewall_pinning_render() {
	if ( ! function_exists( 'sn_ssrf_pinning_health' ) || ! function_exists( 'sn_ssrf_pinning_available' ) ) {
		return;
	}
	$last      = get_option( 'sn_ssrf_unpinned_last', null );
	$last      = is_array( $last ) && ! empty( $last['at'] ) ? $last : null;
	$available = (bool) sn_ssrf_pinning_available();
	$v         = (array) sn_ssrf_pinning_health( $available, $last );
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Outbound pinning', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p class="sn-field-helper">' . esc_html__( 'Whether outbound requests are bound to the addresses the SSRF guard validated, so a DNS answer that changes between validation and connect cannot be followed.', 'signal-and-noise-tools' ) . '</p>';
	echo '<p>' . ( 'good' === (string) ( $v['status'] ?? '' ) ? '' : '<strong>' ) . esc_html( (string) ( $v['summary'] ?? '' ) ) . ( 'good' === (string) ( $v['status'] ?? '' ) ? '' : '</strong>' ) . '</p>';
	echo '<table class="widefat striped"><tbody>';
	echo '<tr><th scope="row">' . esc_html__( 'cURL transport', 'signal-and-noise-tools' ) . '</th><td>' . esc_html( $available ? __( 'present, the pin fires on every outbound request', 'signal-and-noise-tools' ) : __( 'absent, so requests fall back to fsockopen unpinned', 'signal-and-noise-tools' ) ) . '</td></tr>';
	/* translators: 1: host name, 2: how long ago. */
	echo '<tr><th scope="row">' . esc_html__( 'Last unpinned request', 'signal-and-noise-tools' ) . '</th><td>' . esc_html( null === $last ? __( 'none recorded', 'signal-and-noise-tools' ) : sprintf( __( '%1$s, %2$s ago', 'signal-and-noise-tools' ), (string) ( $last['host'] ?? 'unknown' ), human_time_diff( (int) $last['at'], time() ) ) ) . '</td></tr>';
	echo '</tbody></table></div>';
}

/**
 * Edge posture, classic (15.4.0): the same model the native leaf paints.
 * Judged rows first with their verdict, the readings as one line, the custom
 * rules by name with their state; a refused read is a notice naming the scope.
 */
function sn_cf_posture_card_render() {
	if ( ! function_exists( 'sn_cf_posture_model' ) ) {
		return;
	}
	$m = sn_cf_posture_model();
	echo '<div class="sn-fieldset sn-fieldset--wide"><h2 class="sn-fieldset-h">Edge posture</h2>';
	echo '<p class="sn-field-helper">What the edge is set to, read daily with the firewall.</p>';
	if ( 'read' !== $m['state'] ) {
		echo '<p class="sn-field-helper">Not read yet; it reads with Refresh now.</p></div>';
		return;
	}
	foreach ( $m['refused'] as $why ) {
		wp_admin_notice( esc_html( $why ), array( 'type' => 'warning', 'additional_classes' => array( 'inline' ) ) );
	}
	if ( array() !== $m['checks'] ) {
		echo '<table class="widefat striped"><thead><tr><th>Setting</th><th>Value</th><th>Verdict</th></tr></thead><tbody>';
		foreach ( $m['checks'] as $c ) {
			echo '<tr><td>' . esc_html( $c['label'] ) . '</td><td>' . esc_html( sn_cf_posture_check_text( $c ) ) . '</td><td>' . ( $c['ok'] ? 'ok' : '<strong>drift</strong>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	if ( array() !== $m['also'] ) {
		$bits = array();
		foreach ( $m['also'] as $a ) {
			$bits[] = $a['label'] . ' ' . $a['value'];
		}
		echo '<p class="sn-field-helper">' . esc_html( 'Also set: ' . implode( ' · ', $bits ) . '.' ) . '</p>';
	}
	if ( array() !== $m['rules'] ) {
		echo '<h3 class="sn-fieldset-h">Custom rules</h3><table class="widefat striped"><thead><tr><th>Rule</th><th>Action</th><th>State</th></tr></thead><tbody>';
		foreach ( $m['rules'] as $r ) {
			echo '<tr><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( $r['action'] ) . '</td><td>' . ( $r['enabled'] ? 'enabled' : '<strong>disabled</strong>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}
