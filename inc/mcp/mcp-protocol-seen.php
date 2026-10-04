<?php
/**
 * Signal & Noise: which MCP protocol version the local doors' clients announce.
 *
 * Both doors serve two protocol generations. Before a release drops one, the
 * decision needs what clients actually send, not what the specification says
 * they should: a daily count per door and announced version, and when each
 * was last seen (kept across days, so a version that stops arriving shows a
 * date going stale). The remote worker counts the same thing on its status
 * page (sn-remote-mcp 1.13.0).
 *
 * Counted after the route's permission check, so these are real clients. An
 * instrument, never a gate: nothing here can refuse or delay a request.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MCP_PROTOCOL_SEEN_OPT = 'sn_mcp_protocol_seen';
const SN_MCP_PROTOCOL_SEEN_CAP = 12; // unrecognized labels kept by name, per door; a new one past this is `other`.

/**
 * The version a request announces, wherever it says so. PURE. The header is
 * optional in the 2026-07-28 generation (the version is required in the body's
 * `_meta`), a handshake carries it in `initialize`'s params, and an older
 * client sends none at all. Reading the header alone would file a modern
 * client under `none`.
 *
 * @param array<string,string> $headers Lowercase header name => value.
 * @param mixed                $decoded The decoded JSON-RPC message.
 * @return string
 */
function sn_mcp_announced_protocol( $headers, $decoded ) {
	$header = is_array( $headers ) && isset( $headers['mcp-protocol-version'] ) ? (string) $headers['mcp-protocol-version'] : '';
	if ( '' !== $header ) {
		return $header;
	}
	$params = is_array( $decoded ) && isset( $decoded['params'] ) && is_array( $decoded['params'] ) ? $decoded['params'] : array();
	$meta   = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();
	$key    = ( defined( 'SN_MCP_META_PREFIX' ) ? SN_MCP_META_PREFIX : 'io.modelcontextprotocol/' ) . 'protocolVersion';
	if ( isset( $meta[ $key ] ) && is_string( $meta[ $key ] ) && '' !== $meta[ $key ] ) {
		return $meta[ $key ];
	}
	if ( 'initialize' === ( is_array( $decoded ) ? ( $decoded['method'] ?? '' ) : '' ) && isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) && '' !== $params['protocolVersion'] ) {
		return $params['protocolVersion'];
	}
	return 'none';
}

/**
 * Fold one announcement into the stored reading. PURE.
 *
 * @param array  $state The stored reading (any shape; repaired).
 * @param string $door  The door's name.
 * @param string $label The announced version, as sent.
 * @param array  $known Versions the doors serve: these always keep their name.
 * @param int    $now   Unix time.
 * @return array { day, today: { door: { label: n } }, last_seen: { door: { label: ts } } }
 */
function sn_mcp_protocol_seen_fold( $state, $door, $label, array $known, $now ) {
	$day   = gmdate( 'Y-m-d', (int) $now );
	$state = is_array( $state ) ? $state : array();
	$today = ( $state['day'] ?? '' ) === $day && is_array( $state['today'] ?? null ) ? $state['today'] : array();
	$seen  = is_array( $state['last_seen'] ?? null ) ? $state['last_seen'] : array();
	$door  = (string) preg_replace( '/[^a-z_]/', '', (string) $door );
	$label = substr( (string) preg_replace( '/[^0-9a-z-]/', '', strtolower( (string) $label ) ), 0, 32 );
	$label = '' === $label ? 'none' : $label;
	$mine  = is_array( $seen[ $door ] ?? null ) ? $seen[ $door ] : array();
	// A client can announce any string, and the stamps are kept: bound the set.
	if ( 'none' !== $label && ! in_array( $label, $known, true ) && ! isset( $mine[ $label ] ) ) {
		$unknown = count( array_diff( array_keys( $mine ), $known, array( 'none', 'other' ) ) );
		if ( $unknown >= SN_MCP_PROTOCOL_SEEN_CAP ) {
			$label = 'other';
		}
	}
	// A bucket that is not an array (a hand edit, an older shape) is replaced,
	// never indexed: this runs inline in the dispatch.
	$bucket                   = is_array( $today[ $door ] ?? null ) ? $today[ $door ] : array();
	$bucket[ $label ]         = ( is_numeric( $bucket[ $label ] ?? null ) ? (int) $bucket[ $label ] : 0 ) + 1;
	$today[ $door ]           = $bucket;
	$mine[ $label ]           = (int) $now;
	$seen[ $door ]            = $mine;
	return array( 'day' => $day, 'today' => $today, 'last_seen' => $seen );
}

/**
 * Count one request. Called from the dispatch, after the permission check.
 *
 * @param string               $door    The door's name.
 * @param array<string,string> $headers Request headers.
 * @param mixed                $decoded The decoded message.
 * @return void
 */
function sn_mcp_protocol_seen_record( $door, $headers, $decoded ) {
	if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
		return;
	}
	$known = array_merge(
		function_exists( 'sn_mcp_legacy_protocol_versions' ) ? sn_mcp_legacy_protocol_versions() : array(),
		defined( 'SN_MCP_MODERN_VERSIONS' ) ? SN_MCP_MODERN_VERSIONS : array()
	);
	// An instrument, never a gate: whatever goes wrong here, the request goes on.
	// ponytail: read-modify-write on one option, so two simultaneous requests
	// can lose a count; one row per door and label if the counts ever matter
	// more than "was this version seen".
	try {
		update_option( SN_MCP_PROTOCOL_SEEN_OPT, sn_mcp_protocol_seen_fold( get_option( SN_MCP_PROTOCOL_SEEN_OPT, array() ), $door, sn_mcp_announced_protocol( $headers, $decoded ), $known, time() ), false );
	} catch ( \Throwable $e ) {
		return;
	}
}

/**
 * The reading, for a readout: today's counts (empty when the stored day is
 * not today) and the kept last-seen stamps as ISO times.
 *
 * @param int|null $now Unix time; null reads the clock.
 * @return array { day, today, last_seen }
 */
function sn_mcp_protocol_seen( $now = null ) {
	$now   = null === $now ? time() : (int) $now;
	$state = function_exists( 'get_option' ) ? get_option( SN_MCP_PROTOCOL_SEEN_OPT, array() ) : array();
	$state = is_array( $state ) ? $state : array();
	$day   = gmdate( 'Y-m-d', $now );
	$seen  = array();
	foreach ( (array) ( $state['last_seen'] ?? array() ) as $door => $labels ) {
		foreach ( (array) $labels as $label => $ts ) {
			$seen[ (string) $door ][ (string) $label ] = gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts );
		}
	}
	return array(
		'day'       => $day,
		'today'     => ( $state['day'] ?? '' ) === $day && is_array( $state['today'] ?? null ) ? $state['today'] : array(),
		'last_seen' => $seen,
	);
}
