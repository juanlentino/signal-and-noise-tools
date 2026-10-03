<?php
/**
 * Signal & Noise Tools: the alerts watch, the agent-readable twin of the alert
 * email. Reads the two options inc/alerts-cron.php writes; sends nothing.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Watch: ripe while an alert was mailed in the last 24 hours, or the last
 * evaluation fired and its mail did not leave. The agent-readable twin of
 * the email: what fired, when, and whether it was sent.
 *
 * @param array      $watch The watch row.
 * @param int        $now   Unix time.
 * @param array|null $state Test seam: {sent, last}.
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_alerts( $watch, $now, $state = null ) {
	unset( $watch );
	$state  = is_array( $state ) ? $state : array( 'sent' => get_option( SNT_ALERTS_SENT_OPT, array() ), 'last' => get_option( SNT_ALERTS_LAST_OPT, array() ) );
	$last   = (array) $state['last'];
	$recent = array_keys( array_filter( (array) $state['sent'], static fn( $at ) => (int) $at > (int) $now - DAY_IN_SECONDS ) );
	$when   = empty( $last['at'] ) ? 'never evaluated' : 'last evaluated ' . gmdate( 'Y-m-d H:i', (int) $last['at'] ) . ' UTC (' . (string) ( $last['state'] ?? '' ) . ')';
	if ( 'read_failed' === ( $last['state'] ?? '' ) ) {
		return array( 'ripe' => true, 'note' => 'NOT evaluated, ' . (string) $last['error'] . '; ' . $when );
	}
	if ( ! empty( $last['capped'] ) ) {
		$when .= '; the stored 5xx list was full on ' . implode( ', ', (array) $last['capped'] ) . ', so a quieter page may be missing from it';
	}
	if ( ! empty( $last['error'] ) && ! empty( $last['fired'] ) ) {
		return array( 'ripe' => true, 'note' => 'fired but NOT mailed (' . $last['error'] . '): ' . implode( ', ', array_map( 'snt_alerts_clean', (array) $last['fired'] ) ) . '; ' . $when );
	}
	if ( $recent ) {
		return array( 'ripe' => true, 'note' => 'mailed in the last 24 hours: ' . implode( ', ', array_map( 'snt_alerts_clean', array_slice( $recent, 0, 5 ) ) ) . '; ' . $when );
	}
	return array( 'ripe' => false, 'note' => 'nothing mailed in the last 24 hours; ' . $when );
}
