<?php
/**
 * S&N Dashboard — the Cloudflare monitor's three readings, each where its
 * question is asked: Token on Connections › Cloudflare, Edge on Measurement ›
 * Analytics, Firewall on Security › Firewall (15.3.0). All from one record.
 *
 * Paints what inc/cloudflare-monitor.php stored. The token's health sits
 * under the Credentials (left column, next to the token); the zone's seven
 * days are the Edge section and the firewall's 24 hours the Firewall
 * section (right column), with one Refresh footer under the last. A reading
 * the token cannot make paints the reason, never a zero. The Refresh button
 * posts `cf_monitor_refresh` through the shared handler table; painting
 * never fetches.
 *
 * @package SignalNoiseTools
 * @since 14.9.0
 * @since 15.1.0 Three sections instead of one nested under Cache status.
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The stored record, or null when the monitor never ran.
 *
 * @return array<string,mixed>|null
 */
function cloudflare_monitor_record() {
	return function_exists( 'sn_cf_monitor_read' ) ? sn_cf_monitor_read() : null;
}

/**
 * When the record was read, in the site's timezone.
 *
 * @param array<string,mixed> $record
 * @return string
 */
function cloudflare_monitor_when( array $record ) {
	return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i T', (int) $record['fetched_at'] ) : gmdate( 'Y-m-d H:i', (int) $record['fetched_at'] ) . ' UTC';
}

/**
 * The token's health, painted under the Credentials: status, kind, expiry,
 * and when that was read. Nothing when the monitor never ran.
 *
 * @return string
 */
function cloudflare_token_html( array $d = array() ) {
	$record = cloudflare_monitor_record();
	// 15.3.0: the monitor's Refresh lives here now (it reads the token first);
	// before the first run the section says so and offers it.
	if ( ! is_array( $record ) ) {
		return \snt_kit_section( __( 'Token', 'signal-and-noise-tools' ), '<p class="snt-hint">' . \snt_kit_esc( __( 'The monitor has not run yet. It runs daily; press Refresh to run it now.', 'signal-and-noise-tools' ) ) . '</p>' . cloudflare_monitor_footer_html( $d, null ), __( 'As Cloudflare reports it, read daily with the monitor.', 'signal-and-noise-tools' ) );
	}
	if ( empty( $record['configured'] ) ) {
		return '';
	}
	$now   = time();
	$inner = '';
	// ── Token
	$t = is_array( $record['token'] ) ? $record['token'] : array();
	if ( ! empty( $t['verified'] ) ) {
		$exp_ts = '' !== (string) $t['expires_on'] ? strtotime( (string) $t['expires_on'] ) : 0;
		$exp    = $exp_ts ? ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $exp_ts ) : gmdate( 'Y-m-d', $exp_ts ) ) : __( 'never', 'signal-and-noise-tools' );
		$tone   = 'active' === (string) $t['status'] ? ( $exp_ts && $exp_ts < $now + 14 * DAY_IN_SECONDS ? 'warn' : 'ok' ) : 'err';
		$kind   = (string) ( $t['kind'] ?? '' );
		$inner .= \snt_kit_kv( array(
			array( 'label' => __( 'Token', 'signal-and-noise-tools' ), 'value' => (string) $t['status'] . ( '' !== $kind ? ' · ' . $kind : '' ), 'tone' => $tone ),
			array( 'label' => __( 'Expires', 'signal-and-noise-tools' ), 'value' => $exp ),
			array( 'label' => __( 'Verified', 'signal-and-noise-tools' ), 'value' => cloudflare_monitor_when( $record ) ),
		) );
	} else {
		$inner .= \snt_kit_notice( 'error', \snt_kit_esc( sprintf( /* translators: %s: reason. */ __( 'Token could not be verified: %s', 'signal-and-noise-tools' ), (string) ( $t['error'] ?: $t['status'] ) ) ) );
	}

	$inner .= cloudflare_monitor_footer_html( $d, $record );
	return \snt_kit_section( __( 'Token', 'signal-and-noise-tools' ), $inner, __( 'As Cloudflare reports it, read daily with the monitor.', 'signal-and-noise-tools' ) );
}

