<?php
/**
 * AI › Agent tools: the refusals, counted, in the words both twins say.
 *
 * Every sentence the native leaf paints (ai-agent-tools-refusals.php) comes
 * from a helper here, so the classic block and the leaf cannot drift. The
 * data is sn_door_refusals_read() (inc/mcp/mcp-door-refusals-read.php).
 *
 * @package Signal_And_Noise_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The table rows both twins paint: door by its screen name, rule, 7 days,
 * 30 days. Already sorted by count; a rule that never fired is not here.
 *
 * @param array $read From sn_door_refusals_read().
 * @return array<int,array<string,string>>
 */
function sn_admin_door_refusals_rows( $read ) {
	$out = array();
	foreach ( (array) ( $read['rows'] ?? array() ) as $r ) {
		$out[] = array(
			'door' => function_exists( 'sn_admin_mcp_door_name' ) ? sn_admin_mcp_door_name( (string) $r['door'] ) : (string) $r['door'],
			'rule' => (string) $r['rule'],
			'd7'   => number_format_i18n( (int) $r['d7'] ),
			'd30'  => number_format_i18n( (int) $r['d30'] ),
		);
	}
	return $out;
}

/**
 * The finding over the table, or the honest empty sentence.
 *
 * @param array $read From sn_door_refusals_read().
 * @return string
 */
function sn_admin_door_refusals_finding( $read ) {
	$rows = (array) ( $read['rows'] ?? array() );
	if ( ! $rows ) {
		return __( 'No write door declined a call in the last 30 days.', 'signal-and-noise-tools' );
	}
	$top = $rows[0];
	/* translators: 1: refusals in 30 days, 2: refusals in 7 days, 3: busiest rule, 4: its door, 5: its 30-day count. */
	return sprintf( _n( '%1$s write was declined in the last 30 days, %2$s in the last 7. The rule that declined most is %3$s at the %4$s, %5$s times.', '%1$s writes were declined in the last 30 days, %2$s in the last 7. The rule that declined most is %3$s at the %4$s, %5$s times.', (int) ( $read['total_30'] ?? 0 ), 'signal-and-noise-tools' ), number_format_i18n( (int) ( $read['total_30'] ?? 0 ) ), number_format_i18n( (int) ( $read['total_7'] ?? 0 ) ), (string) $top['rule'], function_exists( 'sn_admin_mcp_door_name' ) ? sn_admin_mcp_door_name( (string) $top['door'] ) : (string) $top['door'], number_format_i18n( (int) $top['d30'] ) );
}

/**
 * The warning when the call log could not be read, '' when it could.
 *
 * @param array $read From sn_door_refusals_read().
 * @return string
 */
function sn_admin_door_refusals_unreadable( $read ) {
	if ( ! empty( $read['log_readable'] ) ) {
		return '';
	}
	return __( 'The call log could not be read, so a refusal that reached a tool call is missing here. Only the write door\'s own checks are counted below.', 'signal-and-noise-tools' );
}

/**
 * Where the counts come from, one sentence.
 *
 * @return string
 */
function sn_admin_door_refusals_sources() {
	return __( 'From the call log: every refused, rejected or conflicting call to a write tool, with the code of the gate that declined it. Counted on their own since this version: the write door\'s credential, kill switch and capability checks, and write abilities denied outside MCP, which are decided before the log. A signed-out request is not counted. Counts only; no payload is kept.', 'signal-and-noise-tools' );
}

/**
 * The column labels, shared.
 *
 * @return array<string,string>
 */
function sn_admin_door_refusals_columns() {
	return array(
		'door' => __( 'Door', 'signal-and-noise-tools' ),
		'rule' => __( 'Rule', 'signal-and-noise-tools' ),
		'd7'   => __( '7 days', 'signal-and-noise-tools' ),
		'd30'  => __( '30 days', 'signal-and-noise-tools' ),
	);
}

/**
 * Classic twin: the refusals table under Through MCP.
 *
 * @param array|null $read From sn_door_refusals_read(); null reads it here.
 * @return void
 */
function sn_admin_render_door_refusals( $read = null ) {
	if ( ! function_exists( 'sn_door_refusals_read' ) ) {
		return;
	}
	$read = null === $read ? sn_door_refusals_read() : $read;
	echo '<h3>' . esc_html__( 'Refusals at the write doors', 'signal-and-noise-tools' ) . '</h3>';
	$warn = sn_admin_door_refusals_unreadable( $read );
	if ( '' !== $warn ) {
		wp_admin_notice( esc_html( $warn ), array( 'type' => 'warning', 'additional_classes' => array( 'inline' ) ) );
	}
	echo '<p>' . esc_html( sn_admin_door_refusals_finding( $read ) ) . '</p>';
	$rows = sn_admin_door_refusals_rows( $read );
	if ( $rows ) {
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( sn_admin_door_refusals_columns() as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r['door'] ) . '</td><td><code>' . esc_html( $r['rule'] ) . '</code></td><td>' . esc_html( $r['d7'] ) . '</td><td>' . esc_html( $r['d30'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '<p class="description">' . esc_html( sn_admin_door_refusals_sources() ) . '</p>';
}
