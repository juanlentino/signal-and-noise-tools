<?php
/**
 * Signal & Noise Tools — the keyring's change log (19.6.2).
 *
 * Every write to a keyring row's option, by ANY code path, leaves one line:
 * when, which row, set or clear, the value's last four characters, and the
 * context that wrote it. Hooked on the option actions, not the save handler,
 * so a writer nobody expected is caught too. Never the value itself.
 * Read through `signal-noise/keyring-status` (`changes`).
 *
 * @package SignalNoiseTools
 * @since 19.6.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_KEYRING_CHANGES_OPT = 'sn_keyring_changes';
const SN_KEYRING_CHANGES_CAP = 50;

/**
 * Who is writing: the keyring form when it said so, else the request kind.
 *
 * @return string
 */
function sn_keyring_change_source() {
	if ( ! empty( $GLOBALS['sn_keyring_write_source'] ) ) {
		return (string) $GLOBALS['sn_keyring_write_source'];
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return 'cli';
	}
	if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
		return 'cron';
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return 'rest';
	}
	return function_exists( 'is_admin' ) && is_admin() ? 'admin' : 'other';
}

/**
 * Record one change to a keyring option. Ignores every other option.
 *
 * @param string $option Option name.
 * @param mixed  $value  New value ('' or null on delete).
 * @return void
 */
function sn_keyring_record_change( $option, $value ) {
	static $map = null;
	if ( null === $map ) {
		$map = array();
		foreach ( sn_keyring() as $id => $row ) {
			if ( isset( $row['option'] ) ) {
				$map[ (string) $row['option'] ] = array( $id, (string) $row['kind'] );
			}
		}
		$map[ SN_KEYRING_SITE_ROWS ] = array( 'site_rows', 'list' );
	}
	if ( ! isset( $map[ $option ] ) ) {
		return;
	}
	list( $id, $kind ) = $map[ $option ];
	$v    = is_array( $value ) ? implode( ',', array_map( 'strval', $value ) ) : (string) $value;
	$tail = 'list' === $kind ? $v : ( strlen( $v ) >= 8 ? substr( $v, -4 ) : '' );
	$log  = get_option( SN_KEYRING_CHANGES_OPT, array() );
	$log  = is_array( $log ) ? $log : array();
	$log[] = array(
		'at'     => time(),
		'row'    => $id,
		'verb'   => '' === $v ? 'clear' : 'set',
		'last4'  => $tail,
		'source' => sn_keyring_change_source(),
		'user'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
	);
	update_option( SN_KEYRING_CHANGES_OPT, array_slice( $log, -SN_KEYRING_CHANGES_CAP ), false );
}

/** @return array<int,array<string,mixed>> The log, oldest first. */
function sn_keyring_changes() {
	$log = get_option( SN_KEYRING_CHANGES_OPT, array() );
	return is_array( $log ) ? $log : array();
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'added_option', static function ( $option, $value ) { sn_keyring_record_change( (string) $option, $value ); }, 10, 2 );
	add_action( 'updated_option', static function ( $option, $old, $value ) { if ( $old !== $value ) { sn_keyring_record_change( (string) $option, $value ); } }, 10, 3 );
	add_action( 'deleted_option', static function ( $option ) { sn_keyring_record_change( (string) $option, '' ); }, 10, 1 );
}
