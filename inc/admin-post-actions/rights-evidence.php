<?php
/**
 * Signal & Noise: rights evidence actions (Monitoring > Machine Readers).
 *
 * rights_evidence_view streams a held month's dry-run payloads as a JSON
 * download (a GET door, its nonce in the URL; nothing stored or posted).
 * rights_evidence_lift removes one month from the hold and nothing else: the
 * next daily pass composes it. Nonce and capability are the dispatcher's
 * (inc/admin-post-handler.php); the month is read against the hold list, so
 * no other value reaches either action.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The month a request names, when it is held; '' otherwise.
 *
 * @param array $src $_GET or $_POST.
 * @return string YYYY-MM or ''.
 */
function sn_rights_evidence_request_month( array $src ) {
	$ym = sanitize_text_field( wp_unslash( (string) ( $src['month'] ?? '' ) ) );
	return function_exists( 'sn_rights_evidence_held' ) && in_array( $ym, sn_rights_evidence_held( false ), true ) ? $ym : '';
}

/**
 * Can a dry run compose right now? The worker is set up and the sensor
 * answered its version endpoint. The gate the Lift button paints behind and
 * the Lift handler re-checks.
 *
 * @return bool
 */
function sn_rights_evidence_can_compose() {
	$info = function_exists( 'snt_mr_sensor_info' ) ? snt_mr_sensor_info() : null;
	return function_exists( 'sn_rights_evidence_is_ready' ) && sn_rights_evidence_is_ready() && is_array( $info ) && ! empty( $info['reachable'] );
}

/**
 * rights_evidence_view: stream the month's dry-run payloads as JSON.
 *
 * @param array $post Raw $_POST (unused: a GET door).
 * @return string Flash code on refusal; streams and exits on success.
 */
function sn_handle_rights_evidence_view( $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- dispatcher signature.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the dispatcher ran check_admin_referer for this action.
	$ym = sn_rights_evidence_request_month( $_GET );
	if ( '' === $ym ) {
		return 'rights_evidence_not_held';
	}
	$dry = sn_rights_evidence_dry_run( $ym );
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="rights-evidence-' . $ym . '-dry-run.json"' );
	$out = array( 'month' => $ym, 'ok' => $dry['ok'], 'error' => $dry['error'], 'posted' => false, 'payloads' => array() );
	foreach ( $dry['payloads'] as $family => $canonical ) {
		$out['payloads'][ $family ] = json_decode( $canonical );
	}
	echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON download, not HTML.
	exit;
}

/**
 * rights_evidence_lift: take one month off the hold. Never runs the pass.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_rights_evidence_lift( $post ) {
	$ym = sn_rights_evidence_request_month( (array) $post );
	if ( '' === $ym ) {
		return 'rights_evidence_not_held';
	}
	if ( ! sn_rights_evidence_can_compose() ) {
		return 'rights_evidence_lift_refused';
	}
	update_option( 'sn_rights_evidence_hold', array_values( array_diff( sn_rights_evidence_held( false ), array( $ym ) ) ), false );
	return 'rights_evidence_lifted';
}
