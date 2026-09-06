<?php
/**
 * S&N Dashboard — Tools → Provenance, painted from the kit.
 *
 * The classic leaf (inc/provenance-admin.php:266,
 * `sn_admin_render_provenance_section()`) is a glance-card hero (Worker /
 * Genesis / Pending / Confirmed) over a two-column shell: the wide Commits
 * table (a live JS poller against a REST route) plus a conditional Ledger
 * backfill fieldset in the main column, and System + Key rotation + Genesis
 * anchor fieldsets in the rail. Same five `admin-post.php` forms
 * (`sn_prov_reanchor`, `sn_prov_runsweep`, `sn_prov_chain_backfill`,
 * `sn_prov_stage_key`, `sn_prov_rotate_key`), same readers
 * (`sn_prov_admin_system_status()`, `sn_prov_admin_glance_cards()`,
 * `sn_prov_admin_status()`, `sn_prov_backfill_candidates()`,
 * `sn_prov_next_key_commitment()`), the kit's parts instead of wp-admin's.
 *
 * TWO DELIBERATE DEPARTURES FROM THE SHARED KIT VOCABULARY, both because
 * these five forms are wired through `admin-post.php` and NOT the shared
 * `sn_action` table:
 *   - `provenance_post_action()` below hand-builds the `<os-form>` instead of
 *     calling `snt_kit_form()`, because that helper's hidden field is always
 *     named `sn_action` — but the host's admin-post pipeline
 *     (`inc/openstation-host-pipelines.php`, `snt_os_host_pipeline_for()`)
 *     routes on a field literally named `action`, exactly as the classic
 *     `<form>`s here do (`<input type="hidden" name="action" value="…">`).
 *   - `snt_kit_action_button()` is never used for these five, because
 *     `posted_values()` (apps/sn-dashboard/sn-dashboard.os.php) maps a
 *     button's `os-arg-action` into `values['sn_action']`, never
 *     `values['action']` — it cannot drive this pipeline. Using an `<os-form>`
 *     with no visible fields (as classic does — every one of these is a bare
 *     `<form>` around one submit button) reaches the same place faithfully.
 *
 * The Commits table is server-rendered from the SAME status list the poller
 * would have hydrated (`sn_prov_admin_status()`), since an inline script never
 * runs in a window; a ghost "Refresh" button stands in for the poll.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * A bare `<os-form>` around one submit button: the admin-post pipeline, a
 * hidden `action` field (the literal name the host's admin-post routing
 * reads) and the shared hidden nonce. Mirrors the classic leaf's own
 * `<form action="admin-post.php">` around a lone `<button>`.
 *
 * @param string              $action WP admin-post action name.
 * @param string              $label  Submit button label.
 * @param string              $inner  Extra painted content before the hidden fields ('' for none).
 * @param array<string,mixed> $opts   confirm, danger, busy.
 * @return string
 *
 * DEPARTURE: the nonce is `wp_create_nonce( $action )` — the form's OWN
 * action — not `\snt_kit_nonce()`. `snt_kit_nonce()` signs the SHARED
 * `sn_theme_options_nonce` action, but every one of these five handlers
 * calls `check_admin_referer( $action )` against its OWN action name
 * (inc/provenance-admin.php, inc/provenance-rotation.php,
 * inc/provenance-chain-backfill.php) — exactly as the classic
 * `wp_nonce_field( $action )` per form does. Mirrors monitoring-rss.php's
 * `rss_form()`, which departs from `snt_kit_nonce()` for the same reason.
 */
function provenance_post_action( $action, $label, $inner = '', array $opts = array() ) {
	$hidden = \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => 'action', 'value' => (string) $action ) )
		. \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => '_wpnonce', 'value' => function_exists( 'wp_create_nonce' ) ? (string) wp_create_nonce( (string) $action ) : '' ) );
	return \snt_kit_tag(
		'os-form',
		array(
			'class'             => 'snt-form',
			'os-action'         => 'post',
			'os-arg-pipeline'   => 'admin-post',
			'submit-label'      => (string) $label,
			'show-reset'        => 'false',
			'columns'           => '1',
			'os-confirm'        => isset( $opts['confirm'] ) ? (string) $opts['confirm'] : null,
			'os-confirm-danger' => ! empty( $opts['danger'] ),
			'busy'              => ! empty( $opts['busy'] ),
		),
		(string) $inner . $hidden
	);
}

/**
 * Everything the leaf paints, read the way the classic renderer reads it.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return array<string,mixed>
 */
