<?php
/**
 * Signal & Noise: MCP Layer B telemetry, the live wrappers (actor, timestamp, the insert, the read rollup, the opportunistic prune, the record entry point).
 *
 * Split from inc/mcp/mcp-telemetry.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ════════════════════════════════════════════════════════════════════════
 * Live wrappers.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Live: resolve the 'actor' column. The rw door's bound app-password UUID
 * resolver is door-agnostic in practice (it only reads
 * rest_get_authenticated_app_password() for the CURRENT request) so it is
 * reused for both doors here — a deviation from the letter of the task brief
 * ("the rw door's... resolver"), noted in FINDINGS.md: app-password
 * attribution is equally meaningful on the read door, and there is no
 * separate read-door resolver to prefer instead.
 *
 * @return string
 */
function sn_mcp_telemetry_actor() {
	if ( function_exists( 'sn_mcp_rw_audit_authenticated_app_password_uuid' ) ) {
		$uuid = sn_mcp_rw_audit_authenticated_app_password_uuid();
		if ( is_string( $uuid ) && '' !== $uuid ) {
			return 'app-pw:' . substr( $uuid, 0, 8 );
		}
	}
	return 'human';
}

/**
 * Live: millisecond-precision UTC timestamp string for the `ts DATETIME(3)`
 * column. gmdate()'s own 'v' format char always reads as 000 against a
 * time()-based integer (no sub-second component to format), so the
 * milliseconds are computed from microtime(true) directly instead —
 * gmdate() supplies the UTC date/second part only, per the project's
 * standing "never let session/local time leak into a stored timestamp" rule.
 *
 * @return string
 */
function sn_mcp_telemetry_now_ts() {
	$now = microtime( true );
	$ms  = (int) round( ( $now - floor( $now ) ) * 1000 );
	if ( 1000 === $ms ) { // Rounding at the second boundary.
		$now += 1;
		$ms   = 0;
	}
	return gmdate( 'Y-m-d H:i:s', (int) $now ) . '.' . sprintf( '%03d', $ms );
}

/**
 * Live: the single INSERT. Never called directly by anything except
 * sn_mcp_telemetry_record(), which already wraps this in a try/catch — this
 * function does not catch on its own so a genuine wpdb exception still
 * propagates to that outer boundary rather than being swallowed twice.
 *
 * @param array<string,mixed> $row
 * @return void
 */
function sn_mcp_telemetry_insert_row( $row ) {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'insert' ) ) {
		return;
	}
	$table = $wpdb->prefix . SN_MCP_TELEMETRY_TABLE;
	$wpdb->insert(
		$table,
		array(
			'ts'           => $row['ts'],
			'layer'        => $row['layer'],
			'door'         => $row['door'],
			'actor'        => $row['actor'],
			'tool_name'    => $row['tool_name'],
			'args_shape'   => $row['args_shape'],
			'args_hash'    => $row['args_hash'],
			'outcome'      => $row['outcome'],
			'refusal_gate' => $row['refusal_gate'],
			'latency_ms'   => $row['latency_ms'],
			'result_count' => $row['result_count'],
			'candidate_id' => $row['candidate_id'],
			'change_type'  => $row['change_type'] ?? null,
			'dimensions'   => $row['dimensions'] ?? null,
			'error_code'   => $row['error_code'] ?? null,
			'error_detail' => $row['error_detail'] ?? null,
			'error_status' => $row['error_status'] ?? null,
		),
		// v13.48.0: 17 columns, 17 formats. The count pin exists because a new
		// column without its %s misbinds every column after it.
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
	);
}

