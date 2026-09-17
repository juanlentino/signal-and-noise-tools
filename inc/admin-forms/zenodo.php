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
		'enabled' => function_exists( 'sn_zenodo_is_enabled' ) && sn_zenodo_is_enabled(),
		'rows'    => $rows,
		'counts'  => $counts,
		'batch'   => (array) get_transient( 'sn_zenodo_last_batch' ),
	);
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
function sn_admin_render_zenodo_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'sn_zenodo_ledger' ) ) {
		return;
	}
	$d = sn_zenodo_leaf_data();
	sn_admin_shell_open();

	echo '<p class="sn-prose">Every signed document (the notes, the pillar essays) gets a DOI on <strong>Zenodo</strong> once its anchor is confirmed, with the Markdown, the signed record and the Bitcoin proof filed beside it. The papers stay with SSRN. Sandbox DOIs are test DOIs and never reach a public surface.</p>';

	echo '<div class="sn-2up">';
	echo '<form method="post" class="sn-fieldset"><input type="hidden" name="tab" value="connections"><input type="hidden" name="sub" value="zenodo">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="zenodo_env_save">';
	echo '<h2 class="sn-fieldset-h">Environment</h2>';
	echo '<div class="sn-field"><label for="sn_zenodo_env">Environment</label><select id="sn_zenodo_env" name="zenodo_env"><option value="sandbox"' . selected( $d['env'], 'sandbox', false ) . '>Sandbox (sandbox.zenodo.org): test DOIs, never public</option><option value="production"' . selected( $d['env'], 'production', false ) . '>Production (zenodo.org)</option></select></div>';
	echo '<p class="sn-helper">Each environment reads its own token from Connections &rsaquo; Credentials.</p>';
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">Save environment</button></div></form>';

	echo '<form method="post" class="sn-card sn-card--narrow"><input type="hidden" name="tab" value="connections"><input type="hidden" name="sub" value="zenodo">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<strong>Deposit</strong><p class="sn-helper">Runs one pass now: up to ' . (int) SN_ZENODO_PASS_MAX . ' confirmed documents without a DOI. The hourly pass does the same on its own.</p>';
	echo '<button type="submit" name="sn_action" value="zenodo_deposit_batch" class="button"' . ( $d['enabled'] ? '' : ' disabled' ) . '>Deposit the next batch</button></form>';
	echo '</div>';

	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">Ledger</h2>';
	if ( array() === $d['rows'] ) {
		echo '<p class="sn-helper">No signed documents yet.</p>';
	} else {
		echo '<table class="widefat striped sn-status-table sn-status-table--full"><thead><tr><th>Document</th><th>Kind</th><th>State</th><th>DOI</th><th>Deposited</th></tr></thead><tbody>';
		foreach ( $d['rows'] as $r ) {
			$doi = '' !== $r['doi'] ? ( 'minted' === $r['state'] ? '<a href="https://doi.org/' . esc_attr( $r['doi'] ) . '" target="_blank" rel="noopener"><code>' . esc_html( $r['doi'] ) . '</code></a>' : '<code>' . esc_html( $r['doi'] ) . '</code>' ) : '&mdash;';
			echo '<tr><td><a href="' . esc_url( admin_url( 'post.php?post=' . (int) $r['id'] . '&action=edit' ) ) . '">' . esc_html( $r['title'] ) . '</a></td><td>' . esc_html( $r['kind'] ) . '</td><td>' . esc_html( sn_zenodo_state_label( $r['state'] ) ) . ( '' !== $r['error'] ? '<br><code>' . esc_html( $r['error'] ) . '</code>' : '' ) . '</td><td>' . $doi . '</td><td>' . ( '' !== $r['at'] ? esc_html( $r['at'] ) : '&mdash;' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';

	sn_admin_shell_rail( 'Zenodo status' );
	if ( ! $d['enabled'] ) {
		echo '<div class="sn-status-box sn-status-box--warn"><div><p class="sn-status-box-title">No token</p><p class="sn-status-box-body">Add the ' . esc_html( $d['env'] ) . ' token under Connections &rsaquo; Credentials.</p></div><span class="sn-pill sn-pill--warn">Off</span></div>';
	} else {
		echo '<div class="sn-status-box"><div><p class="sn-status-box-title">' . esc_html( ucfirst( $d['env'] ) ) . '</p><p class="sn-status-box-body">' . (int) ( $d['counts']['minted'] ?? 0 ) . ' minted of ' . count( $d['rows'] ) . ' documents.</p></div><span class="sn-pill sn-pill--ok">On</span></div>';
	}
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">States</h2><table class="form-table sn-status-table sn-status-table--full"><tbody>';
	foreach ( $d['counts'] as $state => $n ) {
		echo '<tr><th>' . esc_html( sn_zenodo_state_label( $state ) ) . '</th><td>' . (int) $n . '</td></tr>';
	}
	if ( ! empty( $d['batch'] ) ) {
		echo '<tr><th>Last pass</th><td>' . (int) ( $d['batch']['published'] ?? 0 ) . ' published, ' . (int) ( $d['batch']['failed'] ?? 0 ) . ' failed, of ' . (int) ( $d['batch']['attempted'] ?? 0 ) . ' attempted</td></tr>';
	}
	echo '</tbody></table></div>';
	sn_admin_shell_close();
}
