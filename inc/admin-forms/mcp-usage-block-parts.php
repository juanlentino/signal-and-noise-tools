<?php
/**
 * AI › Agent tools: the tools no door call reached, each door tool's own
 * reading, and the classic block. Split from mcp-usage-block.php (house line
 * cap); both twins read every helper here.
 *
 * Zero calls means either nobody used a tool or nobody could: opposite
 * conclusions from identical evidence, so the verdict is counted in one
 * sentence and carried on every tool's own entry in its door's list
 * (2026-10-12 audit: until then a second section repeated every uncalled
 * slug the door lists already painted).
 *
 * @package Signal_And_Noise_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The tools a door lists that no call reached through a door, counted by
 * verdict, the bugs first.
 *
 * @param array $zero Entries from sn_mcp_telemetry_zero_call().
 * @return string '' when every listed tool had a door call.
 */
function sn_admin_mcp_usage_zero_finding( $zero ) {
	$zero = (array) $zero;
	if ( array() === $zero ) {
		return __( 'Every allowlisted tool was called at least once in this window.', 'signal-and-noise-tools' );
	}
	$counts = array_count_values( array_map( static fn( $e ) => (string) ( $e['verdict'] ?? '' ), $zero ) );
	$parts  = array();
	foreach ( array( 'unreachable', 'undetermined', 'unused', 'first_party_only' ) as $verdict ) {
		$n = (int) ( $counts[ $verdict ] ?? 0 );
		if ( $n > 0 ) {
			$f       = number_format_i18n( $n );
			$parts[] = array(
				/* translators: %s: tool count. */
				'unreachable'      => sprintf( _n( '%s cannot be projected', '%s cannot be projected', $n, 'signal-and-noise-tools' ), $f ),
				/* translators: %s: tool count. */
				'undetermined'     => sprintf( _n( '%s with reachability unknown', '%s with reachability unknown', $n, 'signal-and-noise-tools' ), $f ),
				/* translators: %s: tool count. */
				'unused'           => sprintf( _n( '%s retirement candidate', '%s retirement candidates', $n, 'signal-and-noise-tools' ), $f ),
				/* translators: %s: tool count. */
				'first_party_only' => sprintf( _n( '%s called only by the plugin itself', '%s called only by the plugin itself', $n, 'signal-and-noise-tools' ), $f ),
			)[ $verdict ];
		}
	}
	/* translators: 1: tool count, 2: the count by verdict, e.g. "1 cannot be projected; 41 retirement candidates". */
	return sprintf( _n( '%1$s allowlisted tool had no call through a door: %2$s.', '%1$s allowlisted tools had no call through a door: %2$s.', count( $zero ), 'signal-and-noise-tools' ), number_format_i18n( count( $zero ) ), implode( '; ', $parts ) ) . ' ' . __( 'Each door\'s list marks them.', 'signal-and-noise-tools' );
}

/**
 * The caption and tone of the "Tools with no door calls" tile: a tool that
 * cannot be projected is a bug, an unused one a retirement candidate.
 *
 * @param array $zero Entries from sn_mcp_telemetry_zero_call().
 * @return array{text:string,kind:string}
 */
function sn_admin_mcp_usage_zero_note( $zero ) {
	$counts = array_count_values( array_map( static fn( $e ) => (string) ( $e['verdict'] ?? '' ), (array) $zero ) );
	$bad    = (int) ( $counts['unreachable'] ?? 0 );
	$unused = (int) ( $counts['unused'] ?? 0 );
	if ( $bad > 0 ) {
		/* translators: %s: tool count. */
		return array( 'text' => sprintf( _n( '%s cannot be projected', '%s cannot be projected', $bad, 'signal-and-noise-tools' ), number_format_i18n( $bad ) ), 'kind' => 'err' );
	}
	if ( $unused > 0 ) {
		/* translators: %s: tool count. */
		return array( 'text' => sprintf( _n( '%s retirement candidate', '%s retirement candidates', $unused, 'signal-and-noise-tools' ), number_format_i18n( $unused ) ), 'kind' => 'warn' );
	}
	return array( 'text' => '', 'kind' => 'ok' );
}

/**
 * How to read a zero: the one rule, the same words on both twins.
 *
 * @return string
 */
