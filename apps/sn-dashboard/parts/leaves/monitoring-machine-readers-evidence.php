<?php
/**
 * S&N Dashboard: rights evidence on Monitoring > Machine Readers, from the kit.
 *
 * The classic twin is inc/admin-forms/rights-evidence.php. The status line,
 * then per held month a door to its dry-run payloads (admin-post GET with its
 * nonce, a JSON download; a door because a form's handler cannot hand a
 * download to the reader) and a one-button Lift form behind a confirm, only
 * when a dry run can compose. Lifting never runs the pass.
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
	if ( ! function_exists( 'sn_rights_evidence_held' ) || ! function_exists( 'sn_rights_evidence_status_line' ) ) {
		return '';
	}
	$can = function_exists( 'sn_rights_evidence_can_compose' ) && \sn_rights_evidence_can_compose();
	$out = '<p class="snt-prose">' . \snt_kit_esc( \sn_rights_evidence_status_line() ) . '</p>';
	foreach ( \sn_rights_evidence_held( false ) as $ym ) {
		$label = gmdate( 'F Y', (int) strtotime( $ym . '-01T00:00:00Z' ) );
		/* translators: %s: a month, e.g. September 2026 */
		$out .= '<p class="snt-prose">' . \snt_kit_door( sprintf( __( 'View %s payloads', 'signal-and-noise-tools' ), $label ), \sn_rights_evidence_view_url( $ym ) ) . '</p>';
		if ( $can ) {
			$out .= \snt_kit_form(
				'rights_evidence_lift',
				'',
				array(
					/* translators: %s: a month */
					'submit'  => sprintf( __( 'Lift %s hold', 'signal-and-noise-tools' ), $label ),
					/* translators: %s: a month */
					'confirm' => sprintf( __( 'Lift the hold on %s? The next daily pass composes and posts it to the public, append-only ledger.', 'signal-and-noise-tools' ), $label ),
					'hidden'  => array( 'tab' => 'monitoring', 'sub' => 'machine-readers', 'month' => $ym ),
				)
			);
		}
	}
	if ( ! $can && \sn_rights_evidence_held( false ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Lift appears once the provenance worker is set up and the sensor answers: a month that cannot be composed stays held.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	return \snt_kit_section( __( 'Rights evidence', 'signal-and-noise-tools' ), $out );
}
