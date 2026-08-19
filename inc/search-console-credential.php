<?php
/**
 * Signal & Noise Tools — the Google Search Console service-account credential.
 *
 * R6b step 0. The arc's long pole is an OWNER action (create the service
 * account in Google Cloud, grant it the Search Console property permission),
 * and that action has nowhere to land until this field exists. v10.84.0
 * shipped the page-signing gate with no way to set it and the setting sat
 * unreachable for thirty releases; this ships the control FIRST, before the
 * client that consumes it.
 *
 * WHERE IT IS STORED, and why the scaffold's `autoload=false` is not honoured
 * literally: the credential lives in the `search_console` settings subtree,
 * not its own option. The drift snapshot (inc/config-drift.php) reads ONLY
 * SN_SETTINGS_OPTION, so a dedicated option would be drift-INVISIBLE — a
 * silently-replaced credential is exactly what drift detection is for. That
 * is the same trade `machine_readers.read_token` already makes. The cost is
 * that the settings option is autoloaded; the mitigation is that the private
 * key is never echoed, never returned by any accessor here except the one
 * that signs, and hashed rather than copied into the drift snapshot.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Dot-path of the credential leaf. Suffix `credential` is what config-drift hashes on. */
define( 'SNT_GSC_CREDENTIAL_PATH', 'search_console.gsc_credential' );

/**
 * Validate a pasted service-account JSON.
 *
 * Returns the parsed array on success. The checks are the ones that decide
 * whether the JWT grant can even be attempted — a credential that fails any
 * of them cannot produce a token, so accepting it would store a value whose
 * only future is a runtime failure with no obvious cause.
 *
 * @param string $json Raw pasted JSON.
 * @return array{ok:bool,error:string,parsed:array} error is '' on success.
 */
function snt_gsc_credential_validate( $json ) {
	$fail = static function ( $msg ) {
		return array( 'ok' => false, 'error' => $msg, 'parsed' => array() );
	};
	$json = trim( (string) $json );
	if ( '' === $json ) {
		return $fail( 'empty' );
	}
	$parsed = json_decode( $json, true );
	if ( ! is_array( $parsed ) || json_last_error() !== JSON_ERROR_NONE ) {
		return $fail( 'not_json' );
	}
	// A top-level JSON ARRAY also decodes to a PHP array, so is_array() alone
	// lets `[1,2,3]` through to the type check and mislabels it as "not a
	// service account". A key file is a JSON object; anything else is not JSON
	// of the right shape at all. (No array_is_list(): it is PHP 8.1+, and this
	// check has to hold on the oldest runtime the plugin supports.)
	if ( array() !== $parsed && array_keys( $parsed ) === range( 0, count( $parsed ) - 1 ) ) {
		return $fail( 'not_json' );
	}
	if ( 'service_account' !== (string) ( $parsed['type'] ?? '' ) ) {
		// An OAuth *client* JSON is the common wrong paste: it has the same
		// shape and the same origin screen, and it cannot do a JWT grant.
		return $fail( 'not_service_account' );
	}
	foreach ( array( 'client_email', 'private_key', 'private_key_id', 'project_id' ) as $required ) {
		if ( '' === trim( (string) ( $parsed[ $required ] ?? '' ) ) ) {
			return $fail( 'missing_' . $required );
		}
	}
	if ( false === strpos( (string) $parsed['private_key'], 'BEGIN PRIVATE KEY' ) ) {
		return $fail( 'private_key_not_pem' );
	}
	if ( false === strpos( (string) $parsed['client_email'], '@' ) ) {
		return $fail( 'client_email_malformed' );
	}
	return array( 'ok' => true, 'error' => '', 'parsed' => $parsed );
}

/** The stored raw JSON, or '' when unset. Internal — callers want the helpers below. */
function snt_gsc_credential_raw() {
	return (string) sn_setting( SNT_GSC_CREDENTIAL_PATH, '' );
}

/**
 * The decoded credential, or null when unset/invalid.
 *
 * Returns the WHOLE array including `private_key` — this is the signing path's
 * accessor and the only one that hands the key back. Anything rendering to a
 * screen wants snt_gsc_credential_identity() instead.
 *
 * @return array|null
 */
function snt_gsc_credential() {
	$raw = snt_gsc_credential_raw();
	if ( '' === $raw ) {
		return null;
	}
	$check = snt_gsc_credential_validate( $raw );
	return $check['ok'] ? $check['parsed'] : null;
}

/** True when a credential is stored AND still parses. */
function snt_gsc_credential_is_configured() {
	return null !== snt_gsc_credential();
}

/**
 * The non-secret identity card for the settings screen.
 *
 * NEVER returns `private_key`. `key_fingerprint` is a truncated hash so the
 * owner can tell one uploaded key from another — and so a replaced credential
 * is visibly different — without the value ever reaching a template.
 *
 * `signing_ready` is here because a valid credential is still useless without
 * openssl_sign(): the JWT grant is RS256 and the plugin carries no composer
 * dependencies. Reporting it beside the credential means the arc's next step
 * cannot fail for a reason the screen already knew.
 *
 * @return array|null Null when nothing is configured.
 */
function snt_gsc_credential_identity() {
	$cred = snt_gsc_credential();
	if ( null === $cred ) {
		return null;
	}
	return array(
		'client_email'    => (string) $cred['client_email'],
		'project_id'      => (string) $cred['project_id'],
		'private_key_id'  => (string) $cred['private_key_id'],
		'key_fingerprint' => 'sha256:' . substr( hash( 'sha256', (string) $cred['private_key'] ), 0, 12 ),
		'token_uri'       => (string) ( $cred['token_uri'] ?? 'https://oauth2.googleapis.com/token' ),
		'signing_ready'   => function_exists( 'openssl_sign' ),
	);
}

/**
 * Human-readable reason for a rejected paste.
 *
 * @param string $code From snt_gsc_credential_validate()['error'].
 * @return string
 */
function snt_gsc_credential_error_text( $code ) {
	$map = array(
		'empty'                  => __( 'Nothing was pasted.', 'signal-and-noise-tools' ),
		'not_json'               => __( 'That is not valid JSON. Paste the whole downloaded key file, including the outer braces.', 'signal-and-noise-tools' ),
		'not_service_account'    => __( 'That JSON is not a service-account key (its "type" is not "service_account"). An OAuth client JSON looks similar and cannot be used here.', 'signal-and-noise-tools' ),
		'private_key_not_pem'    => __( 'The "private_key" field is not a PEM block. The pasted JSON may have been reformatted or truncated.', 'signal-and-noise-tools' ),
		'client_email_malformed' => __( 'The "client_email" field is not an email address.', 'signal-and-noise-tools' ),
	);
	if ( isset( $map[ $code ] ) ) {
		return $map[ $code ];
	}
	if ( 0 === strpos( (string) $code, 'missing_' ) ) {
		/* translators: %s: the missing JSON field name. */
		return sprintf( __( 'The pasted JSON is missing the "%s" field.', 'signal-and-noise-tools' ), substr( (string) $code, 8 ) );
	}
	return __( 'The pasted credential was rejected.', 'signal-and-noise-tools' );
}