function sn_admin_mcp_usage_rule() {
	return __( 'Only “retirement candidate” entries are evidence for removal. A tool that cannot be projected has no calls because it cannot be called; retiring it would delete the evidence of the defect. Reachability is checked from inside the plugin, so it cannot see a client proxy rejecting a schema; treat it as necessary, not sufficient.', 'signal-and-noise-tools' );
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

/**
 * One door tool's reading in its door's list: its door calls, or why it has
 * none, and the tone that goes with it (the words carry the state; the tone
 * only repeats it).
 *
 * @param string     $slug  Ability slug.
 * @param array|null $usage From sn_mcp_telemetry_usage(), or null when the log is not readable.
 * @return array{text:string,kind:string}|null Null when there is no reading to show.
 */
function sn_admin_mcp_tool_reading( $slug, $usage ) {
	if ( ! is_array( $usage ) || null === ( $usage['measured_since'] ?? null ) ) {
		return null;
	}
	foreach ( (array) ( $usage['zero_call'] ?? array() ) as $entry ) {
		if ( (string) ( $entry['slug'] ?? '' ) === (string) $slug ) {
			switch ( (string) ( $entry['verdict'] ?? '' ) ) {
				case 'unreachable':
					return array( 'text' => __( 'cannot be projected', 'signal-and-noise-tools' ), 'kind' => 'err' );
				case 'undetermined':
					return array( 'text' => __( 'reachability unknown', 'signal-and-noise-tools' ), 'kind' => 'warn' );
				case 'first_party_only':
					/* translators: %s: direct call count. */
					return array( 'text' => sprintf( __( 'plugin only, %s', 'signal-and-noise-tools' ), number_format_i18n( (int) ( $entry['calls'] ?? 0 ) ) ), 'kind' => 'info' );
			}
			return array( 'text' => __( 'no calls', 'signal-and-noise-tools' ), 'kind' => 'warn' );
		}
	}
	$row = (array) ( $usage['by_tool'][ str_replace( '/', '__', (string) $slug ) ] ?? array() );
	$n   = (int) ( $row['door_calls'] ?? 0 );
	return $n > 0 ? array( 'text' => number_format_i18n( $n ), 'kind' => '' ) : array( 'text' => __( 'no calls', 'signal-and-noise-tools' ), 'kind' => 'warn' );
}

/**
 * The classic block: the same tiles, sentences and capped ledger as the
 * native leaf, in wp-admin markup.
 *
 * @param array|null|false $usage The log as the caller read it; false reads it here.
 */
function sn_admin_render_mcp_usage( $usage = false ) {
	if ( ! function_exists( 'sn_mcp_telemetry_usage' ) ) {
		return;
	}
	$usage = false === $usage ? sn_mcp_telemetry_usage() : $usage;
	echo '<h3>' . esc_html__( 'Tool usage', 'signal-and-noise-tools' ) . '</h3>';
	if ( null === $usage ) {
		// Absent table and failed read are different problems; the accessor
		// returns null for both, so ask the cheaper question again here.
		$installed = function_exists( 'sn_mcp_telemetry_table_exists' ) && sn_mcp_telemetry_table_exists();
		echo '<p class="description">' . esc_html(
			$installed
				? __( 'The call log exists but could not be read: a database error, not an absence of calls. Nothing here should be treated as usage evidence.', 'signal-and-noise-tools' )
				: __( 'The call log table is not installed yet. It is created on the first MCP call; until then there is no usage evidence either way.', 'signal-and-noise-tools' )
		) . '</p>';
		return;
	}
	if ( null === ( $usage['measured_since'] ?? null ) ) {
		echo '<p class="description">' . esc_html__( 'The call log is installed and has recorded nothing. That is not the same as these tools going unused: no call has been made through any door yet.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	$rows  = sn_admin_mcp_usage_rows( $usage );
	$zero  = (array) ( $usage['zero_call'] ?? array() );
	$calls = array_sum( array_map( static fn( $r ) => (int) ( $r['door_calls'] ?? 0 ), sn_admin_mcp_usage_listed( $usage ) ) );
	if ( function_exists( 'sn_admin_glance_grid' ) ) {
		$days = (int) ( $usage['measured_days'] ?? 0 );
		sn_admin_glance_grid(
			array(
				/* translators: 1: measured days, 2: window days. */
				array( 'label' => __( 'Calls through a door', 'signal-and-noise-tools' ), 'value' => number_format_i18n( $calls ), 'meta_html' => esc_html( sprintf( _n( 'measured over %1$s day of a %2$s-day window', 'measured over %1$s days of a %2$s-day window', $days, 'signal-and-noise-tools' ), number_format_i18n( $days ), number_format_i18n( (int) ( $usage['window_days'] ?? 0 ) ) ) ) ),
				array( 'label' => __( 'Tools called through a door', 'signal-and-noise-tools' ), 'value' => number_format_i18n( count( $rows ) ), 'meta_html' => esc_html__( 'at least once in the window', 'signal-and-noise-tools' ) ),
				array( 'label' => __( 'Tools with no door calls', 'signal-and-noise-tools' ), 'value' => number_format_i18n( count( $zero ) ), 'meta_html' => esc_html( sn_admin_mcp_usage_zero_note( $zero )['text'] ) ),
			)
		);
	}
	$partial = sn_admin_mcp_usage_partial( $usage );
	if ( '' !== $partial ) {
		echo '<p class="description">' . esc_html( $partial ) . '</p>';
	}
	echo '<p>' . esc_html( sn_admin_mcp_usage_finding( $usage ) ) . '</p>';
	if ( $rows ) {
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Tool', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Calls', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Last seen', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Doors', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, SN_MCP_USAGE_LEDGER_CAP ) as $r ) {
			echo '<tr><td><code>' . esc_html( $r['tool'] ) . '</code></td><td>' . esc_html( $r['calls'] ) . '</td><td>' . esc_html( $r['last_seen'] ) . '</td><td>' . esc_html( $r['doors'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( count( $rows ) > SN_MCP_USAGE_LEDGER_CAP ) {
			/* translators: %s: tools not listed. */
			echo '<p class="description">' . esc_html( sprintf( __( '+%s more tools, busiest first; each door\'s list carries every tool\'s count.', 'signal-and-noise-tools' ), number_format_i18n( count( $rows ) - SN_MCP_USAGE_LEDGER_CAP ) ) ) . '</p>';
		}
	}
	echo '<p class="description">' . esc_html( sn_admin_mcp_usage_door_split( $usage ) ) . '</p>';
	echo '<p>' . esc_html( sn_admin_mcp_usage_zero_finding( $zero ) ) . '</p>';
	foreach ( $zero as $entry ) {
		if ( 'first_party_only' === (string) ( $entry['verdict'] ?? '' ) ) {
			echo '<p><code>' . esc_html( (string) ( $entry['slug'] ?? '' ) ) . '</code>: ' . esc_html( sn_admin_mcp_usage_first_party_label( $entry ) ) . '.</p>';
		}
	}
	if ( $zero ) {
		echo '<p class="description">' . esc_html( sn_admin_mcp_usage_rule() ) . '</p>';
	}
}