function provenance_data( array $ctx ) {
	$sys        = function_exists( 'sn_prov_admin_system_status' ) ? sn_prov_admin_system_status() : array();
	$status     = function_exists( 'sn_prov_admin_status' ) ? sn_prov_admin_status() : array( 'pending' => array(), 'genesis' => array() );
	$candidates = function_exists( 'sn_prov_backfill_candidates' ) ? sn_prov_backfill_candidates() : array();
	$backfill   = get_transient( 'sn_prov_backfill_result_' . get_current_user_id() );
	// Classic deletes this the instant it renders (inc/provenance-chain-backfill.php:371)
	// so the one-shot result notice never re-prints. A window repaints in the
	// same session on every state change (unlike classic's single post-redirect
	// render), so leaving it unset would make the notice sticky for the
	// transient's whole lifetime.
	if ( is_array( $backfill ) ) {
		delete_transient( 'sn_prov_backfill_result_' . get_current_user_id() );
	}

	// The classic leaf reads its ok|fail flashes from $_GET; a window never
	// carries a real query string, so the SAME values ride back as
	// `state('params')` (inc/openstation-host.php, snt_os_host_params() —
	// every `sn_*` key from the admin-post redirect target survives).
	$state  = isset( $ctx['state'] ) ? $ctx['state'] : null;
	$params = ( is_object( $state ) && method_exists( $state, 'get' ) ) ? (array) $state->get( 'params' ) : array();

	$reanchor_flag = isset( $params['sn_prov_reanchor'] ) ? sanitize_text_field( (string) $params['sn_prov_reanchor'] ) : '';
	$swept_flag    = isset( $params['sn_prov_swept'] ) ? sanitize_text_field( (string) $params['sn_prov_swept'] ) : '';

	// $_GET['sn_prov_rotate'] is a THIRD flash the classic code sets (the
	// rotation redirect target) but never reads back anywhere in the file —
	// grep confirms no renderer or notice consumes it. A faithful port
	// reproduces readers, not dead writers; nothing is painted for it here.
	$sweep_result = null;
	if ( '' !== $swept_flag ) {
		$key          = 'sn_prov_sweep_result_' . get_current_user_id();
		$sweep_result = get_transient( $key );
		delete_transient( $key );
		$sweep_result = is_array( $sweep_result ) ? $sweep_result : array();
	}

	return array(
		'sys'             => is_array( $sys ) ? $sys : array(),
		'pending'         => (array) ( $status['pending'] ?? array() ),
		'candidates'      => (array) $candidates,
		'backfill_result' => is_array( $backfill ) ? $backfill : null,
		'commitment'      => function_exists( 'sn_prov_next_key_commitment' ) ? sn_prov_next_key_commitment() : null,
		'reanchor_flag'   => $reanchor_flag,
		'swept_flag'      => $swept_flag,
		'sweep_result'    => $sweep_result,
	);
}

/**
 * The at-a-glance hero: Worker / Genesis / Pending / Confirmed, from the
 * classic leaf's own `sn_prov_admin_glance_cards()`.
 *
 * @param array $sys sn_prov_admin_system_status() view-model.
 * @return string
 */
function provenance_glance_html( array $sys ) {
	$cards = function_exists( 'sn_prov_admin_glance_cards' ) ? sn_prov_admin_glance_cards( $sys ) : array();
	$out   = '';
	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$pill = isset( $card['pill'] ) && is_array( $card['pill'] ) ? $card['pill'] : array();
		$kind = (string) ( $pill['kind'] ?? '' );
		$out .= \snt_kit_stat(
			(string) ( $card['value'] ?? '' ),
			(string) ( $card['label'] ?? '' ),
			'' !== $kind ? (string) ( $pill['text'] ?? '' ) : '',
			$kind
		);
	}
	return '<div class="snt-stats">' . $out . '</div>';
}

/**
 * One config-presence readout: the setting label with a configured/not-set
 * tone — presence ONLY, the value (e.g. the HMAC secret) is never rendered,
 * exactly as the classic ✓/✗ pill.
 *
 * @param bool $present Whether the constant/option is configured.
 * @return string
 */
function provenance_presence_text( $present ) {
	return $present
		? __( '✓ Configured', 'signal-and-noise-tools' )
		: __( '✗ Not set', 'signal-and-noise-tools' );
}

