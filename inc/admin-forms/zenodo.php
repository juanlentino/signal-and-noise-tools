<?php
/**
 * Signal & Noise Tools -- Connections › Zenodo (classic leaf, 15.11.0).
 *
 * The environment switch, the ledger (every signed document with its DOI
 * state), one action. The tokens live in the keyring (Connections ›
 * Credentials), never here. See docs/zenodo-doi-design.md.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/zenodo-doi-line.php';

/**
 * The deposit button's confirm, shared by both surfaces (2026-10-12 audit:
 * the button minted DOIs with no question asked). Production DOIs are
 * permanent; the sandbox's are tests.
 *
 * @param string $env 'production' or 'sandbox'.
 * @return string
 */
function sn_zenodo_deposit_confirm( $env ) {
	return 'production' === $env
		/* translators: %d: documents per pass */
		? sprintf( __( 'Deposit up to %d documents to Zenodo now? On production each one gets a permanent public DOI.', 'signal-and-noise-tools' ), (int) SN_ZENODO_PASS_MAX )
		/* translators: %d: documents per pass */
		: sprintf( __( 'Deposit up to %d documents to the Zenodo sandbox now? Sandbox DOIs are tests and never public.', 'signal-and-noise-tools' ), (int) SN_ZENODO_PASS_MAX );
}

/**
 * The ledger rows and the counts the leaf paints, shared with the kit leaf.
 *
 * @since 15.11.0
 * @return array{env:string,enabled:bool,rows:array,counts:array<string,int>,batch:array}
 */
function sn_zenodo_leaf_data() {
	$rows   = function_exists( 'sn_zenodo_ledger' ) ? sn_zenodo_ledger() : array();
	$counts = array();
	foreach ( $rows as $r ) {
		$counts[ $r['state'] ] = ( $counts[ $r['state'] ] ?? 0 ) + 1;
	}
	return array(
		'env'     => function_exists( 'sn_zenodo_env' ) ? sn_zenodo_env() : 'sandbox',
		'ledger'  => function_exists( 'sn_zenodo_ledger_doi' ) ? sn_zenodo_ledger_doi() : '',
		'enabled' => function_exists( 'sn_zenodo_is_enabled' ) && sn_zenodo_is_enabled(),
		'verdict' => function_exists( 'sn_zenodo_token_verdict' ) ? sn_zenodo_token_verdict() : null,
		'rows'    => $rows,
		'counts'  => $counts,
		// (array) false is array( false ), never empty: with no pass on record
		// the row read "0 published, 0 failed, of 0" (2026-10-12 audit).
		'batch'   => is_array( $batch = get_transient( 'sn_zenodo_last_batch' ) ) ? $batch : array(),
	);
}

/**
 * The ledger's rows in reading order: every row still waiting first, then the
 * minted ones, each group in the ledger's own order. PURE.
 *
 * @param array<int,array<string,mixed>> $rows sn_zenodo_ledger() rows.
 * @return array<int,array<string,mixed>>
 */
function sn_zenodo_ledger_sorted( array $rows ) {
	$waiting = array();
	$minted  = array();
	foreach ( $rows as $r ) {
		if ( 'minted' === (string) ( $r['state'] ?? '' ) ) {
			$minted[] = $r;
		} else {
			$waiting[] = $r;
		}
	}
	return array_merge( $waiting, $minted );
}

/**
 * The ledger's one sentence, built from its rows, the same on both surfaces
 * (2026-10-12 audit: 58 rows of "Minted" and no sentence). PURE.
 *
 * @param array<int,array<string,mixed>> $rows sn_zenodo_ledger() rows.
 * @return string '' when the ledger is empty.
 */
