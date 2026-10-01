<?php
/**
 * Signal & Noise: rights_evidence_retract, one signed retraction per click
 * (Monitoring > Machine Readers). Nonce and capability are the dispatcher's
 * (inc/admin-post-handler.php). The month and family are matched against the
 * eligible records (inc/rights-evidence-retract.php), so nothing else reaches
 * the worker; the text is fixed in code, never posted by the form.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Where the last refused or failed retraction's worker error waits for its flash. */
const SN_RIGHTS_EVIDENCE_RETRACT_ERROR = 'sn_rights_evidence_retract_error';

/**
 * rights_evidence_retract: post one retraction.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_rights_evidence_retract( $post ) {
	$post   = (array) $post;
	$month  = sanitize_text_field( wp_unslash( (string) ( $post['month'] ?? '' ) ) );
	$family = sanitize_text_field( wp_unslash( (string) ( $post['family'] ?? '' ) ) );
	if ( ! function_exists( 'sn_rights_evidence_retract' ) ) {
		return 'rights_evidence_not_retractable';
	}
	$r = sn_rights_evidence_retract( $month, $family );
	if ( in_array( $r['result'], array( 'refused', 'failed' ), true ) ) {
		set_transient( SN_RIGHTS_EVIDENCE_RETRACT_ERROR, substr( (string) $r['error'], 0, 300 ), 10 * MINUTE_IN_SECONDS );
	}
	$codes = array(
		'retracted'  => 'rights_evidence_retracted',
		'refused'    => 'rights_evidence_retract_refused',
		'failed'     => 'rights_evidence_retract_failed',
		'busy'       => 'rights_evidence_retract_busy',
		'ineligible' => 'rights_evidence_not_retractable',
	);
	return $codes[ $r['result'] ] ?? 'rights_evidence_retract_failed';
}
