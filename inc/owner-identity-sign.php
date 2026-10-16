<?php
/**
 * Signal & Noise Tools: the owner identity signer (SN Traffic Lanes, Phase 1).
 *
 * Two bearer formats, one HMAC construction, shared byte for byte with the
 * analytics Worker and the rights-signals Worker (build contract and test
 * vectors: docs/plans/traffic-lanes-2026-10-15.md):
 *
 *   device marker  cookie `sn_owner_dev` = v1.<device_id>.<YYYYMMDD>.<sig>,
 *                  sig over `v1|dev|<device_id>|<YYYYMMDD>`.
 *   agent token    header `SN-Owner-Agent` = v1.<agent>.<exp>.<sig>,
 *                  sig over `v1|agent|<agent>|<exp>`.
 *
 * sig = base64url, no padding, of HMAC-SHA256 keyed with the 32 raw bytes a
 * 64-hex key decodes to. Verification tries the current key, then the
 * previous one, and compares in constant time against the CANONICAL encoding
 * (43 characters, the last one's two unused bits zero), so `...Lh` never
 * passes for `...Lg`. Pure: no WordPress calls, so
 * the Workers' vectors pin it standalone.
 *
 * @package SignalNoiseTools
 * @since 2026.10.18
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_OWNER_MARKER_MAX_DAYS = 400;
const SN_OWNER_TOKEN_MAX_TTL   = 43200;
const SN_OWNER_TOKEN_SKEW      = 300;

/**
 * The agent allowlist: the contract's agent codes 1 to 7, in code order.
 *
 * @return array<int,string>
 */
function sn_owner_agents() {
	return array( 'browser', 'chrome-extension', 'claude-code', 'playwright', 'script', 'mcp-local', 'other' );
}

/**
 * Whether a key is the generated shape: 64 hex characters.
 *
 * @param string $hex Key.
 * @return bool
 */
function sn_owner_key_ok( $hex ) {
	$hex = (string) $hex;
	return 64 === strlen( $hex ) && ctype_xdigit( $hex );
}

/**
 * base64url, no padding, of HMAC-SHA256( hex2bin( key ), message ).
 *
 * @param string $hex     64-hex key.
 * @param string $message Message.
 * @return string '' when the key is not the generated shape.
 */
function sn_owner_sign( $hex, $message ) {
	if ( ! sn_owner_key_ok( $hex ) ) {
		return '';
	}
	$raw = hash_hmac( 'sha256', (string) $message, (string) hex2bin( (string) $hex ), true );
	return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
}

/**
 * Which key signed a message: 'current', 'previous' or '' (neither).
 *
 * @param array<int,string> $keys    Current key, then previous; '' for unset.
 * @param string            $message Message.
 * @param string            $sig     Presented signature.
 * @return string
 */
function sn_owner_matching_key( array $keys, $message, $sig ) {
	foreach ( array( 'current', 'previous' ) as $i => $name ) {
		$want = sn_owner_sign( (string) ( $keys[ $i ] ?? '' ), $message );
		if ( '' !== $want && hash_equals( $want, (string) $sig ) ) {
			return $name;
		}
	}
	return '';
}

/**
 * The device marker for an id and a UTC day.
 *
 * @param string $hex Key.
 * @param string $id  16 lowercase hex.
 * @param string $day YYYYMMDD.
 * @return string '' without a key.
 */
function sn_owner_device_marker( $hex, $id, $day ) {
	$sig = sn_owner_sign( $hex, 'v1|dev|' . $id . '|' . $day );
	return '' === $sig ? '' : 'v1.' . $id . '.' . $day . '.' . $sig;
}

/**
 * Verify a device marker. Rejects a malformed value, a day that is not a
 * date, a day more than 400 days before $today, and a signature neither key
 * made.
 *
 * @param string            $value Cookie value.
 * @param array<int,string> $keys  Current, previous.
 * @param string            $today YYYYMMDD, UTC.
 * @return array{id:string,day:string,key:string}|null
 */
function sn_owner_device_verify( $value, array $keys, $today ) {
	if ( ! preg_match( '/^v1\.([0-9a-f]{16})\.([0-9]{8})\.([A-Za-z0-9_-]{43})$/', (string) $value, $m ) ) {
		return null;
	}
	$utc    = new DateTimeZone( 'UTC' );
	$issued = DateTimeImmutable::createFromFormat( '!Ymd', $m[2], $utc );
	$now    = DateTimeImmutable::createFromFormat( '!Ymd', (string) $today, $utc );
	if ( ! $issued || ! $now || $issued->format( 'Ymd' ) !== $m[2] ) {
		return null;
	}
	if ( ( $now->getTimestamp() - $issued->getTimestamp() ) > SN_OWNER_MARKER_MAX_DAYS * 86400 ) {
		return null;
	}
	$key = sn_owner_matching_key( $keys, 'v1|dev|' . $m[1] . '|' . $m[2], $m[3] );
	return '' === $key ? null : array( 'id' => $m[1], 'day' => $m[2], 'key' => $key );
}

/**
 * The agent token for an allowlisted agent and an expiry.
 *
 * @param string $hex   Key.
 * @param string $agent Allowlisted agent.
 * @param int    $exp   Unix seconds.
 * @return string '' without a key or for an agent off the list.
 */
function sn_owner_agent_token( $hex, $agent, $exp ) {
	if ( ! in_array( (string) $agent, sn_owner_agents(), true ) ) {
		return '';
	}
	$sig = sn_owner_sign( $hex, 'v1|agent|' . $agent . '|' . (int) $exp );
	return '' === $sig ? '' : 'v1.' . $agent . '.' . (int) $exp . '.' . $sig;
}

/**
 * Verify an agent token: valid iff a key signed it AND now < exp AND
 * exp - now <= 43200 + 300.
 *
 * @param string            $value Header value.
 * @param array<int,string> $keys  Current, previous.
 * @param int               $now   Unix seconds.
 * @return array{agent:string,exp:int,key:string}|null
 */
function sn_owner_agent_verify( $value, array $keys, $now ) {
	if ( ! preg_match( '/^v1\.([a-z-]+)\.([1-9][0-9]{0,11})\.([A-Za-z0-9_-]{43})$/', (string) $value, $m ) || ! in_array( $m[1], sn_owner_agents(), true ) ) {
		return null;
	}
	$exp = (int) $m[2];
	if ( ! ( (int) $now < $exp ) || $exp - (int) $now > SN_OWNER_TOKEN_MAX_TTL + SN_OWNER_TOKEN_SKEW ) {
		return null;
	}
	$key = sn_owner_matching_key( $keys, 'v1|agent|' . $m[1] . '|' . $m[2], $m[3] );
	return '' === $key ? null : array( 'agent' => $m[1], 'exp' => $exp, 'key' => $key );
}
