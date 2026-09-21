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
 * The five writes are one-click `snt_kit_action_button()`s that declare the
 * admin-post pipeline (`os-arg-pipeline="admin-post"`): `posted_values()`
 * (apps/sn-dashboard/sn-dashboard.os.php) names the field `action` for that
 * pipeline, the literal name the host's admin-post routing reads
 * (inc/openstation-host-pipelines.php, snt_os_host_pipeline_for()), and each
 * button carries its handler's OWN nonce, never the shared one (#1614).
 *
 * The Commits table is server-rendered from the SAME status list the poller
 * would have hydrated (`sn_prov_admin_status()`), since an inline script never
 * runs in a window; the poll is the runtime's own (`os-poll` on a no-op
 * `poll` action, snt_kit_poll(), #1607), so a pending row changes state on
 * its own within 30 s. This leaf's writes are bare buttons, so a tick's
 * repaint has no typed field to reset.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * One admin-post write as a one-click button: `<os-button os-action="post"
 * os-arg-action os-arg-nonce os-arg-pipeline="admin-post">`, the compat doc's
 * shape for a one-click write (docs/openstation-compat.md). Mirrors the
 * classic leaf's `<form action="admin-post.php">` around a lone `<button>`.
 *
 * The nonce is `wp_create_nonce( $action )`, the handler's OWN action: every
 * one of these five handlers calls `check_admin_referer( $action )` against
 * its own name (inc/provenance-admin.php, inc/provenance-rotation.php,
 * inc/provenance-chain-backfill.php), exactly as the classic
 * `wp_nonce_field( $action )` per form does, and since #1614 exactly as every
 * table action does. None of the five confirms: classic confirms none, and
 * the rotation handler's docblock refuses a dialog on purpose (the commitment
 * step IS the second act).
 *
 * @param string $action WP admin-post action name.
 * @param string $label  Button label.
 * @return string
 */
