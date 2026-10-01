<?php
/**
 * S&N Dashboard: rights evidence on Monitoring > Machine Readers, from the kit.
 *
 * The classic twin is inc/admin-forms/rights-evidence.php. The status line,
 * then per month held or composed and waiting out its review window: a door
 * to its payloads (admin-post GET with its nonce, a JSON download; a door
 * because a form's handler cannot hand a download to the reader), and
 * one-button forms behind a confirm: Lift for a held month (only when a dry
 * run can compose), Hold and Post now for a waiting one. None runs the pass.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The Rights evidence section; '' when the module is absent.
 *
 * @return string
 */
function machine_readers_rights_evidence_html() {
	if ( ! function_exists( 'sn_rights_evidence_held' ) || ! function_exists( 'sn_rights_evidence_status_line' ) || ! function_exists( 'sn_rights_evidence_pending' ) ) {
		return '';
	}
	$can  = function_exists( 'sn_rights_evidence_can_compose' ) && \sn_rights_evidence_can_compose();
	$post = function_exists( 'sn_rights_evidence_can_retract' ) && \sn_rights_evidence_can_retract();
	$out  = '<p class="snt-prose">' . \snt_kit_esc( \sn_rights_evidence_status_line() ) . '</p>';
	foreach ( \sn_rights_evidence_control_months() as $ym => $state ) {
		$label = gmdate( 'F Y', (int) strtotime( $ym . '-01T00:00:00Z' ) );
		$note  = \sn_rights_evidence_month_note( $ym, $state );
		if ( '' !== $note ) {
			$out .= '<p class="snt-prose"><strong>' . \snt_kit_esc( $label ) . '</strong>: ' . \snt_kit_esc( $note ) . '</p>';
		}
		/* translators: %s: a month, e.g. September 2026 */
		$out .= '<p class="snt-prose">' . \snt_kit_door( sprintf( __( 'View %s payloads', 'signal-and-noise-tools' ), $label ), \sn_rights_evidence_view_url( $ym ) ) . '</p>';
		if ( 'held' === $state && $can ) {
			/* translators: %s: a month */
			$out .= machine_readers_rights_evidence_button( 'rights_evidence_lift', $ym, sprintf( __( 'Lift %s hold', 'signal-and-noise-tools' ), $label ), \sn_rights_evidence_confirm( 'lift', $label ) );
		}
		if ( 'review' === $state ) {
			/* translators: %s: a month */
			$out .= machine_readers_rights_evidence_button( 'rights_evidence_hold', $ym, sprintf( __( 'Hold %s', 'signal-and-noise-tools' ), $label ), \sn_rights_evidence_confirm( 'hold', $label ) );
			if ( $post ) {
				/* translators: %s: a month */
				$out .= machine_readers_rights_evidence_button( 'rights_evidence_post_now', $ym, sprintf( __( 'Post %s now', 'signal-and-noise-tools' ), $label ), \sn_rights_evidence_confirm( 'post', $label ), true );
			}
		}
	}
	if ( ! $can && \sn_rights_evidence_held( false ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Lift appears once the provenance worker is set up and the sensor answers: a month that cannot be composed stays held.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	return \snt_kit_section( __( 'Rights evidence', 'signal-and-noise-tools' ), $out . machine_readers_rights_evidence_retract_html() );
}

/**
 * One month button: a one-button form posting the month, behind a confirm.
 *
 * @param string $action  Handler action.
 * @param string $ym      YYYY-MM.
 * @param string $text    Button text.
 * @param string $confirm The confirm question.
 * @param bool   $danger  Painted as a danger confirm.
 * @return string
 */
function machine_readers_rights_evidence_button( $action, $ym, $text, $confirm, $danger = false ) {
	return \snt_kit_form(
		$action,
		'',
		array(
			'submit'  => $text,
			'confirm' => $confirm,
			'danger'  => $danger,
			'hidden'  => array( 'tab' => 'monitoring', 'sub' => 'machine-readers', 'month' => $ym ),
		)
	);
}

/**
 * Per retractable record: the exact text that will be published, then a
 * one-button Retract form behind a confirm; '' when none is eligible.
 *
 * @return string
 */
function machine_readers_rights_evidence_retract_html() {
	$rows = function_exists( 'sn_rights_evidence_can_retract' ) && function_exists( 'sn_rights_evidence_retract_confirm' ) && \sn_rights_evidence_can_retract() ? \sn_rights_evidence_retractable() : array();
	if ( ! $rows ) {
		return '';
	}
	$out = '<h3>' . \snt_kit_esc( __( 'Retractions', 'signal-and-noise-tools' ) ) . '</h3><p class="snt-hint">' . \snt_kit_esc( \SN_RIGHTS_EVIDENCE_RETRACT_NOTE ) . '</p>';
	foreach ( $rows as $r ) {
		$label = gmdate( 'F Y', (int) strtotime( $r['month'] . '-01T00:00:00Z' ) ) . ', ' . $r['family'];
		$out  .= '<p class="snt-prose"><strong>' . \snt_kit_esc( $label ) . '</strong>: <code>' . \snt_kit_esc( (string) $r['entry']['ledger_path'] ) . '</code></p>';
		foreach ( \SN_RIGHTS_EVIDENCE_RETRACT_LABELS as $key => $name ) {
			$out .= '<p class="snt-prose"><strong>' . \snt_kit_esc( $name ) . ':</strong> ' . \snt_kit_esc( (string) $r['text'][ $key ] ) . '</p>';
		}
		$out .= \snt_kit_form(
			'rights_evidence_retract',
			'',
			array(
				'submit'  => 'Retract ' . $label,
				'confirm' => \sn_rights_evidence_retract_confirm( $label ),
				'danger'  => true,
				'hidden'  => array( 'tab' => 'monitoring', 'sub' => 'machine-readers', 'month' => $r['month'], 'family' => $r['family'] ),
			)
		);
	}
	return $out;
}
