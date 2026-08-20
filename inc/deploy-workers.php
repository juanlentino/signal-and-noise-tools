<?php
/**
 * Signal & Noise Tools — Cloudflare worker rows for Deploy Status.
 *
 * Extends the theme/plugin deploy-status surface (inc/admin-tab-dashboard.php
 * + signal-noise/get-deploy-status) with the five owned edge workers so an
 * undeployed or unreachable worker shows as an honest UNKNOWN row rather
 * than disappearing from the panel.
 *
 * Per worker:
 *   - latest = highest vX.Y.Z GitHub TAG (releases stay DRAFT forever here,
 *     so /releases/latest is empty — same tags approach as sn_gh_latest_plugin_tag).
 *   - live   = probed version from the worker's public status/version route,
 *     transient-cached. probe_url null → live "unprobeable" (no route), never
 *     fabricated. Probe failure → state "unknown", never "ok".
 *   - state  = ok | behind | unknown
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Live-probe success TTL — readout freshness, not monitoring. */
const SNT_DEPLOY_WORKER_LIVE_TTL_OK = 600; // 10 min; mirrors SN_WORKER_VERSION_TTL_OK.

/** Live-probe failure TTL — retry sooner after a miss. */
const SNT_DEPLOY_WORKER_LIVE_TTL_FAIL = 120; // 2 min; mirrors SN_WORKER_VERSION_TTL_FAIL.

/** GitHub tags cache TTL — mirrors SN_GH_PLUGIN_CACHE_TTL. */
const SNT_DEPLOY_WORKER_TAG_TTL = HOUR_IN_SECONDS;

/** Short timeout so a cold dashboard never hangs on five edge calls. */
const SNT_DEPLOY_WORKER_TIMEOUT = 4;

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
			'label'        => 'Remote MCP',
			'repo'         => 'juanlentino/sn-remote-mcp-worker',
			'probe_url'    => 'https://juanlentino.com/_sn/remote-mcp/status',
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
	set_transient(
		$key,
		$result,
		$result['ok'] ? SNT_DEPLOY_WORKER_LIVE_TTL_OK : SNT_DEPLOY_WORKER_LIVE_TTL_FAIL
	);
	return $result;
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

/**
 * Status struct for one worker.
 *
 * @param string $id    Registry key.
 * @param array  $opts  { force?:bool, allow_probe?:bool }. allow_probe false
 *                      serves cache only (miss → unknown without HTTP).
 * @return array{id:string,label:string,live:string,latest:string,state:string,repo:string,source_commit:string,reason:string}
 */
function snt_deploy_worker_status_for( $id, $opts = array() ) {
	$registry = snt_deploy_workers_registry();
	$id       = (string) $id;
	$force    = ! empty( $opts['force'] );
	$allow    = ! array_key_exists( 'allow_probe', $opts ) || ! empty( $opts['allow_probe'] );

	$empty = array(
		'id'            => $id,
		'label'         => $id,
		'live'          => '',
		'latest'        => '',
		'state'         => 'unknown',
		'repo'          => '',
		'source_commit' => '',
		'reason'        => 'unknown worker',
	);
	if ( ! isset( $registry[ $id ] ) || ! is_array( $registry[ $id ] ) ) {
		return $empty;
	}
	$cfg = $registry[ $id ];

	$label = isset( $cfg['label'] ) ? (string) $cfg['label'] : $id;
	$repo  = isset( $cfg['repo'] ) ? (string) $cfg['repo'] : '';

	$live_result = array(
		'ok'     => false,
		'live'   => '',
		'commit' => '',
		'error'  => 'skipped',
	);
	if ( $allow ) {
		$live_result = snt_deploy_worker_live_probe( $id, $cfg, $force );
	} else {
		// Cache-only path: never blocks the dashboard on a cold edge.
		$key    = 'snt_dw_live_' . preg_replace( '/[^a-z0-9_-]/i', '', $id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && array_key_exists( 'live', $cached ) ) {
			$live_result = $cached;
		} elseif ( array_key_exists( 'probe_url', $cfg ) && null === $cfg['probe_url'] ) {
			$live_result = array(
				'ok'     => false,
				'live'   => 'unprobeable',
				'commit' => '',
				'error'  => 'unprobeable',
			);
		}
	}

	$latest = snt_deploy_worker_latest_tag( $repo, $id, $force );
	$latest = null === $latest ? '' : (string) $latest;
	$live   = (string) ( $live_result['live'] ?? '' );
	$state  = snt_deploy_worker_state( $live, $latest );

	$reason = '';
	if ( 'unknown' === $state ) {
		if ( 'unprobeable' === $live ) {
			$reason = 'no public version route';
		} elseif ( '' === $live ) {
			$err = (string) ( $live_result['error'] ?? '' );
			// Cold is not broken: a budget-skipped row has simply never been
			// probed this cache cycle (the warm cron is already scheduled).
			// Say so, instead of leaking the internal 'skipped' token as if
			// it were a failure.
			$reason = ( 'skipped' === $err ) ? 'warming' : ( '' !== $err ? $err : 'probe failed' );
		} elseif ( '' === $latest ) {
			$reason = 'no GitHub tag';
		}
	}

	return array(
		'id'            => $id,
		'label'         => $label,
		'live'          => $live,
		'latest'        => $latest,
		'state'         => $state,
		'repo'          => $repo,
		'source_commit' => (string) ( $live_result['commit'] ?? '' ),
		'reason'        => $reason,
	);
}