/**
 * One signing-key readout: the resolved value plus where it came from —
 * public either way (published in did.json and the keys mirror).
 *
 * @param string $value  Resolved value.
 * @param string $source constant|blank-constant|option|default.
 * @return string
 */
function provenance_key_row_html( $value, $source ) {
	$sources = array(
		'constant'       => array( __( 'wp-config.php constant', 'signal-and-noise-tools' ), 'ok' ),
		'option'         => array( __( 'site option', 'signal-and-noise-tools' ), 'ok' ),
		'default'        => array( __( 'shipped default', 'signal-and-noise-tools' ), '' ),
		'blank-constant' => array( __( 'shipped default — a BLANK wp-config constant is shadowing the option', 'signal-and-noise-tools' ), 'warn' ),
	);
	$known = $sources[ $source ] ?? array( (string) $source, '' );
	return \snt_kit_code( (string) $value, false ) . ' ' . \snt_kit_badge( $known[1], $known[0] );
}

/**
 * System fieldset: config presence, Worker version, the public key, the
 * signing key's identity + source, and the public-ledger link.
 *
 * @param array $sys sn_prov_admin_system_status() view-model.
 * @return string
 */
function provenance_system_html( array $sys ) {
	$config = isset( $sys['config'] ) && is_array( $sys['config'] ) ? $sys['config'] : array();
	$inner  = \snt_kit_kv(
		array(
			array( 'label' => __( 'Worker URL', 'signal-and-noise-tools' ), 'value' => provenance_presence_text( ! empty( $config['worker_url'] ) ), 'tone' => \snt_kit_tone( ! empty( $config['worker_url'] ) ? 'ok' : 'warn' ) ),
			array( 'label' => __( 'HMAC secret', 'signal-and-noise-tools' ), 'value' => provenance_presence_text( ! empty( $config['hmac'] ) ), 'tone' => \snt_kit_tone( ! empty( $config['hmac'] ) ? 'ok' : 'warn' ) ),
			array( 'label' => __( 'Public key', 'signal-and-noise-tools' ), 'value' => provenance_presence_text( ! empty( $config['pubkey'] ) ), 'tone' => \snt_kit_tone( ! empty( $config['pubkey'] ) ? 'ok' : 'warn' ) ),
		)
	);

	if ( ! empty( $config['worker_url'] ) ) {
		$wver   = function_exists( 'sn_prov_worker_version' ) ? (string) sn_prov_worker_version() : '';
		$inner .= '<p class="snt-prose">' . \snt_kit_esc( __( 'Worker version', 'signal-and-noise-tools' ) ) . ' '
			. ( '' !== $wver ? \snt_kit_code( $wver, false ) : '<span class="snt-hint">' . \snt_kit_esc( __( 'unknown', 'signal-and-noise-tools' ) ) . '</span>' )
			. '</p>';
	}

	$pubkey = (string) ( $sys['pubkey'] ?? '' );
	if ( '' !== $pubkey ) {
		$inner .= \snt_kit_code( $pubkey, false );
	}

	$sk = isset( $sys['signing_key'] ) && is_array( $sys['signing_key'] ) ? $sys['signing_key'] : array();
	if ( '' !== (string) ( $sk['id'] ?? '' ) ) {
		$inner .= \snt_kit_kv(
			array(
				array( 'label' => __( 'Signing key id', 'signal-and-noise-tools' ), 'value' => provenance_key_row_html( (string) $sk['id'], (string) ( $sk['id_source'] ?? '' ) ), 'html' => true ),
				array( 'label' => __( 'In use since', 'signal-and-noise-tools' ), 'value' => provenance_key_row_html( (string) ( $sk['introduced_at'] ?? '' ), (string) ( $sk['introduced_at_source'] ?? '' ) ), 'html' => true ),
			)
		);
	}

	$ledger_url = (string) ( $sys['ledger_url'] ?? '' );
	if ( '' !== $ledger_url ) {
		$inner .= '<p>' . \snt_kit_link( __( 'Public ledger', 'signal-and-noise-tools' ) . ' →', $ledger_url ) . '</p>';
	}

	return \snt_kit_section( __( 'System', 'signal-and-noise-tools' ), $inner );
}

/**
 * Key rotation fieldset: the published commitment (or its absence), and
 * exactly one of the two ceremony buttons — never both.
 *
 * @param array|null $commitment sn_prov_next_key_commitment() result.
 * @return string
 */
