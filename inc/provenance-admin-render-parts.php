<?php
/**
 * Signal & Noise Tools: provenance-admin.php, the section renderer, the
 * status label, the two notices and the system fieldset.
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
 * Tools → Provenance section body, rendered in the house settings-page idiom: a
 * first-glance stat-card hero over the shared two-column shell (sn_admin_shell_*).
 * The wide live Commits table fills the main column; the compact System and
 * Genesis readouts stack in the narrower rail — the shell's documented RULE (wide
 * data tables in the main column, status readouts in the rail; see
 * inc/admin-shell.php). The Commits <tbody> is the live region
 * assets/provenance-admin.js hydrates by polling /status; it carries the
 * data-endpoint/data-nonce/data-ledger the poller reads.
 *
 * The dispatcher wraps this callback in <div class="sn-section"> (the leaf is
 * registered 'wide'), so this renderer adds NO outer wrapper of its own.
 */
function sn_admin_render_provenance_section() {
	$sys        = sn_prov_admin_system_status();
	$ledger_url = (string) $sys['ledger_url'];
	$note_base  = '' !== $ledger_url ? $ledger_url . '/tree/main/notes/' : '';

	// 1. First-glance hero: Worker / Genesis / Pending / Confirmed.
	if ( function_exists( 'sn_admin_glance_grid' ) ) {
		echo '<section aria-label="Provenance at a glance">';
		sn_admin_glance_grid( sn_prov_admin_glance_cards( $sys ) );
		echo '</section>';
	}

	// 2. Two-column shell: the wide live Commits table in the main column, the
	// compact System + Genesis readouts stacked in the rail. Between open() and
	// close() no early return may occur (would unbalance the wrapper divs).
	sn_admin_shell_open();
	sn_prov_admin_render_commits_fieldset( $note_base, $sys );
	// v10.3.0: the one-shot ledger backfill (renders only while candidates
	// exist — after a clean import the section disappears).
	if ( function_exists( 'sn_prov_backfill_render_fieldset' ) ) {
		sn_prov_backfill_render_fieldset();
	}
	// 21.1.0: the Internet Archive push and the run over older notes.
	if ( function_exists( 'sn_archive_existing_render_fieldset' ) ) {
		sn_archive_existing_render_fieldset();
	}
	sn_admin_shell_rail( __( 'Provenance status', 'signal-and-noise-tools' ) );
	sn_prov_admin_render_system_fieldset( $sys );
	if ( function_exists( 'sn_prov_admin_render_rotation_fieldset' ) ) {
		sn_prov_admin_render_rotation_fieldset();
	}
	sn_prov_admin_render_genesis_fieldset( $sys );
	sn_admin_shell_close();
}

/**
 * Canonical human label for a commit/anchor status. Shared by the server-
 * rendered Genesis pill and mirrored in the JS commits table so casing matches.
 *
 * @param string $status
 * @return string
 */
function sn_prov_admin_status_label( $status ) {
	$labels = array(
		'pending'    => __( 'Pending', 'signal-and-noise-tools' ),
		'confirmed'  => __( 'Confirmed', 'signal-and-noise-tools' ),
		'unanchored' => __( 'Unanchored', 'signal-and-noise-tools' ),
		'genesis'    => __( 'Genesis', 'signal-and-noise-tools' ),
		'unsent'     => __( 'Unsent', 'signal-and-noise-tools' ),
	);
	$status = (string) $status;
	return $labels[ $status ] ?? ucfirst( $status );
}

/**
 * The ?sn_prov_reanchor=ok|fail flash notice as a status box, rendered inside the
 * Genesis fieldset (beside the re-anchor form). Read-only display of a
 * whitelisted flag — no state change, so no nonce is required here.
 *
 * The failure copy is config-aware (honest reporting): when all three SN_PROV_*
 * constants are present, the dispatch reached a deployed Worker that rejected it,
 * so it blames the Worker; otherwise the constants are simply unset.
 *
 * @param array $sys sn_prov_admin_system_status() view-model (for config booleans).
 */
function sn_prov_admin_render_reanchor_notice( array $sys ) {
	if ( ! isset( $_GET['sn_prov_reanchor'] ) ) {
		return;
	}
	$result = sanitize_text_field( wp_unslash( $_GET['sn_prov_reanchor'] ) );
	if ( 'ok' === $result ) {
		$mod   = '';
		$title = __( 'Re-anchor dispatched', 'signal-and-noise-tools' );
		$body  = __( 'The genesis root was re-submitted to the Worker for anchoring.', 'signal-and-noise-tools' );
	} elseif ( 'fail' === $result ) {
		$mod    = ' sn-status-box--err';
		$title  = __( 'Re-anchor failed', 'signal-and-noise-tools' );
		$config = isset( $sys['config'] ) && is_array( $sys['config'] ) ? $sys['config'] : array();
		if ( ! empty( $config['worker_url'] ) && ! empty( $config['hmac'] ) && ! empty( $config['pubkey'] ) ) {
			$body = __( 'The Worker rejected the dispatch. Check the Worker is deployed and reachable.', 'signal-and-noise-tools' );
		} else {
			$body = __( 'Set the SN_PROV_* constants in wp-config first.', 'signal-and-noise-tools' );
		}
	} else {
		return;
	}
	echo '<div class="sn-status-box' . esc_attr( $mod ) . '"><div>'
		. '<p class="sn-status-box-title">' . esc_html( $title ) . '</p>'
		. '<p class="sn-status-box-body">' . esc_html( $body ) . '</p>'
		. '</div></div>';
}

