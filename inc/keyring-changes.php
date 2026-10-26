<?php
/**
 * Signal & Noise Tools — the keyring's change log (19.6.2).
 *
 * Every write to a keyring row's option, by ANY code path, leaves one line:
 * when, which row, set / clear / switch_site / switch_saved, a fingerprint of
 * the value written, and the context that wrote it. Hooked on the option
 * actions, not the save handler, so a writer nobody expected is caught too.
 * Never the value, nor any part of it: until 2026-10-26 each line carried the
 * value's last four characters, which the read door then served to agents
 * (docs/audits/2026-10-26-mcp-doors.md, N3). Lines from then are scrubbed on
 * read and on the next write.
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
 * The row options, built once per request: option name => array( id, kind ).
 * Every option write in WordPress passes through here, so the per-write cost
 * is one isset() and the registry is never rebuilt per write.
 *
 * @return array<string,array{0:string,1:string}>
 */
function sn_keyring_change_map() {
	static $map = null;
	if ( null === $map ) {
		$map = array();
		foreach ( sn_keyring() as $id => $row ) {
			if ( isset( $row['option'] ) ) {
				$map[ (string) $row['option'] ] = array( (string) $id, (string) $row['kind'] );
			}
		}
	}
	return $map;
}

/**
 * Append one line to the log.
 *
 * @param string $row  Row id.
 * @param string $verb set | clear | switch_site | switch_saved.
 * @param string $fp   sn_keyring_fingerprint() of the value written, or ''.
 * @return void
 */
function sn_keyring_log_append( $row, $verb, $fp = '' ) {
	$log   = sn_keyring_changes(); // the older last-four lines leave storage here
	$log[] = array(
		'at'     => time(),
		'row'    => (string) $row,
		'verb'   => (string) $verb,
		'fp'     => (string) $fp,
		'source' => sn_keyring_change_source(),
		'user'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
	);
	// The log's own option is not a row option, so this write returns at the
	// isset() below and never logs itself.
	update_option( SN_KEYRING_CHANGES_OPT, array_slice( $log, -SN_KEYRING_CHANGES_CAP ), false );
}

/**
 * Record one option write if it is a keyring option. Ignores every other option.
 *
 * @param string $option Option name.
 * @param mixed  $old    Previous value (array() when added or unknown).
 * @param mixed  $value  New value ('' on delete).
 * @return void
 */
function sn_keyring_record_change( $option, $old, $value ) {
	if ( SN_KEYRING_SITE_ROWS === $option ) {
		$before = is_array( $old ) ? array_map( 'strval', $old ) : array();
		$after  = is_array( $value ) ? array_map( 'strval', $value ) : array();
		foreach ( array_diff( $after, $before ) as $id ) {
			sn_keyring_log_append( $id, 'switch_site' );
		}
		foreach ( array_diff( $before, $after ) as $id ) {
			sn_keyring_log_append( $id, 'switch_saved' );
		}
		return;
	}
	$map = sn_keyring_change_map();
	if ( ! isset( $map[ $option ] ) || $old === $value ) {
		return;
	}
	$v = (string) $value;
	sn_keyring_log_append( $map[ $option ][0], '' === $v ? 'clear' : 'set', '' === $v ? '' : sn_keyring_fingerprint( $v ) );
}

/**
 * Eight hex characters that tell two values apart and say nothing about
 * either: an HMAC-SHA256 keyed with the site's own salt (wp-config.php), so a
 * guess cannot be checked offline without it, unlike a plain hash of a weak
 * value. Equal values give equal fingerprints on this site until its salts
 * are rotated. No salt, no fingerprint: an unkeyed one would be checkable.
 *
 * @param string $value The value written.
 * @return string Eight hex characters, or '' when the site has no salt.
 */
function sn_keyring_fingerprint( $value ) {
	$key = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
	if ( '' === $key ) {
		return '';
	}
	return substr( hash_hmac( 'sha256', (string) $value, 'sn-keyring-fp|' . $key ), 0, 8 );
}

/** @return array<int,array<string,mixed>> The log, oldest first; never a last-four. */
function sn_keyring_changes() {
	$log = get_option( SN_KEYRING_CHANGES_OPT, array() );
	if ( ! is_array( $log ) ) {
		return array();
	}
	return array_map(
		static function ( $line ) {
			if ( is_array( $line ) ) {
				unset( $line['last4'] );
				$line += array( 'fp' => '' );
			}
			return $line;
		},
		$log
	);
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'added_option', static function ( $option, $value ) { sn_keyring_record_change( (string) $option, array(), $value ); }, 10, 2 );
	add_action( 'updated_option', static function ( $option, $old, $value ) { sn_keyring_record_change( (string) $option, $old, $value ); }, 10, 3 );
	add_action( 'deleted_option', static function ( $option ) { sn_keyring_record_change( (string) $option, null, '' ); }, 10, 1 );
}