function provenance_rotation_html( $commitment ) {
	$inner = \snt_kit_kv(
		array(
			array(
				'label' => __( 'Commitment to the next key', 'signal-and-noise-tools' ),
				'value' => null === $commitment
					? \snt_kit_badge( '', __( 'none published', 'signal-and-noise-tools' ) )
					: \snt_kit_code( substr( (string) ( $commitment['value'] ?? '' ), 0, 16 ) . '…', false ) . ' '
						. \snt_kit_badge( 'ok', sprintf( /* translators: %s: ISO date */ __( 'committed %s', 'signal-and-noise-tools' ), (string) ( $commitment['committed_at'] ?? '' ) ) ),
				'html'  => true,
			),
		)
	);

	if ( null === $commitment ) {
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Asks the Worker for the successor key it holds, hashes it here, and publishes that hash — so the key that later appears can be checked against the one promised.', 'signal-and-noise-tools' ) ) . '</p>'
			. provenance_post_action( 'sn_prov_stage_key', __( 'Publish a commitment to the staged key', 'signal-and-noise-tools' ) );
	} else {
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Retires the current key into the published history with a closed validity window, then promotes the committed successor. Refused unless the key the Worker returns hashes to the commitment above.', 'signal-and-noise-tools' ) ) . '</p>'
			. provenance_post_action( 'sn_prov_rotate_key', __( 'Rotate to the committed key', 'signal-and-noise-tools' ) );
	}
	return \snt_kit_section( __( 'Key rotation', 'signal-and-noise-tools' ), $inner );
}

/**
 * The re-anchor flash: dispatched|failed, config-aware on failure — mirrors
 * `sn_prov_admin_render_reanchor_notice()`.
 *
 * @param string $flag 'ok'|'fail'|other.
 * @param array  $sys  sn_prov_admin_system_status() view-model.
 * @return string
 */
function provenance_reanchor_notice_html( $flag, array $sys ) {
	if ( 'ok' === $flag ) {
		$kind  = 'ok';
		$title = __( 'Re-anchor dispatched', 'signal-and-noise-tools' );
		$body  = __( 'The genesis root was re-submitted to the Worker for anchoring.', 'signal-and-noise-tools' );
	} elseif ( 'fail' === $flag ) {
		$kind   = 'err';
		$title  = __( 'Re-anchor failed', 'signal-and-noise-tools' );
		$config = isset( $sys['config'] ) && is_array( $sys['config'] ) ? $sys['config'] : array();
		$body   = ( ! empty( $config['worker_url'] ) && ! empty( $config['hmac'] ) && ! empty( $config['pubkey'] ) )
			? __( 'The Worker rejected the dispatch. Check the Worker is deployed and reachable.', 'signal-and-noise-tools' )
			: __( 'Set the SN_PROV_* constants in wp-config first.', 'signal-and-noise-tools' );
	} else {
		return '';
	}
	return \snt_kit_notice( $kind, '<b>' . \snt_kit_esc( $title ) . '</b> ' . \snt_kit_esc( $body ) );
}

/**
 * Genesis anchor fieldset: the re-anchor flash, the status badge, the
 * truncated root, and the re-anchor form — omitted once already anchored.
 *
 * @param array  $sys           sn_prov_admin_system_status() view-model.
 * @param string $reanchor_flag 'ok'|'fail'|''.
 * @return string
 */
function provenance_genesis_html( array $sys, $reanchor_flag ) {
	$genesis = is_array( $sys['genesis'] ?? null ) ? $sys['genesis'] : array();
	$status  = (string) ( $genesis['status'] ?? '' );
	$root    = (string) ( $genesis['root'] ?? '' );

	$inner = '';
	if ( '' !== (string) $reanchor_flag ) {
		$inner .= provenance_reanchor_notice_html( (string) $reanchor_flag, $sys );
	}

	$colors = array( 'confirmed' => 'ok', 'pending' => 'warn', 'unsent' => 'err' );
	$label  = isset( $colors[ $status ] )
		? ( function_exists( 'sn_prov_admin_status_label' ) ? sn_prov_admin_status_label( $status ) : ucfirst( $status ) )
		: __( 'Not anchored', 'signal-and-noise-tools' );
	$inner .= '<p>' . \snt_kit_badge( $colors[ $status ] ?? '', $label ) . '</p>';

	if ( '' !== $root ) {
		$truncated = function_exists( 'sn_prov_admin_truncate' ) ? sn_prov_admin_truncate( $root ) : $root;
		$inner    .= \snt_kit_code( $truncated, false );
	}

	$anchored = ( 'pending' === $status || 'confirmed' === $status );
	if ( $anchored ) {
		// Classic still marks up the form and only disables its submit. A
		// window has no disabled submit on <os-form>, so the form is withheld
		// — same click blocked, and the suite pins the omission.
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Already anchored: nothing to re-anchor.', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$inner .= provenance_post_action( 'sn_prov_reanchor', __( 'Re-anchor genesis', 'signal-and-noise-tools' ) );
	}
	return \snt_kit_section( __( 'Genesis anchor', 'signal-and-noise-tools' ), $inner );
}

