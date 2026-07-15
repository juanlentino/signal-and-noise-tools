<?php
/**
 * Signal & Noise Tools — GitHub Actions runs API wrapper.
 *
 * Thin `wp_remote_get` wrapper for the workflow-scoped runs endpoint:
 *   GET /repos/<owner>/<repo>/actions/workflows/deploy.yml/runs?per_page=N
 *
 * Returns normalized run records (small structs) to keep transients
 * cheap. Cached for SNT_GH_RUNS_CACHE_TTL; empty-sentinel cached on
 * failure to keep retry-storm risk low.
 *
 * Honors SNT_GITHUB_TOKEN constant for authenticated requests
 * (5000/h limit vs. 60/h unauthenticated). Define the constant in
 * wp-config.php to enable.
 *
 * Added in v1.12.0 (2026-05-16) for the deploy-status widget.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_GH_RUNS_CACHE_KEY_PREFIX = 'sn_gh_recent_runs_';
// v1.15.2: bumped 60s → 5min after hitting 53/60 GitHub API rate limit on
// the unauthenticated 60/h tier. ETag-based conditional requests (added in
// v1.15.2 as the real fix) make the practical TTL essentially infinite for
// the no-new-deploys case — a 304 response refreshes the cache without
// consuming quota. The 5min floor is just for the "data DID change, but
// we don't need to know that fast" case. Define SNT_GITHUB_TOKEN in
// wp-config.php to raise the bucket 60/h → 5000/h if poll cadence ever
// needs to be more aggressive.
const SNT_GH_RUNS_CACHE_TTL        = 5 * MINUTE_IN_SECONDS;
const SNT_GH_RUNS_FAIL_TTL         = 5 * MINUTE_IN_SECONDS;

/**
 * Fetch the last $count workflow runs for `deploy.yml` in the given
 * `owner/repo`. Returns array of normalized run records, or empty
 * array on error/cache-miss-failure.
 *
 * Each run record:
 *   [
 *     'id'           => int,
 *     'status'       => 'success'|'failure'|'cancelled'|'in_progress'|...,
 *     'conclusion'   => string|null,
 *     'ref'          => string (e.g. 'v8.5.3' or 'main'),
 *     'trigger'      => 'push'|'workflow_dispatch'|...,
 *     'created_at'   => string (ISO 8601),
 *     'duration_s'   => int|null,
 *     'html_url'     => string,
 *   ]
 *
 * @param string $repo  GitHub "owner/repo" string.
 * @param int    $count Number of runs to fetch (1-30; capped).
 * @return array
 */
