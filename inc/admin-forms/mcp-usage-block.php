<?php
/**
 * MCP Clients → tool usage readout.
 *
 * The readout half of inc/mcp/mcp-telemetry-read.php. The table it reads had
 * an install path, an insert path and a prune path, and no SELECT — evidence
 * accrued for the retirement gate and was deleted at 90 days unread. Shipping
 * the accessor without this block would have reproduced that exact shape.
 *
 * @package Signal_And_Noise_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Folded usage block. Headline carries the MEASURED window and the zero-call
 * count, because those are the two numbers a retirement decision rests on and
 * both are easy to assume wrongly.
 */
function sn_admin_render_mcp_usage() {
	if ( ! function_exists( 'sn_mcp_telemetry_usage' ) ) {
		return;
	}
	$usage = sn_mcp_telemetry_usage();

	echo '<h3>' . esc_html__( 'Tool usage', 'signal-and-noise-tools' ) . '</h3>';

	if ( null === $usage ) {
		// Three ways to have no report, and they are different problems. The
		// accessor returns null for two of them, so ask the cheaper question
		// again here rather than printing one message for both.
		$installed = function_exists( 'sn_mcp_telemetry_table_exists' ) && sn_mcp_telemetry_table_exists();
		echo '<p class="description">' . esc_html(
			$installed
				? __( 'The call log exists but could not be read — a database error, not an absence of calls. Nothing here should be treated as usage evidence.', 'signal-and-noise-tools' )
				: __( 'The call log table is not installed yet. It is created on the first MCP call; until then there is no usage evidence either way.', 'signal-and-noise-tools' )
		) . '</p>';
		return;
	}

	$zero  = $usage['zero_call'];
	$since = $usage['measured_since'];

	if ( null === $since ) {
		echo '<p class="description">' . esc_html__( 'The call log is installed and has recorded nothing. That is not the same as these tools going unused — no call has been made through either door yet.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}

	$summary = sprintf(
		/* translators: 1: measured days, 2: window days, 3: count of tools with no calls through a door. */
		__( 'Measured over %1$d days of a %2$d-day window · %3$d tools with no calls through a door', 'signal-and-noise-tools' ),
		(int) $usage['measured_days'],
		(int) $usage['window_days'],
		count( $zero )
	);

	echo '<details class="sn-mcp-usage">';
	echo '<summary>' . esc_html( $summary ) . '</summary>';

	if ( ! $usage['complete'] ) {
		echo '<p class="description"><strong>' . esc_html__( 'Partial window.', 'signal-and-noise-tools' ) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: 1: first recorded date, 2: window days. */
				__( 'Recording began %1$s, so this covers less than the full %2$d days asked for. A tool with no calls here may simply predate the sensor.', 'signal-and-noise-tools' ),
				$since,
				(int) $usage['window_days']
			)
		) . '</p>';
	}

	sn_admin_render_mcp_usage_table( $usage['by_tool'] );
	sn_admin_render_mcp_usage_zero( $zero );

	echo '<p class="description">' . esc_html( sn_admin_mcp_usage_door_split( $usage ) ) . '</p>';
	echo '</details>';
}

/**
 * The door split, one sentence. First-party is the plugin's own surfaces
 * polling through the lifecycle guard; the three doors are the callers a
 * retirement reading is about. Same words on the native leaf.
 *
 * @param array $usage From sn_mcp_telemetry_usage().
 * @return string
 */
function sn_admin_mcp_usage_door_split( $usage ) {
	$by_door = (array) ( $usage['by_door'] ?? array() );
	return sprintf(
		/* translators: 1: total calls, 2: direct (first-party) calls, 3: read-door calls, 4: rw-door calls, 5: agent-door calls. */
		__( '%1$s calls: %2$s first-party (direct), %3$s read door, %4$s rw, %5$s agent. The total includes calls that never resolved to a tool.', 'signal-and-noise-tools' ),
		number_format_i18n( (int) ( $usage['total_rows'] ?? 0 ) ),
		number_format_i18n( (int) ( $by_door['direct'] ?? 0 ) ),
		number_format_i18n( (int) ( $by_door['read'] ?? 0 ) ),
		number_format_i18n( (int) ( $by_door['rw'] ?? 0 ) ),
		number_format_i18n( (int) ( $by_door['agent'] ?? 0 ) )
	);
}

