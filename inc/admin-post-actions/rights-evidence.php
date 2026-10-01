<?php
/**
 * Signal & Noise: rights evidence actions (Monitoring > Machine Readers).
 *
 * rights_evidence_view streams a held or waiting month's payloads as a JSON
 * download: the stored bytes that would post, else a dry run (a GET door,
 * its nonce in the URL; nothing stored or posted). rights_evidence_lift
 * removes one month from the hold, and the reasons a rule gave, and nothing
 * else. rights_evidence_hold puts a composed, waiting month on the hold.
 * Post now is in its own file (rights-evidence-post-now.php): nothing here
 * can post. Nonce and capability are the dispatcher's
 * (inc/admin-post-handler.php); the month is read against the hold list or
 * the waiting months, so no other value reaches an action.
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
 * The month a request names, when it is composed, unposted and not held; ''
 * otherwise.
 *
 * @param array $src $_GET or $_POST.
 * @return string YYYY-MM or ''.
 */
function sn_rights_evidence_request_pending( array $src ) {
	$ym = sanitize_text_field( wp_unslash( (string) ( $src['month'] ?? '' ) ) );
	return function_exists( 'sn_rights_evidence_pending' ) && array_key_exists( $ym, sn_rights_evidence_pending() ) ? $ym : '';
}

/**
 * A month's stored, unposted bytes: family => canonical.
 *
 * @param string $ym YYYY-MM.
 * @return array<string,string>
 */
function sn_rights_evidence_stored_payloads( $ym ) {
	$out = array();
	foreach ( (array) ( sn_rights_evidence_data()[ $ym ] ?? array() ) as $family => $e ) {
		if ( is_array( $e ) && '' !== (string) ( $e['canonical'] ?? '' ) && '' === (string) ( $e['ledger_path'] ?? '' ) ) {
			$out[ (string) $family ] = (string) $e['canonical'];
		}
	}
	ksort( $out );
	return $out;
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
	$ym = '' === $ym ? sn_rights_evidence_request_pending( $_GET ) : $ym; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	if ( '' === $ym ) {
		return 'rights_evidence_not_held';
	}
	// The stored bytes are what posts; a dry run only when nothing is stored.
	$stored = sn_rights_evidence_stored_payloads( $ym );
	$dry    = $stored ? array( 'ok' => true, 'error' => '', 'payloads' => $stored ) : sn_rights_evidence_dry_run( $ym );
	$source = $stored ? 'stored' : 'dry-run';
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="rights-evidence-' . $ym . '-' . $source . '.json"' );
	$out = array( 'month' => $ym, 'ok' => $dry['ok'], 'error' => $dry['error'], 'posted' => false, 'source' => $source, 'payloads' => array() );
	foreach ( $dry['payloads'] as $family => $canonical ) {
		$out['payloads'][ $family ] = json_decode( $canonical );
	}
	// nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- the month is sanitize_text_field'd and accepted only when it is on the stored hold list (sn_rights_evidence_request_month); the body is a JSON file download (Content-Disposition: attachment), not HTML.
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
	$reasons = sn_rights_evidence_hold_reasons();
	unset( $reasons[ $ym ] );
	update_option( 'sn_rights_evidence_hold_reasons', $reasons, false );
	return 'rights_evidence_lifted';
}

/**
 * rights_evidence_hold: put a composed, waiting month on the hold (the owner's
 * opt-out; no reason stored). Never runs the pass.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_rights_evidence_hold( $post ) {
	$ym = sn_rights_evidence_request_pending( (array) $post );
	if ( '' === $ym ) {
		return 'rights_evidence_not_pending';
	}
	sn_rights_evidence_hold_month( $ym );
	return 'rights_evidence_held';
}
