<?php
/**
 * TEMPORARY — the one-time Copilot tool-budget audit, as a click-in-wp-admin
 * panel. Added v9.62.0 so the measurement can be run from the browser (no
 * terminal); REMOVE this whole file + its require + its CHANGELOG note in the
 * next release once the numbers are captured. It ships nothing durable.
 *
 * manage_options + nonce gated, read-only: it reconstructs the Copilot tool list
 * from the live ability registry exactly as desktop-mode does, runs it through
 * the real `desktop_mode_ai_tools` filter chain (our prune + normalize + the
 * theme's), and prints the sizes. No Ask AI turn, no writes.
 *
 * @package signal-and-noise-tools
 * @since   9.62.0 (temporary)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reconstruct the provider-bound tool list and return a plain-text size report
 * (BEFORE the filter, AFTER it, and the delta). Returns a short notice string if
 * desktop-mode's enumerator is unavailable.
 *
 * @since 9.62.0 (temporary)
 * @return string
 */
function snt_ai_tool_audit_admin_measure() {
	if ( ! function_exists( 'desktop_mode_ai_search_ability_names' )
		|| ! function_exists( 'desktop_mode_ai_ability_tool_name' )
		|| ! function_exists( 'wp_get_ability' ) ) {
		return 'desktop-mode ability enumerator unavailable — is Desktop Mode active?';
	}

	$tools = array();
	$owner = array();
	foreach ( desktop_mode_ai_search_ability_names() as $ability_name ) {
		$ability = wp_get_ability( $ability_name );
		if ( ! $ability instanceof WP_Ability ) {
			continue;
		}
		$tool_name             = desktop_mode_ai_ability_tool_name( $ability_name );
		$schema                = $ability->get_input_schema();
		$owner[ $tool_name ]   = 0 === strpos( $ability_name, 'signal-noise/' ) ? 'SN'
			: ( 0 === strpos( $ability_name, 'signal-and-noise/' ) ? 'theme' : '-' );
		$tools[]               = array(
			'type'        => 'function',
			'name'        => $tool_name,
			'description' => (string) $ability->get_description(),
			'parameters'  => ! empty( $schema ) ? $schema : array( 'type' => 'object', 'properties' => (object) array() ),
		);
	}

	$final = (array) apply_filters(
		'desktop_mode_ai_tools',
		$tools,
		array( 'user_id' => get_current_user_id(), 'request_id' => 'audit', 'query' => 'audit' )
	);

	$report = static function ( $list, $label ) use ( $owner ) {
		$rows  = array();
		$total = 0;
		foreach ( (array) $list as $tool ) {
			$name   = (string) ( $tool['name'] ?? '?' );
			$bytes  = strlen( (string) wp_json_encode( $tool ) );
			$total += $bytes;
			$rows[] = array( 'name' => $name, 'bytes' => $bytes, 'own' => $owner[ $name ] ?? '-' );
		}
		usort( $rows, static function ( $a, $b ) { return $b['bytes'] <=> $a['bytes']; } );
		$out  = sprintf( "===== %s =====\nTOOLS: %d   TOTAL: %d bytes  (~%d tokens)\n\n", $label, count( $rows ), $total, (int) round( $total / 4 ) );
		$out .= sprintf( "%-6s %-8s %s\n", 'OWNER', 'BYTES', 'NAME' );
		foreach ( $rows as $r ) {
			$out .= sprintf( "%-6s %-8d %s\n", $r['own'], $r['bytes'], $r['name'] );
		}
		$sn       = array_filter( $rows, static function ( $r ) { return 'SN' === $r['own']; } );
		$sn_bytes = array_sum( array_column( $sn, 'bytes' ) );
		$out     .= sprintf( "\nOURS (SN): %d tools, %d bytes (~%d tokens) — %d%% of this list\n",
			count( $sn ), $sn_bytes, (int) round( $sn_bytes / 4 ), $total > 0 ? (int) round( 100 * $sn_bytes / $total ) : 0 );
		return array( $out, $total );
	};

	list( $before_txt, $before ) = $report( $tools, 'BEFORE the filter (raw ability tools)' );
	list( $after_txt, $after )   = $report( $final, 'AFTER the filter (pruned + normalized — what the provider gets)' );

	return $before_txt . "\n" . $after_txt . sprintf(
		"\n===== DELTA =====\nfilter chain: %d → %d bytes (%+d, ~%+d tokens)\nCommand tools (client-supplied) are not counted.\n",
		$before, $after, $after - $before, (int) round( ( $after - $before ) / 4 )
	);
}

/**
 * The dashboard panel: a button that runs the audit and prints the result.
 *
 * @since 9.62.0 (temporary)
 * @return void
 */
function snt_ai_tool_audit_admin_panel() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<h2 class="sn-section-h">Copilot tool audit <span class="sn-muted">(temporary — v9.62.0)</span></h2>';

	$run_url = wp_nonce_url( add_query_arg( 'sn_ai_audit', 'run' ), 'sn_ai_audit_run' );
	echo '<p><a class="button" href="' . esc_url( $run_url ) . '">Run Copilot tool audit</a></p>';

	if ( isset( $_GET['sn_ai_audit'] ) && check_admin_referer( 'sn_ai_audit_run' ) ) {
		echo '<pre class="sn-ai-audit-out" style="overflow:auto;max-height:32em">' . esc_html( snt_ai_tool_audit_admin_measure() ) . '</pre>';
	}
}
add_action( 'sn_admin_dashboard_extras', 'snt_ai_tool_audit_admin_panel', 99 );
