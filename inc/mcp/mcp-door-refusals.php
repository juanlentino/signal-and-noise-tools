<?php
/**
 * Signal & Noise: the refusals, counted. Every write a door declines, set
 * against the rule that declined it.
 *
 * Most refusals are already recorded: anything that reaches a tool call lands
 * in the sn_tool_call log with its outcome and rule (rate limit, permission,
 * invalid input, and every gate an ability itself runs, sn-apply's four
 * included). mcp-door-refusals-read.php reads those. Two kinds of refusal are
 * decided before that log exists, and this file counts them:
 *
 *   1. The write door's own checks (mcp-rw-guard.php, wired in
 *      mcp-endpoint.php): the kill switch, the unbound credential, the wrong
 *      credential, and a user without the capability. They run as the REST
 *      route's permission_callback, so a denial never reaches a tool call.
 *      Observed on rest_request_after_callbacks, after core has decided.
 *   2. The lifecycle guard's permission denials for write abilities run
 *      outside MCP (WordPress 7.1+; abilities-lifecycle-guard.php calls
 *      sn_door_refusals_observe() after its decision is made).
 *
 * OBSERVE ONLY. Nothing here sits in a decision chain: the REST filter returns
 * the response it was handed, and sn_door_refusals_observe() returns its input
 * identically. A failure to count never changes or fails the request.
 *
 * What is stored: a door, a rule code and a count per UTC day, in one
 * non-autoloaded option, 90 days at most, a bounded number of keys per day.
 * Never a payload, an argument, a user or a credential. A request with no
 * authenticated user is not counted, so an anonymous flood cannot become a
 * database write per request.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_DOOR_REFUSALS_OPTION   = 'sn_door_refusals';
const SN_DOOR_REFUSALS_DAYS     = 90;
const SN_DOOR_REFUSALS_DAY_KEYS = 40; // Distinct door|rule keys per day; more fold into door|other.

/**
 * The doors a refusal can be filed under: the write door, and the lifecycle
 * guard's names for a call made outside MCP.
 *
 * @return string[]
 */
function sn_door_refusals_doors() {
	return array( 'rw', 'direct', 'agent', 'remote' );
}

/**
 * A rule code reduced to its safe shape: lowercase letters, digits and
 * underscores, at most 64 characters. Anything else is 'other'.
 *
 * @param mixed $rule
 * @return string
 */
function sn_door_refusals_rule( $rule ) {
	$rule = strtolower( is_scalar( $rule ) ? (string) $rule : '' );
	return 1 === preg_match( '/^[a-z0-9_]{1,64}$/', $rule ) ? $rule : 'other';
}

/**
 * PURE: add one refusal to a stored blob. Drops days older than the window,
 * and folds a new key past the day's cap into door|other.
 *
 * @param mixed  $blob The stored option value.
 * @param string $door
 * @param string $rule
 * @param int    $now  Unix time.
 * @return array{days:array<string,array<string,int>>}
 */
function sn_door_refusals_add( $blob, $door, $rule, $now ) {
	$days = ( is_array( $blob ) && isset( $blob['days'] ) && is_array( $blob['days'] ) ) ? $blob['days'] : array();
	$day  = gmdate( 'Y-m-d', (int) $now );
	$keep = gmdate( 'Y-m-d', (int) $now - ( SN_DOOR_REFUSALS_DAYS - 1 ) * DAY_IN_SECONDS );
	foreach ( array_keys( $days ) as $d ) {
		if ( ! is_string( $d ) || $d < $keep || ! is_array( $days[ $d ] ) ) {
			unset( $days[ $d ] );
		}
	}
	$counts = $days[ $day ] ?? array();
	$key    = $door . '|' . $rule;
	if ( ! isset( $counts[ $key ] ) && count( $counts ) >= SN_DOOR_REFUSALS_DAY_KEYS ) {
		$key = $door . '|other';
	}
	$counts[ $key ] = (int) ( $counts[ $key ] ?? 0 ) + 1;
	$days[ $day ]   = $counts;
	ksort( $days );
	return array( 'days' => $days );
}

/**
 * LIVE: count one refusal. Fail-open: returns false and changes nothing when
 * the door is unknown, nobody is signed in, or the write throws.
 *
 * @param string   $door One of sn_door_refusals_doors().
 * @param mixed    $rule The rule code that declined.
 * @param int|null $now  Clock seam for tests.
 * @return bool True when counted.
 */