function provenance_post_action( $action, $label ) {
	return \snt_kit_action_button( (string) $label, (string) $action );
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

	// The classic leaf reads its ok|fail flashes from $_GET; a window never
	// carries a real query string, so the SAME values ride back as
	// `state('params')` (inc/openstation-host.php, snt_os_host_params() —
	// every `sn_*` key from the admin-post redirect target survives).
	$state  = isset( $ctx['state'] ) ? $ctx['state'] : null;
	$params = ( is_object( $state ) && method_exists( $state, 'get' ) ) ? (array) $state->get( 'params' ) : array();
	$stash  = false;

	// The backfill result is a one-shot transient the classic fieldset deletes
	// the instant it renders (inc/provenance-chain-backfill.php:371), and its
	// redirect carries NO flag: the transient is the whole signal. A window
	// repaints the same session on every poll tick, so deleting on every read
	// made the SECOND paint (anywhere in the 30 s after the post) drop the
	// "Imported N" notice, and with no candidates left the whole section. So
	// the first read moves the result INTO `params`, the sweep's shape below,
	// and every later paint reads it from there; `go` and `post` reset
	// `params`, so it leaves with the next user action (#1607).
	if ( isset( $params['sn_prov_backfill_result'] ) && is_array( $params['sn_prov_backfill_result'] ) ) {
		$backfill = $params['sn_prov_backfill_result'];
	} else {
		$key      = 'sn_prov_backfill_result_' . get_current_user_id();
		$backfill = get_transient( $key );
		if ( is_array( $backfill ) ) {
			delete_transient( $key );
			$params['sn_prov_backfill_result'] = $backfill;
			$stash                             = true;
		}
	}

	$reanchor_flag = isset( $params['sn_prov_reanchor'] ) ? sanitize_text_field( (string) $params['sn_prov_reanchor'] ) : '';
	$swept_flag    = isset( $params['sn_prov_swept'] ) ? sanitize_text_field( (string) $params['sn_prov_swept'] ) : '';

	// $_GET['sn_prov_rotate'] is a THIRD flash the classic code sets (the
	// rotation redirect target) but never reads back anywhere in the file —
	// grep confirms no renderer or notice consumes it. A faithful port
	// reproduces readers, not dead writers; nothing is painted for it here.
	//
	// The result is a one-shot transient the classic notice deletes on read,
	// while the `sn_prov_swept` flag rides `params` until the next `go` or
	// `post`. Deleting on every read made the SECOND paint (a poll tick, the
	// title-bar Refresh, any post on this leaf) read the flag with an empty
	// result and paint "Sweep failed" for a sweep that succeeded. So the
	// first read moves the result INTO `params`, beside the flag, and every
	// later paint reads it from there: the read is idempotent and the two
	// live and die together (#1607).
	$sweep_result = null;
	if ( '' !== $swept_flag ) {
		if ( isset( $params['sn_prov_sweep_result'] ) && is_array( $params['sn_prov_sweep_result'] ) ) {
			$sweep_result = $params['sn_prov_sweep_result'];
		} else {
			$key          = 'sn_prov_sweep_result_' . get_current_user_id();
			$sweep_result = get_transient( $key );
			delete_transient( $key );
			$sweep_result = is_array( $sweep_result ) ? $sweep_result : array();
			$params['sn_prov_sweep_result'] = $sweep_result;
			$stash                          = true;
		}
	}
	if ( $stash && is_object( $state ) && method_exists( $state, 'set' ) ) {
		$state->set( 'params', $params );
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
 * The empty-table sentence: pending proofs are this table's whole subject,
 * so it says there are none, and then defers to the integrity sweep's LAST
 * READING when that reading disagrees with "all is well". Anchored (the
 * proof landed) and intact (the twin still matches) are different questions;
 * the sweep answers the second and this table never does.
 *
 * @return string
 */
function provenance_commits_empty_copy() {
	$copy  = __( 'No pending proofs; press Refresh after minting to see new ones here.', 'signal-and-noise-tools' );
	$state = function_exists( 'sn_prov_integrity_state' ) ? sn_prov_integrity_state() : null;
	if ( is_array( $state ) && (int) ( $state['failed'] ?? 0 ) > 0 ) {
		$when  = (int) ( $state['swept_at'] ?? 0 );
		// The host's MIO callout (assets/os-host.js) reads this marker: a plain
		// tip beside the sentence, pointing at Trust checks.
		$copy .= ' ' . sprintf(
			/* translators: 1: failing subject count, 2: time of the sweep. */
			_n( 'The integrity sweep\'s last reading (%2$s) reports %1$d subject failing; see Trust checks.', 'The integrity sweep\'s last reading (%2$s) reports %1$d subjects failing; see Trust checks.', (int) $state['failed'], 'signal-and-noise-tools' ),
			(int) $state['failed'],
			$when > 0 ? ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i T', $when ) : gmdate( 'Y-m-d H:i', $when ) . ' UTC' ) : __( 'undated', 'signal-and-noise-tools' )
		);
	}
	return $copy;
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
	$out   = array();
	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$pill    = isset( $card['pill'] ) && is_array( $card['pill'] ) ? $card['pill'] : array();
		$kind    = (string) ( $pill['kind'] ?? '' );
		$value   = (string) ( $card['value'] ?? '' );
		$caption = '' !== $kind ? (string) ( $pill['text'] ?? '' ) : '';
		if ( 'Worker' === ( $card['label'] ?? '' ) && false !== strpos( $value, ' · ' ) ) {
			list( $value, $contact ) = explode( ' · ', $value, 2 );
			$caption = trim( $caption . ' · ' . $contact, ' ·' );
		}
		$out[] = \snt_kit_stat(
			$value,
			(string) ( $card['label'] ?? '' ),
			$caption,
			$kind
		);
	}
	return \snt_kit_grid( $out, 160, 10 );
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
		$inner .= '<p class="snt-provenance-key"><span class="snt-hint">' . \snt_kit_esc( __( 'Public signing key', 'signal-and-noise-tools' ) ) . '</span><br>' . \snt_kit_code( $pubkey, false ) . '</p>';
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
		// window paints no disabled write button, so the button is withheld:
		// same click blocked, and the suite pins the omission.
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
 * Commits fieldset: the sweep flash, the on-demand trigger, the poll trigger
 * (the runtime's `os-poll`, in place of the classic JS poller) and the same
 * status list the poller would have hydrated, server-rendered, since no
 * script runs inside a window.
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
		// A window has no poller: the rows are painted server-side, so an
		// empty table means "nothing pending", not "not loaded yet". Until
		// 14.7.2 this borrowed the classic page's pre-hydration copy ("Loading
		// anchor status…"), which read as a stall the first time the queue
		// drained. 14.7.3: the sentence says only what THIS table measures
		// (pending proofs) and hands the integrity verdict to the sweep that
		// owns it; "every commit is anchored" read as "all is well" beside an
		// Attention queue listing three twin-drift failures (2026-09-14).
		array( 'empty' => provenance_commits_empty_copy() )
	);

	$inner = '';
	if ( '' !== (string) $data['swept_flag'] ) {
		$inner .= provenance_sweep_notice_html( (string) $data['swept_flag'], $data['sweep_result'] );
	}
	$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Ask the Worker to check pending proofs against Bitcoin now, rather than waiting for the hourly sweep.', 'signal-and-noise-tools' ) ) . '</p>'
		. provenance_post_action( 'sn_prov_runsweep', __( 'Check for confirmations', 'signal-and-noise-tools' ) )
		. \snt_kit_poll()
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
		$kind    = empty( $result['skipped'] ) ? 'ok' : 'warn';
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

	$out  = '<div class="snt-provenance"><section aria-label="Provenance at a glance">' . provenance_glance_html( $sys ) . '</section>';
	$out .= \snt_kit_grid(
		array(
			\snt_kit_stack( provenance_commits_html( $data ) . provenance_backfill_html( $data ) ),
			\snt_kit_stack(
				provenance_system_html( $sys ) . provenance_rotation_html( $data['commitment'] ) . provenance_genesis_html( $sys, $data['reanchor_flag'] ),
				18,
				array( 'role' => 'complementary', 'aria-label' => __( 'Provenance status', 'signal-and-noise-tools' ) )
			),
		),
		290,
		24,
		array( 'class' => 'snt-provenance-columns' )
	);
	return $out . '</div>';
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['tools/provenance'] = __NAMESPACE__ . '\\paint_tools_provenance';
		return $painters;
	}
);
