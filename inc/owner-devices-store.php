<?php
/**
 * Signal & Noise Tools: owner device storage (SN Traffic Lanes, Phase 1).
 *
 * `sn_owner_devices` (id, label, enrolled, last_seen, confirmed; no secrets)
 * and `sn_owner_revoked` (ids, at), both autoload off, and the one way to
 * change the device list: under a short add_option() lock, against a fresh
 * read, by id (final review P3, no lost update). Split out of
 * inc/owner-devices.php, which holds the cookie and the actions.
 *
 * @package SignalNoiseTools
 * @since 2026.10.18
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_OWNER_LOCK_OPT      = 'sn_owner_devices_lock';
const SN_OWNER_LOCK_STALE    = 10; // seconds: a lock older than this was left by a dead request

/** @return array<int,array{id:string,label:string,enrolled:int,last_seen:int}> */
function sn_owner_devices() {
	$list = get_option( SN_OWNER_DEVICES_OPT, array() );
	return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
}

/** @return array{ids:array<int,string>,at:int} */
function sn_owner_revoked() {
	$r = get_option( SN_OWNER_REVOKED_OPT, array() );
	$r = is_array( $r ) ? $r : array();
	return array( 'ids' => array_values( array_map( 'strval', (array) ( $r['ids'] ?? array() ) ) ), 'at' => (int) ( $r['at'] ?? 0 ) );
}

/**
 * Run $fn while holding the device-list lock: add_option() inserts the lock
 * row only when it does not exist, which the database does atomically. Waits
 * about a second, breaks a lock a dead request left behind. Final review P3:
 * enroll, renew and revoke never drop a row another request is writing.
 *
 * @param callable $fn What to run under the lock.
 * @return mixed $fn's result, or null when the lock stayed busy.
 */
function sn_owner_devices_locked( callable $fn ) {
	for ( $try = 0; $try < 20; $try++ ) {
		if ( add_option( SN_OWNER_LOCK_OPT, (string) time(), '', false ) ) {
			try {
				return $fn();
			} finally {
				delete_option( SN_OWNER_LOCK_OPT );
			}
		}
		if ( time() - (int) get_option( SN_OWNER_LOCK_OPT, 0 ) > SN_OWNER_LOCK_STALE ) {
			delete_option( SN_OWNER_LOCK_OPT );
			continue;
		}
		usleep( 50000 );
	}
	return null;
}

/**
 * Change the device list against a FRESH read taken under the lock, by id,
 * never by a stale index. Call it inside sn_owner_devices_locked().
 *
 * @param callable $change fn( array $devices ): array.
 * @return void
 */
function sn_owner_devices_change( callable $change ) {
	update_option( SN_OWNER_DEVICES_OPT, array_values( $change( sn_owner_devices() ) ), false );
}

/**
 * Whether a row has been confirmed: its marker came back on a wp-admin
 * request at least once. A row from before the flag existed counts as
 * confirmed.
 *
 * @param array<string,mixed> $device Row.
 * @return bool
 */
function sn_owner_device_confirmed( array $device ) {
	return false !== ( $device['confirmed'] ?? true );
}

/**
 * Add ids to the revoked list.
 *
 * @param array<int,string> $ids Ids.
 * @param int               $now Unix time.
 * @return void
 */
function sn_owner_revoke_ids( array $ids, $now ) {
	$r = sn_owner_revoked();
	update_option( SN_OWNER_REVOKED_OPT, array( 'ids' => array_slice( array_values( array_unique( array_merge( $r['ids'], $ids ) ) ), -SN_OWNER_REVOKED_MAX ), 'at' => (int) $now ), false );
}

/** @return array<int,string> The owner keys: current, previous. */
function sn_owner_keys() {
	return array( sn_credential( 'owner_key' ), sn_credential( 'owner_key_previous' ) );
}
