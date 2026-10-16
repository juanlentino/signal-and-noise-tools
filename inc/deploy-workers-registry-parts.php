<?php
/**
 * Signal & Noise Tools: deploy-workers.php, the worker registry, the JSON path
 * reader and the latest-tag fetch and staleness check.
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
 * Registry of owned Cloudflare workers on the Deploy Status surface.
 *
 * Filterable via `snt_deploy_workers_registry`. Each entry:
 *   - label         Display name (glance card / ability).
 *   - repo          GitHub owner/repo for tag lookup.
 *   - probe_url     Absolute HTTPS URL, or null when the worker has no public
 *                   version/status route (live becomes "unprobeable").
 *   - version_path  Dot-path into the JSON body for the semver (e.g. "version").
 *   - commit_path   Dot-path for source_commit, or null when absent.
 *   - worker_id     Expected body.worker identity (wrong identity = probe fail).
 *
 * @return array<string,array<string,mixed>>
 */
function snt_deploy_workers_registry() {
	$analytics = '';
	if ( function_exists( 'sn_worker_version_endpoint_url' ) ) {
		$analytics = (string) sn_worker_version_endpoint_url();
	}
	if ( '' === $analytics ) {
		$analytics = 'https://juanlentino.com/_sn/version';
	}

	$provenance = '';
	if ( function_exists( 'sn_prov_worker_url' ) ) {
		$base = (string) sn_prov_worker_url();
		if ( '' !== $base ) {
			$provenance = untrailingslashit( $base ) . '/_sn/version';
		}
	}

	$login_guard = '';
	if ( function_exists( 'sn_login_defense_status_url' ) ) {
		$login_guard = (string) sn_login_defense_status_url();
	}
	if ( '' === $login_guard ) {
		$login_guard = 'https://juanlentino.com/_sn/login-guard/status';
	}

	$defaults = array(
		'sn-analytics'      => array(
			'label'        => 'Analytics',
			'repo'         => 'juanlentino/signal-and-noise-analytics-worker',
			'probe_url'    => $analytics,
			'version_path' => 'version',
			'commit_path'  => 'source_commit',
			'worker_id'    => 'sn-analytics',
		),
		'sn-provenance'     => array(
			// Distinct from the Dashboard "Provenance" anchor glance card.
			'label'        => 'Provenance edge',
			'repo'         => 'juanlentino/sn-provenance-worker',
			// Empty string when SN_PROV_WORKER_URL is unset: route exists on the
			// workers.dev host, but we have no base to probe — state unknown,
			// not "unprobeable" (that word is reserved for no version route).
			'probe_url'    => '' !== $provenance ? $provenance : '',
			'version_path' => 'version',
			'commit_path'  => 'source_commit',
			'worker_id'    => 'sn-provenance',
		),
		'sn-login-guard'    => array(
			'label'        => 'Login guard',
			'repo'         => 'juanlentino/signal-and-noise-login-guard-worker',
			'probe_url'    => $login_guard,
			'version_path' => 'version',
			'commit_path'  => 'source_commit',
			'worker_id'    => 'sn-login-guard',
		),
		'sn-remote-mcp'     => array(
			// 2026.10.4 audit: AI > MCP Clients calls this worker the remote
			// door; the Systems chip and the Deploy Status row say the same.
			'label'        => 'Remote door',
			'repo'         => 'juanlentino/sn-remote-mcp-worker',
			'probe_url'    => 'https://juanlentino.com/_sn/remote-mcp/status',
			// versioned-contract phase 2: /status.contract_version is compared
			// against SN_REMOTE_CONTRACT_VERSION by the live probe.
			'contract_path' => 'contract_version',
			'version_path' => 'version',
			'commit_path'  => 'source_commit',
			'worker_id'    => 'sn-remote-mcp',
		),
		'sn-rights-signals' => array(
			'label'        => 'Rights signals',
			'repo'         => 'juanlentino/sn-rights-signals-worker',
			'probe_url'    => defined( 'SN_MR_VERSION_ENDPOINT' )
				? SN_MR_VERSION_ENDPOINT
				: 'https://juanlentino.com/_sn/rights-signals/version',
			'version_path' => 'version',
			'commit_path'  => 'source_commit',
			'worker_id'    => 'sn-rights-signals',
		),
	);

	$registry = apply_filters( 'snt_deploy_workers_registry', $defaults );
	return is_array( $registry ) ? $registry : $defaults;
}

