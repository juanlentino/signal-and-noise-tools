<?php
/**
 * Signal & Noise Tools: the Insights scan status in words, the same on the
 * classic tab (inc/insights-admin.php) and the native leaf: the last scan's
 * questions by what the owner did with each, and how long its answer is kept.
 * Split out of insights-admin.php (PR #2025 review: that file had grown past
 * 500 lines).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The last scan's questions by what the owner did with each, the same on both
 * surfaces (2026-10-12 audit: "active" kept the done ones, "dismissed" and
 * "done" counted every scan's ids, and snoozed questions went uncounted, so a
 * four-question scan could read "4 active · 17 dismissed · 9 done").
 *
 * @param array $recommendations The last scan's recommendations.
 * @return array{total:int,open:int,done:int,dismissed:int,snoozed:int}
 */
function snt_insights_scan_counts( $recommendations ) {
	$state     = snt_insights_state_read();
	$now       = time();
	$dismissed = array_flip( $state['dismissed_ids'] );
	$done      = array_flip( $state['done_ids'] );
	$c         = array( 'total' => 0, 'open' => 0, 'done' => 0, 'dismissed' => 0, 'snoozed' => 0 );
	foreach ( (array) $recommendations as $rec ) {
		$id = isset( $rec['id'] ) ? (string) $rec['id'] : '';
		if ( '' === $id ) {
			continue;
		}
		++$c['total'];
		if ( isset( $dismissed[ $id ] ) ) {
			++$c['dismissed'];
		} elseif ( isset( $state['snoozed_until'][ $id ] ) && (int) $state['snoozed_until'][ $id ] > $now ) {
			++$c['snoozed'];
		} elseif ( isset( $done[ $id ] ) ) {
			++$c['done'];
		} else {
			++$c['open'];
		}
	}
	return $c;
}

/**
 * The counts as one phrase: "4 questions: 1 open, 2 done, 1 dismissed, 0 snoozed".
 *
 * @param array{total:int,open:int,done:int,dismissed:int,snoozed:int} $c snt_insights_scan_counts().
 * @return string
 */
function snt_insights_scan_counts_text( array $c ) {
	return sprintf(
		/* translators: 1: questions in the last scan, 2: open, 3: done, 4: dismissed, 5: snoozed. */
		_n( '%1$s question: %2$s open, %3$s done, %4$s dismissed, %5$s snoozed', '%1$s questions: %2$s open, %3$s done, %4$s dismissed, %5$s snoozed', (int) $c['total'], 'signal-and-noise-tools' ),
		number_format_i18n( (int) $c['total'] ),
		number_format_i18n( (int) $c['open'] ),
		number_format_i18n( (int) $c['done'] ),
		number_format_i18n( (int) $c['dismissed'] ),
		number_format_i18n( (int) $c['snoozed'] )
	);
}

/**
 * How long the last scan took and how long its answer is kept, in the site's
 * date format: "Scan ran in 12s; cached until October 19, 2026 9:00 am."
 *
 * @param array $last snt_insights_last_scan().
 * @return string
 */
function snt_insights_scan_timing_text( array $last ) {
	$ttl     = defined( 'SN_INSIGHTS_CACHE_TTL' ) ? SN_INSIGHTS_CACHE_TTL : 7 * DAY_IN_SECONDS;
	$elapsed = function_exists( 'snt_health_format_elapsed' ) ? snt_health_format_elapsed( (int) ( $last['elapsed_ms'] ?? 0 ) ) : '';
	$until   = wp_date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), (int) ( $last['scanned_at'] ?? 0 ) + $ttl );
	return '' !== $elapsed
		/* translators: 1: how long the scan took, 2: when its cache expires. */
		? sprintf( __( 'Scan ran in %1$s; cached until %2$s.', 'signal-and-noise-tools' ), $elapsed, $until )
		/* translators: %s: when the scan's cache expires. */
		: sprintf( __( 'Cached until %s.', 'signal-and-noise-tools' ), $until );
}
