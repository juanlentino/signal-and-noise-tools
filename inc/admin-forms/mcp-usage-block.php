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

	/* translators: 1: measured days, 2: window days, 3: zero-call tool count. */
	$summary = sprintf(
		__( 'Measured over %1$d days of a %2$d-day window · %3$d tools with no calls', 'signal-and-noise-tools' ),
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

	echo '<p class="description">' . esc_html(
		sprintf(
			/* translators: %d: total recorded calls. */
			__( '%d calls recorded in this window, including calls that never resolved to a tool.', 'signal-and-noise-tools' ),
			(int) $usage['total_rows']
		)
	) . '</p>';
	echo '</details>';
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
	uasort( $by_tool, function ( $a, $b ) { return $b['calls'] <=> $a['calls']; } );

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>' . esc_html__( 'Tool', 'signal-and-noise-tools' ) . '</th>';
	echo '<th>' . esc_html__( 'Calls', 'signal-and-noise-tools' ) . '</th>';
	echo '<th>' . esc_html__( 'Last seen', 'signal-and-noise-tools' ) . '</th>';
	echo '<th>' . esc_html__( 'Doors', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $by_tool as $name => $row ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( (string) $name ) . '</code></td>';
		echo '<td>' . esc_html( number_format_i18n( (int) $row['calls'] ) ) . '</td>';
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
		echo esc_html( $labels[ $verdict ] ?? $verdict );
		echo '</li>';
	}
	echo '</ul>';
	echo '<p class="description">' . esc_html__( 'Only “retirement candidate” entries are evidence for removal. A tool that cannot be projected has no calls because it cannot be called — retiring it would delete the evidence of the defect. Reachability is checked from inside the plugin, so it cannot see a client proxy rejecting a schema; treat it as necessary, not sufficient.', 'signal-and-noise-tools' ) . '</p>';
}
