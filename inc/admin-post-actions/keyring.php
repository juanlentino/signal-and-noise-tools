<?php
/**
 * Signal & Noise Tools — admin-post actions: the keyring (15.2.0).
 *
 * `keyring_save`: one row per save. `key_id` names the row; `key_value` is
 * the verb: '' or an obscured value → nothing; 'clear' → remove; 'site' →
 * derive from the site secret (rows that can); anything else → save it and
 * stop deriving. A row a constant sets, or one with no home, is never written.
 * `keyring_verify`: run every probe, store the verdicts.
 *
 * @package SignalNoiseTools
 * @since 15.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array<string,mixed> $post
 * @return string Flash code.
 */
function sn_handle_keyring_save( $post ) {
	if ( ! function_exists( 'sn_keyring' ) ) {
		return 'keyring_unavailable';
	}
	// 15.2.1: one row per save: `key_id` names it, `key_value` is the verb.
	$id    = isset( $post['key_id'] ) ? sanitize_key( (string) $post['key_id'] ) : '';
	$value = isset( $post['key_value'] ) ? sanitize_text_field( wp_unslash( (string) $post['key_value'] ) ) : '';
	$rows  = sn_keyring();
	if ( '' === $id || ! isset( $rows[ $id ] ) ) {
		return 'keyring_unknown_row';
	}
	$row = $rows[ $id ];
	if ( 'constant' === sn_keyring_source( $id ) || ( ! isset( $row['option'] ) && ! isset( $row['setting'] ) ) ) {
		return 'keyring_locked';
	}
	if ( '' === $value || 0 === strpos( $value, '••••' ) ) {
		return 'keyring_unchanged';
	}
	// 15.2.2: a shared secret must be its own value. An issued token pasted
	// into the site secret or a worker row is refused by name; the same value
	// on two rows of any kind is refused too, because one leak then opens both.
	$shared = 'site' === (string) $row['group'];
	if ( 'clear' !== $value && 'site' !== $value ) {
		foreach ( sn_keyring_issued_values() as $other_id => $other ) {
			if ( $other_id !== $id && hash_equals( $other, $value ) ) {
				return $shared ? 'keyring_issued_as_shared' : 'keyring_duplicate';
			}
		}
		foreach ( sn_keyring() as $other_id => $other_row ) {
			if ( $other_id !== $id && 'site' === (string) $other_row['group'] && '' !== sn_credential( $other_id ) && hash_equals( sn_credential( $other_id ), $value ) ) {
				return 'keyring_duplicate';
			}
		}
	}
	$site_rows  = sn_keyring_site_rows();
	$can_derive = 'site' === ( $row['derive'] ?? '' );
	if ( 'site' === $value && $can_derive ) {
		if ( ! in_array( $id, $site_rows, true ) ) {
			$site_rows[] = $id;
		}
	} else {
		$site_rows = array_values( array_diff( $site_rows, array( $id ) ) );
		sn_keyring_write( $row, 'clear' === $value ? '' : $value );
	}
	update_option( SN_KEYRING_SITE_ROWS, $site_rows, false );
	sn_keyring_flush( $row );
	if ( function_exists( 'sn_setting_reset_cache' ) ) {
		sn_setting_reset_cache();
	}
	return 'keyring_saved';
}

/**
 * Write a row's stored value ('' deletes). Options are never autoloaded.
 *
 * @param array<string,mixed> $row   Registry row.
 * @param string              $value Value, '' to delete.
 * @return void
 */
function sn_keyring_write( array $row, $value ) {
	if ( isset( $row['setting'] ) ) {
		if ( function_exists( 'sn_setting_update' ) ) {
			sn_setting_update( (string) $row['setting'], (string) $value );
		}
		return;
	}
	if ( ! isset( $row['option'] ) ) {
		return;
	}
	if ( '' === (string) $value ) {
		delete_option( (string) $row['option'] );
	} else {
		update_option( (string) $row['option'], (string) $value, false );
	}
}

/**
 * @param array<string,mixed> $post
 * @return string Flash code.
 */
function sn_handle_keyring_verify( $post ) {
	unset( $post );
	if ( ! function_exists( 'sn_keyring_verify_all' ) ) {
		return 'keyring_unavailable';
	}
	$verdicts = sn_keyring_verify_all();
	$refused  = 0;
	foreach ( $verdicts as $v ) {
		if ( in_array( (string) ( $v['status'] ?? '' ), array( 'refused', 'error' ), true ) ) {
			++$refused;
		}
	}
	return $refused > 0 ? 'keyring_verified_with_refusals' : 'keyring_verified';
}

/**
 * Drop what a rotated value would otherwise serve stale: the row's transients
 * and its flush function (15.2.1; the deleted per-tab handlers did this each
 * in its own way).
 *
 * @param array<string,mixed> $row Registry row.
 * @return void
 */
function sn_keyring_flush( array $row ) {
	foreach ( (array) ( $row['flush'] ?? array() ) as $key ) {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( (string) $key );
		}
	}
	$fn = (string) ( $row['flush_fn'] ?? '' );
	if ( '' !== $fn && function_exists( $fn ) ) {
		$fn();
	}
}