function sn_zenodo_ledger_finding( array $rows ) {
	$total = count( $rows );
	if ( 0 === $total ) {
		return '';
	}
	$states = array();
	foreach ( $rows as $r ) {
		$states[ (string) ( $r['state'] ?? '' ) ] = ( $states[ (string) ( $r['state'] ?? '' ) ] ?? 0 ) + 1;
	}
	$minted  = (int) ( $states['minted'] ?? 0 );
	unset( $states['minted'] );
	/* translators: 1: minted documents, 2: all documents. */
	$out = sprintf( __( '%1$d of %2$d minted', 'signal-and-noise-tools' ), $minted, $total );
	if ( array() === $states ) {
		return $out . __( '; none waiting.', 'signal-and-noise-tools' );
	}
	arsort( $states );
	$parts = array();
	foreach ( $states as $state => $n ) {
		$parts[] = $n . ' ' . sn_zenodo_state_label( $state );
	}
	/* translators: 1: documents not minted, 2: their states, e.g. "3 Ready, 1 Anchor pending". */
	return $out . sprintf( __( '; %1$d not yet: %2$s.', 'signal-and-noise-tools' ), $total - $minted, implode( ', ', $parts ) );
}

/**
 * When a document was deposited, in the site's own date and time format and
 * timezone (2026-10-12 audit: the raw ISO stamp, "2026-10-11T18:33:13+00:00").
 *
 * @param string $at The stored ISO 8601 time, '' when never deposited.
 * @return string
 */
function sn_zenodo_deposited_text( $at ) {
	$ts = '' !== (string) $at ? strtotime( (string) $at ) : false;
	if ( false === $ts ) {
		return __( 'not deposited', 'signal-and-noise-tools' );
	}
	return wp_date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $ts );
}

/**
 * The States box's "Last pass" line: how long ago, and what it did. Every
 * pass records itself since 2026-10-12, the hourly one too.
 *
 * @param array<string,mixed> $batch The stored pass ({attempted, published, failed, at}).
 * @return string
 */
function sn_zenodo_last_pass_text( array $batch ) {
	$attempted = (int) ( $batch['attempted'] ?? 0 );
	$what      = 0 === $attempted
		? __( 'nothing waiting', 'signal-and-noise-tools' )
		/* translators: 1: published, 2: failed, 3: attempted */
		: sprintf( __( '%1$d published, %2$d failed, of %3$d attempted', 'signal-and-noise-tools' ), (int) ( $batch['published'] ?? 0 ), (int) ( $batch['failed'] ?? 0 ), $attempted );
	if ( empty( $batch['at'] ) ) {
		return $what;
	}
	/* translators: 1: how long ago, e.g. "20 mins", 2: what the pass did. */
	return sprintf( __( '%1$s ago: %2$s', 'signal-and-noise-tools' ), human_time_diff( (int) $batch['at'], time() ), $what );
}

/**
 * A state's human label. PURE.
 *
 * @since 15.11.0
 */
function sn_zenodo_state_label( $state ) {
	$map = array(
		'minted'         => 'Minted',
		'sandbox'        => 'Sandbox only',
		'ready'          => 'Ready',
		'anchor-pending' => 'Anchor pending',
		'no-commit'      => 'No commit',
		'not-published'  => 'Not published',
		'not-a-subject'  => 'Not a subject',
	);
	return $map[ (string) $state ] ?? (string) $state;
}

/**
 * Render the classic leaf.
 *
 * @since 15.11.0
 */
/**
 * The tile's word for the active environment, from the token AND the
 * verdict. PURE. A stored token with a refused verdict is "refused", not
 * "on": the 16.1.x tile read On for a sandbox row the keyring had refused.
 *
 * @since 16.2.2
 * @return array{tone:string,badge:string,body:string}
 */
function sn_zenodo_tile_state( $has_token, $verdict, $env, $minted, $total ) {
	if ( ! $has_token ) {
		return array( 'tone' => 'warn', 'badge' => 'Off', 'body' => sprintf( 'Add the %s token under Credentials.', $env ) );
	}
	$status = is_array( $verdict ) ? (string) ( $verdict['status'] ?? '' ) : '';
	if ( 'refused' === $status || 'error' === $status ) {
		return array( 'tone' => 'error', 'badge' => 'refused' === $status ? 'Refused' : 'Error', 'body' => (string) ( $verdict['detail'] ?? '' ) );
	}
	if ( 'ok' !== $status ) {
		return array( 'tone' => 'warn', 'badge' => 'Unverified', 'body' => sprintf( 'The %s token is stored but Verify all has not run for it; the first deposit is the test.', $env ) );
	}
	return array( 'tone' => 'ok', 'badge' => 'On', 'body' => sprintf( '%1$d minted of %2$d documents.', (int) $minted, (int) $total ) );
}