/**
 * Read a dotted path from a decoded JSON array. Returns null when any segment
 * is missing or the leaf is null.
 *
 * @param array  $data Decoded JSON.
 * @param string $path Dot-separated path (e.g. "version" or "config.foo").
 * @return mixed|null
 */
function snt_deploy_json_path( $data, $path ) {
	if ( ! is_array( $data ) || ! is_string( $path ) || '' === $path ) {
		return null;
	}
	$cur = $data;
	foreach ( explode( '.', $path ) as $seg ) {
		if ( ! is_array( $cur ) || ! array_key_exists( $seg, $cur ) ) {
			return null;
		}
		$cur = $cur[ $seg ];
	}
	return $cur;
}

/**
 * Highest vX.Y.Z tag for a worker repo. Cached; null on failure / no tags.
 * Mirrors sn_gh_latest_plugin_tag() — tags, never /releases/latest.
 *
 * @param string $repo  owner/repo.
 * @param string $cache_id Registry key (stable transient suffix).
 * @param bool   $force Bypass cache.
 * @return string|null Tag without leading "v", or null.
 */
function snt_deploy_worker_latest_tag( $repo, $cache_id, $force = false ) {
	$repo     = (string) $repo;
	$cache_id = preg_replace( '/[^a-z0-9_-]/i', '', (string) $cache_id );
	if ( '' === $repo || '' === $cache_id ) {
		return null;
	}

	$key = 'snt_dw_tag_' . $cache_id;
	if ( ! $force ) {
		$cached = get_site_transient( $key );
		if ( false !== $cached ) {
			return '' === $cached ? null : (string) $cached;
		}
	}

	$url     = 'https://api.github.com/repos/' . $repo . '/tags?per_page=100';
	$headers = array(
		'Accept'     => 'application/vnd.github+json',
		'User-Agent' => 'WordPress; ' . ( function_exists( 'home_url' ) ? home_url() : 'signal-and-noise-tools' ),
	);
	if ( defined( 'SNT_GITHUB_TOKEN' ) && SNT_GITHUB_TOKEN ) {
		$headers['Authorization'] = 'Bearer ' . SNT_GITHUB_TOKEN;
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => SNT_DEPLOY_WORKER_TIMEOUT,
			'headers'     => $headers,
			'redirection' => 0,
		)
	);
	$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return snt_deploy_worker_tag_stale( $key, $cache_id );
	}

	$tags = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $tags ) ) {
		return snt_deploy_worker_tag_stale( $key, $cache_id );
	}

	$highest = '';
	foreach ( $tags as $tag ) {
		$name = isset( $tag['name'] ) ? (string) $tag['name'] : '';
		if ( ! preg_match( '/^v\d+\.\d+\.\d+$/', $name ) ) {
			continue;
		}
		$plain = ltrim( $name, 'v' );
		if ( '' === $highest || version_compare( $plain, $highest, '>' ) ) {
			$highest = $plain;
		}
	}

	// Empty sentinel = "fetched, nothing usable" (distinct from a cache miss).
	// Only a POSITIVE 200-with-no-matching-tags may write it — a transport
	// failure goes through snt_deploy_worker_tag_stale() instead, so a known
	// tag is never demoted to "no GitHub tag" by an outage or rate limit
	// (learned live 2026-08-17: all five workers went red during the GitHub
	// incident minutes after their tags were pushed).
	set_site_transient( $key, $highest, '' === $highest ? SNT_DEPLOY_WORKER_LIVE_TTL_FAIL : SNT_DEPLOY_WORKER_TAG_TTL );
	if ( '' !== $highest ) {
		update_option( 'snt_dw_tag_good_' . $cache_id, $highest, false );
	}
	return '' === $highest ? null : $highest;
}

/**
 * Failure path for the tag lookup: serve the last tag a SUCCESSFUL fetch
 * recorded, re-cached briefly so the next render retries GitHub. Returns
 * null only when no good value has ever been seen.
 *
 * @param string $key      Transient key for this worker's tag cache.
 * @param string $cache_id Sanitized registry key.
 * @return string|null
 */
function snt_deploy_worker_tag_stale( $key, $cache_id ) {
	$good = get_option( 'snt_dw_tag_good_' . $cache_id, '' );
	$good = is_string( $good ) ? $good : '';
	set_site_transient( $key, $good, SNT_DEPLOY_WORKER_LIVE_TTL_FAIL );
	return '' === $good ? null : $good;
}

