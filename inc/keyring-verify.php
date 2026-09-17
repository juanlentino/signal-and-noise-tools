<?php
/**
 * Signal & Noise Tools — the keyring's probes: one verdict per credential,
 * from the service that holds the other half.
 *
 * 15.2.0. "Verify all" runs every row's probe and stores the verdicts with
 * a timestamp, so after a rotation the leaf says which row is refused and by
 * WHICH SIDE, in words: a sensor 401 is "this side and the worker's secret
 * differ", a sensor 502 is "the worker's Cloudflare token lost Analytics
 * Engine". A row with no probe says so; it is never a pass. Read-only against
 * every service; the Cloudflare probe is the monitor's own verify.
 *
 * @package SignalNoiseTools
 * @since 15.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_KEYRING_VERDICTS_OPT = 'sn_keyring_verdicts';

/**
 * A verdict: status ok | refused | error | unset | none, and a sentence.
 *
 * @param string $status Status.
 * @param string $detail Sentence.
 * @return array{status:string,detail:string,at:int}
 */
function sn_keyring_verdict( $status, $detail = '' ) {
	return array( 'status' => (string) $status, 'detail' => (string) $detail, 'at' => time() );
}

/**
 * Run one row's probe.
 *
 * @param string              $id  Row id.
 * @param array<string,mixed> $row Registry row.
 * @return array{status:string,detail:string,at:int}
 */
function sn_keyring_probe( $id, array $row ) {
	if ( '' === sn_credential( $id ) ) {
		return sn_keyring_verdict( 'unset', __( 'Nothing to verify: no value is set.', 'signal-and-noise-tools' ) );
	}
	switch ( (string) ( $row['probe'] ?? '' ) ) {
		case 'cloudflare':
			return sn_keyring_probe_cloudflare();
		case 'cloudflare_token':
			// 15.3.1: any Cloudflare token that is not the central one (the analytics
			// override, the Workers AI token) verifies with its own bytes.
			return sn_keyring_probe_cloudflare( sn_credential( $id ) );
		case 'sensor':
			return sn_keyring_probe_sensor( $row );
		case 'betterstack':
			return sn_keyring_probe_betterstack();
		case 'spotify':
			return sn_keyring_probe_spotify();
		case 'github':
			return sn_keyring_probe_github();
		case 'zenodo':
			return sn_keyring_probe_zenodo( $id );
	}
	return sn_keyring_verdict( 'none', __( 'No probe for this credential; the tab that uses it is the witness.', 'signal-and-noise-tools' ) );
}

/**
 * @param string|null $token 15.2.2: null verifies the central token; a string verifies that one (the override).
 * @return array{status:string,detail:string,at:int}
 */
function sn_keyring_probe_cloudflare( $token = null ) {
	if ( ! function_exists( 'sn_cf_monitor_verify' ) ) {
		return sn_keyring_verdict( 'none', __( 'The Cloudflare monitor is not loaded.', 'signal-and-noise-tools' ) );
	}
	$t = sn_cf_monitor_verify( '', $token );
	if ( ! empty( $t['verified'] ) ) {
		/* translators: 1: status, 2: token kind. */
		return sn_keyring_verdict( 'ok', sprintf( __( 'Cloudflare answers %1$s (%2$s token).', 'signal-and-noise-tools' ), (string) $t['status'], '' !== (string) ( $t['kind'] ?? '' ) ? (string) $t['kind'] : __( 'unknown kind', 'signal-and-noise-tools' ) ) );
	}
	return sn_keyring_verdict( 'unreachable' === (string) ( $t['status'] ?? '' ) ? 'error' : 'refused', (string) ( $t['error'] ?: $t['status'] ) );
}

/**
 * The sensor, with the read token: its answer names the side that broke.
 *
 * @param array<string,mixed> $row Registry row.
 * @return array{status:string,detail:string,at:int}
 */
function sn_keyring_probe_sensor( array $row ) {
	$url = defined( 'SN_MR_WORKER_URL' ) && '' !== (string) SN_MR_WORKER_URL ? (string) SN_MR_WORKER_URL : ( function_exists( 'sn_setting' ) ? (string) sn_setting( 'machine_readers.worker_url', '' ) : '' );
	if ( '' === $url ) {
		$url = defined( 'SN_MR_DEFAULT_ENDPOINT' ) ? (string) SN_MR_DEFAULT_ENDPOINT : '';
	}
	if ( '' === $url ) {
		return sn_keyring_verdict( 'none', __( 'No sensor URL.', 'signal-and-noise-tools' ) );
	}
	$resp = wp_remote_get( $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'days=1', array( 'timeout' => 6, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'Authorization' => 'Bearer ' . sn_credential( 'mr_read_token' ), 'Accept' => 'application/json' ) ) );
	if ( is_wp_error( $resp ) ) {
		return sn_keyring_verdict( 'error', $resp->get_error_message() );
	}
	$code   = (int) wp_remote_retrieve_response_code( $resp );
	$secret = (string) ( $row['other_half']['secret'] ?? 'the worker\'s secret' );
	switch ( $code ) {
		case 200:
			return sn_keyring_verdict( 'ok', __( 'The sensor accepts this token and answered.', 'signal-and-noise-tools' ) );
		case 401:
			/* translators: %s: worker secret name. */
			return sn_keyring_verdict( 'refused', sprintf( __( 'The sensor refused it: this value and the worker\'s %s differ. Set the same value on both sides.', 'signal-and-noise-tools' ), $secret ) );
		case 502:
			return sn_keyring_verdict( 'error', __( 'The sensor accepts this token but Cloudflare refused its Analytics Engine query: the worker\'s SN_MR_SQL_TOKEN needs Account › Account Analytics › Read.', 'signal-and-noise-tools' ) );
		case 503:
			return sn_keyring_verdict( 'error', __( 'The sensor says it is not configured: its own secrets are missing.', 'signal-and-noise-tools' ) );
	}
	/* translators: %d: HTTP status. */
	return sn_keyring_verdict( 'error', sprintf( __( 'The sensor answered HTTP %d.', 'signal-and-noise-tools' ), $code ) );
}

