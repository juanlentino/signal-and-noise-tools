<?php
/**
 * Signal & Noise — MCP telemetry READ.
 *
 * `sn_tool_call` had three code paths — install, insert, prune — and no
 * `SELECT` anywhere in the plugin. The module's own docblock names six metrics
 * it "feeds"; nothing consumed any of them. The retirement program's gate
 * ("nothing retires until usage data justifies it") was therefore not merely
 * unmet but unmeetable: evidence accrued into a table with no reader and was
 * deleted at 90 days whether or not anyone looked.
 *
 * This file is the reader, and it is deliberately NOT an ability and NOT an MCP
 * tool. Two reasons, the second being the interesting one:
 *
 *   1. Precedent — inc/ai-tool-invocation-log.php declines to expose its log as
 *      a tool because a read-only ability re-adds the per-turn rent.
 *   2. OBSERVER EFFECT. A tool that reads the call log writes a row to the call
 *      log every time it runs. It would appear in its own zero-call analysis,
 *      always non-zero, inflating the corpus it exists to measure.
 *
 * @package Signal_And_Noise_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Does the telemetry table physically exist?
 *
 * Absent table and empty table are different answers and must stay that way:
 * "the sensor is not installed" is not "the sensor recorded nothing", and
 * neither is "these tools went unused".
 *
 * @return bool
 */
function sn_mcp_telemetry_table_exists() {
	global $wpdb;
	$table = $wpdb->prefix . SN_MCP_TELEMETRY_TABLE;
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return (string) $found === $table;
}

/**
 * Can this slug still be projected onto the wire?
 *
 * Reachability is read through the SAME path `tools/list` uses — resolve the
 * ability, project it — because a slug that no longer resolves is dropped
 * silently there, and that silence is exactly what makes a zero-call row
 * ambiguous.
 *
 * LIMIT, stated because it changes how the verdict should be read: a `true`
 * here proves the plugin can project the tool. It does NOT prove a client's
 * proxy accepts the projected schema. Proxy `-32602` schema refusals never
 * reach `sn_mcp_telemetry_record()`, so they are invisible from inside the
 * plugin at any cost. `true` therefore means "reachable as far as we can see",
 * which is why retirement stays a human judgement on top of this number.
 *
 * @param string $slug Ability slug.
 * @return bool|null True projected, false unresolvable, null cannot tell.
 */
function sn_mcp_telemetry_slug_reachable( $slug ) {
	if ( ! function_exists( 'wp_get_ability' ) || ! function_exists( 'sn_mcp_project_tool' ) ) {
		return null; // Abilities API absent — unknown, never an optimistic true.
	}
	$ability = wp_get_ability( $slug );
	if ( ! $ability ) {
		return false;
	}
	try {
		$tool = sn_mcp_project_tool( $ability, sn_mcp_telemetry_door_for_slug( $slug ) );
	} catch ( \Throwable $e ) {
		return false;
	}
	return is_array( $tool ) && ! empty( $tool['name'] );
}

/**
 * Which door curates this slug. Read door wins when a slug is on both, because
 * the read door is the wider surface and the conservative attribution.
 *
 * @param string $slug
 * @return string
 */
function sn_mcp_telemetry_door_for_slug( $slug ) {
	$read = function_exists( 'sn_mcp_allowlist' ) ? sn_mcp_allowlist() : array();
	return in_array( $slug, $read, true ) ? SN_MCP_DOOR_READ : SN_MCP_DOOR_RW;
}

/**
 * Every slug that SHOULD have been callable, across both doors.
 *
 * Computed in PHP by diffing the allowlists against the grouped result, never
 * in SQL: the allowlist is the source of truth for what was callable, and it
 * lives in code, not in the table.
 *
 * @return array<string,string> slug => tool_name
 */
function sn_mcp_telemetry_expected_tools() {
	$slugs = array();
	if ( function_exists( 'sn_mcp_allowlist' ) ) {
		$slugs = array_merge( $slugs, sn_mcp_allowlist() );
	}
	if ( function_exists( 'sn_mcp_rw_allowlist' ) ) {
		$slugs = array_merge( $slugs, sn_mcp_rw_allowlist() );
	}
	$map = array();
	foreach ( array_unique( $slugs ) as $slug ) {
		// Telemetry stores the PROJECTED tool_name, not the slug. Diffing slugs
		// against tool_names would report every tool as zero-call — the whole
		// corpus, confidently, and wrongly.
		$map[ $slug ] = function_exists( 'sn_mcp_tool_name_from_slug' )
			? sn_mcp_tool_name_from_slug( $slug )
			: str_replace( '/', '__', (string) $slug );
	}
	return $map;
}

/**
 * Usage over a window.
 *
 * @param int $days Window asked for.
 * @return array|null Null when the table is absent or unreadable.
 */