function sn_admin_render_zenodo_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'sn_zenodo_ledger' ) ) {
		return;
	}
	$d = sn_zenodo_leaf_data();
	sn_admin_shell_open();

	echo '<p class="sn-prose">Every signed document (the notes, the pillar essays) gets a DOI on <strong>Zenodo</strong> once its anchor is confirmed, with the Markdown, the signed record and the Bitcoin proof filed with it. The papers stay with SSRN. Sandbox DOIs are test DOIs and never reach a public surface.</p>';

	echo '<div class="sn-2up">';
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-fieldset"><input type="hidden" name="tab" value="connections"><input type="hidden" name="sub" value="zenodo">';
	wp_nonce_field( 'sn_zenodo_env_save' );
	echo '<input type="hidden" name="action" value="sn_zenodo_env_save">';
	echo '<h2 class="sn-fieldset-h">Environment</h2>';
	echo '<div class="sn-field"><label for="sn_zenodo_env">Environment</label><select id="sn_zenodo_env" name="zenodo_env"><option value="sandbox"' . selected( $d['env'], 'sandbox', false ) . '>Sandbox (sandbox.zenodo.org): test DOIs, never public</option><option value="production"' . selected( $d['env'], 'production', false ) . '>Production (zenodo.org)</option></select></div>';
	echo '<p class="sn-helper">Each environment reads its own token from Connections &rsaquo; Credentials.</p>';
	echo '<div class="sn-field"><label for="sn_zenodo_ledger_doi">Ledger concept DOI</label><input type="text" id="sn_zenodo_ledger_doi" name="zenodo_ledger_doi" value="' . esc_attr( $d['ledger'] ) . '" placeholder="10.5281/zenodo.NNNNNNN" autocomplete="off"></div>';
	echo '<p class="sn-helper">The DOI Zenodo minted for the ledger repository\'s monthly snapshot (the concept DOI, which resolves to the latest snapshot). Every deposit names it as the record it is part of. Empty until the first snapshot release.</p>';
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">Save environment</button></div></form>';

	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-card sn-card--narrow"><input type="hidden" name="tab" value="connections"><input type="hidden" name="sub" value="zenodo">';
	wp_nonce_field( 'sn_zenodo_deposit_batch' );
	echo '<strong>Deposit</strong><p class="sn-helper">Runs one pass now: up to ' . (int) SN_ZENODO_PASS_MAX . ' confirmed documents without a DOI. The hourly pass does the same on its own.</p>';
	echo '<button type="submit" name="action" value="sn_zenodo_deposit_batch" class="button" data-snt-confirm="' . esc_attr( sn_zenodo_deposit_confirm( $d['env'] ) ) . '" data-snt-confirm-title="' . esc_attr__( 'Deposit the next batch', 'signal-and-noise-tools' ) . '" data-snt-confirm-label="' . esc_attr__( 'Deposit', 'signal-and-noise-tools' ) . '"' . ( $d['enabled'] ? '' : ' disabled' ) . '>Deposit the next batch</button></form>';
	echo '</div>';

	$line = snt_prov_doi_settings();
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-fieldset"><input type="hidden" name="tab" value="connections"><input type="hidden" name="sub" value="zenodo">';
	wp_nonce_field( 'sn_zenodo_doi_line_save' );
	echo '<input type="hidden" name="action" value="sn_zenodo_doi_line_save">';
	echo '<h2 class="sn-fieldset-h">' . esc_html( sn_zenodo_doi_line_copy()['title'] ) . '</h2>';
	echo '<p class="sn-helper">' . esc_html( sn_zenodo_doi_line_copy()['intro'] ) . '</p>';
	echo '<div class="sn-field"><label><input type="checkbox" id="sn_zenodo_doi_line_enabled" name="zenodo_doi_line_enabled" value="1"' . checked( $line['enabled'], true, false ) . '> ' . esc_html( sn_zenodo_doi_line_copy()['enabled'] ) . '</label></div>';
	echo '<div class="sn-field"><label for="sn_zenodo_doi_line_label">' . esc_html( sn_zenodo_doi_line_copy()['label'] ) . '</label><input type="text" id="sn_zenodo_doi_line_label" name="zenodo_doi_line_label" value="' . esc_attr( $line['label'] ) . '" maxlength="80" autocomplete="off"></div>';
	echo '<p class="sn-helper">' . esc_html( sn_zenodo_doi_line_copy()['label_hint'] ) . '</p>';
	echo '<div class="sn-field"><label for="sn_zenodo_doi_line_floor">' . esc_html( sn_zenodo_doi_line_copy()['floor'] ) . '</label><input type="number" id="sn_zenodo_doi_line_floor" name="zenodo_doi_line_floor" value="' . (int) $line['floor'] . '" min="0" step="1"></div>';
	echo '<p class="sn-helper">' . esc_html( sn_zenodo_doi_line_copy()['floor_hint'] ) . '</p>';
	echo '<p class="sn-helper">' . esc_html( sn_zenodo_doi_line_preview() ) . '</p>';
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">' . esc_html( sn_zenodo_doi_line_copy()['submit'] ) . '</button></div></form>';

	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">Ledger</h2>';
	if ( array() === $d['rows'] ) {
		echo '<p class="sn-helper">No signed documents yet.</p>';
	} else {
		echo '<p class="sn-helper">' . esc_html( sn_zenodo_ledger_finding( $d['rows'] ) ) . '</p>';
		echo '<table class="widefat striped sn-status-table sn-status-table--full"><thead><tr><th>Document</th><th>Kind</th><th>State</th><th>DOI</th><th>Deposited</th></tr></thead><tbody>';
		foreach ( sn_zenodo_ledger_sorted( $d['rows'] ) as $r ) {
			$doi = '' !== $r['doi'] ? ( 'minted' === $r['state'] ? '<a href="https://doi.org/' . esc_attr( $r['doi'] ) . '" target="_blank" rel="noopener"><code>' . esc_html( $r['doi'] ) . '</code></a>' : '<code>' . esc_html( $r['doi'] ) . '</code>' ) : esc_html__( 'none yet', 'signal-and-noise-tools' );
			echo '<tr><td><a href="' . esc_url( admin_url( 'post.php?post=' . (int) $r['id'] . '&action=edit' ) ) . '">' . esc_html( $r['title'] ) . '</a></td><td>' . esc_html( $r['kind'] ) . '</td><td>' . esc_html( sn_zenodo_state_label( $r['state'] ) ) . ( '' !== $r['error'] ? '<br><code>' . esc_html( $r['error'] ) . '</code>' : '' ) . '</td><td>' . wp_kses_post( $doi ) . '</td><td>' . esc_html( sn_zenodo_deposited_text( $r['at'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';

	sn_admin_shell_rail( 'Zenodo status' );
	$tile = sn_zenodo_tile_state( $d['enabled'], $d['verdict'], $d['env'], (int) ( $d['counts']['minted'] ?? 0 ), count( $d['rows'] ) );
	$mod  = 'ok' === $tile['tone'] ? '' : ' sn-status-box--' . ( 'error' === $tile['tone'] ? 'err' : 'warn' );
	$pill = 'ok' === $tile['tone'] ? 'ok' : ( 'error' === $tile['tone'] ? 'err' : 'warn' );
	echo '<div class="sn-status-box' . esc_attr( $mod ) . '"><div><p class="sn-status-box-title">' . esc_html( $d['enabled'] ? ucfirst( $d['env'] ) : 'No token' ) . '</p><p class="sn-status-box-body">' . esc_html( $tile['body'] ) . '</p></div><span class="sn-pill sn-pill--' . esc_attr( $pill ) . '">' . esc_html( $tile['badge'] ) . '</span></div>';
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">States</h2><table class="form-table sn-status-table sn-status-table--full"><tbody>';
	foreach ( $d['counts'] as $state => $n ) {
		echo '<tr><th>' . esc_html( sn_zenodo_state_label( $state ) ) . '</th><td>' . (int) $n . '</td></tr>';
	}
	if ( ! empty( $d['batch'] ) ) {
		echo '<tr><th>Last pass</th><td>' . esc_html( sn_zenodo_last_pass_text( $d['batch'] ) ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	sn_admin_shell_close();
}
