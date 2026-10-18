<?php
/**
 * Signal & Noise Tools: the account hooks that feed the audit log's changes.
 *
 * Each callback observes a core action after the change happened and records
 * one row through snt_audit_record_change_impl() (inc/audit-log-changes.php).
 * None of them blocks, alters or second-guesses the change.
 *
 * Account hooks here; settings, plugin and theme hooks in
 * inc/audit-log-changes-site.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An account's login, or its ID when the account is already gone.
 *
 * @param int $user_id User ID.
 * @return string
 */
function snt_audit_change_login( $user_id ) {
	$user = get_userdata( (int) $user_id );
	return $user ? (string) $user->user_login : '#' . (int) $user_id;
}

/**
 * Role rows from add_user_role / remove_user_role wait here until shutdown.
 * Core's WP_User::set_role() fires remove_user_role and add_user_role and
 * then set_user_role for the same change, and a new account's role arrives
 * the same way before user_register; set_user_role and user_register drop
 * the waiting rows for their user and record the one row that says it all.
 * A standalone add_role() / remove_role() is flushed at shutdown.
 *
 * @param string $op      add, drop or take.
 * @param int    $user_id User ID (add, drop).
 * @param array  $row     Arguments for snt_audit_record_change_impl() (add).
 * @return array<int,array<int,array>> The waiting rows (take).
 */
function snt_audit_change_role_buffer( $op, $user_id = 0, array $row = array() ) {
	static $pending = array();
	if ( 'add' === $op ) {
		$pending[ (int) $user_id ][] = $row;
	} elseif ( 'drop' === $op ) {
		unset( $pending[ (int) $user_id ] );
	} else {
		$all     = $pending;
		$pending = array();
		return $all;
	}
	return array();
}

/**
 * shutdown: record the role rows nothing else accounted for.
 */
function snt_audit_change_flush_roles_cb() {
	foreach ( snt_audit_change_role_buffer( 'take' ) as $rows ) {
		foreach ( $rows as $args ) {
			snt_audit_record_change_impl( ...$args );
		}
	}
}
add_action( 'shutdown', 'snt_audit_change_flush_roles_cb' );

/**
 * user_register: a new account, with its roles. A new administrator is high.
 *
 * @param int $user_id New user ID.
 */
function snt_audit_change_user_register_cb( $user_id ) {
	snt_audit_change_role_buffer( 'drop', $user_id );
	$user  = get_userdata( (int) $user_id );
	$roles = $user ? array_values( (array) $user->roles ) : array();
	snt_audit_record_change_impl( 'user_created', snt_audit_change_login( $user_id ), implode( ', ', $roles ), in_array( 'administrator', $roles, true ) );
}
add_action( 'user_register', 'snt_audit_change_user_register_cb', 10, 1 );

/**
 * set_user_role: one role replaced another.
 *
 * Core sets a new account's role just before it fires user_register, which
 * already records the role. That first set has no old roles and an account
 * registered during this request, so it is skipped here; an older account
 * with no role being given one is still recorded.
 * ponytail: "registered this request" is second-resolution; two accounts made
 * and re-roled in one request would drop the second set.
 *
 * @param int      $user_id   User ID.
 * @param string   $role      New role.
 * @param string[] $old_roles Previous roles.
 */
function snt_audit_change_set_role_cb( $user_id, $role, $old_roles = array() ) {
	snt_audit_change_role_buffer( 'drop', $user_id );
	$old_roles = array_values( (array) $old_roles );
	if ( array() === $old_roles ) {
		$user  = get_userdata( (int) $user_id );
		$start = (int) ( $_SERVER['REQUEST_TIME'] ?? time() );
		if ( $user && strtotime( $user->user_registered . ' UTC' ) >= $start ) {
			return;
		}
	}
	$from = array() === $old_roles ? __( 'no role', 'signal-and-noise-tools' ) : implode( ', ', $old_roles );
	snt_audit_record_change_impl( 'role_set', snt_audit_change_login( $user_id ), $from . ' → ' . (string) $role, 'administrator' === $role );
}
add_action( 'set_user_role', 'snt_audit_change_set_role_cb', 10, 3 );

/**
 * add_user_role: a role added beside the others. Administrator is high.
 *
 * @param int    $user_id User ID.
 * @param string $role    Added role.
 */
function snt_audit_change_add_role_cb( $user_id, $role ) {
	snt_audit_change_role_buffer( 'add', $user_id, array( 'role_added', snt_audit_change_login( $user_id ), (string) $role, 'administrator' === $role ) );
}
add_action( 'add_user_role', 'snt_audit_change_add_role_cb', 10, 2 );

/**
 * remove_user_role: a role taken away.
 *
 * @param int    $user_id User ID.
 * @param string $role    Removed role.
 */
function snt_audit_change_remove_role_cb( $user_id, $role ) {
	snt_audit_change_role_buffer( 'add', $user_id, array( 'role_removed', snt_audit_change_login( $user_id ), (string) $role ) );
}
add_action( 'remove_user_role', 'snt_audit_change_remove_role_cb', 10, 2 );

/**
 * profile_update: records only that an account's email changed, never the
 * old or the new address.
 *
 * @param int     $user_id       User ID.
 * @param WP_User $old_user_data The account before the save.
 */
function snt_audit_change_profile_update_cb( $user_id, $old_user_data = null ) {
	$user = get_userdata( (int) $user_id );
	if ( ! $user || ! is_object( $old_user_data ) || (string) $old_user_data->user_email === (string) $user->user_email ) {
		return;
	}
	snt_audit_record_change_impl( 'email_changed', (string) $user->user_login );
}
add_action( 'profile_update', 'snt_audit_change_profile_update_cb', 10, 2 );
