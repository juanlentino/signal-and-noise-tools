<?php
/**
 * Signal & Noise Tools: alerts, the hourly half. Gathers what the site
 * already stores, asks inc/alerts.php what is due, mails it once.
 *
 * NO NEW OUTSIDE CALLS. Spikes read the daily analytics table (kept fresh by
 * the rollup the analytics worker pokes); breaks read the stored edge 5xx
 * rollup, which files a day's errors after that day ends, so a break mails
 * the morning after, not within the hour. Sent stamps live in an OPTION:
 * this site's transients sit in Redis and get flushed.
 *
 * The hook is always scheduled and the callback returns early when the
 * toggle is off, so cron health never reads an off switch as a missing job.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_ALERTS_HOOK     = 'snt_alerts_hourly';
const SNT_ALERTS_SENT_OPT = 'snt_alerts_sent'; // key => unix time mailed, 14 days.
const SNT_ALERTS_LAST_OPT = 'snt_alerts_last'; // the last evaluation: at, state, fired, mailed, error.

/** On by default (owner, 2026-10-03); the toggle sits beside the morning brief's. */
function snt_alerts_enabled() {
	return (bool) sn_setting( 'operations.alerts_enabled', true );
}

/**
 * The stored rows the evaluation reads. Local tables and options only.
 *
 * @param int $now Unix time.
 * @return array<string,mixed> The $in of snt_alerts_evaluate(), minus `sent`.
 */
function snt_alerts_gather( $now ) {
	$today = function_exists( 'sn_analytics_local_day' ) ? sn_analytics_local_day( $now ) : gmdate( 'Y-m-d', $now );
	$back  = static fn( $days ) => gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) - $days * DAY_IN_SECONDS );
	$sum   = static function ( $from, $to ) {
		$out = array();
		foreach ( function_exists( 'sn_analytics_daily_range' ) ? sn_analytics_daily_range( $from, $to, 'human' ) : array() as $r ) {
			$out[ $r['path'] ] = ( $out[ $r['path'] ] ?? 0 ) + (int) $r['views'];
		}
		return $out;
	};
	$errors = array();
	foreach ( array( gmdate( 'Y-m-d', $now ), gmdate( 'Y-m-d', $now - DAY_IN_SECONDS ) ) as $day ) {
		foreach ( function_exists( 'sn_edge_top_dim' ) ? sn_edge_top_dim( 'err_path', $day, $day, 100 ) : array() as $r ) {
			$errors[ $day ][ $r['value'] ] = (int) $r['requests'];
		}
	}
	return array(
		'today'    => $today,
		'views'    => $sum( $today, $today ),
		'history'  => $sum( $back( SNT_ALERT_BASE_DAYS ), $back( 1 ) ),
		'errors'   => $errors,
		'excluded' => function_exists( 'sn_analytics_is_excluded_path' ) ? 'sn_analytics_is_excluded_path' : null,
	);
}

/**
 * The hourly run. A key is stamped only when its mail left, so a failed send
 * is tried again next hour instead of being recorded as told.
 *
 * @param int|null $now Unix time; null reads the clock.
 * @return array<string,mixed> The stored last evaluation.
 */
function snt_alerts_run( $now = null ) {
	$now  = null === $now ? time() : (int) $now;
	$last = array( 'at' => $now, 'state' => 'off', 'fired' => array(), 'mailed' => false, 'error' => '' );
	if ( snt_alerts_enabled() ) {
		$sent   = snt_alerts_prune( (array) get_option( SNT_ALERTS_SENT_OPT, array() ), $now );
		$in     = snt_alerts_gather( $now );
		$alerts = snt_alerts_evaluate( $in + array( 'sent' => $sent ), snt_alerts_thresholds() );
		$last   = array( 'at' => $now, 'state' => 'evaluated', 'fired' => array_slice( array_column( $alerts, 'key' ), 0, 20 ), 'mailed' => false, 'error' => '' );
		if ( $alerts ) {
			$email   = (string) get_option( 'admin_email' ); // the morning brief's recipient.
			$spike   = (bool) array_filter( $alerts, static fn( $a ) => 'break' !== $a['kind'] );
			$sources = $spike && function_exists( 'sn_analytics_top_sources' ) ? (array) sn_analytics_top_sources( $in['today'], $in['today'], 'human', 5 ) : array();
			$where   = function_exists( 'snt_analytics_page_url' ) ? snt_analytics_page_url() : admin_url();
			$mail    = snt_alerts_compose( $alerts, $sources, (string) wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $where );
			if ( '' === $email || ! is_email( $email ) ) {
				$last['error'] = 'admin_email missing or invalid';
			} elseif ( wp_mail( $email, $mail[0], $mail[1] ) ) {
				$last['mailed'] = true;
				$sent          += array_fill_keys( array_column( $alerts, 'key' ), $now ); // every key mailed, not only the 20 the record keeps.
			} else {
				$last['error'] = 'wp_mail returned false';
			}
		}
		update_option( SNT_ALERTS_SENT_OPT, $sent, false );
	}
	update_option( SNT_ALERTS_LAST_OPT, $last, false );
	return $last;
}

/** Keep the hourly evaluation scheduled. */
function snt_alerts_schedule() {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( SNT_ALERTS_HOOK ) ) {
		wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', SNT_ALERTS_HOOK );
	}
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'init', 'snt_alerts_schedule' );
	add_action( SNT_ALERTS_HOOK, 'snt_alerts_run', 10, 0 );
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
	if ( ! empty( $last['error'] ) && ! empty( $last['fired'] ) ) {
		return array( 'ripe' => true, 'note' => 'fired but NOT mailed (' . $last['error'] . '): ' . implode( ', ', array_map( 'snt_alerts_clean', (array) $last['fired'] ) ) . '; ' . $when );
	}
	if ( $recent ) {
		return array( 'ripe' => true, 'note' => 'mailed in the last 24 hours: ' . implode( ', ', array_map( 'snt_alerts_clean', array_slice( $recent, 0, 5 ) ) ) . '; ' . $when );
	}
	return array( 'ripe' => false, 'note' => 'nothing mailed in the last 24 hours; ' . $when );
}