function sn_mcp_telemetry_usage( $days = SN_MCP_TELEMETRY_RETENTION_DAYS ) {
	global $wpdb;
	$days = max( 1, (int) $days );

	if ( ! sn_mcp_telemetry_table_exists() ) {
		return null;
	}

	$table = $wpdb->prefix . SN_MCP_TELEMETRY_TABLE;
	$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

	// MIN(ts) rides in the SAME query as the aggregate. A window derived from
	// the retention constant instead of the data would have claimed 90 days
	// over a table that started writing on 2026-08-01 — a confident wrong
	// answer, and the exact failure the IPv6 gauge was fixed for twice.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT tool_name, door, outcome, COUNT(*) AS calls,
			        MAX(ts) AS last_seen, MIN(ts) AS first_seen
			 FROM {$table}
			 WHERE ts >= %s
			 GROUP BY tool_name, door, outcome",
			$since
		),
		ARRAY_A
	);
	// A failed wpdb query returns false or null, never an empty result set.
	// Treating that as "no rows" would report an unused corpus on a database
	// error.
	if ( ! is_array( $rows ) ) {
		return null;
	}

	$by_tool    = array();
	$total_rows = 0;
	$earliest   = null;

	foreach ( $rows as $row ) {
		$name    = (string) ( $row['tool_name'] ?? '' );
		$calls   = (int) ( $row['calls'] ?? 0 );
		$outcome = (string) ( $row['outcome'] ?? '' );
		$door    = (string) ( $row['door'] ?? '' );
		$first   = (string) ( $row['first_seen'] ?? '' );
		$last    = (string) ( $row['last_seen'] ?? '' );

		$total_rows += $calls;
		if ( '' !== $first && ( null === $earliest || $first < $earliest ) ) {
			$earliest = $first;
		}

		// Schema-error rows are recorded with an EMPTY tool_name
		// (mcp-tools.php:436) because the call never resolved to a tool. They
		// are real traffic and count toward the total, but attributing them to
		// a tool would invent a caller.
		if ( '' === $name ) {
			continue;
		}

		if ( ! isset( $by_tool[ $name ] ) ) {
			$by_tool[ $name ] = array(
				'calls'     => 0,
				'last_seen' => null,
				'doors'     => array(),
				'outcomes'  => array(),
			);
		}
		$by_tool[ $name ]['calls'] += $calls;
		if ( null === $by_tool[ $name ]['last_seen'] || $last > $by_tool[ $name ]['last_seen'] ) {
			$by_tool[ $name ]['last_seen'] = $last;
		}
		if ( '' !== $door && ! in_array( $door, $by_tool[ $name ]['doors'], true ) ) {
			$by_tool[ $name ]['doors'][] = $door;
		}
		if ( '' !== $outcome ) {
			$by_tool[ $name ]['outcomes'][ $outcome ] = ( $by_tool[ $name ]['outcomes'][ $outcome ] ?? 0 ) + $calls;
		}
	}

	$measured_days = null;
	if ( null !== $earliest ) {
		$span          = time() - (int) strtotime( $earliest . ' UTC' );
		$measured_days = max( 0, (int) floor( $span / DAY_IN_SECONDS ) );
	}

	return array(
		'measured_since' => $earliest,
		'measured_days'  => $measured_days,
		'window_days'    => $days,
		'complete'       => ( null !== $measured_days && $measured_days >= $days ),
		'by_tool'        => $by_tool,
		'zero_call'      => sn_mcp_telemetry_zero_call( $by_tool ),
		'total_rows'     => $total_rows,
	);
}

/**
 * Allowlisted tools with no rows, each carrying WHY it might have none.
 *
 * Zero rows is not "unused". A tool the proxy refuses on schema grounds never
 * reaches the recorder, so it also has zero rows — the opposite conclusion from
 * the same evidence. These are reported as separate facts and the reader
 * refuses to collapse them.
 *
 * A tool may only be retired on verdict `unused`. `unreachable` is a BUG
 * REPORT: retiring it would delete the evidence of a defect.
 *
 * @param array $by_tool Grouped usage keyed by tool_name.
 * @return array<int,array{tool:string,slug:string,calls:int,reachable:bool|null,verdict:string}>
 */
function sn_mcp_telemetry_zero_call( $by_tool ) {
	$out = array();
	foreach ( sn_mcp_telemetry_expected_tools() as $slug => $tool_name ) {
		if ( isset( $by_tool[ $tool_name ] ) && $by_tool[ $tool_name ]['calls'] > 0 ) {
			continue;
		}
		$reachable = sn_mcp_telemetry_slug_reachable( $slug );
		if ( true === $reachable ) {
			$verdict = 'unused';
		} elseif ( false === $reachable ) {
			$verdict = 'unreachable';
		} else {
			$verdict = 'undetermined';
		}
		$out[] = array(
			'tool'      => $tool_name,
			'slug'      => $slug,
			'calls'     => 0,
			'reachable' => $reachable,
			'verdict'   => $verdict,
		);
	}
	return $out;
}