/**
 * Read rollup of sn_tool_call, for sn_site_facts' "tool_telemetry" fact
 * (v11.9.0). Grouped SELECTs only, no per-row reads, never argument values.
 *
 * WHY THIS EXISTS: v11.8.0 added the `change_type` dimension so individual
 * sn-apply change types could be retired or kept on evidence, and the
 * `conflict` outcome so fingerprint contention was distinguishable from
 * malformed input — then shipped both into a table with no read path. The data
 * was collected and unreachable from the place the decisions get made. This is
 * that path.
 *
 * `table_present` carries the same meaning it does in
 * snt_scan_telemetry_summary(): an empty rollup with table_present:true is an
 * honest empty window; table_present:false means the table is missing or the
 * query failed, the fail-open insert path has been eating rows, and NO number
 * here is a measurement. Zero and null are different answers.
 *
 * `by_change_type` is the half that services the consolidation programme —
 * rows where change_type IS NOT NULL, which today is sn-apply only.
 * `by_error_code` keeps each plugin-authored failure identifier attributable
 * to its tool and outcome instead of collapsing distinct causes into a bucket.
 *
 * @param int $days Window, in days.
 * @return array{window_days:int,generated_at:string,table_present:bool,total_calls:int,by_tool:array,by_change_type:array,by_dimension:array,by_error_code:array}
 */
