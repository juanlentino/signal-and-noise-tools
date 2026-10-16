<?php
/**
 * Signal & Noise Tools: provenance-admin.php, the key and config rows, the
 * genesis and commits fieldsets, and the assets.
 *
 * Split from inc/provenance-admin.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One config-presence row (a striped table row): the setting label with a ✓/✗
 * presence pill. Presence ONLY — the value (e.g. the HMAC secret) is NEVER
 * rendered.
 *
 * @param string $label   Human setting label.
 * @param bool   $present Whether the constant/option is configured.
 */
/**
 * One signing-key row: label, the resolved VALUE, and where it came from.
 *
 * Unlike sn_prov_admin_config_row() this prints the value, which is correct
 * here and only here: the key id and its introduction date are already
 * published in did.json and the keys mirror. Nothing secret is ever passed in.
 *
 * 'blank-constant' gets its own warning phrasing rather than being shown as
 * 'default'. The served value is the same, but the fix is not — it means a
 * wp-config.php constant IS set, is empty, and is silently shadowing an option
 * that may well be correct.
 *
 * @param string $label  Human label.
 * @param string $value  Resolved value (public).
 * @param string $source constant|blank-constant|option|default
 */
function sn_prov_admin_key_row( $label, $value, $source ) {
	$sources = array(
		'constant'       => array( __( 'wp-config.php constant', 'signal-and-noise-tools' ), 'sn-pill--ok' ),
		'option'         => array( __( 'site option', 'signal-and-noise-tools' ), 'sn-pill--ok' ),
		'default'        => array( __( 'shipped default', 'signal-and-noise-tools' ), 'sn-pill--muted' ),
		'blank-constant' => array( __( 'shipped default: a BLANK wp-config constant is shadowing the option', 'signal-and-noise-tools' ), 'sn-pill--warn' ),
	);
	$known = $sources[ $source ] ?? array( $source, 'sn-pill--muted' );
	echo '<tr><td>' . esc_html( $label ) . '</td>'
		. '<td><code>' . esc_html( $value ) . '</code> '
		. '<span class="sn-pill ' . esc_attr( $known[1] ) . '">' . esc_html( $known[0] ) . '</span></td></tr>';
}

function sn_prov_admin_config_row( $label, $present ) {
	$class = $present ? 'sn-pill sn-pill--ok' : 'sn-pill sn-pill--warn';
	$mark  = $present ? '✓' : '✗';
	echo '<tr><td>' . esc_html( $label ) . '</td>'
		. '<td><span class="' . esc_attr( $class ) . '">' . esc_html( $mark ) . '</span></td></tr>';
}

/**
 * Genesis anchor fieldset: the flash notice for the last re-anchor action, the
 * anchor-status pill, the truncated root, and the re-anchor button (POSTs to
 * admin-post.php, nonce-protected).
 *
 * @param array $sys sn_prov_admin_system_status() view-model.
 */
function sn_prov_admin_render_genesis_fieldset( array $sys ) {
	$genesis = is_array( $sys['genesis'] ) ? $sys['genesis'] : array();
	$status  = (string) ( $genesis['status'] ?? '' );
	$root    = (string) ( $genesis['root'] ?? '' );

	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Genesis anchor', 'signal-and-noise-tools' ) . '</h2>';

	// The re-anchor form lives in this fieldset, so its result notice does too.
	sn_prov_admin_render_reanchor_notice( $sys );

	// Color class per genesis-anchor status; the label text comes from the shared
	// label map so its casing matches the commits table.
	$colors = array(
		'confirmed' => 'sn-pill sn-pill--ok',
		'pending'   => 'sn-pill sn-pill--warn',
		'unsent'    => 'sn-pill sn-pill--err',
	);
	if ( isset( $colors[ $status ] ) ) {
		echo '<p><span class="' . esc_attr( $colors[ $status ] ) . '">' . esc_html( sn_prov_admin_status_label( $status ) ) . '</span></p>';
	} else {
		echo '<p><span class="sn-pill">' . esc_html__( 'Not anchored', 'signal-and-noise-tools' ) . '</span></p>';
	}

	if ( '' !== $root ) {
		echo '<p class="sn-prov-root"><code>' . esc_html( sn_prov_admin_truncate( $root ) ) . '</code></p>';
	}

	// Re-anchoring is only meaningful when the root isn't already anchored. Once
	// it's 'pending' (in flight to Bitcoin) or 'confirmed', re-stamping would reset
	// the OTS clock / revert the anchor — so the button is disabled (and the
	// handler no-ops as a belt: sn_prov_genesis_reanchor()).
	$anchored = ( 'pending' === $status || 'confirmed' === $status );
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'sn_prov_reanchor' );
	echo '<input type="hidden" name="action" value="sn_prov_reanchor" />';
	echo '<button type="submit" class="button"' . ( $anchored ? ' disabled' : '' ) . '>' . esc_html__( 'Re-anchor genesis', 'signal-and-noise-tools' ) . '</button>';
	if ( $anchored ) {
		echo ' <span class="sn-fieldset-intro">' . esc_html__( 'Already anchored: nothing to re-anchor.', 'signal-and-noise-tools' ) . '</span>';
	}
	echo '</form>';
	echo '</div>';
}

/**
 * Commits fieldset: a widefat table whose <tbody class="sn-prov-live"> is the
 * aria-live region assets/provenance-admin.js hydrates into commit rows. The
 * tbody carries the data-endpoint/data-nonce/data-ledger the poller reads.
 *
 * @param string $note_base Per-Note ledger URL base (empty when unconfigured).
 * @param array  $sys       sn_prov_admin_system_status() view-model (the "+N" line).
 */
