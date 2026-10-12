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

require_once __DIR__ . '/tools-provenance-rail.php';

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

	// The rotation handlers' flag (staged|rotated|e-<code>): a flag alone, no
	// transient, so it rides `params` until the next go or post like the
	// re-anchor flag. Read on both surfaces since 2026-10-12; before that
	// nothing painted it, and a rotation looked like nothing had happened.
	$rotate_flag = isset( $params['sn_prov_rotate'] ) ? sanitize_text_field( (string) $params['sn_prov_rotate'] ) : '';

	// The sweep result is a one-shot transient the classic notice deletes on read,
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
		'rotate_flag'     => $rotate_flag,
		'swept_flag'      => $swept_flag,
		'sweep_result'    => $sweep_result,
	);
}

/**
 * The empty-table sentence: pending proofs are this table's whole subject,
 * so it says there are none (or, when the newest-100 cut leaves some out,
 * where they are: sn_prov_admin_empty_line(), the classic poller's words
 * too), and then defers to the Provenance integrity check's LAST READING
 * when that reading disagrees with "all is well". Anchored (the proof landed)
 * and intact (the twin still matches) are different questions; the sweep
 * answers the second and this table never does.
 *
 * @param int $hidden Proofs waiting past the table's cut.
 * @return string
 */
function provenance_commits_empty_copy( $hidden = 0 ) {
	$copy  = function_exists( 'sn_prov_admin_empty_line' ) ? sn_prov_admin_empty_line( (int) $hidden ) : __( 'No pending proofs; a new one appears here within 30 seconds of minting.', 'signal-and-noise-tools' );
	$state = function_exists( 'sn_prov_integrity_state' ) ? sn_prov_integrity_state() : null;
	if ( is_array( $state ) && (int) ( $state['failed'] ?? 0 ) > 0 ) {
		$when  = (int) ( $state['swept_at'] ?? 0 );
		// The host's MIO callout (assets/os-host.js) reads this marker: a plain
		// tip beside the sentence, pointing at Trust checks.
		$copy .= ' ' . sprintf(
			/* translators: 1: failing subject count, 2: time of the sweep. */
			_n( 'The Provenance integrity check\'s last reading (%2$s) reports %1$d subject failing; see Trust checks.', 'The Provenance integrity check\'s last reading (%2$s) reports %1$d subjects failing; see Trust checks.', (int) $state['failed'], 'signal-and-noise-tools' ),
			(int) $state['failed'],
			$when > 0 && function_exists( 'sn_prov_admin_format_ts' ) ? sn_prov_admin_format_ts( $when ) : __( 'undated', 'signal-and-noise-tools' )
		);
	}
	return $copy;
}

/**
 * The at-a-glance hero, from the classic leaf's own
 * `sn_prov_admin_glance_cards()`: each card says what it counts. The system,
 * key rotation and genesis boxes live in tools-provenance-rail.php.
 *
 * @param array $sys sn_prov_admin_system_status() view-model.
 * @return string
 */
function provenance_glance_html( array $sys ) {
	$cards = function_exists( 'sn_prov_admin_glance_cards' ) ? sn_prov_admin_glance_cards( $sys ) : array();
	$out   = array();
	foreach ( $cards as $card ) {
		if ( is_array( $card ) ) {
			$out[] = \snt_kit_stat( (string) ( $card['value'] ?? '' ), (string) ( $card['label'] ?? '' ), (string) ( $card['caption'] ?? '' ), (string) ( $card['pill']['kind'] ?? '' ) );
		}
	}
	return \snt_kit_grid( $out, 160, 10 );
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
		$status  = (string) ( $row['status'] ?? '' );
		$ledger  = (string) ( $row['ledger_url'] ?? '' );
		$version = (string) ( $row['version'] ?? '' );
		$rows[]  = array(
			'uid'     => (string) ( $row['note_uid'] ?? '' ),
			'version' => $version,
			'status'  => function_exists( 'sn_prov_admin_status_label' ) ? sn_prov_admin_status_label( $status ) : ucfirst( $status ),
			// A link cell (os-table takes html/text cells since 17.9.0), the
			// classic poller's words; a missing record says so, never a dash.
			'ledger'  => '' !== $ledger
				/* translators: %s: commit version number. */
				? array( 'html' => \snt_kit_link( sprintf( __( 'v%s on the ledger', 'signal-and-noise-tools' ), $version ), $ledger ), 'text' => $ledger )
				: __( 'No ledger record', 'signal-and-noise-tools' ),
		);
	}
	// The tiles count every subject; this table reads the newest 100. The cut
	// is said once: under the rows, or by the empty sentence when none are listed.
	$hidden = function_exists( 'sn_prov_admin_unlisted' ) ? sn_prov_admin_unlisted( $data['sys'], count( $rows ) ) : 0;
	$cut    = array() !== $rows && function_exists( 'sn_prov_admin_unlisted_line' ) ? sn_prov_admin_unlisted_line( $hidden ) : '';
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
		array( 'empty' => provenance_commits_empty_copy( $hidden ) )
	);

	$inner = '';
	if ( '' !== (string) $data['swept_flag'] ) {
		$inner .= provenance_sweep_notice_html( (string) $data['swept_flag'], $data['sweep_result'] );
	}
	$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Ask the Worker to check pending proofs against Bitcoin now, rather than waiting for the hourly sweep.', 'signal-and-noise-tools' ) ) . '</p>'
		. provenance_post_action( 'sn_prov_runsweep', __( 'Check for confirmations', 'signal-and-noise-tools' ) )
		. \snt_kit_poll()
		. $table
		. ( '' !== $cut ? '<p class="snt-hint snt-ledger-more">' . \snt_kit_esc( $cut ) . '</p>' : '' );
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

	$inner = is_array( $result ) ? \snt_kit_notice( empty( $result['skipped'] ) ? 'ok' : 'warn', \snt_kit_esc( sn_prov_backfill_copy( 'result', $result ) ) ) : '';
	if ( ! empty( $candidates ) ) {
		$count  = count( $candidates );
		$budget = (int) apply_filters( 'sn_prov_backfill_time_budget', defined( 'SN_PROV_BACKFILL_TIME_BUDGET' ) ? SN_PROV_BACKFILL_TIME_BUDGET : 20 );
		$inner .= '<p class="snt-prose">' . \snt_kit_esc( sn_prov_backfill_copy( 'intro', $count ) ) . '</p>'
			. provenance_post_action( 'sn_prov_chain_backfill', sn_prov_backfill_copy( 'button', $count ) )
			. '<p class="snt-hint">' . \snt_kit_esc( sn_prov_backfill_copy( 'hint', $budget ) ) . '</p>';
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
			\snt_kit_stack( provenance_commits_html( $data ) . provenance_backfill_html( $data ) . ( function_exists( __NAMESPACE__ . '\\provenance_archive_html' ) ? provenance_archive_html() : '' ) ),
			\snt_kit_stack(
				provenance_system_html( $sys ) . provenance_rotation_html( $data['commitment'], $data['rotate_flag'] ) . provenance_genesis_html( $sys, $data['reanchor_flag'] ),
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
