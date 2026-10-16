<?php
/**
 * Signal & Noise: the refusals, counted, the read half. One table of door ×
 * rule × count over 7 and 30 days, from two sources that never overlap:
 *
 *   - the call log (sn_tool_call), for every refusal that reached a tool call;
 *   - the sn_door_refusals option (mcp-door-refusals.php), for the ones decided
 *     before it: the write door's own checks and the lifecycle guard's.
 *
 * What counts as declined, from the log: outcome refused (rate limit,
 * permission, write throttle), schema_error (the ability rejected what the
 * caller sent, sn-apply's validation and mode gates among them), invalid_input,
 * and conflict (a 409: sn-apply's fingerprint and idempotency gates declining a
 * write against stale state; the classifier calls it a lost race, and it is
 * still a write the door declined). Not counted: not_found (no tool, so no
 * write) and server_error (a failure, not a decision).
 *
 * Which rows are writes: every row on the write door, and rows on any other
 * door whose tool is write-class (the write allowlist plus the abilities held
 * off both doors). The remote door is read-only by construction.
 *
 * The rule is the row's error code, else its refusal gate, else its outcome.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every name a write-class tool is logged under: its slug (calls outside MCP)
 * and its projected tool name (calls through the write door).
 *
 * @return string[]
 */
function sn_door_refusals_write_names() {
	$slugs = sn_mcp_rw_allowlist(); // mcp-capabilities.php loads before this file.
	if ( function_exists( 'sn_ability_guard_held_out_writes' ) ) {
		$slugs = array_merge( $slugs, sn_ability_guard_held_out_writes() );
	}
	$names = array();
	foreach ( $slugs as $slug ) {
		$names[] = (string) $slug;
		$names[] = str_replace( '/', '__', (string) $slug );
	}
	return $names;
}

/**
 * The call log's refusals over 30 days, grouped. Null when the table is
 * missing or the query failed: that is not the same answer as none.
 *
 * @param int $now Unix time.
 * @return array<int,array{door:string,rule:string,d7:int,d30:int}>|null
 */
function sn_door_refusals_from_log( $now ) {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! defined( 'SN_MCP_TELEMETRY_TABLE' ) ) {
		return null;
	}
	$table = $wpdb->prefix . SN_MCP_TELEMETRY_TABLE;
	$d30   = gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS );
	$d7    = gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS );

	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a plugin constant, never user input; both dates bind via prepare().
	$sql = $wpdb->prepare( "SELECT door, tool_name, outcome, refusal_gate, error_code, COUNT(*) AS d30, SUM(ts >= %s) AS d7 FROM {$table} WHERE ts >= %s AND outcome IN ('refused','schema_error','invalid_input','conflict') GROUP BY door, tool_name, outcome, refusal_gate, error_code", $d7, $d30 );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $sql is the string prepare() returned above; the plugin-owned table has no core API.
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	// A missing table returns an empty array with last_error set, never false.
	if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
		return null;
	}

	$writes = sn_door_refusals_write_names();
	$out    = array();
	foreach ( $rows as $row ) {
		$door = (string) ( $row['door'] ?? '' );
		if ( 'rw' !== $door && ! in_array( (string) ( $row['tool_name'] ?? '' ), $writes, true ) ) {
			continue;
		}
		$rule  = (string) ( $row['error_code'] ?? '' );
		$rule  = '' !== $rule ? $rule : (string) ( $row['refusal_gate'] ?? '' );
		$rule  = '' !== $rule ? $rule : (string) ( $row['outcome'] ?? '' );
		$out[] = array( 'door' => $door, 'rule' => $rule, 'd7' => (int) ( $row['d7'] ?? 0 ), 'd30' => (int) ( $row['d30'] ?? 0 ) );
	}
	return $out;
}

/**
 * The option's counts over 7 and 30 days.
 *
 * @param mixed $blob The stored option value.
 * @param int   $now  Unix time.
 * @return array<int,array{door:string,rule:string,d7:int,d30:int}>
 */
function sn_door_refusals_from_option( $blob, $now ) {
	$days = ( is_array( $blob ) && isset( $blob['days'] ) && is_array( $blob['days'] ) ) ? $blob['days'] : array();
	$d7   = gmdate( 'Y-m-d', $now - 6 * DAY_IN_SECONDS );
	$d30  = gmdate( 'Y-m-d', $now - 29 * DAY_IN_SECONDS );
	$out  = array();
	foreach ( $days as $day => $counts ) {
		if ( ! is_string( $day ) || $day < $d30 || ! is_array( $counts ) ) {
			continue;
		}
		foreach ( $counts as $key => $n ) {
			list( $door, $rule ) = array_pad( explode( '|', (string) $key, 2 ), 2, 'other' );
			$out[] = array( 'door' => $door, 'rule' => $rule, 'd7' => $day >= $d7 ? (int) $n : 0, 'd30' => (int) $n );
		}
	}
	return $out;
}

/**
 * PURE: merge rows on door|rule, drop any that never fired in 30 days, sort
 * by the 30-day count, then the 7-day count, then the name.
 *
 * @param array<int,array{door:string,rule:string,d7:int,d30:int}> $rows
 * @return array<int,array{door:string,rule:string,d7:int,d30:int}>
 */
function sn_door_refusals_merge( array $rows ) {
	$by = array();
	foreach ( $rows as $r ) {
		$key = $r['door'] . '|' . $r['rule'];
		if ( ! isset( $by[ $key ] ) ) {
			$by[ $key ] = array( 'door' => $r['door'], 'rule' => $r['rule'], 'd7' => 0, 'd30' => 0 );
		}
		$by[ $key ]['d7']  += (int) $r['d7'];
		$by[ $key ]['d30'] += (int) $r['d30'];
	}
	$by = array_values( array_filter( $by, static fn( $r ) => $r['d30'] > 0 ) );
	usort( $by, static fn( $a, $b ) => ( $b['d30'] <=> $a['d30'] ) ?: ( ( $b['d7'] <=> $a['d7'] ) ?: strcmp( $a['door'] . $a['rule'], $b['door'] . $b['rule'] ) ) );
	return $by;
}

/**
 * The read every surface uses: the merged table, and whether the call log
 * could be read at all.
 *
 * @param int|null $now Clock seam for tests.
 * @return array{log_readable:bool,rows:array<int,array{door:string,rule:string,d7:int,d30:int}>,total_7:int,total_30:int}
 */
function sn_door_refusals_read( $now = null ) {
	$now  = null === $now ? time() : (int) $now;
	$log  = sn_door_refusals_from_log( $now );
	$rows = sn_door_refusals_merge( array_merge( (array) $log, sn_door_refusals_from_option( get_option( SN_DOOR_REFUSALS_OPTION, array() ), $now ) ) );
	return array(
		'log_readable' => null !== $log,
		'rows'         => $rows,
		'total_7'      => (int) array_sum( array_column( $rows, 'd7' ) ),
		'total_30'     => (int) array_sum( array_column( $rows, 'd30' ) ),
	);
}
