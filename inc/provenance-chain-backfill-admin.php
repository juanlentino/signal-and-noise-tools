<?php
/**
 * Signal & Noise Tools: the Ledger backfill box on the classic Tools >
 * Provenance page, its admin-post handler, and the box's words, which the
 * S&N Home leaf (apps/sn-dashboard/parts/leaves/tools-provenance.php) reads
 * too. Split out of inc/provenance-chain-backfill.php (line cap) on
 * 2026-10-12; the import itself stays there.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The backfill box's words, one copy for both surfaces (this fieldset and the
 * S&N Home leaf). The count is published subjects, notes and opted-in pages,
 * so the button says so too: it said "Repair N Notes" beside a sentence that
 * said "Import or repair" (2026-10-12 audit).
 *
 * @param string $part   intro|button|hint|result.
 * @param mixed  $detail The candidate count, the time budget, or the run result.
 * @return string
 */
function sn_prov_backfill_copy( $part, $detail ) {
	switch ( $part ) {
		case 'intro':
			return sprintf(
				/* translators: %s: number of candidate subjects (notes and opted-in pages). */
				__( '%s published notes and pages cannot be verified yet. Each has a proof on the public ledger but none on this site, or the one here is missing its signature, so /verify tells a reader no proof exists. Importing reads each record from the ledger and checks it against its own hash first; a repair only fills in the missing signature.', 'signal-and-noise-tools' ),
				number_format_i18n( (int) $detail )
			);
		case 'button':
			/* translators: %s: number of candidate notes and pages. */
			return sprintf( __( 'Import or repair %s notes and pages from the ledger', 'signal-and-noise-tools' ), number_format_i18n( (int) $detail ) );
		case 'hint':
			return sprintf(
				/* translators: %s: the per-run time budget in seconds. */
				__( 'Each one costs one ledger fetch, so a run stops after about %s seconds. If any are left, this box says how many, and you can run it again.', 'signal-and-noise-tools' ),
				number_format_i18n( (int) $detail )
			);
	}
	$result = (array) $detail;
	$skips  = array();
	foreach ( (array) ( $result['skipped'] ?? array() ) as $reason => $n ) {
		$skips[] = $reason . ' ×' . (int) $n;
	}
	$line = sprintf(
		/* translators: 1: number of imported commits, 2: number of repaired commits. */
		__( 'Imported %1$s confirmed anchors from the ledger, and repaired %2$s missing signatures.', 'signal-and-noise-tools' ),
		number_format_i18n( (int) ( $result['imported'] ?? 0 ) ),
		number_format_i18n( (int) ( $result['repaired'] ?? 0 ) )
	) . ( $skips ? ' ' . __( 'Skipped:', 'signal-and-noise-tools' ) . ' ' . implode( ', ', $skips ) : '' );
	// What is LEFT, always: a run that stopped on a bound and said nothing
	// reads exactly like a run that finished the job.
	$left    = (int) ( $result['remaining'] ?? 0 );
	$stopped = (string) ( $result['stopped'] ?? '' );
	return $line . ' ' . ( $left > 0
		? sprintf(
			/* translators: 1: number still to repair, 2: why the run stopped. */
			__( '%1$s still cannot be verified%2$s; run this again.', 'signal-and-noise-tools' ),
			number_format_i18n( $left ),
			'time' === $stopped ? __( ' (this run hit its time budget)', 'signal-and-noise-tools' ) : ( 'cap' === $stopped ? __( ' (this run hit its per-run ceiling)', 'signal-and-noise-tools' ) : '' )
		)
		: __( 'Nothing is left unverifiable.', 'signal-and-noise-tools' ) );
}

/**
 * Panel fieldset: the one-shot trigger, shown ONLY while candidates exist
 * (after a clean import the section disappears: the surface is the task).
 */
function sn_prov_backfill_render_fieldset() {
	$candidates = sn_prov_backfill_candidates();
	$result     = get_transient( 'sn_prov_backfill_result_' . get_current_user_id() );
	if ( ! $candidates && ! is_array( $result ) ) {
		return;
	}
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Ledger backfill', 'signal-and-noise-tools' ) . '</h2>';
	if ( is_array( $result ) ) {
		delete_transient( 'sn_prov_backfill_result_' . get_current_user_id() );
		wp_admin_notice(
			esc_html( sn_prov_backfill_copy( 'result', $result ) ),
			array( 'type' => empty( $result['skipped'] ) ? 'success' : 'warning', 'additional_classes' => array( 'notice-alt', 'inline' ) )
		);
	}
	if ( $candidates ) {
		echo '<p class="sn-fieldset-intro">' . esc_html( sn_prov_backfill_copy( 'intro', count( $candidates ) ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sn_prov_chain_backfill' );
		echo '<input type="hidden" name="action" value="sn_prov_chain_backfill" />';
		echo '<button type="submit" class="button button-primary">' . esc_html( sn_prov_backfill_copy( 'button', count( $candidates ) ) ) . '</button>';
		echo '</form>';
		// Stated up front rather than discovered afterwards: each candidate costs
		// one ledger fetch, so a run is bounded by wall clock. A residual is the
		// design working, not the button failing.
		echo '<p class="sn-fieldset-actions-hint">' . esc_html( sn_prov_backfill_copy( 'hint', (int) apply_filters( 'sn_prov_backfill_time_budget', SN_PROV_BACKFILL_TIME_BUDGET ) ) ) . '</p>';
	}
	echo '</div>';
}

/**
 * admin_post handler — the sn_prov_runsweep pattern: nonce + manage_options,
 * result in a short per-user transient, redirect back to the Provenance sub-tab.
 */
function sn_prov_backfill_handler() {
	check_admin_referer( 'sn_prov_chain_backfill' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'signal-and-noise-tools' ), '', array( 'response' => 403 ) );
	}
	set_transient( 'sn_prov_backfill_result_' . get_current_user_id(), sn_prov_backfill_run(), MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg(
		array(
			'page' => 'sn-theme-options',
			'tab'  => 'tools',
			'sub'  => 'provenance',
		),
		admin_url( 'admin.php' )
	) );
	exit;
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'admin_post_sn_prov_chain_backfill', 'sn_prov_backfill_handler' );
}
