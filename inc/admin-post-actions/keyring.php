<?php
/**
 * Signal & Noise Tools — admin-post actions: the keyring (15.2.0).
 *
 * `keyring_save`: one form for every credential. Per row, the field
 * `key_<id>` reads: '' or the obscured value → keep; 'clear' → remove;
 * 'site' → derive from the site secret (rows that can); anything else →
 * save it and stop deriving. A row a constant sets is never written.
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
	$site_rows = sn_keyring_site_rows();
	$changed   = 0;
	foreach ( sn_keyring() as $id => $row ) {
		if ( ! isset( $post[ 'key_' . $id ] ) || 'constant' === sn_keyring_source( $id ) ) {
			continue;
		}
		$value = sanitize_text_field( wp_unslash( (string) $post[ 'key_' . $id ] ) );
		if ( '' === $value || 0 === strpos( $value, '••••' ) ) {
			continue;
		}
		$can_derive = 'site' === ( $row['derive'] ?? '' );
		if ( 'site' === $value && $can_derive ) {
			if ( ! in_array( $id, $site_rows, true ) ) {
				$site_rows[] = $id;
				sn_keyring_flush( $row );
				++$changed;
			}
			continue;
		}
		$site_rows = array_values( array_diff( $site_rows, array( $id ) ) );
		if ( 'clear' === $value ) {
			sn_keyring_write( $row, '' );
		} else {
			sn_keyring_write( $row, $value );
		}
		sn_keyring_flush( $row );
		++$changed;
	}
	update_option( SN_KEYRING_SITE_ROWS, $site_rows, false );
	if ( function_exists( 'sn_setting_reset_cache' ) ) {
		sn_setting_reset_cache();
	}
	return $changed > 0 ? 'keyring_saved' : 'keyring_unchanged';
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
