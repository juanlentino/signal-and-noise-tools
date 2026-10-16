<?php
/**
 * S&N Dashboard — Connections → Zenodo, painted from the kit (15.11.0).
 *
 * The same reads as the classic leaf (inc/admin-forms/zenodo.php,
 * `sn_admin_render_zenodo_section()`): the environment switch, one deposit
 * action, the ledger as the house table, the status. Tokens live in
 * Credentials.
 *
 * 2026-10-16 (leaf anatomy, docs/kit-leaf-anatomy.md): a Status card leads
 * (one status line, a problem's notice, the states, Deposit, the intro as
 * How this works), the ledger takes the full width, and the environment form
 * is the one fold of a Setup card. Before: an 8/4 row with a status rail.
 *
 * @package SignalNoiseTools
 * @since 15.11.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The environment form as a Setup fold: the closed summary is the
 * environment and the ledger DOI; it opens while no token is set, since the
 * environment names which token to add.
 *
 * @param array<string,mixed> $d sn_zenodo_leaf_data().
 * @return array<string,mixed> A Setup fold.
 */
function zenodo_env_fold( array $d ) {
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
	$ledger  = (string) ( $d['ledger'] ?? '' );
	$summary = ucfirst( (string) $d['env'] ) . ' · ' . ( '' !== $ledger
		/* translators: %s: the ledger concept DOI. */
		? sprintf( __( 'ledger DOI %s', 'signal-and-noise-tools' ), $ledger )
		: __( 'ledger DOI not set', 'signal-and-noise-tools' ) );
	return array(
		'title'   => __( 'Environment', 'signal-and-noise-tools' ),
		'summary' => $summary,
		'body'    => \snt_kit_form( 'zenodo_env_save', $env_field . $doi_field, array( 'submit' => __( 'Save environment', 'signal-and-noise-tools' ), 'hidden' => array( 'tab' => 'connections', 'sub' => 'zenodo' ) ) ),
		'open'    => empty( $d['enabled'] ),
	);
}

/**
 * The deposit action and what one pass does.
 *
 * @param array<string,mixed> $d sn_zenodo_leaf_data().
 * @return string
 */
function zenodo_deposit_html( array $d ) {
	return '<p class="snt-hint">' . \snt_kit_esc( sprintf(
		/* translators: %d: documents per pass */
		__( 'Runs one pass now: up to %d confirmed documents without a DOI. The hourly pass does the same on its own.', 'signal-and-noise-tools' ),
		(int) \SN_ZENODO_PASS_MAX
	) ) . '</p>'
		. \snt_kit_tag( 'os-cluster', array( 'gap' => '8' ), \snt_kit_action_button( __( 'Deposit the next batch', 'signal-and-noise-tools' ), 'zenodo_deposit_batch', array( 'disabled' => ! $d['enabled'], 'confirm' => \sn_zenodo_deposit_confirm( (string) $d['env'] ), 'confirm_title' => __( 'Deposit the next batch', 'signal-and-noise-tools' ), 'confirm_label' => __( 'Deposit', 'signal-and-noise-tools' ) ) ) );
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
			'title' => array( 'html' => '<span class="snt-zenodo-title">' . \snt_kit_link( (string) $r['title'], admin_url( 'post.php?post=' . (int) ( $r['id'] ?? 0 ) . '&action=edit' ) ) . '</span>', 'text' => (string) $r['title'] ),
			'kind'  => (string) $r['kind'],
			'state' => \sn_zenodo_state_label( $r['state'] ),
			// A sandbox DOI resolves nowhere public, by design: text, not a
			// link. Either way it reads whole (it broke mid-number at 149px).
			'doi'   => '' === $doi ? __( 'none yet', 'signal-and-noise-tools' ) : array( 'html' => '<span class="sn-nowrap">' . ( 'minted' === (string) $r['state'] ? \snt_kit_link( $doi, 'https://doi.org/' . rawurlencode( $doi ) ) : \snt_kit_esc( $doi ) ) . '</span>', 'text' => $doi ),
			// One line ("6:33 pm" broke across two).
			'at'    => array( 'html' => '<span class="sn-nowrap">' . \snt_kit_esc( \sn_zenodo_deposited_text( (string) $r['at'] ) ) . '</span>', 'text' => \sn_zenodo_deposited_text( (string) $r['at'] ) ),
		);
		if ( $errors ) {
			$row['error'] = (string) $r['error'];
		}
		$rows[] = $row;
	}
	return \snt_kit_section(
		__( 'Ledger', 'signal-and-noise-tools' ),
		\snt_kit_ledger( \sn_zenodo_ledger_finding( $d['rows'] ), $columns, $rows, ZENODO_LEDGER_ROWS, array( 'empty' => __( 'No signed documents yet.', 'signal-and-noise-tools' ), 'stack_on_phone' => true, 'class' => 'snt-table-full' ) ),
		__( 'Every signed document with its DOI state. A minted DOI resolves at doi.org; a sandbox DOI does not, by design.', 'signal-and-noise-tools' )
	);
}