/**
 * Edge, seven days: the five stats and the 5xx split. When the monitor
 * never ran, the section says so and offers Refresh.
 *
 * @param array<string,mixed> $d From cloudflare_data().
 * @return string
 */
function cloudflare_edge_html( array $d ) {
	$record = cloudflare_monitor_record();
	$inner  = '';
	if ( ! is_array( $record ) ) {
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'The monitor has not run yet. It runs daily; press Refresh to run it now.', 'signal-and-noise-tools' ) ) . '</p>' . cloudflare_monitor_footer_html( $d, null );
		return \snt_kit_section( __( 'Edge, 7 days', 'signal-and-noise-tools' ), $inner );
	}
	if ( empty( $record['configured'] ) ) {
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Not configured: set the API token and zone ID under Credentials.', 'signal-and-noise-tools' ) ) . '</p>';
		return \snt_kit_section( __( 'Edge, 7 days', 'signal-and-noise-tools' ), $inner );
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
		// 14.9.1: which 5xx. 520/522/524 are Cloudflare failing to reach or
		// wait for the origin; 503 is the origin's own answer (Varnish).
		$codes = (array) ( $tt['status_5xx_codes'] ?? array() );
		if ( array() !== $codes ) {
			$code_rows = array();
			$cf_side   = array( 520 => __( 'origin returned an unreadable or empty response', 'signal-and-noise-tools' ), 521 => __( 'origin refused the connection', 'signal-and-noise-tools' ), 522 => __( 'connection to the origin timed out', 'signal-and-noise-tools' ), 523 => __( 'origin unreachable', 'signal-and-noise-tools' ), 524 => __( 'origin took too long to answer', 'signal-and-noise-tools' ), 525 => __( 'TLS handshake with the origin failed', 'signal-and-noise-tools' ), 526 => __( 'origin certificate invalid', 'signal-and-noise-tools' ) );
			foreach ( $codes as $code => $n ) {
				$code_rows[] = array( 'label' => (string) $code . ' · ' . ( $cf_side[ (int) $code ] ?? __( 'answered by the origin', 'signal-and-noise-tools' ) ), 'value' => number_format_i18n( (int) $n ), 'tone' => 'warn' );
			}
			$inner .= \snt_kit_list( $code_rows );
		}
	} elseif ( ! empty( $z['needs_permission'] ) ) {
		$inner .= \snt_kit_notice( 'warning', \snt_kit_esc( __( 'Zone analytics: ', 'signal-and-noise-tools' ) . sn_cf_monitor_permission_hint() ) );
	} else {
		$inner .= \snt_kit_notice( 'error', \snt_kit_esc( sprintf( /* translators: %s: reason. */ __( 'Zone analytics could not be read: %s', 'signal-and-noise-tools' ), (string) ( $z['error'] ?? '' ) ) ) );
	}

	$inner .= cloudflare_monitor_footer_html( $d, $record );
	return \snt_kit_section( __( 'Edge, 7 days', 'signal-and-noise-tools' ), $inner, __( 'Requests, cache share, bytes, threats and 5xx, as the zone reports them.', 'signal-and-noise-tools' ) );
}

/**
 * Firewall, 24 hours: by action, top rules, and from the event log the
 * paths and countries acted on. The one Refresh footer sits here.
 *
 * @param array<string,mixed> $d From cloudflare_data().
 * @return string
 */