function sn_door_refusals_record( $door, $rule, $now = null ) {
	try {
		$door = (string) $door;
		if ( ! in_array( $door, sn_door_refusals_doors(), true ) ) {
			return false;
		}
		if ( ! function_exists( 'get_current_user_id' ) || (int) get_current_user_id() < 1 ) {
			return false;
		}
		$blob = sn_door_refusals_add( get_option( SN_DOOR_REFUSALS_OPTION, array() ), $door, sn_door_refusals_rule( $rule ), null === $now ? time() : (int) $now );
		update_option( SN_DOOR_REFUSALS_OPTION, $blob, false );
		return true;
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * The rule a permission result names: a WP_Error's own code, 'capability' for
 * a plain false. '' for anything that is not a refusal.
 *
 * @param mixed $result
 * @return string
 */
function sn_door_refusals_rule_of( $result ) {
	if ( true === $result ) {
		return '';
	}
	if ( is_object( $result ) && method_exists( $result, 'get_error_code' ) ) {
		return (string) $result->get_error_code();
	}
	return ( false === $result || null === $result ) ? 'capability' : '';
}

/**
 * Observe a permission decision already made, and hand it back untouched.
 *
 * @param string $door
 * @param mixed  $result The decision.
 * @return mixed The same value, identically.
 */
function sn_door_refusals_observe( $door, $result ) {
	$rule = sn_door_refusals_rule_of( $result );
	if ( '' !== $rule ) {
		sn_door_refusals_record( $door, $rule );
	}
	return $result;
}

/**
 * Is an MCP door's REST request being served right now? sn_mcp_call_tool()
 * runs an ability's check_permissions() before it opens the lifecycle guard's
 * depth bracket, and core fires wp_ability_permission_result inside
 * check_permissions(), so on WordPress 7.1 an MCP permission denial reaches the
 * guard at depth 0. The call log already has it; this flag keeps the guard
 * from counting it a second time under the wrong door.
 *
 * @param bool|null $set True or false to set, null to read.
 * @return bool
 */
function sn_door_refusals_in_mcp_request( $set = null ) {
	static $in = false;
	if ( null !== $set ) {
		$in = (bool) $set;
	}
	return $in;
}

/**
 * rest_request_before_callbacks: mark a request on either MCP door.
 *
 * @param mixed $response
 * @param mixed $handler
 * @return mixed The same response.
 */
function sn_door_refusals_rest_mark( $response, $handler ) {
	$cb = is_array( $handler ) ? ( $handler['permission_callback'] ?? null ) : null;
	sn_door_refusals_in_mcp_request( in_array( $cb, array( 'sn_mcp_read_permission', 'sn_mcp_rw_permission' ), true ) );
	return $response;
}
add_filter( 'rest_request_before_callbacks', 'sn_door_refusals_rest_mark', 10, 2 );

/**
 * rest_request_after_callbacks: when the write door's permission_callback
 * declined, core has already put its error in $response. Count it under the
 * rule's own code (sn_mcp_rw_ prefix dropped; rest_forbidden is the
 * capability check). Any other route, or a response the callback itself made,
 * is left alone.
 *
 * @param mixed $response
 * @param mixed $handler
 * @return mixed The same response.
 */
function sn_door_refusals_rest_observer( $response, $handler ) {
	sn_door_refusals_in_mcp_request( false );
	if ( ! is_array( $handler ) || 'sn_mcp_rw_permission' !== ( $handler['permission_callback'] ?? null ) ) {
		return $response;
	}
	$code  = is_object( $response ) && method_exists( $response, 'get_error_code' ) ? (string) $response->get_error_code() : '';
	$rules = function_exists( 'sn_mcp_rw_error_messages' ) ? array_keys( sn_mcp_rw_error_messages() ) : array();
	if ( 'rest_forbidden' === $code ) {
		sn_door_refusals_record( 'rw', 'capability' );
	} elseif ( 0 === strpos( $code, 'sn_mcp_rw_' ) && in_array( substr( $code, 10 ), $rules, true ) ) {
		sn_door_refusals_record( 'rw', substr( $code, 10 ) );
	}
	return $response;
}
add_filter( 'rest_request_after_callbacks', 'sn_door_refusals_rest_observer', 10, 2 );

require_once __DIR__ . '/mcp-door-refusals-read.php';
