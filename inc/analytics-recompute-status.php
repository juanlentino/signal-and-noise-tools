<?php
/**
 * The history recompute's readouts: the status line, the button form, and the
 * read ability behind sn-status{recompute} (19.4.3), so an operator can read
 * the run without a browser.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The status line beside the button. PURE over the status array (and the clock).
 *
 * @param array $st sn_analytics_recompute_status() shape.
 * @return string
 */
function sn_analytics_recompute_line( array $st ) {
	$n = sprintf( '%d of %d days', (int) $st['done'], (int) $st['total'] );
	if ( sn_analytics_recompute_stalled( $st ) ) {
		return 'Recompute stalled at ' . ( '' !== (string) $st['unit'] ? $st['unit'] : 'its first unit' ) . ' since ' . gmdate( 'Y-m-d H:i', (int) $st['last_tick'] ) . ' UTC, ' . $n . ' done. Resume continues from there.';
	}
	switch ( $st['state'] ) {
		case 'running':
			return 'Recomputing history: ' . $n . ' done' . ( '' !== (string) $st['unit'] ? ', now ' . $st['unit'] : '' ) . '.';
		case 'partial':
			return 'Recompute stopped at ' . $n . ': ' . $st['error'] . '. Resume retries from there.';
		case 'done':
			return 'Recomputed through ' . $st['through'] . ', ' . $n . '.';
	}
	return 'History before the current rule was set has not been recomputed.';
}

/**
 * One form per button: Resume (partial or stalled) and a fresh start.
 *
 * @param string $label Button text.
 * @param string $mode  '' or 'resume'.
 * @param bool   $off   Disabled.
 * @return void
 */
function snt_analytics_recompute_button( $label, $mode, $off ) {
	echo '<form method="post" action="' . esc_url( sn_admin_post_url( 'analytics_recompute' ) ) . '" style="display:inline">';
	wp_nonce_field( 'sn_analytics_recompute' );
	if ( '' !== $mode ) {
		echo '<input type="hidden" name="mode" value="' . esc_attr( $mode ) . '">';
	}
	echo '<button type="submit" name="action" value="sn_analytics_recompute" class="button button-small"' . ( $off ? ' disabled' : '' ) . '>' . esc_html( $label ) . '</button> ';
	echo '</form>';
}

/**
 * Status line + buttons, printed under the human-rule note.
 *
 * @return void
 */
function snt_analytics_render_recompute() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$st      = sn_analytics_recompute_status();
	$stalled = sn_analytics_recompute_stalled( $st );
	echo '<p class="sn-an-visitor-note">' . esc_html( sn_analytics_recompute_line( $st ) ) . ' ';
	if ( $stalled || 'partial' === $st['state'] ) {
		snt_analytics_recompute_button( __( 'Resume recompute', 'signal-and-noise-tools' ), 'resume', false );
	}
	snt_analytics_recompute_button( __( 'Recompute analytics history', 'signal-and-noise-tools' ), '', 'running' === $st['state'] && ! $stalled );
	echo '</p>';
}

/**
 * Ability payload: the run as data. `stalled` is derived, never stored.
 *
 * @return array
 */
function snt_ability_analytics_recompute_status() {
	$st = sn_analytics_recompute_status();
	return array(
		'state'     => (string) $st['state'],
		'stalled'   => sn_analytics_recompute_stalled( $st ),
		'done'      => (int) $st['done'],
		'total'     => (int) $st['total'],
		'step'      => (int) $st['step'],
		'unit'      => (string) $st['unit'],
		'through'   => (string) $st['through'],
		'error'     => (string) $st['error'],
		'started'   => (int) $st['started'],
		'last_tick' => (int) $st['last_tick'],
		'line'      => sn_analytics_recompute_line( $st ),
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/analytics-recompute-status', array(
		'label'               => 'Analytics Recompute Status',
		'description'         => 'The owner-run 90-day analytics history recompute as data: state (idle | running | partial | done), stalled (running but no tick within 15 minutes), done/total days, the cursor (step inside the current 7-day batch and the unit it names: a rollup family or session:<days-ago>), through, error (a partial run names the unit and why, including a fatal or timeout caught at shutdown), started, last_tick, and the status line the button shows. Read-only. Read it through sn-status{recompute}.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_analytics_recompute_status',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object' ),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => false, 'type' => 'tool' ), // absorbed: read via sn-status{recompute}.
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ),
		),
	) );
} );
