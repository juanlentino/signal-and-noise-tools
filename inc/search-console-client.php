<?php
/**
 * Signal & Noise Tools — the Google Search Console client (R6b step 1).
 *
 * NO LIBRARY. The plugin carries no composer dependencies and stays that way,
 * so the service-account flow is done by hand: build a JWT, sign it RS256 with
 * openssl_sign(), exchange it at the token endpoint for a bearer token.
 *
 * Verified against the current docs 2026-08-18 rather than from memory:
 *   token   POST https://oauth2.googleapis.com/token
 *           grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer & assertion=<JWT>
 *           header {alg:RS256, typ:JWT}; claims iss, scope, aud, exp, iat;
 *           exp is capped at ONE HOUR after iat.
 *   sites   GET  https://www.googleapis.com/webmasters/v3/sites
 *           scope https://www.googleapis.com/auth/webmasters.readonly
 *           -> { "siteEntry": [ { siteUrl, permissionLevel } ] }
 *
 * EVERY failure is a WP_Error naming the STAGE it failed at. A credential can
 * fail in five distinct places — no openssl, a key that will not sign, a token
 * endpoint that refuses the grant, a transport error, an API that answers 403
 * because nobody granted the service account the property — and "connection
 * failed" for all five sends the owner to the wrong fix.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SNT_GSC_SCOPE', 'https://www.googleapis.com/auth/webmasters.readonly' );
define( 'SNT_GSC_TOKEN_URL', 'https://oauth2.googleapis.com/token' );
define( 'SNT_GSC_API_BASE', 'https://www.googleapis.com/webmasters/v3' );
define( 'SNT_GSC_TOKEN_TRANSIENT', 'snt_gsc_access_token' );

/** base64url, per RFC 7515 §2 — '+/' become '-_' and padding is dropped. */
function snt_gsc_b64url( $bytes ) {
	return rtrim( strtr( base64_encode( (string) $bytes ), '+/', '-_' ), '=' );
}

/**
 * Build and sign the assertion JWT.
 *
 * @param array $cred A validated service-account array.
 * @param int   $now  Injected clock — the test fixture pins a known iat/exp.
 * @return string|WP_Error
 */
function snt_gsc_build_assertion( $cred, $now = null ) {
	if ( ! function_exists( 'openssl_sign' ) ) {
		return new WP_Error( 'snt_gsc_no_openssl', __( 'This host has no openssl_sign(), so an RS256 assertion cannot be signed. The credential is fine; the PHP build is not.', 'signal-and-noise-tools' ) );
	}
	$now = null === $now ? time() : (int) $now;
	// exp is capped at one hour by Google. 3600 exactly sits on the boundary and
	// any clock skew puts it over, so the assertion asks for slightly less.
	$header = array( 'alg' => 'RS256', 'typ' => 'JWT', 'kid' => (string) ( $cred['private_key_id'] ?? '' ) );
	$claims = array(
		'iss'   => (string) $cred['client_email'],
		'scope' => SNT_GSC_SCOPE,
		'aud'   => (string) ( $cred['token_uri'] ?? SNT_GSC_TOKEN_URL ),
		'iat'   => $now,
		'exp'   => $now + 3540,
	);
	$signing_input = snt_gsc_b64url( wp_json_encode( $header ) ) . '.' . snt_gsc_b64url( wp_json_encode( $claims ) );
	$signature     = '';
	$ok            = openssl_sign( $signing_input, $signature, (string) $cred['private_key'], OPENSSL_ALGO_SHA256 );
	if ( ! $ok || '' === $signature ) {
		// Reached when the PEM parses as text but not as a key. Deliberately does
		// NOT echo openssl_error_string() — it can quote key material.
		return new WP_Error( 'snt_gsc_sign_failed', __( 'The stored private key could not sign an assertion. Re-download the key from Google Cloud and paste it again.', 'signal-and-noise-tools' ) );
	}
	return $signing_input . '.' . snt_gsc_b64url( $signature );
}

/**
 * A bearer token, from cache when warm.
 *
 * The cache key carries the credential fingerprint, so replacing the credential
 * invalidates the token by construction rather than by remembering to purge.
 *
 * @param bool $force Skip the cache (the Test-connection button passes true).
 * @return string|WP_Error
 */
