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
const SNT_ALERTS_LAST_OPT = 'snt_alerts_last'; // the last evaluation: at, state, fired, mailed, error, capped.
const SNT_ALERT_EDGE_GROUPS = 50; // path groups the edge errors query keeps per day (inc/edge-analytics.php).

/** On by default (owner, 2026-10-03); the toggle sits beside the morning brief's. */
function snt_alerts_enabled() {
	return (bool) sn_setting( 'operations.alerts_enabled', true );
}

/**
 * Whether a path is a real page: the home page, a known front-end archive
 * route (filter `snt_alerts_archive_routes`), or a URL core resolves to a
 * PUBLISHED post or page. A scanner probe resolves to nothing, so it can
 * never mail a break. Asked only for paths already at the break line.
 *
 * @param string $path Request path.
 * @return bool
 */
function snt_alerts_is_real_page( $path ) {
	$path   = '/' . ltrim( trim( (string) $path, '/' ) . '/', '/' );
	$routes = (array) apply_filters( 'snt_alerts_archive_routes', array( '/notes/', '/provenance/' ) );
	if ( '/' === $path || in_array( $path, $routes, true ) ) {
		return true;
	}
	$id = (int) url_to_postid( home_url( $path ) );
	return $id > 0 && 'publish' === get_post_status( $id );
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
	// The readers answer a failed query with the same empty array as a quiet
	// day. Read as zero traffic, a failed history read drops every baseline to
	// nothing and mails a false spike, so each read is checked and named.
	$failed = array();
	$check  = static function ( $what ) use ( &$failed ) {
		$error = isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? (string) $GLOBALS['wpdb']->last_error : '';
		if ( '' !== $error ) {
			$failed[] = $what;
		}
	};
	$sum    = static function ( $from, $to, $what ) use ( $check ) {
		$out = array();
		foreach ( function_exists( 'sn_analytics_daily_range' ) ? sn_analytics_daily_range( $from, $to, 'human' ) : array() as $r ) {
			$out[ $r['path'] ] = ( $out[ $r['path'] ] ?? 0 ) + (int) $r['views'];
		}
		$check( $what );
		return $out;
	};
	$errors = array();
	$capped = array();
	// Three UTC days, not two: a day the rollup filed late is still read, and
	// the key is the error day, so a wider window never mails twice.
	foreach ( array( 0, 1, 2 ) as $ago ) {
		$day = gmdate( 'Y-m-d', $now - $ago * DAY_IN_SECONDS );
		// 100 is above the 50 path groups the edge query stores per day, so
		// nothing is cut HERE before the junk and real-page filters run. A day
		// that stored all 50 may have lost a quieter real page upstream: named.
		$rows = function_exists( 'sn_edge_top_dim' ) ? sn_edge_top_dim( 'err_path', $day, $day, 100 ) : array();
		$check( 'edge 5xx ' . $day );
		foreach ( $rows as $r ) {
			$errors[ $day ][ $r['value'] ] = (int) $r['requests'];
		}
		if ( count( $rows ) >= SNT_ALERT_EDGE_GROUPS ) {
			$capped[] = $day;
		}
	}
	$views   = $sum( $today, $today, 'views today' );
	$history = $sum( $back( SNT_ALERT_BASE_DAYS ), $back( 1 ), 'views history' );
	return array(
		'failed'   => $failed,
		'capped'   => $capped,
		'unread'   => snt_alerts_edge_unread( defined( 'SN_EDGE_ERRORS_READ_OPT' ) ? (array) get_option( SN_EDGE_ERRORS_READ_OPT, array() ) : array(), $now ),
		'today'    => $today,
		'views'    => $views,
		'history'  => $history,
		'errors'   => $errors,
		'cache'    => function_exists( 'sn_cf_purge_failure' ) ? sn_cf_purge_failure() : null,
		'excluded' => function_exists( 'sn_analytics_is_excluded_path' ) ? 'sn_analytics_is_excluded_path' : null,
		'real'     => 'snt_alerts_is_real_page',
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
		// A failed read is not a quiet day: no traffic or error rule is judged,
		// and the record says which read failed. A cache failure does not
		// depend on those reads, so it is still judged and mailed.
		$alerts = snt_alerts_evaluate( ( $in['failed'] ? array( 'cache' => $in['cache'] ) : $in ) + array( 'sent' => $sent ), snt_alerts_thresholds() );
		$last   = array( 'at' => $now, 'state' => $in['failed'] ? 'read_failed' : 'evaluated', 'fired' => array_slice( array_column( $alerts, 'key' ), 0, 20 ), 'mailed' => false, 'error' => $in['failed'] ? 'stored read failed: ' . implode( ', ', $in['failed'] ) : '', 'capped' => $in['capped'], 'unread' => $in['unread'] );
		if ( $alerts ) {
			$email   = (string) get_option( 'admin_email' ); // the morning brief's recipient.
			$spike   = (bool) array_filter( $alerts, static fn( $a ) => ! in_array( $a['kind'], array( 'break', 'cache' ), true ) );
			// null is the reader's failed-read verdict: kept, so the mail says
			// "could not be read", never "none stored".
			$sources = $spike && function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $in['today'], $in['today'], 'human', 5 ) : array();
			$last['sources'] = null === $sources ? 'read failed' : 'read';
			$where   = function_exists( 'snt_analytics_page_url' ) ? snt_analytics_page_url() : admin_url();
			$mail    = snt_alerts_compose( $alerts, $sources, (string) wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $where );
			// 20.9.0: the same headline for the open app, mailed or not.
			if ( function_exists( 'snt_alerts_notice_build' ) ) {
				update_option( SNT_ALERTS_NOTICE_OPT, snt_alerts_notice_build( $alerts, $mail[0], $mail[1], $now ), false );
			}
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
