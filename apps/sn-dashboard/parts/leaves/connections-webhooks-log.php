<?php
/**
 * S&N Dashboard: Connections > Webhooks, one webhook's delivery log.
 *
 * Split out of connections-webhooks-parts.php (2026-10-11, no folds). The log
 * was a closed disclosure while editing and a section while watching; it is
 * the same open section on both paints now: the store cap as context, one
 * finding sentence that says how many deliveries failed and when the last
 * one did, the newest WEBHOOKS_LOG_ROWS rows with a failed row toned, and
 * "+N more" for the rest.
 *
 * The classic table carries `data-webhook-id` and assets/admin-heartbeat.js
 * re-renders it on every Heartbeat tick (T5, v4.9.0). Here the live refresh
 * is the runtime's `os-poll` (snt_kit_watch_bar() in the leaf, #1607), and the
 * section is open by construction, so a repaint on a tick has no open state
 * to lose.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** Deliveries painted per webhook before the "+N more" line (the store keeps 20). */
const WEBHOOKS_LOG_ROWS = 10;

/**
 * The finding over a log: how many deliveries, how many failed, and the last
 * failure (or, with none, the newest delivery).
 *
 * @param array<int,array<string,mixed>> $newest_first Log entries, newest first.
 * @return string
 */
function webhooks_log_finding( array $newest_first ) {
	$total  = count( $newest_first );
	$failed = array_values( array_filter( $newest_first, static function ( $e ) { return empty( $e['success'] ); } ) );
	if ( array() === $failed ) {
		return sprintf(
			/* translators: 1: deliveries logged, 2: newest delivery time. */
			_n( '%1$s delivery logged, none failed; the newest was %2$s.', '%1$s deliveries logged, none failed; the newest was %2$s.', $total, 'signal-and-noise-tools' ),
			number_format_i18n( $total ),
			wp_date( 'Y-m-d H:i:s', (int) ( $newest_first[0]['fired_at'] ?? 0 ) )
		);
	}
	$code = (int) ( $failed[0]['response_code'] ?? 0 );
	return sprintf(
		/* translators: 1: deliveries logged, 2: failed deliveries, 3: last failure time, 4: how it failed ("HTTP 503" or "no HTTP response"). */
		_n( '%1$s delivery logged, %2$s failed; the last failure was %3$s, %4$s.', '%1$s deliveries logged, %2$s failed; the last failure was %3$s, %4$s.', $total, 'signal-and-noise-tools' ),
		number_format_i18n( $total ),
		number_format_i18n( count( $failed ) ),
		wp_date( 'Y-m-d H:i:s', (int) ( $failed[0]['fired_at'] ?? 0 ) ),
		/* translators: %d: HTTP status code. */
		$code > 0 ? sprintf( __( 'HTTP %d', 'signal-and-noise-tools' ), $code ) : __( 'no HTTP response', 'signal-and-noise-tools' )
	);
}

/**
 * The delivery log, newest first, as an open ledger section. '' when the
 * webhook has delivered nothing yet.
 *
 * @param string $id Webhook id.
 * @return string
 */
function webhooks_log_html( $id ) {
	$log = \sn_webhook_log_read( $id );
	if ( empty( $log ) ) {
		return '';
	}
	$entries = array_values( array_reverse( $log ) );
	$rows    = array();
	foreach ( $entries as $entry ) {
		$ok     = ! empty( $entry['success'] );
		$rows[] = array(
			// Identity, not position: a poll that lands a new delivery shifts every row.
			'_key'     => (string) ( $entry['delivery_id'] ?? '' ) . ':' . (string) ( $entry['attempt'] ?? '' ) . ':' . (string) ( $entry['fired_at'] ?? '' ),
			'fired_at' => wp_date( 'Y-m-d H:i:s', (int) ( $entry['fired_at'] ?? 0 ) ),
			'attempt'  => (string) ( $entry['attempt'] ?? '' ),
			'http'     => (string) ( $entry['response_code'] ?? '' ),
			// A failed delivery is toned, as the classic pill is; an ok one stays plain.
			'status'   => $ok ? array( 'html' => 'ok', 'text' => 'ok' ) : array( 'html' => \snt_kit_badge( 'warn', 'fail' ), 'text' => 'fail' ),
			'response' => (string) ( $entry['response_excerpt'] ?? '' ),
		);
	}
	$columns = array(
		array( 'key' => 'fired_at', 'label' => __( 'Fired at', 'signal-and-noise-tools' ) ),
		array( 'key' => 'attempt', 'label' => __( 'Attempt', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'http', 'label' => __( 'HTTP', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'status', 'label' => __( 'Status', 'signal-and-noise-tools' ) ),
		array( 'key' => 'response', 'label' => __( 'Response', 'signal-and-noise-tools' ) ),
	);
	$store = defined( 'SN_WEBHOOK_LOG_CAP' )
		/* translators: %s: deliveries the log keeps per webhook. */
		? sprintf( __( 'The log keeps the newest %s deliveries per webhook.', 'signal-and-noise-tools' ), number_format_i18n( SN_WEBHOOK_LOG_CAP ) )
		: '';
	return \snt_kit_section(
		__( 'Recent deliveries', 'signal-and-noise-tools' ),
		\snt_kit_ledger(
			webhooks_log_finding( $entries ),
			$columns,
			$rows,
			WEBHOOKS_LOG_ROWS,
			/* translators: %s: deliveries not shown. */
			array( 'more' => __( '+%s more deliveries; the list is capped, not complete. Newest first, so the tail is the oldest end.', 'signal-and-noise-tools' ) )
		),
		$store
	);
}