/**
 * Status for every registered worker, stable registry order.
 *
 * $opts:
 *   - force (bool)         Bypass caches.
 *   - probe_budget (int)   Max cold live probes this call. Dashboard uses 1 so
 *                          a cold page load never fans out five HTTP calls;
 *                          the ability uses a high budget. Cached rows never
 *                          spend budget. Tags always resolve (separate cache).
 *
 * @param array $opts
 * @return array<int,array<string,mixed>>
 */
function snt_deploy_workers_status( $opts = array() ) {
	$force  = ! empty( $opts['force'] );
	$budget = array_key_exists( 'probe_budget', $opts ) ? (int) $opts['probe_budget'] : 5;
	if ( $force ) {
		$budget = PHP_INT_MAX;
	}

	$out  = array();
	$cold = 0;
	foreach ( snt_deploy_workers_registry() as $id => $cfg ) {
		if ( ! is_array( $cfg ) ) {
			continue;
		}
		$cache_key   = 'snt_dw_live_' . preg_replace( '/[^a-z0-9_-]/i', '', (string) $id );
		$warm        = is_array( get_transient( $cache_key ) );
		// Unprobeable (null URL) never needs HTTP — always allow.
		$unprobeable = array_key_exists( 'probe_url', $cfg ) && null === $cfg['probe_url'];
		$allow       = $force || $warm || $unprobeable;
		if ( ! $allow && $budget > 0 ) {
			$allow = true;
			--$budget;
		}
		if ( ! $allow ) {
			$cold++;
		}

		$out[] = snt_deploy_worker_status_for(
			(string) $id,
			array(
				'force'       => $force,
				'allow_probe' => $allow,
			)
		);
	}

	// A render that left workers cold (post-flush, post-install) schedules an
	// immediate out-of-band warm so the NEXT render reads every cache hot —
	// the budget keeps THIS render unblocked, the cron removes the four
	// dead-eyed "unknown" cards the budget would otherwise leave behind for
	// several page loads (observed live minutes after v11.11.4 installed).
	if ( $cold > 0 && function_exists( 'wp_schedule_single_event' )
		&& function_exists( 'wp_next_scheduled' )
		&& ! wp_next_scheduled( 'snt_deploy_workers_warm' ) ) {
		wp_schedule_single_event( time() + 5, 'snt_deploy_workers_warm' );
	}
	return $out;
}

/** Cron: probe every cold worker with a full budget (never in a page load). */
function snt_deploy_workers_warm_cb() {
	snt_deploy_workers_status( array( 'probe_budget' => 10 ) );
}
add_action( 'snt_deploy_workers_warm', 'snt_deploy_workers_warm_cb' );

/**
 * Register the 5-minute recurrence the warm runs on.
 *
 * The interval is not a taste: it MUST stay below
 * SNT_DEPLOY_WORKER_LIVE_TTL_OK, or every cycle leaves a window in which the
 * cache has expired and the dashboard is cold again. The suite pins that
 * relationship as arithmetic rather than as a comment.
 *
 * @since 11.31.2
 * @param array<string,array<string,mixed>> $schedules Existing schedules.
 * @return array<string,array<string,mixed>>
 */
function snt_deploy_workers_cron_schedules( $schedules ) {
	if ( ! is_array( $schedules ) ) {
		$schedules = array();
	}
	if ( ! isset( $schedules['sn_five_minutes'] ) ) {
		$schedules['sn_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Signal & Noise)', 'signal-and-noise-tools' ),
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'snt_deploy_workers_cron_schedules' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 5 min is required to stay under the live-probe TTL; see the docblock.

/**
 * Keep the fleet's live-probe cache warm ahead of any human arriving.
 *
 * WHY THIS EXISTS. The dashboard renders with `probe_budget => 1` across FIVE
 * workers so a cold edge can never block the page. That is correct — but it
 * means a cold cache leaves four rows reading "warming…", and the cache lives
 * ten minutes. The previous design healed that reactively: a render that left
 * workers cold scheduled a ONE-OFF warm. That fixes the NEXT render and
 * nothing after it, so on an admin screen visited every few hours the caches
 * are always expired on arrival and "warming…" is the steady state. Observed
 * live 2026-08-19: three cells reading "warming…" for hours while every worker
 * was in fact answering with a current version.
 *
 * Warming on a schedule shorter than the TTL inverts that: the cache is hot
 * before anyone looks, the render never pays for a probe, and "warming…" goes
 * back to meaning what it says — a genuinely cold start.
 *
 * MIGRATION. An install carrying the old one-off event must be moved onto the
 * recurrence. wp_next_scheduled() cannot tell the two apart (both return a
 * timestamp), so this reads the event itself and clears a non-recurring one.
 *
 * @since 11.31.2
 * @return void
 */
function snt_deploy_workers_warm_schedule() {
	if ( ! function_exists( 'wp_get_scheduled_event' ) || ! function_exists( 'wp_schedule_event' ) ) {
		return;
	}

	$event = wp_get_scheduled_event( 'snt_deploy_workers_warm' );
	if ( $event && empty( $event->schedule ) ) {
		// A leftover one-off. Firing once and stopping is exactly the bug.
		wp_clear_scheduled_hook( 'snt_deploy_workers_warm' );
		$event = false;
	}
	if ( ! $event ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'sn_five_minutes', 'snt_deploy_workers_warm' );
	}
}
add_action( 'init', 'snt_deploy_workers_warm_schedule' );

/**
 * Drop live + tag caches for every registered worker (force_refresh path).
 *
 * @return void
 */
function snt_deploy_workers_flush_caches() {
	foreach ( array_keys( snt_deploy_workers_registry() ) as $id ) {
		$safe = preg_replace( '/[^a-z0-9_-]/i', '', (string) $id );
		delete_transient( 'snt_dw_live_' . $safe );
		delete_site_transient( 'snt_dw_tag_' . $safe );
	}
}