function snt_gsc_access_token( $force = false ) {
	$cred = snt_gsc_credential();
	if ( null === $cred ) {
		return new WP_Error( 'snt_gsc_not_configured', __( 'No Search Console credential is stored.', 'signal-and-noise-tools' ) );
	}
	$fingerprint = substr( hash( 'sha256', (string) $cred['private_key'] ), 0, 12 );
	$key         = SNT_GSC_TOKEN_TRANSIENT . '_' . $fingerprint;
	if ( ! $force ) {
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
	}
	$assertion = snt_gsc_build_assertion( $cred );
	if ( is_wp_error( $assertion ) ) {
		return $assertion;
	}
	$res = wp_remote_post(
		(string) ( $cred['token_uri'] ?? SNT_GSC_TOKEN_URL ),
		array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $assertion,
			),
		)
	);
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'snt_gsc_token_transport', sprintf( /* translators: %s: transport error. */ __( 'Could not reach Google to exchange the credential: %s', 'signal-and-noise-tools' ), $res->get_error_message() ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $body ) || empty( $body['access_token'] ) ) {
		// Google's own error/error_description is the most useful text there is
		// here ("invalid_grant" on a clock skew, "unauthorized_client" on a
		// disabled account), and it never contains key material.
		$detail = is_array( $body ) ? trim( (string) ( $body['error'] ?? '' ) . ' ' . (string) ( $body['error_description'] ?? '' ) ) : '';
		return new WP_Error(
			'snt_gsc_token_refused',
			'' !== $detail
				/* translators: 1: HTTP status, 2: Google's error text. */
				? sprintf( __( 'Google refused the credential (HTTP %1$d): %2$s', 'signal-and-noise-tools' ), $code, $detail )
				/* translators: %d: HTTP status. */
				: sprintf( __( 'Google refused the credential (HTTP %d) with no explanation.', 'signal-and-noise-tools' ), $code )
		);
	}
	$token   = (string) $body['access_token'];
	$expires = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
	// Expire the cache a minute early so a token is never spent on its last breath.
	set_transient( $key, $token, max( 60, $expires - 60 ) );
	return $token;
}

/**
 * Turn a 403 into a message that reports what Google SAID, not what I guessed.
 *
 * The first cut asserted one cause — "the service account is almost certainly
 * not a user on the property" — and shipped it as the whole message. It was
 * wrong on the first real credential: the account HAD been added with Full
 * permission and the 403 persisted, because a 403 here has at least three
 * distinct causes and only Google knows which:
 *
 *   1. The Search Console API is not ENABLED in the Cloud project. Google
 *      answers PERMISSION_DENIED / SERVICE_DISABLED and includes an activation
 *      URL. Nothing about the property or the key is wrong.
 *   2. The service account is genuinely not a user on the property.
 *   3. It was added moments ago and the grant has not propagated yet.
 *
 * Google's own `error.message` distinguishes them and contains no secret, so it
 * leads. My hypotheses follow it, as hypotheses.
 *
 * @param array|null $data Decoded error body, if any.
 * @return WP_Error
 */
function snt_gsc_forbidden_error( $data ) {
	$message = is_array( $data ) ? trim( (string) ( $data['error']['message'] ?? '' ) ) : '';
	$reason  = '';
	if ( is_array( $data ) ) {
		foreach ( (array) ( $data['error']['details'] ?? array() ) as $detail ) {
			if ( is_array( $detail ) && ! empty( $detail['reason'] ) ) {
				$reason = (string) $detail['reason'];
				break;
			}
		}
		if ( '' === $reason ) {
			$reason = (string) ( $data['error']['errors'][0]['reason'] ?? '' );
		}
	}

	// The one cause with a precise, actionable remedy that is NOT about the
	// property at all — worth naming outright when Google says it.
	$disabled = ( 'SERVICE_DISABLED' === $reason )
		|| ( '' !== $message && false !== stripos( $message, 'has not been used in project' ) )
		|| ( '' !== $message && false !== stripos( $message, 'is disabled' ) );

	if ( $disabled ) {
		return new WP_Error(
			'snt_gsc_api_disabled',
			sprintf(
				/* translators: %s: Google's own error message, which includes an activation link. */
				__( 'The Search Console API is not enabled for this credential\'s Google Cloud project. This is not a property-permission problem — enable "Google Search Console API" in that project, wait a minute, then test again. Google said: %s', 'signal-and-noise-tools' ),
				$message
			)
		);
	}

	$lead = '' !== $message
		/* translators: %s: Google's own error message. */
		? sprintf( __( 'Google refused the data (403). Google said: %s', 'signal-and-noise-tools' ), $message )
		: __( 'Google refused the data (403) without explaining why.', 'signal-and-noise-tools' );

	return new WP_Error(
		'snt_gsc_forbidden',
		$lead . ' ' . __( 'The usual causes, in order: the Search Console API is not enabled in the credential\'s Cloud project; the service account is not a user on the property; or it was added moments ago and the grant has not propagated yet — that can take a few minutes.', 'signal-and-noise-tools' )
	);
}