/** @return array{status:string,detail:string,at:int} */
function sn_keyring_probe_betterstack() {
	if ( ! function_exists( 'sn_uptime_status_api_get' ) ) {
		return sn_keyring_verdict( 'none', __( 'The Uptime module is not loaded.', 'signal-and-noise-tools' ) );
	}
	$r = sn_uptime_status_api_get( 'v2/monitors' );
	if ( is_wp_error( $r ) ) {
		$msg = $r->get_error_message();
		return sn_keyring_verdict( false !== strpos( $msg, 'HTTP 401' ) || false !== strpos( $msg, 'HTTP 403' ) ? 'refused' : 'error', $msg );
	}
	/* translators: %d: monitors. */
	return sn_keyring_verdict( 'ok', sprintf( __( 'Better Stack answers: %d monitor(s).', 'signal-and-noise-tools' ), count( (array) ( $r['data'] ?? array() ) ) ) );
}

/** @return array{status:string,detail:string,at:int} */
function sn_keyring_probe_spotify() {
	if ( ! function_exists( 'sn_spotify_token' ) ) {
		return sn_keyring_verdict( 'none', __( 'The Spotify module is not loaded.', 'signal-and-noise-tools' ) );
	}
	if ( defined( 'SN_SPOTIFY_TOKEN_KEY' ) ) {
		delete_transient( SN_SPOTIFY_TOKEN_KEY ); // a cached token would pass a rotated secret.
	}
	return '' !== sn_spotify_token()
		? sn_keyring_verdict( 'ok', __( 'Spotify issued an access token for this client.', 'signal-and-noise-tools' ) )
		: sn_keyring_verdict( 'refused', __( 'Spotify did not issue a token: the client id and secret do not match, or the app is disabled.', 'signal-and-noise-tools' ) );
}

/** @return array{status:string,detail:string,at:int} */
function sn_keyring_probe_github() {
	$resp = wp_remote_get( 'https://api.github.com/user', array( 'timeout' => 6, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'Authorization' => 'Bearer ' . sn_credential( 'github_token' ), 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'signal-and-noise-tools' ) ) );
	if ( is_wp_error( $resp ) ) {
		return sn_keyring_verdict( 'error', $resp->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( 200 === $code ) {
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		/* translators: %s: GitHub login. */
		return sn_keyring_verdict( 'ok', sprintf( __( 'GitHub answers as %s.', 'signal-and-noise-tools' ), (string) ( $body['login'] ?? '?' ) ) );
	}
	/* translators: %d: HTTP status. */
	return sn_keyring_verdict( 401 === $code ? 'refused' : 'error', sprintf( __( 'GitHub answered HTTP %d.', 'signal-and-noise-tools' ), $code ) );
}

/**
 * 15.11.0: Zenodo answers a bearer GET on the depositions list with 200 and
 * names the environment that answered, so a sandbox token in the production
 * row (or the reverse) reads as refused, not as fine.
 *
 * @return array{status:string,detail:string,at:int}
 */
function sn_keyring_probe_zenodo( $id ) {
	$env  = 'zenodo_sandbox_token' === (string) $id ? 'sandbox' : 'production';
	$base = function_exists( 'sn_zenodo_api_base' ) ? sn_zenodo_api_base( $env ) : ( 'sandbox' === $env ? 'https://sandbox.zenodo.org/api' : 'https://zenodo.org/api' );
	$resp = wp_remote_get( $base . '/deposit/depositions?size=1', array( 'timeout' => 8, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'Authorization' => 'Bearer ' . sn_credential( $id ), 'Accept' => 'application/json', 'User-Agent' => 'signal-and-noise-tools' ) ) );
	if ( is_wp_error( $resp ) ) {
		return sn_keyring_verdict( 'error', $resp->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( 200 === $code ) {
		/* translators: %s: environment name. */
		return sn_keyring_verdict( 'ok', sprintf( __( 'Zenodo %s answers; the token can list depositions.', 'signal-and-noise-tools' ), $env ) );
	}
	/* translators: 1: environment name, 2: HTTP status. */
	return sn_keyring_verdict( in_array( $code, array( 401, 403 ), true ) ? 'refused' : 'error', sprintf( __( 'Zenodo %1$s answered HTTP %2$d.', 'signal-and-noise-tools' ), $env, $code ) );
}

/**
 * Run every probe and store the verdicts.
 *
 * @return array<string,array{status:string,detail:string,at:int}>
 */
function sn_keyring_verify_all() {
	$verdicts = array();
	foreach ( sn_keyring() as $id => $row ) {
		$verdicts[ $id ] = sn_keyring_probe( $id, $row );
	}
	update_option( SN_KEYRING_VERDICTS_OPT, $verdicts, false );
	return $verdicts;
}

/**
 * The stored verdicts, or an empty array when never run. Never probes.
 *
 * @return array<string,array{status:string,detail:string,at:int}>
 */
function sn_keyring_verdicts() {
	$v = get_option( SN_KEYRING_VERDICTS_OPT, array() );
	return is_array( $v ) ? $v : array();
}
