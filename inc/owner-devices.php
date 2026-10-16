<?php
/**
 * Signal & Noise Tools: owner device enrollment (SN Traffic Lanes, Phase 1).
 *
 * An enrolled device carries `sn_owner_dev` (inc/owner-identity-sign.php): a
 * signed, HttpOnly marker the analytics Worker verifies on each beacon, so
 * the owner's own views land in lane `owner` instead of `human`. The plugin
 * never sees a beacon; it only enrolls, lists, renews and revokes.
 *
 * THE ONE RULE. The marker is set only on a wp-admin response: the enroll
 * action (admin-post.php, or the native window's authenticated POST
 * dispatch) and the daily renewal on `admin_init`. Never on a front-end
 * render: a Set-Cookie on a page Cloudflare caches would hand the marker to
 * every reader. sn_owner_dev_cookie_allowed() is that gate, and the one
 * writer below is the only place that calls setcookie() for it.
 *
 * Storage: `sn_owner_devices` (id, label, enrolled, last_seen; no secrets)
 * and `sn_owner_revoked` (ids, at), both autoload off. Screens never show an
 * id: a row is revoked by its index, bound to its id by a per-row nonce.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_OWNER_DEVICES_OPT   = 'sn_owner_devices';
const SN_OWNER_REVOKED_OPT   = 'sn_owner_revoked';
const SN_OWNER_DEV_COOKIE    = 'sn_owner_dev';
const SN_OWNER_DEV_TTL       = 34560000; // 400 days, the browser cap.
const SN_OWNER_LABEL_MAX     = 60;
const SN_OWNER_REVOKED_MAX   = 1000; // the newest ids kept and served, as the Worker keeps them

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
 * @param array<int,array<string,mixed>> $devices Devices.
 * @return void
 */
function sn_owner_devices_save( array $devices ) {
	update_option( SN_OWNER_DEVICES_OPT, array_values( $devices ), false );
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

/** @return array<int,string> The device keys: current, previous. */
function sn_owner_device_keys() {
	return array( sn_credential( 'owner_device_key' ), sn_credential( 'owner_device_key_previous' ) );
}

/**
 * The enrolled, non-revoked device THIS browser's marker names, or null.
 *
 * @param int $now Unix time.
 * @return array{index:int,device:array<string,mixed>,marker:array{id:string,day:string,key:string}}|null
 */
function sn_owner_this_device( $now ) {
	$raw    = isset( $_COOKIE[ SN_OWNER_DEV_COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ SN_OWNER_DEV_COOKIE ] ) ) : '';
	$marker = '' === $raw ? null : sn_owner_device_verify( $raw, sn_owner_device_keys(), gmdate( 'Ymd', (int) $now ) );
	if ( null === $marker || in_array( $marker['id'], sn_owner_revoked()['ids'], true ) ) {
		return null;
	}
	foreach ( sn_owner_devices() as $i => $d ) {
		if ( (string) ( $d['id'] ?? '' ) === $marker['id'] ) {
			return array( 'index' => $i, 'device' => $d, 'marker' => $marker );
		}
	}
	return null;
}

/**
 * Whether this request may carry the marker's Set-Cookie: a wp-admin request,
 * or the native window's authenticated POST dispatch (a REST POST, never
 * cached). Never a front-end GET render.
 *
 * @return bool
 */
function sn_owner_dev_cookie_allowed() {
	if ( function_exists( 'is_admin' ) && is_admin() ) {
		return true;
	}
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : '';
	return defined( 'REST_REQUEST' ) && REST_REQUEST && 'POST' === $method && current_user_can( 'manage_options' );
}

/**
 * The ONE writer of `sn_owner_dev`. '' expires it.
 *
 * @param string $value Marker, or '' to expire.
 * @param int    $now   Unix time.
 * @return array<string,mixed>|null The setcookie() options, or null when refused.
 */