/**
 * The on-demand sweep's last-run flash — mirrors
 * `sn_prov_admin_render_sweep_notice()`.
 *
 * @param string     $flag   'ok'|'fail'.
 * @param array|null $result The per-user transient (already read + cleared).
 * @return string
 */
function provenance_sweep_notice_html( $flag, $result ) {
	$result = is_array( $result ) ? $result : array();
	if ( 'ok' === $flag && ! empty( $result['ok'] ) ) {
		$up    = (int) ( $result['upgraded'] ?? 0 );
		$pend  = (int) ( $result['still_pending'] ?? 0 );
		$kind  = 'ok';
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
		$kind  = 'err';
		$title = __( 'Sweep failed', 'signal-and-noise-tools' );
		$err   = isset( $result['error'] ) ? (string) $result['error'] : '';
		$body  = 'unconfigured' === $err
			? __( 'Set the SN_PROV_* constants in wp-config first.', 'signal-and-noise-tools' )
			: __( 'Could not reach the Worker, or it rejected the request.', 'signal-and-noise-tools' );
	}
	return \snt_kit_notice( $kind, '<b>' . \snt_kit_esc( $title ) . '</b> ' . \snt_kit_esc( $body ) );
}

/**
 * Commits fieldset: the sweep flash, the on-demand trigger (+ a ghost Refresh
 * standing in for the JS poller), and the same status list the poller would
 * have hydrated — server-rendered, since no script runs inside a window.
 *
 * @param array $data provenance_data() view-model.
 * @return string
 */