function cloudflare_firewall_html( array $d ) {
	$parts = cloudflare_firewall_parts( $d );
	if ( array() === $parts ) {
		return '';
	}
	// 15.3.1: two columns. Left, what happened (events by action, the rules);
	// right, to what (paths, countries). Notes and Refresh under the left.
	return '<div class="snt-2up">'
		. '<div class="snt-2up-col">' . \snt_kit_section( __( 'Firewall, 24 hours', 'signal-and-noise-tools' ), $parts['actions'] . $parts['rules'] . $parts['notes'] . $parts['footer'], __( 'What Cloudflare stopped before WordPress ran.', 'signal-and-noise-tools' ) ) . '</div>'
		. '<div class="snt-2up-col">' . ( '' !== $parts['targets'] ? \snt_kit_section( __( 'Acted on', 'signal-and-noise-tools' ), $parts['targets'], __( 'The paths and countries behind the events, from the event log.', 'signal-and-noise-tools' ) ) : '' ) . '</div>'
		. '</div>';
}

/**
 * The firewall reading's pieces, so a leaf can lay them out: `actions`
 * (the count and by-action list, or the refusal notice), `rules` (top rules
 * under a heading), `targets` (the log's top paths and countries), `notes`
 * (the raw-dataset and floor notes), `footer` (Refresh). Empty array when
 * the monitor never ran or is unconfigured.
 *
 * @param array<string,mixed> $d From cloudflare_data().
 * @return array<string,string>
 */
function cloudflare_firewall_parts( array $d ) {
	$record = cloudflare_monitor_record();
	if ( ! is_array( $record ) || empty( $record['configured'] ) ) {
		return array();
	}
	$parts = array( 'actions' => '', 'rules' => '', 'targets' => '', 'notes' => '', 'footer' => cloudflare_monitor_footer_html( $d, $record ) );
	$f     = is_array( $record['firewall'] ) ? $record['firewall'] : array();
	if ( ! empty( $f['available'] ) ) {
		$rows = array();
		foreach ( (array) $f['by_action'] as $action => $n ) {
			$rows[] = array( 'label' => (string) $action, 'value' => number_format_i18n( (int) $n ), 'tone' => in_array( $action, array( 'block', 'managed_challenge', 'challenge', 'jschallenge' ), true ) ? 'warn' : '' );
		}
		$parts['actions'] = '<h4 class="snt-h">' . \snt_kit_esc( sprintf( /* translators: %s: count. */ __( '%s events', 'signal-and-noise-tools' ), number_format_i18n( (int) $f['events'] ) ) ) . '</h4>'
			. ( array() !== $rows ? \snt_kit_list( $rows ) : '<p class="snt-hint">' . \snt_kit_esc( __( 'No firewall events in the window.', 'signal-and-noise-tools' ) ) . '</p>' );
		if ( ! empty( $f['top_rules'] ) ) {
			$rule_rows = array();
			foreach ( (array) $f['top_rules'] as $r ) {
				$rule_rows[] = array( 'label' => trim( (string) $r['source'] . ' ' . (string) $r['rule'] ) ?: __( '(unnamed)', 'signal-and-noise-tools' ), 'value' => (string) $r['action'] . ' × ' . number_format_i18n( (int) $r['count'] ) );
			}
			$parts['rules'] = '<h4 class="snt-h">' . \snt_kit_esc( __( 'Top rules', 'signal-and-noise-tools' ) ) . '</h4>' . \snt_kit_list( $rule_rows );
		}
		// 15.1.0: from the event log, what the origin never saw: the paths
		// and countries Cloudflare acted on. Weighted by sampleInterval.
		$log = function_exists( 'sn_cf_firewall_events_read' ) ? sn_cf_firewall_events_read() : null;
		if ( is_array( $log ) && ! empty( $log['available'] ) && array() !== (array) $log['rows'] ) {
			foreach ( array( 'clientRequestPath' => __( 'Top paths acted on', 'signal-and-noise-tools' ), 'clientCountryName' => __( 'Top countries acted on', 'signal-and-noise-tools' ) ) as $field => $heading ) {
				$top_rows = array();
				foreach ( sn_cf_firewall_events_top( (array) $log['rows'], $field, 5 ) as $value => $n ) {
					$top_rows[] = array( 'label' => (string) $value, 'value' => number_format_i18n( (int) $n ) );
				}
				$parts['targets'] .= '<h4 class="snt-h">' . \snt_kit_esc( $heading ) . '</h4>' . \snt_kit_list( $top_rows );
			}
			if ( ! empty( $log['truncated'] ) ) {
				$parts['targets'] .= '<p class="snt-hint">' . \snt_kit_esc( __( 'The event log had more rows than one page holds; these are a floor.', 'signal-and-noise-tools' ) ) . '</p>';
			}
		}
		if ( 'raw' === (string) ( $f['dataset'] ?? '' ) ) {
			// 15.0.1: the grouped dataset is not on this zone's plan; the raw one is.
			$parts['notes'] = '<p class="snt-hint">' . \snt_kit_esc( __( 'Read from the raw firewallEventsAdaptive dataset and grouped here; the grouped dataset is not on this zone\'s plan.', 'signal-and-noise-tools' ) . ( ! empty( $f['truncated'] ) ? ' ' . __( 'The day had more events than one page holds; the counts are a floor.', 'signal-and-noise-tools' ) : '' ) ) . '</p>';
		}
	} elseif ( ! empty( $f['needs_permission'] ) ) {
		// 15.0.1: the API's two sentences, grouped and raw, beside the plan hint.
		$detail = '' !== (string) ( $f['error'] ?? '' ) ? ' ' . sprintf( /* translators: %s: the API's message. */ __( 'The API said: “%s”', 'signal-and-noise-tools' ), (string) $f['error'] ) : '';
		if ( '' !== (string) ( $f['error_raw'] ?? '' ) ) {
			$detail .= ' ' . sprintf( /* translators: %s: the API's message for the raw dataset. */ __( 'The raw dataset said: “%s”', 'signal-and-noise-tools' ), (string) $f['error_raw'] );
		}
		$parts['actions'] = \snt_kit_notice( 'warning', \snt_kit_esc( __( 'Firewall events: ', 'signal-and-noise-tools' ) . sn_cf_monitor_permission_hint( 'firewall' ) . $detail ) );
	} else {
		$parts['actions'] = \snt_kit_notice( 'error', \snt_kit_esc( sprintf( /* translators: %s: reason. */ __( 'Firewall events could not be read: %s', 'signal-and-noise-tools' ), (string) ( $f['error'] ?? '' ) ) ) );
	}
	return $parts;
}

