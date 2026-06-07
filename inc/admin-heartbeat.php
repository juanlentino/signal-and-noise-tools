<?php
/**
 * Signal & Noise Tools — Heartbeat live-refresh (v4.9.0, Task 5).
 *
 * Piggybacks WordPress' Heartbeat API to live-refresh two admin tables
 * without a full page reload:
 *   - Cron tab: the "last fired" cells (.sn-cron-last-fired, keyed by
 *     the row's data-hook)
 *   - Webhooks tab: each delivery-log table (keyed by data-webhook-id)
 *
 * The client (assets/admin-heartbeat.js) adds data.sn_heartbeat listing
 * which tables are present on the page; the server responder below answers
 * ONLY for those, and ONLY for manage_options users. It early-returns on
 * the global heartbeat otherwise so it costs nothing on every tick.
 *
 * The JS DOM-patching is manual-UAT; this PHP responder is fully tested
 * (tests/admin-heartbeat.php).
 *
 * @package SignalNoiseTools
 * @since 4.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Heartbeat received handler. Adds SN payloads to $response ONLY when the
 * client requested them (via $data['sn_heartbeat']) AND the user can
 * manage_options. Never does work on a bare global heartbeat.
 *
 * @since 4.9.0
 * @param array $response Outgoing heartbeat response.
 * @param array $data     Incoming heartbeat data from the client.
 * @return array
 */
function snt_admin_heartbeat_received( $response, $data ) {
	if ( empty( $data['sn_heartbeat'] ) || ! current_user_can( 'manage_options' ) ) {
		return $response;
	}

	$want = (array) $data['sn_heartbeat'];

	if ( in_array( 'cron', $want, true ) && function_exists( 'snt_cron_sn_owned_hooks' ) ) {
		$map = array();
		foreach ( snt_cron_sn_owned_hooks() as $hook ) {
			$ts = function_exists( 'snt_cron_last_fired_for' ) ? snt_cron_last_fired_for( $hook ) : null;
			$map[ $hook ] = array(
				'ts'        => ( null === $ts ? null : (int) $ts ),
				'formatted' => ( null === $ts ) ? '' : wp_date( 'Y-m-d H:i:s', (int) $ts ),
			);
		}
		$response['sn_cron_last_fired'] = $map;
	}

	if ( in_array( 'webhooks', $want, true ) && function_exists( 'sn_webhooks_all' ) && function_exists( 'sn_webhook_log_read' ) ) {
		$logs = array();
		foreach ( sn_webhooks_all() as $wh ) {
			if ( empty( $wh['id'] ) ) {
				continue;
			}
			$id = (string) $wh['id'];
			$rows = sn_webhook_log_read( $id );
			// Cap at the most-recent 20 (same as the log store cap).
			if ( is_array( $rows ) && count( $rows ) > 20 ) {
				$rows = array_slice( $rows, -20 );
			}
			$logs[ $id ] = is_array( $rows ) ? $rows : array();
		}
		$response['sn_webhook_logs'] = $logs;
	}

	return $response;
}
add_filter( 'heartbeat_received', 'snt_admin_heartbeat_received', 10, 2 );

/**
 * Enqueue the live-refresh client on SN admin pages. Depends on the core
 * 'heartbeat' handle. Loading on every SN admin page is fine — the JS is a
 * no-op when neither target table is present.
 *
 * @since 4.9.0
 */
add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
	if ( ! function_exists( 'sn_admin_page_hooks' ) || ! in_array( $hook_suffix, sn_admin_page_hooks(), true ) ) {
		return;
	}
	wp_enqueue_script(
		'sn-admin-heartbeat',
		plugins_url( 'assets/admin-heartbeat.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'jquery', 'heartbeat' ),
		SNT_VERSION,
		true
	);
} );
