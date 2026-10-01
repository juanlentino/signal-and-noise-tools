<?php
/**
 * Signal & Noise: rights_evidence_post_now, the owner's bypass of the review
 * window (Monitoring > Machine Readers). Nonce and capability are the
 * dispatcher's (inc/admin-post-handler.php), behind a confirm on both twins.
 * The month is matched against the composed, unposted, unheld months, so
 * nothing else reaches the worker; it never composes.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * rights_evidence_post_now: post one waiting month's composed records now.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_rights_evidence_post_now( $post ) {
	$ym = sanitize_text_field( wp_unslash( (string) ( ( (array) $post )['month'] ?? '' ) ) );
	if ( ! function_exists( 'sn_rights_evidence_post_now' ) || ! function_exists( 'sn_rights_evidence_can_retract' ) ) {
		return 'rights_evidence_not_pending';
	}
	if ( ! sn_rights_evidence_can_retract() ) {
		return 'rights_evidence_post_now_unconfigured';
	}
	$codes = array(
		'posted'     => 'rights_evidence_posted_now',
		'partial'    => 'rights_evidence_post_now_partial',
		'busy'       => 'rights_evidence_retract_busy',
		'ineligible' => 'rights_evidence_not_pending',
	);
	return $codes[ sn_rights_evidence_post_now( $ym )['result'] ] ?? 'rights_evidence_post_now_partial';
}