/**
 * The Refresh footer: when the readings were taken, the button, and the
 * rate-limit note the Dashboard's API row rests on.
 *
 * @param array<string,mixed>      $d      From cloudflare_data().
 * @param array<string,mixed>|null $record The stored record, or null.
 * @return string
 */
function cloudflare_monitor_footer_html( array $d, $record ) {
	$out = '';
	if ( is_array( $record ) && ! empty( $record['configured'] ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( /* translators: %s: time. */ __( 'Read %s. Refresh reads the token, the edge and the firewall again.', 'signal-and-noise-tools' ), cloudflare_monitor_when( $record ) ) ) . '</p>';
	}
	$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Cloudflare publishes no rate-limit headers; its 1,200-per-five-minutes limit answers 429 when crossed. The API row on the Dashboard reads this monitor instead.', 'signal-and-noise-tools' ) ) . '</p>';
	$out .= '<footer>' . \snt_kit_action_button( __( 'Refresh now', 'signal-and-noise-tools' ), 'cf_monitor_refresh', array( 'disabled' => empty( $d['is_configured'] ) ) ) . '</footer>';
	return $out;
}

/**
 * The right column's two monitor sections. Kept under this name so the
 * leaf's call site reads as before.
 *
 * @param array<string,mixed> $d From cloudflare_data().
 * @return string
 */
function cloudflare_monitor_html( array $d ) {
	return cloudflare_edge_html( $d ) . cloudflare_firewall_html( $d );
}