/**
 * The ?sn_prov_swept=ok|fail flash for the manual sweep, rendered inside the
 * Commits fieldset beside the trigger button. Reads the per-user transient the
 * handler stashed (then clears it — one-shot). Count-driven copy on success; a
 * config-aware line on failure.
 *
 * @return void
 */
function sn_prov_admin_render_sweep_notice() {
	if ( ! isset( $_GET['sn_prov_swept'] ) ) {
		return;
	}
	$flag   = sanitize_text_field( wp_unslash( $_GET['sn_prov_swept'] ) );
	$key    = 'sn_prov_sweep_result_' . get_current_user_id();
	$result = get_transient( $key );
	delete_transient( $key );
	$result = is_array( $result ) ? $result : array();

	if ( 'ok' === $flag && ! empty( $result['ok'] ) ) {
		$up    = (int) ( $result['upgraded'] ?? 0 );
		$pend  = (int) ( $result['still_pending'] ?? 0 );
		$mod   = '';
		$title = __( 'Sweep complete', 'signal-and-noise-tools' );
		$body  = $up > 0
			? sprintf(
				/* translators: 1: newly-confirmed count, 2: still-pending count */
				_n( '%1$d proof newly confirmed on Bitcoin; %2$d still pending.', '%1$d proofs newly confirmed on Bitcoin; %2$d still pending.', $up, 'signal-and-noise-tools' ),
				$up,
				$pend
			)
			: sprintf(
				/* translators: %d: still-pending count */
				_n( 'Nothing new. %d proof is still awaiting Bitcoin confirmation.', 'Nothing new. %d proofs are still awaiting Bitcoin confirmation.', $pend, 'signal-and-noise-tools' ),
				$pend
			);
	} else {
		$mod   = ' sn-status-box--err';
		$title = __( 'Sweep failed', 'signal-and-noise-tools' );
		$err   = isset( $result['error'] ) ? (string) $result['error'] : '';
		$body  = 'unconfigured' === $err
			? __( 'Set the SN_PROV_* constants in wp-config first.', 'signal-and-noise-tools' )
			: __( 'Could not reach the Worker, or it rejected the request.', 'signal-and-noise-tools' );
	}
	echo '<div class="sn-status-box' . esc_attr( $mod ) . '"><div>'
		. '<p class="sn-status-box-title">' . esc_html( $title ) . '</p>'
		. '<p class="sn-status-box-body">' . esc_html( $body ) . '</p>'
		. '</div></div>';
}

/**
 * System fieldset: the config-presence readout (Worker URL / HMAC secret /
 * Public key — presence booleans ONLY, never the secret value), the Ed25519
 * public key, and the public-ledger link.
 *
 * @param array $sys sn_prov_admin_system_status() view-model.
 */
function sn_prov_admin_render_system_fieldset( array $sys ) {
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'System', 'signal-and-noise-tools' ) . '</h2>';

	echo '<table class="widefat striped sn-prov-config"><tbody>';
	sn_prov_admin_config_row( __( 'Worker URL', 'signal-and-noise-tools' ), ! empty( $sys['config']['worker_url'] ) );
	sn_prov_admin_config_row( __( 'HMAC secret', 'signal-and-noise-tools' ), ! empty( $sys['config']['hmac'] ) );
	sn_prov_admin_config_row( __( 'Public key', 'signal-and-noise-tools' ), ! empty( $sys['config']['pubkey'] ) );
	echo '</tbody></table>';

	// Deployed Worker version, from its /_sn/version endpoint (cached). Shown only
	// when the Worker is configured; "unknown" when it can't be reached.
	if ( ! empty( $sys['config']['worker_url'] ) ) {
		$wver = function_exists( 'sn_prov_worker_version' ) ? sn_prov_worker_version() : '';
		echo '<p class="sn-prov-worker-ver">' . esc_html__( 'Worker version', 'signal-and-noise-tools' ) . ' '
			. ( '' !== $wver
				? '<code>' . esc_html( $wver ) . '</code>'
				: '<span class="sn-prov-muted">' . esc_html__( 'unknown', 'signal-and-noise-tools' ) . '</span>' )
			. '</p>';
	}

	$pubkey = (string) $sys['pubkey'];
	if ( '' !== $pubkey ) {
		echo '<p class="sn-prov-key"><code>' . esc_html( $pubkey ) . '</code></p>';
	}

	// Signing key identity. Read-only: no form, no nonce, no write path.
	$sk = isset( $sys['signing_key'] ) && is_array( $sys['signing_key'] ) ? $sys['signing_key'] : array();
	if ( '' !== (string) ( $sk['id'] ?? '' ) ) {
		echo '<table class="widefat striped sn-prov-config"><tbody>';
		sn_prov_admin_key_row(
			__( 'Signing key id', 'signal-and-noise-tools' ),
			(string) $sk['id'],
			(string) ( $sk['id_source'] ?? '' )
		);
		sn_prov_admin_key_row(
			__( 'In use since', 'signal-and-noise-tools' ),
			(string) ( $sk['introduced_at'] ?? '' ),
			(string) ( $sk['introduced_at_source'] ?? '' )
		);
		echo '</tbody></table>';
	}

	$ledger_url = (string) $sys['ledger_url'];
	if ( '' !== $ledger_url ) {
		echo '<p><a href="' . esc_url( $ledger_url ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'Public ledger', 'signal-and-noise-tools' ) . ' &rarr;</a></p>';
	}
	echo '</div>';
}

