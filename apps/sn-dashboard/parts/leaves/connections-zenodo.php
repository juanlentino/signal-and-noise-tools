<?php
/**
 * S&N Dashboard — Connections → Zenodo, painted from the kit (15.11.0).
 *
 * The same reads as the classic leaf (inc/admin-forms/zenodo.php,
 * `sn_admin_render_zenodo_section()`): the environment switch, one deposit
 * action, the ledger as the house table, the status rail. Tokens live in
 * Credentials.
 *
 * @package SignalNoiseTools
 * @since 15.11.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The environment form and the deposit action.
 *
 * @param array<string,mixed> $d sn_zenodo_leaf_data().
 * @return string
 */
function zenodo_main_html( array $d ) {
	$env_field = \snt_kit_field(
		'select',
		'zenodo_env',
		__( 'Environment', 'signal-and-noise-tools' ),
		$d['env'],
		array(
			'options' => array(
				'sandbox'    => __( 'Sandbox (sandbox.zenodo.org): test DOIs, never public', 'signal-and-noise-tools' ),
				'production' => __( 'Production (zenodo.org)', 'signal-and-noise-tools' ),
			),
			'help'    => __( 'Each environment reads its own token from Credentials.', 'signal-and-noise-tools' ),
		)
	);
	$env = \snt_kit_section(
		__( 'Environment', 'signal-and-noise-tools' ),
		\snt_kit_form( 'zenodo_env_save', $env_field, array( 'submit' => __( 'Save environment', 'signal-and-noise-tools' ), 'hidden' => array( 'tab' => 'connections', 'sub' => 'zenodo' ) ) )
	);
	$deposit = \snt_kit_section(
		__( 'Deposit', 'signal-and-noise-tools' ),
		\snt_kit_tag( 'os-cluster', array( 'gap' => '8' ), \snt_kit_action_button( __( 'Deposit the next batch', 'signal-and-noise-tools' ), 'zenodo_deposit_batch', array( 'disabled' => ! $d['enabled'] ) ) ),
		sprintf(
			/* translators: %d: documents per pass */
			__( 'Runs one pass now: up to %d confirmed documents without a DOI. The hourly pass does the same on its own.', 'signal-and-noise-tools' ),
			(int) \SN_ZENODO_PASS_MAX
		)
	);
	return '<div class="snt-cols"><section class="snt-col">' . $env . '</section><section class="snt-col">' . $deposit . '</section></div>';
}

/**
 * The ledger as the house table.
 *
 * @param array<string,mixed> $d sn_zenodo_leaf_data().
 * @return string
 */
function zenodo_ledger_html( array $d ) {
	$columns = array(
		array( 'key' => 'title', 'label' => __( 'Document', 'signal-and-noise-tools' ), 'filter' => 'text' ),
		array( 'key' => 'kind', 'label' => __( 'Kind', 'signal-and-noise-tools' ) ),
		array( 'key' => 'state', 'label' => __( 'State', 'signal-and-noise-tools' ), 'filter' => 'text' ),
		array( 'key' => 'doi', 'label' => __( 'DOI', 'signal-and-noise-tools' ) ),
		array( 'key' => 'at', 'label' => __( 'Deposited', 'signal-and-noise-tools' ) ),
		array( 'key' => 'error', 'label' => __( 'Last error', 'signal-and-noise-tools' ) ),
	);
	$rows = array();
	foreach ( $d['rows'] as $r ) {
		$rows[] = array(
			'title' => (string) $r['title'],
			'kind'  => (string) $r['kind'],
			'state' => \sn_zenodo_state_label( $r['state'] ),
			'doi'   => '' !== $r['doi'] ? (string) $r['doi'] : '—',
			'at'    => '' !== $r['at'] ? (string) $r['at'] : '—',
			'error' => (string) $r['error'],
		);
	}
	return \snt_kit_section(
		__( 'Ledger', 'signal-and-noise-tools' ),
		\snt_kit_table( $columns, $rows, array( 'empty' => __( 'No signed documents yet.', 'signal-and-noise-tools' ) ) ),
		__( 'Every signed document with its DOI state. A minted DOI resolves at doi.org; a sandbox DOI does not, by design.', 'signal-and-noise-tools' )
	);
}

/**
 * The status rail.
 *
 * @param array<string,mixed> $d sn_zenodo_leaf_data().
 * @return string
 */
function zenodo_rail_html( array $d ) {
	if ( ! $d['enabled'] ) {
		$status = \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( __( 'No token', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_badge( 'warn', __( 'Off', 'signal-and-noise-tools' ) ) . '<br>' . \snt_kit_esc( sprintf( /* translators: %s: environment */ __( 'Add the %s token under Credentials.', 'signal-and-noise-tools' ), $d['env'] ) ) );
	} else {
		$status = \snt_kit_notice( 'ok', '<b>' . \snt_kit_esc( ucfirst( $d['env'] ) ) . '</b> ' . \snt_kit_badge( 'ok', __( 'On', 'signal-and-noise-tools' ) ) . '<br>' . \snt_kit_esc( sprintf( /* translators: 1: minted, 2: total */ __( '%1$d minted of %2$d documents.', 'signal-and-noise-tools' ), (int) ( $d['counts']['minted'] ?? 0 ), count( $d['rows'] ) ) ) );
	}
	$rows = array();
	foreach ( $d['counts'] as $state => $n ) {
		$rows[] = array( 'label' => \sn_zenodo_state_label( $state ), 'value' => (string) (int) $n );
	}
	if ( ! empty( $d['batch'] ) ) {
		$rows[] = array( 'label' => __( 'Last pass', 'signal-and-noise-tools' ), 'value' => sprintf( '%1$d published, %2$d failed, of %3$d', (int) ( $d['batch']['published'] ?? 0 ), (int) ( $d['batch']['failed'] ?? 0 ), (int) ( $d['batch']['attempted'] ?? 0 ) ) );
	}
	return \snt_kit_tag(
		'aside',
		array( 'col' => '4', 'aria-label' => __( 'Zenodo status', 'signal-and-noise-tools' ) ),
		\snt_kit_tag( 'os-stack', array( 'gap' => '16' ), $status . \snt_kit_section( __( 'States', 'signal-and-noise-tools' ), \snt_kit_kv( $rows ) ) )
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_connections_zenodo( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	if ( ! function_exists( 'sn_zenodo_leaf_data' ) ) {
		return \snt_kit_empty( __( 'Zenodo is not available.', 'signal-and-noise-tools' ) );
	}
	$d   = \sn_zenodo_leaf_data();
	$out = '<p class="snt-prose">' . \snt_kit_esc( __( 'Every signed document (the notes, the pillar essays) gets a DOI on Zenodo once its anchor is confirmed, with the Markdown, the signed record and the Bitcoin proof filed beside it. The papers stay with SSRN. Sandbox DOIs are test DOIs and never reach a public surface.', 'signal-and-noise-tools' ) ) . '</p>';
	$out .= \snt_kit_tag(
		'os-row',
		array( 'gap' => '16' ),
		\snt_kit_tag( 'os-stack', array( 'col' => '8', 'gap' => '12' ), zenodo_main_html( $d ) . zenodo_ledger_html( $d ) ) . zenodo_rail_html( $d )
	);
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/zenodo'] = __NAMESPACE__ . '\\paint_connections_zenodo';
		return $painters;
	}
);