function provenance_commits_html( array $data ) {
	$rows = array();
	foreach ( $data['pending'] as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$status   = (string) ( $row['status'] ?? '' );
		$ledger   = (string) ( $row['ledger_url'] ?? '' );
		$rows[]   = array(
			'uid'     => (string) ( $row['note_uid'] ?? '' ),
			'version' => (string) ( $row['version'] ?? '' ),
			'status'  => function_exists( 'sn_prov_admin_status_label' ) ? sn_prov_admin_status_label( $status ) : ucfirst( $status ),
			// os-table cells are plain values (no documented HTML/link cell
			// slot), so the classic clickable ledger link becomes the URL
			// itself as text — still the same information, not clickable.
			'ledger'  => '' !== $ledger ? $ledger : '—',
		);
	}
	$table = \snt_kit_table(
		array(
			array( 'key' => 'uid', 'label' => __( 'UID', 'signal-and-noise-tools' ) ),
			array( 'key' => 'version', 'label' => __( 'Version', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'status', 'label' => __( 'Status', 'signal-and-noise-tools' ) ),
			array( 'key' => 'ledger', 'label' => __( 'Ledger', 'signal-and-noise-tools' ) ),
		),
		$rows,
		// Classic paints this copy into the empty <tbody> before the poller
		// hydrates (inc/provenance-admin.php sn_prov_admin_render_commits_fieldset).
		// A window has no poller, so the same sentence is the empty-table copy
		// the leaf suite pins; the Refresh button next to it is the stand-in.
		array( 'empty' => __( 'Loading anchor status…', 'signal-and-noise-tools' ) )
	);

	$inner = '';
	if ( '' !== (string) $data['swept_flag'] ) {
		$inner .= provenance_sweep_notice_html( (string) $data['swept_flag'], $data['sweep_result'] );
	}
	$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Ask the Worker to check pending proofs against Bitcoin now, rather than waiting for the hourly sweep.', 'signal-and-noise-tools' ) ) . '</p>'
		. '<os-cluster gap="8">'
		. provenance_post_action( 'sn_prov_runsweep', __( 'Check for confirmations', 'signal-and-noise-tools' ) )
		. \snt_kit_button( __( 'Refresh', 'signal-and-noise-tools' ), 'refresh', array( 'variant' => 'ghost' ) )
		. '</os-cluster>'
		. $table;
	return \snt_kit_section( __( 'Commits', 'signal-and-noise-tools' ), $inner );
}

/**
 * Ledger backfill fieldset — painted only while candidates exist or a result
 * just landed (after a clean import the section disappears, as classic).
 *
 * @param array $data provenance_data() view-model.
 * @return string
 */
function provenance_backfill_html( array $data ) {
	$candidates = $data['candidates'];
	$result     = $data['backfill_result'];
	if ( empty( $candidates ) && null === $result ) {
		return '';
	}

	$inner = '';
	if ( is_array( $result ) ) {
		$skips = array();
		foreach ( (array) ( $result['skipped'] ?? array() ) as $reason => $n ) {
			$skips[] = $reason . ' ×' . (int) $n;
		}
		$kind = empty( $result['skipped'] ) ? 'ok' : 'warn';
		$body = sprintf(
			/* translators: 1: number of imported commits, 2: number of repaired commits. */
			__( 'Imported %1$s confirmed anchors from the ledger, and repaired %2$s missing signatures.', 'signal-and-noise-tools' ),
			number_format_i18n( (int) ( $result['imported'] ?? 0 ) ),
			number_format_i18n( (int) ( $result['repaired'] ?? 0 ) )
		);
		if ( $skips ) {
			$body .= ' ' . __( 'Skipped:', 'signal-and-noise-tools' ) . ' ' . implode( ', ', $skips );
		}
		$remaining = (int) ( $result['remaining'] ?? 0 );
		$body     .= $remaining > 0
			? ' ' . sprintf(
				/* translators: 1: number still to repair, 2: why the run stopped. */
				__( '%1$s still cannot be verified%2$s — run this again.', 'signal-and-noise-tools' ),
				number_format_i18n( $remaining ),
				'time' === ( $result['stopped'] ?? '' ) ? __( ' (this run hit its time budget)', 'signal-and-noise-tools' ) : ( 'cap' === ( $result['stopped'] ?? '' ) ? __( ' (this run hit its per-run ceiling)', 'signal-and-noise-tools' ) : '' )
			)
			: ' ' . __( 'Nothing is left unverifiable.', 'signal-and-noise-tools' );
		$inner .= \snt_kit_notice( $kind, \snt_kit_esc( $body ) );
	}

	if ( ! empty( $candidates ) ) {
		$count  = count( $candidates );
		$budget = (int) apply_filters( 'sn_prov_backfill_time_budget', defined( 'SN_PROV_BACKFILL_TIME_BUDGET' ) ? SN_PROV_BACKFILL_TIME_BUDGET : 20 );
		$inner .= '<p class="snt-prose">' . \snt_kit_esc( sprintf(
			/* translators: %s: number of candidate subjects (Notes and opted-in pages). */
			__( '%s published subjects cannot currently be verified: either they carry a provenance UID with no local commit chain (the July ledger backfill anchored them worker-side only), or their imported commit is missing its signature, which makes /verify tell a reader no proof exists. Import or repair them from the public ledger; every record is re-verified against its own hash first, and a repair only ever fills in the missing signature.', 'signal-and-noise-tools' ),
			number_format_i18n( $count )
		) ) . '</p>'
			. provenance_post_action( 'sn_prov_chain_backfill', sprintf( /* translators: %s: number of candidate Notes. */ __( 'Repair %s Notes from the ledger', 'signal-and-noise-tools' ), number_format_i18n( $count ) ) )
			. '<p class="snt-hint">' . \snt_kit_esc( sprintf(
				/* translators: %s: the per-run time budget in seconds. */
				__( 'Each Note costs one ledger fetch, so a run is bounded to about %s seconds. If any are left afterwards the panel says how many, and you can run it again.', 'signal-and-noise-tools' ),
				number_format_i18n( $budget )
			) ) . '</p>';
	}

	return \snt_kit_section( __( 'Ledger backfill', 'signal-and-noise-tools' ), $inner );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_tools_provenance( array $ctx ) {
	$data = provenance_data( $ctx );
	$sys  = $data['sys'];

	$out  = '<section aria-label="Provenance at a glance">' . provenance_glance_html( $sys ) . '</section>';
	$out .= provenance_commits_html( $data );
	$out .= provenance_backfill_html( $data );
	$out .= provenance_system_html( $sys );
	$out .= provenance_rotation_html( $data['commitment'] );
	$out .= provenance_genesis_html( $sys, $data['reanchor_flag'] );
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['tools/provenance'] = __NAMESPACE__ . '\\paint_tools_provenance';
		return $painters;
	}
);