function sn_mcp_telemetry_summary( $days = 30 ) {
	global $wpdb;

	$days = max( 1, (int) $days );
	$out  = array(
		'window_days'    => $days,
		'generated_at'   => gmdate( 'c' ),
		'table_present'  => false,
		'total_calls'    => 0,
		'by_tool'        => array(),
		'by_change_type' => array(),
		'by_dimension'   => array(),
		'by_error_code'  => array(),
	);

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
		return $out;
	}

	$table  = $wpdb->prefix . SN_MCP_TELEMETRY_TABLE;
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a plugin constant, never user input; $cutoff is bound via prepare() below.
	$sql  = $wpdb->prepare( "SELECT tool_name, door, outcome, COUNT(*) AS calls, AVG(latency_ms) AS avg_latency_ms, MAX(ts) AS last_call FROM {$table} WHERE ts >= %s GROUP BY tool_name, door, outcome ORDER BY calls DESC, tool_name ASC", $cutoff );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the STRING RETURNED BY $wpdb->prepare() one line above, already safely bound.
	$rows = $wpdb->get_results( $sql, ARRAY_A );

	// A FAILED wpdb query returns []/null with last_error SET — it does not
	// throw, and a missing table yields an EMPTY ARRAY, not false. Checking
	// is_array() alone would report a missing table as an honest zero, which is
	// the exact lie table_present exists to prevent. Mirrors
	// snt_scan_telemetry_summary()'s check, including resetting last_error
	// first so a pre-existing error from an unrelated query cannot leak in.
	// A FAILED wpdb query returns []/null with last_error SET — it does not
	// throw, and a missing table yields an EMPTY ARRAY, not false. Checking
	// is_array() alone would report a missing table as an honest zero, which is
	// the exact lie table_present exists to prevent. Mirrors
	// snt_scan_telemetry_summary()'s check, including resetting last_error
	// first so a pre-existing error from an unrelated query cannot leak in.
	if ( '' !== (string) $wpdb->last_error || null === $rows ) {
		return $out;
	}

	foreach ( (array) $rows as $r ) {
		$calls               = (int) ( $r['calls'] ?? 0 );
		$out['total_calls'] += $calls;
		$out['by_tool'][]    = array(
			'tool_name'      => (string) ( $r['tool_name'] ?? '' ),
			'door'           => (string) ( $r['door'] ?? '' ),
			'outcome'        => (string) ( $r['outcome'] ?? '' ),
			'calls'          => $calls,
			'avg_latency_ms' => isset( $r['avg_latency_ms'] ) && null !== $r['avg_latency_ms'] ? (int) round( (float) $r['avg_latency_ms'] ) : null,
			'last_call'      => (string) ( $r['last_call'] ?? '' ),
		);
	}

	// v13.3.0: tool_name joined the grouping — the change_type column now
	// carries a per-tool dimension (sn-apply's change.type, and the single
	// requested section/fact for the batch read tools), so rows must name
	// which tool's dimension they are. Additive key; sn-apply rows read as
	// before with tool_name alongside.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a plugin constant, never user input; $cutoff is bound via prepare() below.
	$sql2  = $wpdb->prepare( "SELECT tool_name, change_type, outcome, COUNT(*) AS calls, AVG(latency_ms) AS avg_latency_ms, MAX(ts) AS last_call FROM {$table} WHERE ts >= %s AND change_type IS NOT NULL GROUP BY tool_name, change_type, outcome ORDER BY calls DESC, change_type ASC, tool_name ASC", $cutoff );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql2 is the STRING RETURNED BY $wpdb->prepare() one line above, already safely bound.
	$rows2 = $wpdb->get_results( $sql2, ARRAY_A );

	// The by_tool half already succeeded, so the table is present either way;
	// a failure HERE degrades only this section rather than discarding a good
	// rollup, and by_change_type stays empty rather than partly filled.
	if ( '' === (string) $wpdb->last_error && null !== $rows2 ) {
		foreach ( (array) $rows2 as $r ) {
			$out['by_change_type'][] = array(
				'tool_name'      => (string) ( $r['tool_name'] ?? '' ),
				'change_type'    => (string) ( $r['change_type'] ?? '' ),
				'outcome'        => (string) ( $r['outcome'] ?? '' ),
				'calls'          => (int) ( $r['calls'] ?? 0 ),
				'avg_latency_ms' => isset( $r['avg_latency_ms'] ) && null !== $r['avg_latency_ms'] ? (int) round( (float) $r['avg_latency_ms'] ) : null,
				'last_call'      => (string) ( $r['last_call'] ?? '' ),
			);
		}
	}

	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a plugin constant, never user input; $cutoff is bound via prepare() below.
	$sql3  = $wpdb->prepare( "SELECT tool_name, error_code, outcome, COUNT(*) AS calls, AVG(latency_ms) AS avg_latency_ms, MAX(ts) AS last_call FROM {$table} WHERE ts >= %s AND error_code IS NOT NULL GROUP BY tool_name, error_code, outcome ORDER BY calls DESC, tool_name ASC, error_code ASC", $cutoff );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql3 is the STRING RETURNED BY $wpdb->prepare() one line above, already safely bound.
	$rows3 = $wpdb->get_results( $sql3, ARRAY_A );

	if ( '' === (string) $wpdb->last_error && null !== $rows3 ) {
		foreach ( (array) $rows3 as $r ) {
			$out['by_error_code'][] = array(
				'tool_name'      => (string) ( $r['tool_name'] ?? '' ),
				'error_code'     => (string) ( $r['error_code'] ?? '' ),
				'outcome'        => (string) ( $r['outcome'] ?? '' ),
				'calls'          => (int) ( $r['calls'] ?? 0 ),
				'avg_latency_ms' => isset( $r['avg_latency_ms'] ) && null !== $r['avg_latency_ms'] ? (int) round( (float) $r['avg_latency_ms'] ) : null,
				'last_call'      => (string) ( $r['last_call'] ?? '' ),
			);
		}
	}

	$out['table_present'] = true;
	$wpdb->last_error = '';
	// v13.46.0. by_dimension answers the question by_change_type CANNOT: not
	// "was the sole dimension of N calls" but "APPEARED IN N calls". The column
	// stores a sorted comma-joined list, so SQL can only group by the exact
	// COMBINATION — the per-section split has to happen here, in PHP, over a
	// bounded set of distinct combinations. Without this the column would be
	// collected and unreachable from the place the decisions get made, which is
	// the precise defect the by_change_type half exists to have fixed.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a plugin constant, never user input; $cutoff is bound via prepare() below.
	$sql4  = $wpdb->prepare( "SELECT tool_name, dimensions, COUNT(*) AS calls FROM {$table} WHERE ts >= %s AND dimensions IS NOT NULL GROUP BY tool_name, dimensions", $cutoff );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql4 is the STRING RETURNED BY $wpdb->prepare() one line above, already safely bound.
	$rows4 = $wpdb->get_results( $sql4, ARRAY_A );

	if ( '' === (string) $wpdb->last_error && null !== $rows4 ) {
		$tally = array();
		foreach ( (array) $rows4 as $r ) {
			$tool  = (string) ( $r['tool_name'] ?? '' );
			$calls = (int) ( $r['calls'] ?? 0 );
			foreach ( explode( ',', (string) ( $r['dimensions'] ?? '' ) ) as $dim ) {
				$dim = trim( $dim );
				if ( '' === $dim ) {
					continue;
				}
				$key = $tool . '|' . $dim;
				$tally[ $key ] = ( $tally[ $key ] ?? 0 ) + $calls;
			}
		}
		// Deterministic order: calls DESC, then tool|dimension ASC — the same
		// shape the sibling rollups use, so two runs never disagree.
		ksort( $tally );
		arsort( $tally );
		foreach ( $tally as $key => $calls ) {
			$parts = explode( '|', $key, 2 );
			$out['by_dimension'][] = array(
				'tool_name' => $parts[0],
				'dimension' => $parts[1] ?? '',
				'calls'     => (int) $calls,
			);
		}
	}

	return $out;
}