/**
 * An authorized GET against the Search Console API.
 *
 * @param string $path Path under SNT_GSC_API_BASE, e.g. '/sites'.
 * @param bool   $force_token Mint a fresh token first.
 * @return array|WP_Error Decoded body.
 */
function snt_gsc_api_get( $path, $force_token = false ) {
	$token = snt_gsc_access_token( $force_token );
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$res = wp_remote_get(
		SNT_GSC_API_BASE . $path,
		array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		)
	);
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'snt_gsc_api_transport', sprintf( /* translators: %s: transport error. */ __( 'Could not reach the Search Console API: %s', 'signal-and-noise-tools' ), $res->get_error_message() ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 403 === $code ) {
		return snt_gsc_forbidden_error( $body );
	}
	if ( 200 !== $code || ! is_array( $body ) ) {
		$detail = is_array( $body ) ? (string) ( $body['error']['message'] ?? '' ) : '';
		return new WP_Error(
			'snt_gsc_api_error',
			'' !== $detail
				/* translators: 1: HTTP status, 2: API error message. */
				? sprintf( __( 'Search Console API error (HTTP %1$d): %2$s', 'signal-and-noise-tools' ), $code, $detail )
				/* translators: %d: HTTP status. */
				: sprintf( __( 'Search Console API returned HTTP %d.', 'signal-and-noise-tools' ), $code )
		);
	}
	return $body;
}

/**
 * The properties this service account can actually read.
 *
 * This is the honest first thing to show after a credential is pasted: it
 * exercises the whole chain (sign → token → API) and answers the question the
 * next step needs anyway — WHICH property to query. Guessing the property from
 * the site URL would be a guess; a domain property and a URL-prefix property
 * for the same site are different strings and only one may be granted.
 *
 * @param bool $force Mint a fresh token.
 * @return array|WP_Error List of ['siteUrl' => string, 'permissionLevel' => string].
 */
function snt_gsc_list_sites( $force = false ) {
	$body = snt_gsc_api_get( '/sites', $force );
	if ( is_wp_error( $body ) ) {
		return $body;
	}
	$out = array();
	foreach ( (array) ( $body['siteEntry'] ?? array() ) as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['siteUrl'] ) ) {
			continue;
		}
		$out[] = array(
			'siteUrl'         => (string) $entry['siteUrl'],
			'permissionLevel' => (string) ( $entry['permissionLevel'] ?? 'unknown' ),
		);
	}
	// An EMPTY list is a real answer, not an error: the credential works and the
	// account has been granted nothing. The caller must be able to tell that
	// apart from a failure, so it is [] and not a WP_Error.
	return $out;
}

/**
 * An authorized POST against the Search Console API.
 *
 * @param string $path Path under SNT_GSC_API_BASE.
 * @param array  $body Request body, JSON-encoded.
 * @return array|WP_Error Decoded body.
 */
