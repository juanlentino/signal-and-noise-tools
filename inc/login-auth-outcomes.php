<?php
/**
 * Private, request-bound authentication observations for Login Guard.
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build protocol headers without exposing usernames, provider data or credentials.
 *
 * @param string $key     Dedicated shared secret: 64 lowercase hexadecimal characters.
 * @param string $nonce   Worker-generated request nonce, also 64 hexadecimal characters.
 * @param string $outcome Authentication observation from the audit capture boundary.
 * @return array<string,string> Headers, or an empty array when not configured/valid.
 */
function snt_login_auth_outcome_headers( $key, $nonce, $outcome ) {
	$allowed = array( 'none', 'auth_failed', 'mfa_failed', 'mfa_throttled', 'mfa_other', 'login_success', 'mfa_success' );
	if ( ! is_string( $key ) || ! is_string( $nonce )
		|| ! preg_match( '/\A[0-9a-f]{64}\z/', $key ) || ! preg_match( '/\A[0-9a-f]{64}\z/', $nonce )
		|| ! in_array( $outcome, $allowed, true ) ) {
		return array();
	}
	$message = "sn-login-outcome-v1\n" . $nonce . "\n" . $outcome;
	return array(
		'X-SN-Auth-Outcome'   => $outcome,
		'X-SN-Auth-Signature' => hash_hmac( 'sha256', $message, $key ),
		'Cache-Control'       => 'private, no-store',
	);
}

/**
 * Send a signed observation only on a nonce-bearing login request.
 *
 * Missing configuration or already-sent headers leaves login behavior unchanged.
 * Define SN_LG_OUTCOME_KEY in wp-config.php; never reuse a WordPress salt or bypass key.
 *
 * @param string $outcome Observation; "none" is a handshake, not a successful login.
 * @return void
 */
function snt_login_auth_outcome_emit( $outcome = 'none' ) {
	if ( headers_sent() || ! defined( 'SN_LG_OUTCOME_KEY' ) || ! did_action( 'login_init' ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict 64-hex allowlist in snt_login_auth_outcome_headers; reject rather than normalize nonce bytes.
	$nonce = isset( $_SERVER['HTTP_X_SN_AUTH_NONCE'] ) ? wp_unslash( $_SERVER['HTTP_X_SN_AUTH_NONCE'] ) : '';
	foreach ( snt_login_auth_outcome_headers( SN_LG_OUTCOME_KEY, $nonce, $outcome ) as $name => $value ) {
		header( $name . ': ' . $value, true );
	}
}
add_action( 'login_init', 'snt_login_auth_outcome_emit', 1, 0 );
add_action( 'snt_login_auth_outcome', 'snt_login_auth_outcome_emit' );