function sn_owner_dev_cookie_write( $value, $now ) {
	if ( ! sn_owner_dev_cookie_allowed() ) {
		return null;
	}
	// Host-only: no `domain`. Read by the Worker, never by page JS.
	$opts = array( 'expires' => '' === $value ? 1 : (int) $now + SN_OWNER_DEV_TTL, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' );
	if ( ! headers_sent() ) {
		setcookie( SN_OWNER_DEV_COOKIE, (string) $value, $opts );
	}
	if ( '' === $value ) {
		unset( $_COOKIE[ SN_OWNER_DEV_COOKIE ] );
	} else {
		$_COOKIE[ SN_OWNER_DEV_COOKIE ] = (string) $value;
	}
	return $opts;
}

/**
 * Enroll this browser under a label: a new id, the device row, the marker.
 *
 * @param string $label Label.
 * @param int    $now   Unix time.
 * @return string Flash code.
 */
function sn_owner_device_enroll( $label, $now ) {
	$label = trim( (string) $label );
	$key   = sn_owner_device_keys()[0];
	if ( ! sn_owner_key_ok( $key ) ) {
		return 'owner_device_no_key';
	}
	if ( '' === $label ) {
		return 'owner_device_no_label';
	}
	if ( null !== sn_owner_this_device( $now ) ) {
		return 'owner_device_already';
	}
	$id = bin2hex( random_bytes( 8 ) );
	if ( null === sn_owner_dev_cookie_write( sn_owner_device_marker( $key, $id, gmdate( 'Ymd', (int) $now ) ), $now ) ) {
		return 'owner_device_not_admin';
	}
	$devices   = sn_owner_devices();
	$devices[] = array( 'id' => $id, 'label' => function_exists( 'mb_substr' ) ? mb_substr( $label, 0, SN_OWNER_LABEL_MAX ) : substr( $label, 0, SN_OWNER_LABEL_MAX ), 'enrolled' => (int) $now, 'last_seen' => (int) $now );
	sn_owner_devices_save( $devices );
	return 'owner_device_enrolled';
}

/**
 * The nonce action that binds a row index to the id stored there, so a
 * screen can revoke by index without ever painting the id.
 *
 * @param int    $index Row index.
 * @param string $id    Device id at that index.
 * @return string
 */
function sn_owner_row_nonce_action( $index, $id ) {
	return 'sn_owner_revoke:' . (int) $index . ':' . (string) $id;
}

/**
 * Revoke one device by row index and its row nonce.
 *
 * @param int    $index Row index.
 * @param string $nonce Row nonce.
 * @param int    $now   Unix time.
 * @return string Flash code.
 */
function sn_owner_device_revoke( $index, $nonce, $now ) {
	$devices = sn_owner_devices();
	$id      = (string) ( $devices[ (int) $index ]['id'] ?? '' );
	if ( '' === $id || ! wp_verify_nonce( (string) $nonce, sn_owner_row_nonce_action( (int) $index, $id ) ) ) {
		return 'owner_device_list_changed';
	}
	$this_device = sn_owner_this_device( $now );
	sn_owner_revoke_ids( array( $id ), $now );
	unset( $devices[ (int) $index ] );
	sn_owner_devices_save( $devices );
	if ( null !== $this_device && $this_device['marker']['id'] === $id ) {
		sn_owner_dev_cookie_write( '', $now );
	}
	return 'owner_device_revoked';
}

/**
 * Revoke every enrolled device: every id onto the revoked list (effective
 * within one Worker cron tick). Rotating the key is a separate step.
 *
 * @param int $now Unix time.
 * @return string Flash code.
 */
function sn_owner_device_revoke_all( $now ) {
	$ids = array_values( array_filter( array_map( static function ( $d ) { return (string) ( $d['id'] ?? '' ); }, sn_owner_devices() ) ) );
	if ( array() === $ids ) {
		return 'owner_device_none';
	}
	$this_device = sn_owner_this_device( $now );
	sn_owner_revoke_ids( $ids, $now );
	sn_owner_devices_save( array() );
	if ( null !== $this_device ) {
		sn_owner_dev_cookie_write( '', $now );
	}
	return 'owner_device_revoked_all';
}

/**
 * Renewal: an enrolled, non-revoked device whose marker is from an earlier
 * day, or was signed with the previous key, is re-signed with the current
 * key today; last_seen moves with it. At most one write a day per device.
 *
 * @param int $now Unix time.
 * @return bool Whether a marker was renewed.
 */
function sn_owner_device_renew( $now ) {
	// Every wp-admin request runs this: without a marker cookie, read nothing.
	if ( empty( $_COOKIE[ SN_OWNER_DEV_COOKIE ] ) ) {
		return false;
	}
	$today = gmdate( 'Ymd', (int) $now );
	$mine  = sn_owner_this_device( $now );
	$key   = sn_owner_device_keys()[0];
	if ( null === $mine || ! sn_owner_key_ok( $key ) || ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	if ( $mine['marker']['day'] >= $today && 'current' === $mine['marker']['key'] ) {
		return false;
	}
	if ( null === sn_owner_dev_cookie_write( sn_owner_device_marker( $key, $mine['marker']['id'], $today ), $now ) ) {
		return false;
	}
	$devices                                  = sn_owner_devices();
	$devices[ $mine['index'] ]['last_seen'] = (int) $now;
	sn_owner_devices_save( $devices );
	return true;
}

/** admin_init only: wp-admin requests, never a front-end render. */
function sn_owner_device_renew_hook() {
	sn_owner_device_renew( time() );
}
add_action( 'admin_init', 'sn_owner_device_renew_hook' );
