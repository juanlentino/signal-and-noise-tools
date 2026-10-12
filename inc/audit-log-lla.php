<?php
/**
 * Signal & Noise Tools: the audit log's reads of Limit Login Attempts
 * Reloaded, split from inc/audit-log.php (house line cap, review on #2022).
 *
 * Verified against LLA's own source (trunk core/LocalLockoutManager.php and
 * core/LockoutCleanupService.php, 2026-10-12): `limit_login_lockouts` maps
 * each locked-out address to the time its lockout ENDS (time() + duration),
 * and expired entries leave only at LLA's next cleanup; LLA also keeps a
 * running total of every lockout in `limit_login_lockouts_total`. LLA fires
 * no action hook, so the daily prune polls.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * New lockouts since the last poll, and the baseline moved forward.
 *
 * 2026-10-12 audit: the count of entries in `limit_login_lockouts` is net of
 * the ones LLA's cleanup dropped, so lockouts that came and went between
 * polls were never counted. LLA's running total counts every lockout; its
 * delta is the day's figure, baselined on first read so the switch adds no
 * spike. Without the total (an older LLA) the entry count stays the fallback.
 *
 * @return int Lockouts since the last poll (0 or more).
 */
function snt_audit_lla_poll_delta() {
	$total = get_option( 'limit_login_lockouts_total', null );
	if ( is_numeric( $total ) ) {
		$current = (int) $total;
		$last    = get_option( SN_AUDIT_LLA_LAST_TOTAL_OPT, null );
		$last    = is_numeric( $last ) ? (int) $last : $current;
		$opt     = SN_AUDIT_LLA_LAST_TOTAL_OPT;
	} else {
		$current = is_array( get_option( 'limit_login_lockouts', array() ) )
			? count( get_option( 'limit_login_lockouts', array() ) )
			: 0;
		$last    = (int) get_option( SN_AUDIT_LLA_LAST_COUNT_OPT, 0 );
		$opt     = SN_AUDIT_LLA_LAST_COUNT_OPT;
	}
	update_option( $opt, $current, false );
	return max( 0, $current - $last );
}

/**
 * Safe read of LLA's `limit_login_lockouts` option.
 * Returns the active count + the latest lockout's end time. Tolerates schema drift / missing option.
 *
 * @return array { active_lockouts: int, most_recent_lockout_ts: int|null }
 */
function snt_audit_read_lla_summary_impl() {
	$lockouts = get_option( 'limit_login_lockouts', array() );
	if ( ! is_array( $lockouts ) ) {
		return array( 'active_lockouts' => 0, 'most_recent_lockout_ts' => null );
	}

	// LLA stores each IP's lockout END time (time() + duration,
	// LocalLockoutManager) and drops expired entries only at its next
	// cleanup, so "active" is an entry still in the future (2026-10-12 audit:
	// every entry was counted). The latest timestamp is when the latest
	// lockout ends, not when it began.
	$count       = 0;
	$most_recent = null;
	foreach ( $lockouts as $ts ) {
		if ( ! is_numeric( $ts ) ) {
			continue;
		}
		$count      += (int) $ts > time() ? 1 : 0;
		$most_recent = (int) $ts > (int) $most_recent ? (int) $ts : $most_recent;
	}

	return array(
		'active_lockouts'        => $count,
		'most_recent_lockout_ts' => $most_recent,
	);
}