/**
 * The Calls cell: door calls, with the direct count in parentheses when there
 * is one. Parentheses over a fifth column because both tables keep their
 * shape and the kit table needs no new key. Same words on the native leaf.
 *
 * @param array $row One by_tool entry.
 * @return string
 */
function sn_admin_mcp_usage_calls_cell( $row ) {
	$doors  = number_format_i18n( (int) ( $row['door_calls'] ?? 0 ) );
	$direct = (int) ( $row['direct_calls'] ?? 0 );
	if ( $direct <= 0 ) {
		return $doors;
	}
	/* translators: 1: calls through a door, 2: direct (first-party) calls. */
	return sprintf( __( '%1$s (%2$s direct)', 'signal-and-noise-tools' ), $doors, number_format_i18n( $direct ) );
}

/**
 * Per-tool call counts, busiest first.
 *
 * @param array $by_tool Keyed by projected tool name.
 */
function sn_admin_render_mcp_usage_table( $by_tool ) {
	if ( empty( $by_tool ) ) {
		return;
	}
	// Door calls lead, since that is the column; the polls only break ties.
	uasort( $by_tool, function ( $a, $b ) { return ( ( $b['door_calls'] ?? 0 ) <=> ( $a['door_calls'] ?? 0 ) ) ?: ( $b['calls'] <=> $a['calls'] ); } );

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>' . esc_html__( 'Tool', 'signal-and-noise-tools' ) . '</th>';
	echo '<th>' . esc_html__( 'Calls', 'signal-and-noise-tools' ) . '</th>';
	echo '<th>' . esc_html__( 'Last seen', 'signal-and-noise-tools' ) . '</th>';
	echo '<th>' . esc_html__( 'Doors', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $by_tool as $name => $row ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( (string) $name ) . '</code></td>';
		echo '<td>' . esc_html( sn_admin_mcp_usage_calls_cell( $row ) ) . '</td>';
		echo '<td>' . esc_html( (string) ( $row['last_seen'] ?? '—' ) ) . '</td>';
		echo '<td>' . esc_html( implode( ', ', (array) $row['doors'] ) ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

/**
 * Tools with no calls, split by WHY.
 *
 * The split is the point. Zero rows means either nobody used it or nobody
 * could — opposite conclusions from identical evidence — so the verdict is
 * printed beside every entry and never inferred by the reader of this page.
 *
 * @param array $zero Entries from sn_mcp_telemetry_zero_call().
 */
function sn_admin_render_mcp_usage_zero( $zero ) {
	if ( empty( $zero ) ) {
		echo '<p class="description">' . esc_html__( 'Every allowlisted tool was called at least once in this window.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}

	$labels = array(
		'unused'       => __( 'no calls — retirement candidate', 'signal-and-noise-tools' ),
		'unreachable'  => __( 'cannot be projected — this is a BUG, not a retirement candidate', 'signal-and-noise-tools' ),
		'undetermined' => __( 'reachability unknown — no judgement possible', 'signal-and-noise-tools' ),
	);

	echo '<h4>' . esc_html__( 'No calls in this window', 'signal-and-noise-tools' ) . '</h4>';
	echo '<ul class="sn-mcp-usage-zero">';
	foreach ( $zero as $entry ) {
		$verdict = (string) $entry['verdict'];
		echo '<li><code>' . esc_html( (string) $entry['slug'] ) . '</code> — ';
		echo esc_html( 'first_party_only' === $verdict ? sn_admin_mcp_usage_first_party_label( $entry ) : ( $labels[ $verdict ] ?? $verdict ) );
		echo '</li>';
	}
	echo '</ul>';
	echo '<p class="description">' . esc_html__( 'Only “retirement candidate” entries are evidence for removal. A tool that cannot be projected has no calls because it cannot be called — retiring it would delete the evidence of the defect. Reachability is checked from inside the plugin, so it cannot see a client proxy rejecting a schema; treat it as necessary, not sufficient.', 'signal-and-noise-tools' ) . '</p>';
}

/**
 * The first-party-only verdict carries its count. Same words on the native leaf.
 *
 * @param array $entry One zero_call entry.
 * @return string
 */
function sn_admin_mcp_usage_first_party_label( $entry ) {
	/* translators: %s: direct (first-party) call count. */
	return sprintf( __( 'no caller through a door; the plugin\'s own surfaces called it %s times. Not a retirement candidate for the code, only for the door allowlist', 'signal-and-noise-tools' ), number_format_i18n( (int) ( $entry['calls'] ?? 0 ) ) );
}