function snt_gsc_api_post( $path, $body, $timeout = 30 ) {
	$token = snt_gsc_access_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	// v13.63.0: an absolute https URL passes through unchanged — the URL
	// Inspection API lives on searchconsole.googleapis.com, not the v3 base.
	$res = wp_remote_post(
		0 === strpos( (string) $path, 'https://' ) ? (string) $path : SNT_GSC_API_BASE . $path,
		array(
			'timeout' => max( 1, (int) $timeout ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'snt_gsc_api_transport', sprintf( /* translators: %s: transport error. */ __( 'Could not reach the Search Console API: %s', 'signal-and-noise-tools' ), $res->get_error_message() ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 403 === $code ) {
		return snt_gsc_forbidden_error( $data );
	}
	if ( 200 !== $code || ! is_array( $data ) ) {
		$detail = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
		return new WP_Error(
			'snt_gsc_api_error',
			'' !== $detail
				/* translators: 1: HTTP status, 2: API error message. */
				? sprintf( __( 'Search Console API error (HTTP %1$d): %2$s', 'signal-and-noise-tools' ), $code, $detail )
				/* translators: %d: HTTP status. */
				: sprintf( __( 'Search Console API returned HTTP %d.', 'signal-and-noise-tools' ), $code )
		);
	}
	return $data;
}

/**
 * GSC reports in PACIFIC TIME, the analytics rollups in UTC.
 *
 * Not a rounding detail: for several hours a day "today" is a different date in
 * the two systems, and a range built from UTC dates asks Google for a window it
 * does not have. Every date this module sends is derived here.
 *
 * Data also LAGS: the most recent 2-3 days are incomplete or absent, so a range
 * ending "today" reports a cliff that looks like a traffic collapse. The default
 * window therefore ends `$lag_days` back — a reported zero for yesterday is not
 * a measurement, it is Google not having finished counting.
 *
 * @param int $days     Window length.
 * @param int $lag_days How far back the window ENDS.
 * @return array{start:string,end:string}
 */
function snt_gsc_window( $days = 28, $lag_days = 3 ) {
	$pt  = new DateTimeZone( 'America/Los_Angeles' );
	$end = new DateTime( 'now', $pt );
	$end->modify( '-' . max( 0, (int) $lag_days ) . ' days' );
	$start = clone $end;
	$start->modify( '-' . max( 1, (int) $days - 1 ) . ' days' );
	return array( 'start' => $start->format( 'Y-m-d' ), 'end' => $end->format( 'Y-m-d' ) );
}

/**
 * One searchAnalytics query.
 *
 * UNITS, because both are easy to get silently wrong:
 *   ctr      is a FRACTION 0..1, not a percentage. Rendering it as "3.2" when
 *            Google said 0.032 is a 100x error that looks plausible.
 *   position is an AVERAGE and LOWER IS BETTER. Sorting it descending puts the
 *            worst rankings on top of a "best pages" table.
 *
 * @param string $property   The property string, e.g. 'sc-domain:example.com'.
 * @param array  $dimensions e.g. array( 'page' ) or array( 'query' ).
 * @param array  $window     From snt_gsc_window().
 * @param int    $row_limit  1..25000 (API max); default 1000 at the API.
 * @return array|WP_Error List of ['key'=>string,'clicks'=>int,'impressions'=>int,'ctr'=>float,'position'=>float].
 */
function snt_gsc_query( $property, $dimensions, $window, $row_limit = 250 ) {
	$property = (string) $property;
	if ( '' === $property ) {
		return new WP_Error( 'snt_gsc_no_property', __( 'No Search Console property is selected.', 'signal-and-noise-tools' ) );
	}
	// The property is a PATH segment and both forms contain characters that must
	// be escaped: 'sc-domain:example.com' has a colon, a URL-prefix property has
	// slashes. rawurlencode, not urlencode — the latter turns spaces into '+'.
	$path = '/sites/' . rawurlencode( $property ) . '/searchAnalytics/query';
	$body = array(
		'startDate'  => (string) $window['start'],
		'endDate'    => (string) $window['end'],
		'dimensions' => array_values( (array) $dimensions ),
		'rowLimit'   => max( 1, min( 25000, (int) $row_limit ) ),
		'type'       => 'web',
	);
	$data = snt_gsc_api_post( $path, $body );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$rows = array();
	foreach ( (array) ( $data['rows'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['keys'][0] ) ) {
			continue;
		}
		$rows[] = array(
			'key'         => (string) $row['keys'][0],
			'clicks'      => (int) round( (float) ( $row['clicks'] ?? 0 ) ),
			'impressions' => (int) round( (float) ( $row['impressions'] ?? 0 ) ),
			'ctr'         => (float) ( $row['ctr'] ?? 0 ),
			'position'    => (float) ( $row['position'] ?? 0 ),
		);
	}
	// An empty `rows` is a real answer — a property with no search traffic in the
	// window — and must not read as a failure.
	return $rows;
}
