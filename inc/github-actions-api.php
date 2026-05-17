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
const SNT_GH_RUNS_CACHE_TTL        = MINUTE_IN_SECONDS; // 60s — widget polls per pageview, transient absorbs.
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
	if ( $cached !== false ) {
		return is_array( $cached ) ? $cached : array();
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

	$response = wp_remote_get( $url, array(
		'timeout' => 8,
		'headers' => $headers,
	) );

	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		set_site_transient( $cache_key, array(), SNT_GH_RUNS_FAIL_TTL );
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$runs = isset( $body['workflow_runs'] ) && is_array( $body['workflow_runs'] ) ? $body['workflow_runs'] : array();

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

	set_site_transient( $cache_key, $records, SNT_GH_RUNS_CACHE_TTL );
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
