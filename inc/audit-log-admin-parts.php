<?php
/**
 * Signal & Noise Tools: Security › Audit log, the words both twins print.
 *
 * Split from inc/audit-log-admin.php (house line cap) on the 2026-10-12
 * audit, which rewrote them: the lead sentence and the prune notice in plain
 * words (no "(s)"), and the question Prune now asks first. Since 2026-10-18
 * the lockout facts are the login guard's own 7-day decisions. The
 * native leaf (apps/sn-dashboard/parts/leaves/security-audit-log.php) reads
 * the same helpers.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One guard count as both twins print it: the number, or why there is none.
 * A failed read is "unavailable", never 0.
 *
 * @param array{state:string} $guard snt_audit_guard_lockouts().
 * @param string              $key   locked_out, throttled or account_throttled.
 * @return string
 */
function snt_audit_log_guard_value( $guard, $key ) {
	$state = (string) ( $guard['state'] ?? 'unconfigured' );
	if ( 'ok' === $state ) {
		return number_format_i18n( (int) ( $guard[ $key ] ?? 0 ) );
	}
	return 'unavailable' === $state ? __( 'unavailable', 'signal-and-noise-tools' ) : __( 'not configured', 'signal-and-noise-tools' );
}

/**
 * The lockout facts both twins print: the login guard's last 7 days.
 *
 * @param array{state:string} $guard snt_audit_guard_lockouts().
 * @return array<int,array{label:string,value:string}>
 */
function snt_audit_log_guard_rows( $guard ) {
	return array(
		array( 'label' => __( 'Lockouts (last 7 days)', 'signal-and-noise-tools' ), 'value' => snt_audit_log_guard_value( $guard, 'locked_out' ) ),
		array( 'label' => __( 'Throttled (last 7 days)', 'signal-and-noise-tools' ), 'value' => snt_audit_log_guard_value( $guard, 'throttled' ) ),
		array( 'label' => __( 'Account throttled (last 7 days)', 'signal-and-noise-tools' ), 'value' => snt_audit_log_guard_value( $guard, 'account_throttled' ) ),
	);
}

/**
 * Where the lockouts box sends the reader: Security › Login defense.
 *
 * @return string
 */
function snt_audit_log_login_defense_url() {
	return function_exists( 'snt_desktop_admin_url' )
		? snt_desktop_admin_url( 'sn-security', 'login-defense' )
		: admin_url( 'admin.php?page=sn-theme-options&tab=security&sub=login-defense' );
}

/**
 * The tab's lead sentence, in plain words, the same on both twins.
 *
 * @param int $days Retention days.
 * @return string
 */
function snt_audit_log_intro_text( $days ) {
	/* translators: %s: retention days. */
	return sprintf( __( 'Records login events: successful logins, failed attempts, probes of /wp-login.php and of /wp-admin while signed out (answered 404), and password resets; lockouts and throttles come from the login guard at the edge. Also records account and site changes: new accounts, roles, account emails, registration and admin email settings, plugins and the theme. Kept %s days. Unique IPs are counted from hashes held for one day; no IP address, raw or hashed, is kept longer.', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) );
}

/**
 * What a prune did, in words (2026-10-12 audit: no "(s)").
 *
 * @param array{counter_buckets_dropped:int,login_rows_dropped:int,change_rows_dropped:int} $stats snt_audit_prune_impl().
 * @return string
 */
function snt_audit_prune_notice_text( $stats ) {
	$days   = (int) ( $stats['counter_buckets_dropped'] ?? 0 );
	$logins = (int) ( $stats['login_rows_dropped'] ?? 0 );
	$changes = (int) ( $stats['change_rows_dropped'] ?? 0 );
	return sprintf(
		/* translators: 1: days of counts deleted, 2: login records deleted, 3: change records deleted. */
		__( '%1$s, %2$s, and %3$s.', 'signal-and-noise-tools' ),
		/* translators: %s: days. */
		sprintf( _n( '%s day of counts deleted', '%s days of counts deleted', $days, 'signal-and-noise-tools' ), number_format_i18n( $days ) ),
		/* translators: %s: records. */
		sprintf( _n( '%s login record deleted', '%s login records deleted', $logins, 'signal-and-noise-tools' ), number_format_i18n( $logins ) ),
		/* translators: %s: records. */
		sprintf( _n( '%s change record deleted', '%s change records deleted', $changes, 'signal-and-noise-tools' ), number_format_i18n( $changes ) )
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

/**
 * The sentence above Prune now, the same on both twins.
 *
 * @param int $days Retention days.
 * @return string
 */
function snt_audit_prune_lead_text( $days ) {
	/* translators: %s: retention days. */
	return sprintf( __( 'Run the daily cleanup now: it deletes the daily counts, the successful-login records and the change records older than %s days.', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) );
}
