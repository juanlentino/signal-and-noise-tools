<?php
/**
 * S&N Dashboard — Connections › Cloudflare: the monitor's three readings.
 *
 * Paints what inc/cloudflare-monitor.php stored: token status and expiry,
 * the zone's last seven days, the firewall's last 24 hours. A reading the
 * token cannot make paints the permission to add, never a zero. The
 * Refresh button posts `cf_monitor_refresh` through the shared handler
 * table; painting never fetches.
 *
 * @package SignalNoiseTools
 * @since 14.9.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * @param array<string,mixed> $d From cloudflare_data().
 * @return string
 */
function cloudflare_monitor_html( array $d ) {
	$record = function_exists( 'sn_cf_monitor_read' ) ? sn_cf_monitor_read() : null;
	$now    = time();
	$inner  = '';

	if ( ! is_array( $record ) ) {
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'The monitor has not run yet. It runs daily; press Refresh to run it now.', 'signal-and-noise-tools' ) ) . '</p>';
	} elseif ( empty( $record['configured'] ) ) {
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Not configured: set the API token and zone ID above.', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$when   = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i T', (int) $record['fetched_at'] ) : gmdate( 'Y-m-d H:i', (int) $record['fetched_at'] ) . ' UTC';
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( /* translators: %s: time. */ __( 'Read %s.', 'signal-and-noise-tools' ), $when ) ) . '</p>';

		// ── Token
		$t = is_array( $record['token'] ) ? $record['token'] : array();
		if ( ! empty( $t['verified'] ) ) {
			$exp_ts = '' !== (string) $t['expires_on'] ? strtotime( (string) $t['expires_on'] ) : 0;
			$exp    = $exp_ts ? ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $exp_ts ) : gmdate( 'Y-m-d', $exp_ts ) ) : __( 'never', 'signal-and-noise-tools' );
			$tone   = 'active' === (string) $t['status'] ? ( $exp_ts && $exp_ts < $now + 14 * DAY_IN_SECONDS ? 'warn' : 'ok' ) : 'err';
			$inner .= \snt_kit_list( array(
				array( 'label' => __( 'Token', 'signal-and-noise-tools' ), 'value' => (string) $t['status'], 'tone' => $tone ),
				array( 'label' => __( 'Expires', 'signal-and-noise-tools' ), 'value' => $exp ),
			) );
		} else {
			$inner .= \snt_kit_notice( 'error', \snt_kit_esc( sprintf( /* translators: %s: reason. */ __( 'Token could not be verified: %s', 'signal-and-noise-tools' ), (string) ( $t['error'] ?: $t['status'] ) ) ) );
		}

		// ── Zone, seven days
		$z = is_array( $record['zone'] ) ? $record['zone'] : array();
		if ( ! empty( $z['available'] ) ) {
			$tt     = (array) $z['totals'];
			$inner .= '<div class="snt-stats">'
				. \snt_kit_stat( number_format_i18n( (int) $tt['requests'] ), __( 'Requests, 7 days', 'signal-and-noise-tools' ) )
				. \snt_kit_stat( null === ( $tt['cache_share'] ?? null ) ? '—' : number_format_i18n( (float) $tt['cache_share'], 1 ) . '%', __( 'Served from cache', 'signal-and-noise-tools' ) )
				. \snt_kit_stat( size_format( (int) $tt['bytes'] ), __( 'Bytes', 'signal-and-noise-tools' ) )
				. \snt_kit_stat( number_format_i18n( (int) $tt['threats'] ), __( 'Threats', 'signal-and-noise-tools' ) )
				. \snt_kit_stat( number_format_i18n( (int) $tt['status_5xx'] ), __( '5xx at the edge', 'signal-and-noise-tools' ), '', (int) $tt['status_5xx'] > 0 ? 'warn' : '' )
				. '</div>';
		} elseif ( ! empty( $z['needs_permission'] ) ) {
			$inner .= \snt_kit_notice( 'warning', \snt_kit_esc( __( 'Zone analytics: ', 'signal-and-noise-tools' ) . sn_cf_monitor_permission_hint() ) );
		} else {
			$inner .= \snt_kit_notice( 'error', \snt_kit_esc( sprintf( /* translators: %s: reason. */ __( 'Zone analytics could not be read: %s', 'signal-and-noise-tools' ), (string) ( $z['error'] ?? '' ) ) ) );
		}

		// ── Firewall, 24 hours
		$f = is_array( $record['firewall'] ) ? $record['firewall'] : array();
		if ( ! empty( $f['available'] ) ) {
			$rows = array();
			foreach ( (array) $f['by_action'] as $action => $n ) {
				$rows[] = array( 'label' => (string) $action, 'value' => number_format_i18n( (int) $n ), 'tone' => in_array( $action, array( 'block', 'managed_challenge', 'challenge', 'jschallenge' ), true ) ? 'warn' : '' );
			}
			$inner .= '<h4 class="snt-h">' . \snt_kit_esc( sprintf( /* translators: %s: count. */ __( 'Firewall, 24 hours: %s events', 'signal-and-noise-tools' ), number_format_i18n( (int) $f['events'] ) ) ) . '</h4>';
			$inner .= array() !== $rows ? \snt_kit_list( $rows ) : '<p class="snt-hint">' . \snt_kit_esc( __( 'No firewall events in the window.', 'signal-and-noise-tools' ) ) . '</p>';
			if ( ! empty( $f['top_rules'] ) ) {
				$rule_rows = array();
				foreach ( (array) $f['top_rules'] as $r ) {
					$rule_rows[] = array( 'label' => trim( (string) $r['source'] . ' ' . (string) $r['rule'] ) ?: __( '(unnamed)', 'signal-and-noise-tools' ), 'value' => (string) $r['action'] . ' × ' . number_format_i18n( (int) $r['count'] ) );
				}
				$inner .= \snt_kit_list( $rule_rows );
			}
		} elseif ( ! empty( $f['needs_permission'] ) ) {
			$inner .= \snt_kit_notice( 'warning', \snt_kit_esc( __( 'Firewall events: ', 'signal-and-noise-tools' ) . sn_cf_monitor_permission_hint() ) );
		} else {
			$inner .= \snt_kit_notice( 'error', \snt_kit_esc( sprintf( /* translators: %s: reason. */ __( 'Firewall events could not be read: %s', 'signal-and-noise-tools' ), (string) ( $f['error'] ?? '' ) ) ) );
		}
	}

	$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Cloudflare publishes no rate-limit headers; its 1,200-per-five-minutes limit answers 429 when crossed. The API row on the Dashboard reads this monitor instead.', 'signal-and-noise-tools' ) ) . '</p>';
	$inner .= '<footer>' . \snt_kit_action_button( __( 'Refresh now', 'signal-and-noise-tools' ), 'cf_monitor_refresh', array( 'disabled' => empty( $d['is_configured'] ) ) ) . '</footer>';

	return \snt_kit_section( __( 'Monitor', 'signal-and-noise-tools' ), $inner, __( 'Token, zone and firewall, as the Cloudflare API reports them. Read daily.', 'signal-and-noise-tools' ) );
}
