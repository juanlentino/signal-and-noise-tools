<?php
/**
 * S&N Dashboard: Security > Audit log, the recent successful logins.
 *
 * Split out of security-audit-log.php (2026-10-11, no folds). The log was a
 * closed disclosure whose heading carried the count; it is an open ledger
 * now: the store cap as the section's context, one finding sentence (shared
 * with the classic tab through snt_audit_log_logins_finding(),
 * inc/audit-log-admin-logins.php), the newest SN_AUDIT_LOGIN_DISPLAY_MAX
 * rows, and the "+N more" line for the rest.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The recent successful-logins ledger, newest first, capped at the AL1
 * display cap with the remainder counted out loud.
 *
 * @param array<int,array{ts:int,user:string,formatted:string}> $logins snt_audit_get_login_successes_impl() result.
 * @return string
 */
function audit_log_logins_html( array $logins ) {
	$heading = __( 'Recent successful logins (last 30 days)', 'signal-and-noise-tools' );
	if ( empty( $logins ) ) {
		return \snt_kit_section( $heading, '<p class="snt-prose">' . \snt_kit_esc( __( 'No successful logins recorded in this window.', 'signal-and-noise-tools' ) ) . '</p>' );
	}

	// Newest first on a copy: a cap over an unsorted list would drop the most
	// recent login, the one a security readout exists to show.
	$sorted = $logins;
	usort(
		$sorted,
		static function ( $a, $b ) {
			return (int) ( $b['ts'] ?? 0 ) <=> (int) ( $a['ts'] ?? 0 );
		}
	);
	$finding = function_exists( 'snt_audit_log_logins_finding' ) ? snt_audit_log_logins_finding( $sorted ) : '';
	$ledger  = \snt_kit_ledger(
		$finding,
		array(
			array( 'key' => 'formatted', 'label' => __( 'Timestamp', 'signal-and-noise-tools' ) ),
			array( 'key' => 'user', 'label' => __( 'User', 'signal-and-noise-tools' ) ),
		),
		$sorted,
		defined( 'SN_AUDIT_LOGIN_DISPLAY_MAX' ) ? (int) SN_AUDIT_LOGIN_DISPLAY_MAX : 50,
		/* translators: %s: number of logins not shown. */
		array( 'more' => __( '+%s more logins; the list is capped, not complete. Newest first, so the tail is the oldest end.', 'signal-and-noise-tools' ) )
	);

	// The STORE's ceiling, named as the section's context. Guarded: the
	// constant belongs to inc/audit-log.php, and a surface must not invent a
	// retention number if that module fails to load.
	$store = defined( 'SN_AUDIT_LOGIN_SUCCESS_CAP' )
		? sprintf(
			/* translators: %s: the storage cap on retained login rows. */
			__( 'The store keeps at most %s successful logins, dropping the oldest first, so a long-enough gap is absence of a record rather than absence of a login.', 'signal-and-noise-tools' ),
			number_format_i18n( SN_AUDIT_LOGIN_SUCCESS_CAP )
		)
		: '';
	return \snt_kit_section( $heading, $ledger, $store );
}
