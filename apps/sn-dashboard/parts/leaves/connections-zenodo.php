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
			'hint'    => __( 'Each environment reads its own token from Connections › Credentials.', 'signal-and-noise-tools' ),
		)
	);
	$doi_field = \snt_kit_field(
		'text',
		'zenodo_ledger_doi',
		__( 'Ledger concept DOI', 'signal-and-noise-tools' ),
		(string) ( $d['ledger'] ?? '' ),
		array(
			'placeholder' => '10.5281/zenodo.NNNNNNN',
			'hint'        => __( 'The DOI Zenodo minted for the ledger repository\'s monthly snapshot (the concept DOI, which resolves to the latest snapshot). Every deposit names it as the record it is part of. Empty until the first snapshot release.', 'signal-and-noise-tools' ),
		)
	);
	$env = \snt_kit_section(
		__( 'Environment', 'signal-and-noise-tools' ),
		\snt_kit_form( 'zenodo_env_save', $env_field . $doi_field, array( 'submit' => __( 'Save environment', 'signal-and-noise-tools' ), 'hidden' => array( 'tab' => 'connections', 'sub' => 'zenodo' ) ) )
	);
	$deposit = \snt_kit_section(
		__( 'Deposit', 'signal-and-noise-tools' ),
		\snt_kit_tag( 'os-cluster', array( 'gap' => '8' ), \snt_kit_action_button( __( 'Deposit the next batch', 'signal-and-noise-tools' ), 'zenodo_deposit_batch', array( 'disabled' => ! $d['enabled'], 'confirm' => \sn_zenodo_deposit_confirm( (string) $d['env'] ), 'confirm_title' => __( 'Deposit the next batch', 'signal-and-noise-tools' ), 'confirm_label' => __( 'Deposit', 'signal-and-noise-tools' ) ) ) ),
		sprintf(
			/* translators: %d: documents per pass */
			__( 'Runs one pass now: up to %d confirmed documents without a DOI. The hourly pass does the same on its own.', 'signal-and-noise-tools' ),
			(int) \SN_ZENODO_PASS_MAX
		)
	);
	return \snt_kit_grid( array( \snt_kit_tag( 'os-card', array(), $env ), \snt_kit_tag( 'os-card', array(), $deposit ) ) );
}

/** The ledger lists this many rows (the waiting ones first), then "+N more". */
const ZENODO_LEDGER_ROWS = 10;

/**
 * The ledger (2026-10-12 audit: a 58-row wall of "Minted" with no sentence,
 * the DOI as plain text, raw ISO times, an empty error column): the finding,
 * the rows still waiting first, ZENODO_LEDGER_ROWS of them then "+N more";
 * the document opens its editor, a minted DOI opens doi.org, the time is the
 * site's, and the Last error column paints only when a row has one.
 *
 * @param array<string,mixed> $d sn_zenodo_leaf_data().
 * @return string
 */
function zenodo_ledger_html( array $d ) {
	$errors  = array() !== array_filter( $d['rows'], static fn( $r ) => '' !== (string) ( $r['error'] ?? '' ) );
	$columns = array(
		array( 'key' => 'title', 'label' => __( 'Document', 'signal-and-noise-tools' ), 'filter' => 'text' ),
		array( 'key' => 'kind', 'label' => __( 'Kind', 'signal-and-noise-tools' ) ),
		array( 'key' => 'state', 'label' => __( 'State', 'signal-and-noise-tools' ), 'filter' => 'text' ),
		array( 'key' => 'doi', 'label' => __( 'DOI', 'signal-and-noise-tools' ) ),
		array( 'key' => 'at', 'label' => __( 'Deposited', 'signal-and-noise-tools' ) ),
	);
	if ( $errors ) {
		$columns[] = array( 'key' => 'error', 'label' => __( 'Last error', 'signal-and-noise-tools' ) );
	}
	$rows = array();
	foreach ( \sn_zenodo_ledger_sorted( $d['rows'] ) as $r ) {
		$doi = (string) $r['doi'];
		$row = array(
			'_key'  => 'zenodo-' . (int) ( $r['id'] ?? 0 ),
			'title' => array( 'html' => \snt_kit_link( (string) $r['title'], admin_url( 'post.php?post=' . (int) ( $r['id'] ?? 0 ) . '&action=edit' ) ), 'text' => (string) $r['title'] ),
			'kind'  => (string) $r['kind'],
			'state' => \sn_zenodo_state_label( $r['state'] ),
			// A sandbox DOI resolves nowhere public, by design: text, not a link.
			'doi'   => '' === $doi ? __( 'none yet', 'signal-and-noise-tools' ) : ( 'minted' === (string) $r['state'] ? array( 'html' => \snt_kit_link( $doi, 'https://doi.org/' . rawurlencode( $doi ) ), 'text' => $doi ) : $doi ),
			'at'    => \sn_zenodo_deposited_text( (string) $r['at'] ),
		);
		if ( $errors ) {
			$row['error'] = (string) $r['error'];
		}
		$rows[] = $row;
	}
	return \snt_kit_section(
		__( 'Ledger', 'signal-and-noise-tools' ),
		\snt_kit_ledger( \sn_zenodo_ledger_finding( $d['rows'] ), $columns, $rows, ZENODO_LEDGER_ROWS, array( 'empty' => __( 'No signed documents yet.', 'signal-and-noise-tools' ), 'stack_on_phone' => true ) ),
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
	// 16.2.2: the tile reads the keyring's verdict, not "a token is stored".
	$tile   = \sn_zenodo_tile_state( $d['enabled'], $d['verdict'] ?? null, $d['env'], (int) ( $d['counts']['minted'] ?? 0 ), count( $d['rows'] ) );
	$status = \snt_kit_notice( $tile['tone'], '<b>' . \snt_kit_esc( $d['enabled'] ? ucfirst( $d['env'] ) : __( 'No token', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_badge( $tile['tone'], $tile['badge'] ) . '<br>' . \snt_kit_esc( $tile['body'] ) );
	$rows = array();
	foreach ( $d['counts'] as $state => $n ) {
		$rows[] = array( 'label' => \sn_zenodo_state_label( $state ), 'value' => (string) (int) $n );
	}
	if ( ! empty( $d['batch'] ) ) {
		$rows[] = array( 'label' => __( 'Last pass', 'signal-and-noise-tools' ), 'value' => \sn_zenodo_last_pass_text( $d['batch'] ) );
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
	$out = '<p class="snt-prose">' . \snt_kit_esc( __( 'Every signed document (the notes, the pillar essays) gets a DOI on Zenodo once its anchor is confirmed, with the Markdown, the signed record and the Bitcoin proof filed with it. The papers stay with SSRN. Sandbox DOIs are test DOIs and never reach a public surface.', 'signal-and-noise-tools' ) ) . '</p>';
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