function snt_gh_recent_runs( $repo, $count = 5 ) {
	if ( ! is_string( $repo ) || strpos( $repo, '/' ) === false ) {
		return array();
	}
	$count = max( 1, min( 30, (int) $count ) );

	$cache_key = SNT_GH_RUNS_CACHE_KEY_PREFIX . sanitize_key( str_replace( '/', '-', $repo ) );
	$cached    = get_site_transient( $cache_key );

	// v1.15.2: cache shape upgraded to { data, etag, fetched_at } to
	// support ETag conditional requests. Pre-v1.15.2 cached values are
	// flat arrays of records — handle both shapes during the transition.
	$cached_data = null;
	$cached_etag = '';
	if ( is_array( $cached ) ) {
		if ( isset( $cached['data'] ) && is_array( $cached['data'] ) && array_key_exists( 'etag', $cached ) ) {
			$cached_data = $cached['data'];
			$cached_etag = (string) $cached['etag'];
		} else {
			// Legacy v1.12-v1.15.1 flat shape — treat as data, no ETag.
			$cached_data = $cached;
		}
	}

	// Live cache hit before TTL expiry — return immediately, no request.
	if ( $cached_data !== null && $cached !== false ) {
		return $cached_data;
	}

	$url     = sprintf(
		'https://api.github.com/repos/%s/actions/workflows/deploy.yml/runs?per_page=%d&exclude_pull_requests=true',
		$repo,
		$count
	);
	$headers = array(
		'Accept'     => 'application/vnd.github+json',
		'User-Agent' => 'WordPress; ' . home_url(),
	);
	if ( defined( 'SNT_GITHUB_TOKEN' ) && SNT_GITHUB_TOKEN ) {
		$headers['Authorization'] = 'Bearer ' . SNT_GITHUB_TOKEN;
	}
	// v1.15.2: send If-None-Match if we have an ETag from a previous
	// successful fetch. A 304 response doesn't count against rate limit.
	if ( '' !== $cached_etag ) {
		$headers['If-None-Match'] = $cached_etag;
	}

	$response = wp_remote_get( $url, array(
		'timeout'     => 5,
		'headers'     => $headers,
		// v8.8.x: forbid redirects — the SNT_GITHUB_TOKEN bearer must never be
		// re-sent to a 3xx target (outbound-hardening convention, v8.7.1).
		'redirection' => 0,
	) );

	if ( is_wp_error( $response ) ) {
		set_site_transient( $cache_key, array( 'data' => array(), 'etag' => '', 'fetched_at' => time() ), SNT_GH_RUNS_FAIL_TTL );
		return array();
	}

	$code = wp_remote_retrieve_response_code( $response );

	// v1.15.2: 304 Not Modified — data unchanged. Refresh cache TTL so
	// we don't re-poll until the next natural expiry, but don't count
	// this request against the quota check below (it's already free).
	if ( $code === 304 && $cached_data !== null ) {
		set_site_transient( $cache_key, array(
			'data'       => $cached_data,
			'etag'       => $cached_etag,
			'fetched_at' => time(),
		), SNT_GH_RUNS_CACHE_TTL );
		return $cached_data;
	}

	if ( $code !== 200 ) {
		set_site_transient( $cache_key, array( 'data' => array(), 'etag' => '', 'fetched_at' => time() ), SNT_GH_RUNS_FAIL_TTL );
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$runs = isset( $body['workflow_runs'] ) && is_array( $body['workflow_runs'] ) ? $body['workflow_runs'] : array();
	$etag = (string) wp_remote_retrieve_header( $response, 'etag' );

	$records = array();
	foreach ( $runs as $run ) {
		if ( ! is_array( $run ) ) {
			continue;
		}
		$started  = isset( $run['run_started_at'] ) ? strtotime( (string) $run['run_started_at'] ) : 0;
		$updated  = isset( $run['updated_at'] ) ? strtotime( (string) $run['updated_at'] ) : 0;
		$duration = ( $started && $updated && $updated >= $started ) ? ( $updated - $started ) : null;

		$record = array(
			'id'         => isset( $run['id'] ) ? (int) $run['id'] : 0,
			'status'     => isset( $run['status'] ) ? (string) $run['status'] : '',
			'conclusion' => isset( $run['conclusion'] ) ? ( $run['conclusion'] === null ? null : (string) $run['conclusion'] ) : null,
			'ref'        => isset( $run['head_branch'] ) ? (string) $run['head_branch'] : ( isset( $run['display_title'] ) ? (string) $run['display_title'] : '' ),
			'trigger'    => isset( $run['event'] ) ? (string) $run['event'] : '',
			'created_at' => isset( $run['created_at'] ) ? (string) $run['created_at'] : '',
			'duration_s' => $duration,
			'html_url'   => isset( $run['html_url'] ) ? esc_url_raw( (string) $run['html_url'] ) : '',
		);

		// Allow downstream filters to enrich (Phase 16+ AI summary, etc.).
		$record    = apply_filters( 'sn_deploy_widget_run_record', $record, $run );
		$records[] = $record;
	}

	set_site_transient( $cache_key, array(
		'data'       => $records,
		'etag'       => $etag,
		'fetched_at' => time(),
	), SNT_GH_RUNS_CACHE_TTL );
	return $records;
}

/**
 * Merge + sort recent runs across multiple repos. Returns the top $count
 * by created_at DESC. Each record gets an extra `repo` key.
 *
 * @param array $repos  Array of GitHub "owner/repo" strings.
 * @param int   $count  Total records to return after merge.
 * @return array
 */
function snt_gh_recent_runs_merged( array $repos, $count = 5 ) {
	$merged = array();
	foreach ( $repos as $repo ) {
		foreach ( snt_gh_recent_runs( $repo, $count ) as $record ) {
			$record['repo'] = $repo;
			$merged[]       = $record;
		}
	}

	usort( $merged, function( $a, $b ) {
		$at = isset( $a['created_at'] ) ? strtotime( $a['created_at'] ) : 0;
		$bt = isset( $b['created_at'] ) ? strtotime( $b['created_at'] ) : 0;
		return $bt <=> $at;
	} );

	return array_slice( $merged, 0, max( 1, (int) $count ) );
}