/**
 * The Status card: one status line (the token's verdict, the environment),
 * the verdict's notice while it is not ok (its sentence a hint while it is),
 * the states with the last pass, Deposit, and the intro as How this works.
 * The "Zenodo status" landmark the rail carried is this card.
 *
 * @param array<string,mixed> $d sn_zenodo_leaf_data().
 * @return string
 */
function zenodo_status_html( array $d ) {
	// 16.2.2: the tile reads the keyring's verdict, not "a token is stored".
	$tile  = \sn_zenodo_tile_state( $d['enabled'], $d['verdict'] ?? null, $d['env'], (int) ( $d['counts']['minted'] ?? 0 ), count( $d['rows'] ) );
	$where = $d['enabled'] ? ucfirst( $d['env'] ) : __( 'No token', 'signal-and-noise-tools' );
	$line  = \snt_kit_status_line(
		array(
			array( 'tone' => 'error' === $tile['tone'] ? 'bad' : $tile['tone'], 'label' => $tile['badge'], 'title' => $tile['body'] ),
			array( 'tone' => 'neutral', 'label' => $where ),
		)
	);
	$under = 'ok' === $tile['tone']
		? '<p class="snt-hint">' . \snt_kit_esc( $tile['body'] ) . '</p>'
		: \snt_kit_notice( $tile['tone'], '<b>' . \snt_kit_esc( $where ) . '</b> ' . \snt_kit_badge( $tile['tone'], $tile['badge'] ) . '<br>' . \snt_kit_esc( $tile['body'] ) );
	$rows = array();
	foreach ( $d['counts'] as $state => $n ) {
		$rows[] = array( 'label' => \sn_zenodo_state_label( $state ), 'value' => (string) (int) $n );
	}
	if ( ! empty( $d['batch'] ) ) {
		$rows[] = array( 'label' => __( 'Last pass', 'signal-and-noise-tools' ), 'value' => \sn_zenodo_last_pass_text( $d['batch'] ) );
	}
	$how = \snt_kit_how_fold(
		__( 'Signed documents get a DOI once their anchor is confirmed.', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . \snt_kit_esc( __( 'Every signed document (the notes, the pillar essays) gets a DOI on Zenodo once its anchor is confirmed, with the Markdown, the signed record and the Bitcoin proof filed with it. The papers stay with SSRN. Sandbox DOIs are test DOIs and never reach a public surface.', 'signal-and-noise-tools' ) ) . '</p>'
	);
	return \snt_kit_section(
		__( 'Status', 'signal-and-noise-tools' ),
		$line . $under . ( array() !== $rows ? \snt_kit_kv( $rows ) : '' ) . zenodo_deposit_html( $d ) . $how,
		'',
		array( 'role' => 'region', 'aria-label' => __( 'Zenodo status', 'signal-and-noise-tools' ) )
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
	$d = \sn_zenodo_leaf_data();
	return zenodo_status_html( $d )
		. zenodo_ledger_html( $d )
		. \snt_kit_setup_card( __( 'Setup', 'signal-and-noise-tools' ), array( zenodo_env_fold( $d ) ), __( 'Set once; open it to change it.', 'signal-and-noise-tools' ) );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/zenodo'] = __NAMESPACE__ . '\\paint_connections_zenodo';
		return $painters;
	}
);
