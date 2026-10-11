<?php
/**
 * S&N Dashboard: AI › Agent tools, the allowlisted tools no call reached
 * through a door, split by WHY.
 *
 * Zero calls means either nobody used a tool or nobody could: opposite
 * conclusions from identical evidence, so the verdict is counted in the
 * finding and printed beside every row, the bugs first. Its own section under
 * the call log (ai-agent-tools-usage.php), open and capped (2026-10-11, owner
 * rule: no collapsible sections). The first-party words are the classic
 * block's helper (inc/admin-forms/mcp-usage-block.php).
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * One verdict's count, as a phrase of the finding.
 *
 * @param string $verdict unreachable, undetermined, unused or first_party_only.
 * @param int    $n       Tools with that verdict.
 * @return string
 */
function agent_tools_zero_phrase( $verdict, $n ) {
	$f = number_format_i18n( $n );
	switch ( $verdict ) {
		case 'unreachable':
			/* translators: %s: tool count. */
			return sprintf( _n( '%s cannot be projected', '%s cannot be projected', $n, 'signal-and-noise-tools' ), $f );
		case 'undetermined':
			/* translators: %s: tool count. */
			return sprintf( _n( '%s with reachability unknown', '%s with reachability unknown', $n, 'signal-and-noise-tools' ), $f );
		case 'unused':
			/* translators: %s: tool count. */
			return sprintf( _n( '%s retirement candidate', '%s retirement candidates', $n, 'signal-and-noise-tools' ), $f );
		default:
			/* translators: %s: tool count. */
			return sprintf( _n( '%s called only by the plugin itself', '%s called only by the plugin itself', $n, 'signal-and-noise-tools' ), $f );
	}
}

/**
 * The section: the finding (the count, split by verdict), the tools as a
 * capped ledger, the bugs first, and the reading rule under it.
 *
 * @param array<int,array> $zero Entries from sn_mcp_telemetry_zero_call().
 * @return string '' when every allowlisted tool had a door call.
 */
function agent_tools_zero_html( array $zero ) {
	if ( empty( $zero ) ) {
		return '';
	}
	$order = array( 'unreachable', 'undetermined', 'unused', 'first_party_only' );
	usort( $zero, static function ( $a, $b ) use ( $order ) {
		$rank = static function ( $e ) use ( $order ) { $i = array_search( (string) ( $e['verdict'] ?? '' ), $order, true ); return false === $i ? count( $order ) : $i; };
		return $rank( $a ) <=> $rank( $b );
	} );
	$counts = array_count_values( array_map( static function ( $e ) { return (string) ( $e['verdict'] ?? '' ); }, $zero ) );
	$parts  = array();
	foreach ( $order as $verdict ) {
		if ( ! empty( $counts[ $verdict ] ) ) {
			$parts[] = agent_tools_zero_phrase( $verdict, (int) $counts[ $verdict ] );
		}
	}
	/* translators: 1: tool count, 2: the count by verdict, e.g. "1 cannot be projected; 41 retirement candidates". */
	$finding = sprintf( _n( '%1$s allowlisted tool had no call through a door: %2$s.', '%1$s allowlisted tools had no call through a door: %2$s.', count( $zero ), 'signal-and-noise-tools' ), number_format_i18n( count( $zero ) ), implode( '; ', $parts ) );

	$labels = array(
		'unused'       => __( 'Retirement candidate', 'signal-and-noise-tools' ),
		'unreachable'  => __( 'Cannot be projected: a bug, not a retirement candidate', 'signal-and-noise-tools' ),
		'undetermined' => __( 'Reachability unknown: no judgement possible', 'signal-and-noise-tools' ),
	);
	$rows = array();
	foreach ( $zero as $entry ) {
		$verdict = (string) ( $entry['verdict'] ?? '' );
		$rows[]  = array(
			'tool'    => (string) ( $entry['slug'] ?? '' ),
			'verdict' => ( 'first_party_only' === $verdict && function_exists( 'sn_admin_mcp_usage_first_party_label' ) ) ? sn_admin_mcp_usage_first_party_label( $entry ) : ( $labels[ $verdict ] ?? $verdict ),
		);
	}
	$columns = array(
		array( 'key' => 'tool', 'label' => __( 'Tool', 'signal-and-noise-tools' ) ),
		array( 'key' => 'verdict', 'label' => __( 'Why it has none', 'signal-and-noise-tools' ) ),
	);
	/* translators: %s: tools not listed. */
	$ledger = \snt_kit_ledger( $finding, $columns, $rows, AGENT_TOOLS_USAGE_CAP, array( 'more' => __( '+%s more tools with no door calls; the bugs are listed first.', 'signal-and-noise-tools' ) ) );
	$rule   = '<p class="snt-hint">' . \snt_kit_esc( __( 'Only “retirement candidate” entries are evidence for removal. A tool that cannot be projected has no calls because it cannot be called; retiring it would delete the evidence of the defect. Reachability is checked from inside the plugin, so it cannot see a client proxy rejecting a schema; treat it as necessary, not sufficient.', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_section( __( 'No calls in this window', 'signal-and-noise-tools' ), $ledger . $rule );
}