/**
 * Live: opportunistic retention prune. ~1-in-50 chance per insert (never a
 * cron, per the standing "schedule nothing" guardrail this week), capped at
 * SN_MCP_TELEMETRY_PRUNE_LIMIT rows per fire so a single request can never be
 * saddled with an unbounded DELETE.
 *
 * @return void
 */
function sn_mcp_telemetry_maybe_prune() {
	$roll = function_exists( 'wp_rand' ) ? wp_rand( 1, SN_MCP_TELEMETRY_PRUNE_CHANCE ) : mt_rand( 1, SN_MCP_TELEMETRY_PRUNE_CHANCE );
	if ( 1 !== $roll ) {
		return;
	}

	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
		return;
	}
	$table  = $wpdb->prefix . SN_MCP_TELEMETRY_TABLE;
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - SN_MCP_TELEMETRY_RETENTION_DAYS * DAY_IN_SECONDS );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a plugin constant, never user input; $cutoff/limit are bound via prepare() below.
	$sql = $wpdb->prepare( "DELETE FROM {$table} WHERE ts < %s LIMIT %d", $cutoff, SN_MCP_TELEMETRY_PRUNE_LIMIT );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the STRING RETURNED BY $wpdb->prepare() one line above, already safely bound.
	$wpdb->query( $sql );
}

/**
 * LIVE: record one tools/call outcome, both doors, every outcome. This is the
 * ONE function inc/mcp/mcp-tools.php's sn_mcp_call_tool() calls, at every
 * return point. Entirely fail-open: table missing, wpdb error, or any
 * unexpected exception all degrade to a silent no-op — the tool response the
 * caller already built is never touched, because this function's return
 * value is never inspected by its call sites.
 *
 * @param string      $tool_name
 * @param mixed       $arguments
 * @param string      $door         SN_MCP_DOOR_READ | SN_MCP_DOOR_RW.
 * @param string      $outcome      'ok'|'schema_error'|'not_found'|'refused'|'server_error'.
 * @param string|null $refusal_gate
 * @param int         $latency_ms
 * @param int|null    $result_count
 * @param string|null $error_code   Captured by the WP_Error classifier before
 *                                  the result is flattened to message-only.
 * @param string|null $error_detail DIAGNOSTIC ONLY — the WP_Error's message.
 *                                  Never aggregated. @since v13.48.0.
 * @param int|null    $error_status DIAGNOSTIC ONLY — the WP_Error's status.
 *                                  Never aggregated. @since v13.48.0.
 * @return void
 */
function sn_mcp_telemetry_record( $tool_name, $arguments, $door, $outcome, $refusal_gate, $latency_ms, $result_count = null, $error_code = null, $error_detail = null, $error_status = null ) {
	if ( ! sn_mcp_telemetry_enabled() ) {
		return;
	}
	try {
		$args = is_array( $arguments ) ? $arguments : array();
		$row  = sn_mcp_telemetry_build_row(
			sn_mcp_telemetry_now_ts(),
			(string) $door,
			sn_mcp_telemetry_actor(),
			(string) $tool_name,
			sn_mcp_telemetry_args_shape( $args ),
			sn_mcp_telemetry_args_hash( $args ),
			(string) $outcome,
			$refusal_gate,
			(int) $latency_ms,
			$result_count,
			sn_mcp_telemetry_change_type( $args, (string) $tool_name ),
			$error_code,
			sn_mcp_telemetry_dimensions( $args, (string) $tool_name ),
			$error_detail,
			$error_status
		);
		sn_mcp_telemetry_insert_row( $row );
		sn_mcp_telemetry_maybe_prune();
	} catch ( \Throwable $e ) {
		return;
	}
}