function sn_prov_admin_render_commits_fieldset( $note_base, array $sys = array() ) {
	echo '<div class="sn-fieldset sn-fieldset--wide"><h2 class="sn-fieldset-h">' . esc_html__( 'Commits', 'signal-and-noise-tools' ) . '</h2>';

	// On-demand sweep: the last result flash + the trigger. Lets the owner flip
	// Bitcoin-confirmed proofs now instead of waiting for the Worker's hourly cron.
	sn_prov_admin_render_sweep_notice();
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sn-prov-sweep">';
	wp_nonce_field( 'sn_prov_runsweep' );
	echo '<input type="hidden" name="action" value="sn_prov_runsweep" />';
	echo '<button type="submit" class="button">' . esc_html__( 'Check for confirmations', 'signal-and-noise-tools' ) . '</button> ';
	echo '<span class="sn-fieldset-intro">' . esc_html__( 'Ask the Worker to check pending proofs against Bitcoin now, rather than waiting for the hourly sweep.', 'signal-and-noise-tools' ) . '</span>';
	echo '</form>';

	echo '<table class="widefat striped sn-prov-table"><thead><tr>'
		. '<th class="manage-column column-primary">' . esc_html__( 'UID', 'signal-and-noise-tools' ) . '</th>'
		. '<th>' . esc_html__( 'Version', 'signal-and-noise-tools' ) . '</th>'
		. '<th>' . esc_html__( 'Status', 'signal-and-noise-tools' ) . '</th>'
		. '<th>' . esc_html__( 'Ledger', 'signal-and-noise-tools' ) . '</th>'
		. '</tr></thead>';
	// The cut (proofs waiting past the newest 100 subjects), counted once per
	// load. The poller reads it, and every sentence it paints, from the tbody:
	// translated here, never English hard-coded in the script (2026-10-12).
	// The poller says the cut in one place, inside the table: a row under the
	// listed proofs, or the empty sentence. A line printed here at load went
	// unsaid until a reload once a proof minted under an empty table.
	$listed = array() !== $sys ? count( sn_prov_admin_status()['pending'] ) : 0;
	$hidden = array() !== $sys ? sn_prov_admin_unlisted( $sys, $listed ) : 0;
	echo '<tbody class="sn-prov-live" aria-live="polite"'
		. ' data-endpoint="' . esc_attr( esc_url_raw( rest_url( 'sn-prov/v1/status' ) ) ) . '"'
		. ' data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"'
		. ' data-ledger="' . esc_attr( esc_url_raw( (string) $note_base ) ) . '"'
		. ' data-unlisted="' . esc_attr( (string) $hidden ) . '"'
		. ' data-text-empty="' . esc_attr( sn_prov_admin_empty_line( 0 ) ) . '"'
		. ' data-text-empty-cut="' . esc_attr( sn_prov_admin_empty_line( $hidden ) ) . '"'
		. ' data-text-cut="' . esc_attr( sn_prov_admin_unlisted_line( $hidden ) ) . '"'
		/* translators: %s: commit version number. */
		. ' data-text-ledger="' . esc_attr( __( 'v%s on the ledger', 'signal-and-noise-tools' ) ) . '"'
		. ' data-text-no-ledger="' . esc_attr( __( 'No ledger record', 'signal-and-noise-tools' ) ) . '"'
		. ' data-text-error="' . esc_attr( __( 'Status check failed: reload the page to check again.', 'signal-and-noise-tools' ) ) . '">';
	echo '<tr><td colspan="4">' . esc_html__( 'Loading anchor status…', 'signal-and-noise-tools' ) . '</td></tr>';
	echo '</tbody></table>';
	echo '</div>';
}

/**
 * Truncate a long hex string for display: head…tail.
 *
 * @param string $hex
 * @return string
 */
function sn_prov_admin_truncate( $hex ) {
	$hex = (string) $hex;
	if ( strlen( $hex ) <= 24 ) {
		return $hex;
	}
	return substr( $hex, 0, 16 ) . '…' . substr( $hex, -8 );
}

/**
 * Enqueue the live-stepper CSS/JS ONLY on the plugin's admin screens
 * (external files — never inline; screen-gated per house rule).
 *
 * @param string $hook_suffix
 */
function sn_prov_admin_enqueue( $hook_suffix ) {
	if ( ! function_exists( 'sn_admin_page_hooks' ) || ! in_array( $hook_suffix, sn_admin_page_hooks(), true ) ) {
		return;
	}
	sn_prov_admin_register_assets();
	wp_enqueue_style( 'sn-provenance-admin' );
	wp_enqueue_script( 'sn-provenance-admin' );
}

/**
 * Register the live-stepper CSS/JS, once. Shared by the classic page and the
 * S&N Dashboard host window (inc/openstation-host.php), which sits outside
 * the `admin_enqueue_scripts` gate above.
 *
 * @return void
 */
function sn_prov_admin_register_assets() {
	$base = plugins_url( 'assets/', SNT_PATH . 'signal-and-noise-tools.php' );
	if ( ! wp_style_is( 'sn-provenance-admin', 'registered' ) ) {
		wp_register_style( 'sn-provenance-admin', $base . 'provenance-admin.css', array(), SNT_VERSION );
	}
	if ( ! wp_script_is( 'sn-provenance-admin', 'registered' ) ) {
		wp_register_script( 'sn-provenance-admin', $base . 'provenance-admin.js', array(), SNT_VERSION, true );
	}
}
add_action( 'admin_enqueue_scripts', 'sn_prov_admin_enqueue' );
