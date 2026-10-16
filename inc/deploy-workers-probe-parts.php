<?php
/**
 * Signal & Noise Tools: deploy-workers.php, the live probe and the tag-
 * staleness and state verdicts.
 *
 * Split from inc/deploy-workers.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Live-probe one worker. Cache-backed. Returns normalized probe result:
 *   { ok:bool, live:string, commit:string, error:string }
 * live is "unprobeable" when the registry has no probe_url (null).
 *
 * @param string               $id    Registry key.
 * @param array<string,mixed>  $cfg   Registry entry.
 * @param bool                 $force Bypass cache.
 * @return array{ok:bool,live:string,commit:string,error:string}
 */
function snt_deploy_worker_live_probe( $id, array $cfg, $force = false ) {
	$id  = (string) $id;
	$key = 'snt_dw_live_' . preg_replace( '/[^a-z0-9_-]/i', '', $id );

	if ( ! $force ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) && array_key_exists( 'live', $cached ) ) {
			return $cached;
		}
	}

	// Explicit null probe_url = no public version route. Honest unprobeable.
	if ( array_key_exists( 'probe_url', $cfg ) && null === $cfg['probe_url'] ) {
		$result = array(
			'ok'     => false,
			'live'   => 'unprobeable',
			'commit' => '',
			'error'  => 'unprobeable',
		);
		set_transient( $key, $result, SNT_DEPLOY_WORKER_LIVE_TTL_OK );
		return $result;
	}

	$url = isset( $cfg['probe_url'] ) ? (string) $cfg['probe_url'] : '';
	if ( '' === $url ) {
		$result = array(
			'ok'     => false,
			'live'   => '',
			'commit' => '',
			'error'  => 'no-endpoint',
		);
		// Don't cache hard — config may appear mid-session.
		set_transient( $key, $result, SNT_DEPLOY_WORKER_LIVE_TTL_FAIL );
		return $result;
	}

	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( ! wp_http_validate_url( $url )
		|| 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
		|| ( function_exists( 'sn_ssrf_host_blocked' ) && sn_ssrf_host_blocked( $host ) )
	) {
		$result = array(
			'ok'     => false,
			'live'   => '',
			'commit' => '',
			'error'  => 'blocked',
		);
		set_transient( $key, $result, SNT_DEPLOY_WORKER_LIVE_TTL_FAIL );
		return $result;
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => SNT_DEPLOY_WORKER_TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : 'dev' ) . ' deploy-workers',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		$result = array(
			'ok'     => false,
			'live'   => '',
			'commit' => '',
			'error'  => 'network',
		);
		set_transient( $key, $result, SNT_DEPLOY_WORKER_LIVE_TTL_FAIL );
		return $result;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	// 503 is accepted for status endpoints that report degraded state with a body.
	if ( 200 !== $code && 503 !== $code ) {
		$result = array(
			'ok'     => false,
			'live'   => '',
			'commit' => '',
			'error'  => 'http-' . $code,
		);
		set_transient( $key, $result, SNT_DEPLOY_WORKER_LIVE_TTL_FAIL );
		return $result;
	}

	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $json ) ) {
		$result = array(
			'ok'     => false,
			'live'   => '',
			'commit' => '',
			'error'  => 'bad-response',
		);
		set_transient( $key, $result, SNT_DEPLOY_WORKER_LIVE_TTL_FAIL );
		return $result;
	}

	// Standing zone memory: /_sn/* paths can answer as the wrong worker.
	// Refuse a body whose worker identity does not match the registry.
	$expected = isset( $cfg['worker_id'] ) ? (string) $cfg['worker_id'] : '';
	$got      = isset( $json['worker'] ) ? (string) $json['worker'] : '';
	if ( '' !== $expected && '' !== $got && $expected !== $got ) {
		$result = array(
			'ok'     => false,
			'live'   => '',
			'commit' => '',
			'error'  => 'wrong-worker',
		);
		set_transient( $key, $result, SNT_DEPLOY_WORKER_LIVE_TTL_FAIL );
		return $result;
	}

	$version_path = isset( $cfg['version_path'] ) ? (string) $cfg['version_path'] : 'version';
	$commit_path  = array_key_exists( 'commit_path', $cfg ) ? $cfg['commit_path'] : 'source_commit';
	$raw_version  = snt_deploy_json_path( $json, $version_path );
	$raw_commit   = ( null === $commit_path || '' === $commit_path )
		? null
		: snt_deploy_json_path( $json, (string) $commit_path );

	$live = '';
	if ( is_scalar( $raw_version ) && null !== $raw_version && '' !== (string) $raw_version ) {
		$live = ltrim( (string) $raw_version, 'v' );
	}
	$commit = is_scalar( $raw_commit ) && null !== $raw_commit ? (string) $raw_commit : '';

	// Reachable but no version field → unknown, not ok.
	$result = array(
		'ok'     => '' !== $live,
		'live'   => $live,
		'commit' => $commit,
		'error'  => '' !== $live ? '' : 'no-version',
	);
	// versioned-contract phase 2 (inc/mcp/mcp-remote-contract.php): for a
	// worker whose registry entry names a contract_path, carry the live
	// contract version and its comparison against the plugin's declared
	// mirror. The fields are ABSENT for every other worker — absence, not
	// null-zeros — and this is a DECLARATION, never a gate: cross-repo skew
	// lands at INSTALL time, so it is made observable here rather than
	// refused anywhere.
	if ( isset( $cfg['contract_path'] ) && '' !== (string) $cfg['contract_path'] && defined( 'SN_REMOTE_CONTRACT_VERSION' ) ) {
		$raw_contract  = snt_deploy_json_path( $json, (string) $cfg['contract_path'] );
		$contract_live = is_scalar( $raw_contract ) && null !== $raw_contract ? (string) $raw_contract : '';
		$result['contract_live']     = $contract_live;
		$result['contract_expected'] = (string) SN_REMOTE_CONTRACT_VERSION;
		$result['contract_match']    = '' !== $contract_live && $contract_live === (string) SN_REMOTE_CONTRACT_VERSION;
	}
	set_transient(
		$key,
		$result,
		$result['ok'] ? SNT_DEPLOY_WORKER_LIVE_TTL_OK : SNT_DEPLOY_WORKER_LIVE_TTL_FAIL
	);
	// A read version stamps the worker's deploy time when it is new
	// (inc/deploy-workers-seen.php). Only fresh reads reach here, never a cache hit.
	if ( $result['ok'] && function_exists( 'snt_deploy_workers_seen_note' ) ) {
		snt_deploy_workers_seen_note( $id, $live );
	}
	return $result;
}

/**
 * Whether the cached newest tag is older than the version actually running.
 * PURE. Only a real version pair counts: '' or "unprobeable" is not a newer
 * live version, it is no reading.
 *
 * @param string $live   Probed version.
 * @param string $latest Cached newest tag (no leading v).
 * @return bool
 */
function snt_deploy_worker_tag_is_stale( $live, $latest ) {
	$live   = (string) $live;
	$latest = (string) $latest;
	return '' !== $live && 'unprobeable' !== $live && '' !== $latest && version_compare( $live, $latest, '>' );
}

/**
 * Compare live vs latest into ok | behind | unknown.
 *
 * @param string $live   Probed version, "unprobeable", or ''.
 * @param string $latest Latest tag version (no leading v), or ''.
 * @return string
 */
function snt_deploy_worker_state( $live, $latest ) {
	$live   = (string) $live;
	$latest = (string) $latest;
	if ( '' === $live || 'unprobeable' === $live || '' === $latest ) {
		return 'unknown';
	}
	if ( version_compare( $live, $latest, '<' ) ) {
		return 'behind';
	}
	return 'ok';
}

