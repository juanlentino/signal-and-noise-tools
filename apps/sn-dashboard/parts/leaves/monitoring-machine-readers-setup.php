<?php
/**
 * S&N Dashboard, Measurement > Machine Readers: the Setup card.
 *
 * 2026-10-16 leaf anatomy (docs/kit-leaf-anatomy.md): the deployed worker's
 * readout and the sensor settings are reference and setup, read or changed
 * once in a while, so each is a fold of one Setup card
 * (snt_kit_setup_card(), inc/openstation-kit-anatomy.php) whose closed line
 * is its current value: the worker and its version, the read token's state.
 * A fold opens itself when it needs the reader: the version endpoint did not
 * answer, or no read token is set. The status line above still carries
 * every pill and its warning notice, open; a fold never holds the only
 * statement of a problem. Same form, field and action as the two boxes
 * these folds replace.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The Setup card.
 *
 * @param array<string,mixed> $d machine_readers_data() output.
 * @return string
 */
function machine_readers_setup_html( array $d ) {
	$rows    = function_exists( '\snt_mr_edge_readout' ) ? \snt_mr_edge_readout( $d['info'] ) : null;
	$version = is_array( $rows ) && isset( $rows[0]['value'] ) ? (string) $rows[0]['value'] : '';
	return \snt_kit_setup_card(
		__( 'Setup', 'signal-and-noise-tools' ),
		array(
			array(
				'title'   => __( 'Edge sensor', 'signal-and-noise-tools' ),
				'summary' => '' !== $version ? $version : __( 'Not read', 'signal-and-noise-tools' ),
				'body'    => '<p class="snt-hint">' . \snt_kit_esc( __( 'The deployed rights-signals Worker, from its version endpoint. Cached for up to 15 minutes, so a fresh deploy can take that long to appear here; Re-read now asks it again.', 'signal-and-noise-tools' ) ) . '</p>'
					. machine_readers_edge_readout_html( $d['info'] ),
				'open'    => null === $rows,
			),
			array(
				'title'   => __( 'Sensor settings', 'signal-and-noise-tools' ),
				'summary' => machine_readers_token_snapshot( $d ),
				'body'    => machine_readers_settings_html( $d ),
				'open'    => ! $d['token_locked'] && ! $d['has_token'],
			),
		),
		__( 'Each line is the setting as it stands; open one to change it.', 'signal-and-noise-tools' )
	);
}
