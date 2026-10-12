<?php
/**
 * Signal & Noise Tools: Security › Audit log, the words both twins print.
 *
 * Split from inc/audit-log-admin.php (house line cap) on the 2026-10-12
 * audit, which rewrote them: the lockout facts as LLA records them (an end
 * time, not a start), the lead sentence and the prune notice in plain words
 * (no "LLA delta", no "(s)"), and the question Prune now asks first. The
 * native leaf (apps/sn-dashboard/parts/leaves/security-audit-log.php) reads
 * the same helpers.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The lockout facts both twins print (2026-10-12 audit: LLA stores when each
 * lockout ENDS, so the latest timestamp is an end, said as one).
 *
 * @param array{active_lockouts:int,most_recent_lockout_ts:int|null} $lla snt_audit_read_lla_summary_impl().
 * @return array<int,array{label:string,value:string}>
 */
function snt_audit_log_lla_rows( $lla ) {
	$end = (int) ( $lla['most_recent_lockout_ts'] ?? 0 );
	return array(
		array( 'label' => __( 'Active lockouts', 'signal-and-noise-tools' ), 'value' => number_format_i18n( (int) ( $lla['active_lockouts'] ?? 0 ) ) ),
		array(
			'label' => $end > time() ? __( 'Latest lockout ends', 'signal-and-noise-tools' ) : __( 'Latest lockout ended', 'signal-and-noise-tools' ),
			'value' => $end > 0 ? (string) wp_date( (string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ), $end ) : __( 'none recorded', 'signal-and-noise-tools' ),
		),
	);
}

/**
 * The tab's lead sentence, in plain words, the same on both twins.
 *
 * @param int $days Retention days.
 * @return string
 */
function snt_audit_log_intro_text( $days ) {
	/* translators: %s: retention days. */
	return sprintf( __( 'Records login events: successful logins, failed attempts, probes of /wp-login.php and of /wp-admin while signed out (answered 404), password resets, and lockouts from Limit Login Attempts. Kept %s days. Unique IPs are counted from hashes held for one day; no IP address, raw or hashed, is kept longer.', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) );
}

/**
 * What a prune did, in words (2026-10-12 audit: "LLA delta +N" and "(s)").
 *
 * @param array{counter_buckets_dropped:int,login_rows_dropped:int,lla_delta:int} $stats snt_audit_prune_impl().
 * @return string
 */
function snt_audit_prune_notice_text( $stats ) {
	$days   = (int) ( $stats['counter_buckets_dropped'] ?? 0 );
	$logins = (int) ( $stats['login_rows_dropped'] ?? 0 );
	$locks  = (int) ( $stats['lla_delta'] ?? 0 );
	return sprintf(
		/* translators: 1: days of counts deleted, 2: login records deleted, 3: new lockouts. */
		__( '%1$s, %2$s, and %3$s.', 'signal-and-noise-tools' ),
		/* translators: %s: days. */
		sprintf( _n( '%s day of counts deleted', '%s days of counts deleted', $days, 'signal-and-noise-tools' ), number_format_i18n( $days ) ),
		/* translators: %s: records. */
		sprintf( _n( '%s login record deleted', '%s login records deleted', $logins, 'signal-and-noise-tools' ), number_format_i18n( $logins ) ),
		/* translators: %s: lockouts. */
		sprintf( _n( '%s new lockout from Limit Login Attempts', '%s new lockouts from Limit Login Attempts', $locks, 'signal-and-noise-tools' ), number_format_i18n( $locks ) )
	);
}

/**
 * The question Prune now asks first (2026-10-12 audit: it deleted rows with no confirm).
 *
 * @param int $days Retention days.
 * @return string
 */
function snt_audit_prune_confirm_text( $days ) {
	/* translators: %s: retention days. */
	return sprintf( __( 'Delete the audit records older than %s days now? This cannot be undone.', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) );
}
