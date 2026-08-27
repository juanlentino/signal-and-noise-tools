<?php
/**
 * Signal & Noise Tools — edge-worker health check (8th Content-Health check).
 *
 * v10.62.0 (cross-worker observability review, 2026-08-08): the check grows
 * from two workers to FOUR. sn-provenance's /_sn/status — a 200/503 health
 * endpoint that had NO consumer anywhere (not here, not Better Stack: a
 * health endpoint nobody polls is the success-only readout wearing a
 * different hat) — is now probed each scan, and a degraded pipeline (stale
 * pending anchor, dead calendars, stalled cron, a cron step that threw)
 * becomes a Health finding. sn-rights-signals' new sensor block (worker
 * v1.6.0+) is read from the version endpoint the plugin already probes: a
 * dead machine-reader sensor means the sn_machine_readers dataset goes
 * quiet, which is INDISTINGUISHABLE from "no crawlers came" — the dataset
 * backs a published argument, so silence must be a finding, not a shrug.
 * Both degrade silently for older worker deploys (absent field = absent
 * measurement, never a failure). The login-guard finding note now carries
 * lastRefreshReason (worker v1.x+) when the last refresh attempt failed.
 *
 * v11.x (H1, R3 §3D Increments 2+4): a FIFTH worker, sn-remote-mcp, reading
 * /_sn/remote-mcp/status. Unlike the other four, this URL is fixed on our own
 * zone (not a setting), so the probe is unconditional and a transport/parse
 * failure is a real outage, not a config skip. Three findings, one deliberate
 * non-finding: `configured: false` and a missing `bridge_secret_bound` field
 * both flag as outages (the latter's note says so distinctly — a lost readout
 * is not the same claim as an unbound secret); `anomaly.flagged` flags as a
 * volume warning carrying COUNTS ONLY, never a subject identity (the origin
 * structurally cannot name the caller — see the client-half spec's blind-spot
 * section). `killed: true` is deliberately NOT a finding: a dark door the
 * owner chose is a state, not a failure, and folding it into the outage
 * branch is exactly the mutation this file's test sweep guards against.
 *
 * The two owned Cloudflare Workers (sn-analytics, sn-login-guard) are already
 * surfaced for DISPLAY — the analytics version card (inc/worker-version.php) and
 * the Login-defense panel + dashboard view (inc/login-defense*.php). What nothing
 * surfaced was an ACTIVE ALERT when a worker goes unreachable or, more importantly,
 * when the login-guard denylist goes STALE because its daily refresh cron stalled
 * (the edge then silently enforces an outdated blocklist). The login-guard worker
 * logs that failure to Workers Logs (v1.1.0 console.warn) — but nobody watches the
 * tail. This check folds that signal into the Health scan instead.
 *
 * Reuses the SSRF-guarded probes the display surfaces already own — it adds NO new
 * outbound primitive: analytics reachability comes from the SWR-cached
 * sn_worker_version_get(); the login-guard status comes from sn_login_defense_status()
 * (cached here so a scan does not re-hit the edge). Detection-only — the fix is a
 * re-deploy / cron repair, not a post mutation, so it is NOT in the AI-suggest set
 * (mirrors the Cloudflare-security-headers check).
 *
 * @package SignalNoiseTools
 * @since 6.49.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The login-guard cron refreshes the denylist daily; flag it stale past this many
// days (filterable). 3 days = the cron has missed ~3 runs — unambiguously stalled,
// not a one-off blip.
if ( ! defined( 'SN_HEALTH_DENYLIST_STALE_DAYS' ) ) {
	define( 'SN_HEALTH_DENYLIST_STALE_DAYS', 3 );
}
const SN_HEALTH_EDGE_LG_TRANSIENT = 'sn_health_edge_lg_status';
const SN_HEALTH_EDGE_LG_TTL       = 6 * HOUR_IN_SECONDS;

// Shared 6h probe cache TTL (quality-review item 3). Introduced alongside the
// fifth worker rather than migrating SN_HEALTH_EDGE_LG_TTL's existing call
// sites — those stay as they are to avoid churning unrelated lines.
const SN_HEALTH_EDGE_PROBE_TTL = 6 * HOUR_IN_SECONDS;

// v11.x (H1, R3 §3D Increments 2+4): the fifth worker. Unlike the analytics/
// login-guard/provenance/rights-signals probes above, this URL is FIXED on our
// own zone — there is no "collector endpoint" or "worker URL" setting to be
// absent, so there is no config-skip valve here. The probe always runs.
const SN_HEALTH_EDGE_REMOTE_MCP_TRANSIENT = 'sn_health_edge_remote_mcp_status';
const SN_HEALTH_EDGE_REMOTE_MCP_URL       = 'https://juanlentino.com/_sn/remote-mcp/status';

/**
 * Build the findings array from already-fetched inputs. PURE (no I/O) so the
 * reachability + staleness logic is exhaustively testable. Mirrors the non-post
 * finding shape the Cloudflare-headers check uses (no edit_url — the fix is an
 * ops action, not a post edit).
 *
 * @since 6.49.0
 * @param bool       $analytics_ok     Whether the analytics /_sn/version probe succeeded.
 * @param string     $analytics_url    The probed analytics endpoint (for the finding subject).
 * @param array|null $lg               Parsed login-guard status JSON, or null when unreachable.
 * @param int        $now              Current unix time.
 * @param int        $stale_secs       Age past which the denylist is "stale".
 * @param array      $analytics_config Presence-only config booleans from /_sn/version (worker v1.9.0+); empty when unknown.
 * @param array|string|null $prov      Parsed provenance /_sn/status JSON (200 AND 503 bodies both parse),
 *                                     null when the probe could not reach/parse, or the string
 *                                     'unconfigured' when no worker URL is set (skip, never flag).
 * @param array|null $mr_sensor        sn-rights-signals sensor block ({ae_bound,last_write_ok,last_write_at})
 *                                     from its version endpoint, or null when the worker predates it /
 *                                     the probe failed (absent measurement — never a finding by itself).
 * @param bool|array|null $remote_mcp  sn-remote-mcp worker status ({configured,killed,bridge_secret_bound,
 *                                     anomaly:{flagged,total_today,subjects_over},version}) from
 *                                     /_sn/remote-mcp/status. `null` means the probe RAN and could not
 *                                     reach/parse it — an outage, never a config skip (the URL is fixed
 *                                     on our own zone, unlike $prov's 'unconfigured'). The default `false`
 *                                     means "not measured" (a caller that predates this param) and, like
 *                                     $mr_sensor's `null`, is never a finding by itself. `anomaly` missing,
 *                                     null, or a non-array scalar (the Worker's own fail-open degrade
 *                                     shape when ITS observability store is unreachable) is UNKNOWN, never
 *                                     a finding — direct-indexing `$anomaly['flagged']` without the
 *                                     is_array() guard below would silently misread that degrade as
 *                                     "not flagged" instead of "not measured"; a mutation pin guards this.
 *                                     `version` is CARRIED in the shape above but not yet read by any
 *                                     code here: spec §8 promises a "stale-deploy hint", but there is no
 *                                     reliable known-current-version source on the plugin side today, so
 *                                     comparison is deliberately deferred rather than built against a
 *                                     guess.
 * @return array[] Finding rows.
 */
function sn_health_edge_worker_findings( $analytics_ok, $analytics_url, $lg, $now, $stale_secs, $analytics_config = array(), $prov = 'unconfigured', $mr_sensor = null, $remote_mcp = false ) {
	$findings = array();
	$mk       = static function ( $label, $url, $note ) {
		return array(
			'subject_type'  => 'edge_worker',
			'subject_id'    => 0,
			'subject_url'   => (string) $url,
			'subject_label' => $label,
			'edit_url'      => '',
			'note'          => $note,
		);
	};

	if ( ! $analytics_ok ) {
		$findings[] = $mk(
			'sn-analytics',
			$analytics_url,
			'Analytics collector worker is not reachable at its /_sn/version endpoint: it may be undeployed, or this host cannot hairpin to the edge. Re-run the scan to rule out a transient blip.'
		);
	} elseif ( is_array( $analytics_config ) && ! empty( $analytics_config ) ) {
		// Worker is reachable but self-reports a DATA-LOSS misconfiguration. These two
		// keys are the silent-zero-data modes: an unset token rejects every beacon; a
		// missing AE binding throws on write (now fails open, but writes nothing). The
		// readout is presence-only (never the secret), so this is a safe, high-signal alert.
		$broken = array();
		if ( isset( $analytics_config['px_token_set'] ) && ! $analytics_config['px_token_set'] ) {
			$broken[] = 'SN_PX_TOKEN is unset (every beacon is rejected: data drops to zero)';
		}
		if ( isset( $analytics_config['ae_bound'] ) && ! $analytics_config['ae_bound'] ) {
			$broken[] = 'the SN_AE Analytics Engine binding is missing (nothing is written)';
		}
		if ( ! empty( $broken ) ) {
			$findings[] = $mk(
				'sn-analytics',
				$analytics_url,
				'Analytics worker is deployed but MISCONFIGURED: ' . implode( '; ', $broken ) . '. Set the missing secret/binding and re-deploy (`npm run deploy`); /_sn/version reports the current config.'
			);
		}
	}

	// sn-provenance (v10.62.0): the 200/503 status endpoint finally has a
	// consumer. 'unconfigured' skips silently (parity with the analytics
	// endpoint-unset skip); null = transport/parse failure = unreachable.
	if ( 'unconfigured' !== $prov ) {
		if ( ! is_array( $prov ) ) {
			$findings[] = $mk(
				'sn-provenance',
				'',
				'Provenance worker is not reachable at /_sn/status: it may be undeployed, or this host cannot hairpin to the edge. Anchoring health is UNKNOWN — re-run the scan to rule out a transient blip.'
			);
		} elseif ( 'healthy' !== (string) ( $prov['status'] ?? '' ) ) {
			// reasons are a server-side enum; allowlist each token anyway so a
			// compromised edge cannot inject markup/secret-shaped text into a
			// Health note.
			$reasons = array();
			foreach ( (array) ( $prov['reasons'] ?? array() ) as $r ) {
				if ( is_string( $r ) && 1 === preg_match( '/^[a-z0-9-]{1,40}$/', $r ) ) {
					$reasons[] = $r;
				}
			}
			$pending_count = isset( $prov['pending']['count'] ) ? (int) $prov['pending']['count'] : 0;
			$findings[]    = $mk(
				'sn-provenance',
				'',
				sprintf(
					/* translators: 1: comma-separated degrade reasons, 2: pending anchor count */
					'Provenance anchoring pipeline is DEGRADED: %1$s. Pending anchors: %2$d. The signed-record promise depends on this worker — check /_sn/status and Workers Logs, then re-deploy or repair the named component.',
					'' !== implode( ', ', $reasons ) ? implode( ', ', $reasons ) : 'unspecified reason',
					$pending_count
				)
			);
		}
	}

	// sn-rights-signals sensor (v10.62.0): a dead sensor makes the
	// sn_machine_readers dataset go quiet — indistinguishable from "no
	// crawlers came", and the dataset backs a published argument. Only a
	// REPORTED-dead sensor flags; an absent block (older worker, failed
	// probe) is an absent measurement, never a finding.
	if ( is_array( $mr_sensor ) ) {
		$ae_bound      = $mr_sensor['ae_bound'] ?? null;
		$last_write_ok = $mr_sensor['last_write_ok'] ?? null;
		if ( false === $ae_bound ) {
			$findings[] = $mk(
				'sn-rights-signals',
				'',
				'Machine-reader sensor is DEAD: the SN_MR Analytics Engine binding is missing, so crawler fetches are silently unrecorded. A quiet sn_machine_readers dataset is now a broken sensor, NOT an absence of crawlers — restore the binding and re-deploy.'
			);
		} elseif ( false === $last_write_ok ) {
			$findings[] = $mk(
				'sn-rights-signals',
				'',
				'Machine-reader sensor writes are FAILING: the last Analytics Engine write errored (see Workers Logs). Crawler fetches may be going unrecorded — the sn_machine_readers dataset undercounts until this recovers.'
			);
		}
	}

	// sn-remote-mcp (v11.x, H1): false = "not measured" (a caller that predates
	// this param) — absent measurement, never a finding, mirroring $mr_sensor's
	// doctrine but on a distinct sentinel because an explicit null HERE is a
	// measured outage (the URL is fixed on our own zone; there is no config to
	// be absent, unlike $prov's 'unconfigured').
	if ( false !== $remote_mcp ) {
		if ( ! is_array( $remote_mcp ) ) {
			$findings[] = $mk(
				'sn-remote-mcp',
				'',
				'sn-remote-mcp worker is unreachable at /_sn/remote-mcp/status: it may be undeployed, or this host cannot hairpin to the edge. Re-run the scan to rule out a transient blip.'
			);
		} else {
			// THE REAL-SHAPE FIX: the live body nests configured/bridge_secret_bound/
			// killed under `config`, not at the top level (top level carries worker,
			// version, source_commit, cf_version_id, deployed_at, increment, config).
			// `anomaly` IS top-level. Defensive: a v0.2.0-era body or garbage still
			// degrades to "not measured" rather than throwing.
			$rm_config = is_array( $remote_mcp['config'] ?? null ) ? $remote_mcp['config'] : array();

			if ( false === ( $rm_config['configured'] ?? null ) ) {
				$findings[] = $mk(
					'sn-remote-mcp',
					'',
					'sn-remote-mcp reports configured: false — the remote MCP door has not been deployed with its secrets and Access application, and every authenticated path returns 503. Something is missing: the bridge secret / Access wiring (see /_sn/remote-mcp/status).'
				);
			}

			// Read (same nested source as configured/bridge_secret_bound) but
			// DELIBERATELY produces no finding either way: a dark door is a state
			// the owner chose, not a failure. Naming the read makes the no-finding
			// behavior an intentional read-and-ignore rather than an accidental
			// miss of a key nothing was looking at in the first place — do not
			// fold this into an "outage" branch; that is the mutation this pin
			// guards against.
			$rm_killed = ! empty( $rm_config['killed'] ); // intentionally unused below.

			if ( ! array_key_exists( 'bridge_secret_bound', $rm_config ) ) {
				$findings[] = $mk(
					'sn-remote-mcp',
					'',
					'sn-remote-mcp status is missing the bridge_secret_bound field entirely: a deploy lost the readout (distinct from the secret being present-but-unbound). Re-deploy the worker and confirm /_sn/remote-mcp/status reports the field again.'
				);
			}

			$anomaly = is_array( $remote_mcp['anomaly'] ?? null ) ? $remote_mcp['anomaly'] : array();
			if ( ! empty( $anomaly['flagged'] ) ) {
				// Counts only, never identities — subject_over is a NUMBER; the
				// per-session detail lives in Workers Logs, never in this note.
				$findings[] = $mk(
					'sn-remote-mcp',
					'',
					sprintf(
						/* translators: 1: brokered calls today, 2: subjects over the per-sub threshold */
						'sn-remote-mcp reports a volume anomaly: %1$d brokered calls today, %2$d subject(s) over the per-subject threshold. This flags a flood, not an outage — read Workers Logs for the per-session detail; the origin structurally cannot name the caller.',
						(int) ( $anomaly['total_today'] ?? 0 ),
						(int) ( $anomaly['subjects_over'] ?? 0 )
					)
				);
			}
		}
	}

	if ( ! is_array( $lg ) ) {
		$findings[] = $mk(
			'sn-login-guard',
			'',
			'Login-guard worker is not reachable at /_sn/login-guard/status: it may be undeployed, or this host cannot hairpin to the edge. Re-run the scan to confirm.'
		);
		return $findings;
	}

	// Worker v1.x+ persists WHY the last denylist refresh failed; carry it
	// into the stale/empty notes below when present. Allowlisted charset —
	// edge JSON never reaches a Health note unsanitized.
	$lg_reason = '';
	if ( isset( $lg['lastRefreshOk'] ) && false === $lg['lastRefreshOk'] && is_string( $lg['lastRefreshReason'] ?? null )
		&& 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,64}$/', $lg['lastRefreshReason'] ) ) {
		$lg_reason = ' Last refresh attempt failed: ' . $lg['lastRefreshReason'] . '.';
	}

	$count    = (int) ( $lg['denylistCount'] ?? 0 );
	$compiled = (string) ( $lg['compiledAt'] ?? '' );
	$ts       = '' !== $compiled ? strtotime( $compiled ) : false;

	if ( $count <= 0 ) {
		$findings[] = $mk(
			'sn-login-guard',
			'',
			'Login-guard denylist is EMPTY: the edge is currently blocking no IPs. The daily refresh cron may have never populated it (check Workers Logs / wrangler tail).' . $lg_reason
		);
	} elseif ( false !== $ts && ( $now - $ts ) > $stale_secs ) {
		$days       = (int) floor( ( $now - $ts ) / DAY_IN_SECONDS );
		$findings[] = $mk(
			'sn-login-guard',
			'',
			sprintf(
				/* translators: 1: denylist range count, 2: ISO timestamp, 3: age in days */
				'Login-guard denylist is STALE: %1$s ranges, last refreshed %2$s (%3$d days ago). The daily refresh cron has stalled: the edge is enforcing an outdated blocklist.',
				number_format_i18n( $count ),
				$compiled,
				$days
			) . $lg_reason
		);
	}

	// The IPv6 denylist (worker v1.11.0) is a SECOND feed on a different
	// upstream, refreshing independently and keeping last-known on every failure
	// branch — so without its own finding a dead Spamhaus cron is invisible
	// here while the v4 half stays green.
	//
	// Gated on the field being REPORTED, not on its value. A worker predating
	// the v6 feed says nothing about it, and silence is not an empty list:
	// treating absence as zero would raise a permanent false finding against
	// every deployment until the new worker ships. Absence is UNKNOWN, never 0.
	if ( array_key_exists( 'denylist6Count', $lg ) ) {
		$lg6_reason = '';
		if ( isset( $lg['last6RefreshOk'] ) && false === $lg['last6RefreshOk']
			&& is_string( $lg['last6RefreshReason'] ?? null )
			&& 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,64}$/', $lg['last6RefreshReason'] ) ) {
			$lg6_reason = ' Last refresh attempt failed: ' . $lg['last6RefreshReason'] . '.';
		}

		$count6    = (int) ( $lg['denylist6Count'] ?? 0 );
		$compiled6 = (string) ( $lg['compiled6At'] ?? '' );
		$ts6       = '' !== $compiled6 ? strtotime( $compiled6 ) : false;

		if ( $count6 <= 0 ) {
			$findings[] = $mk(
				'sn-login-guard',
				'',
				'Login-guard IPv6 denylist is EMPTY: the edge is checking no IPv6 addresses at all, so every IPv6 client reaches the login form unchecked.' . $lg6_reason
			);
		} elseif ( false !== $ts6 && ( $now - $ts6 ) > $stale_secs ) {
			$days6      = (int) floor( ( $now - $ts6 ) / DAY_IN_SECONDS );
			$findings[] = $mk(
				'sn-login-guard',
				'',
				sprintf(
					/* translators: 1: IPv6 range count, 2: ISO timestamp, 3: age in days */
					'Login-guard IPv6 denylist is STALE: %1$s ranges, last refreshed %2$s (%3$d days ago). The Spamhaus DROPv6 refresh has stalled; the IPv4 feed refreshes separately and may still be healthy.',
					number_format_i18n( $count6 ),
					$compiled6,
					$days6
				) . $lg6_reason
			);
		}
	}

	return $findings;
}

/**
 * Probe the provenance worker's /_sn/status. Returns the parsed JSON for
 * BOTH 200 and 503 (a degraded verdict is a successful read — the body is
 * the signal), null on transport/parse failure, or 'unconfigured' when no
 * worker URL is set. Cached 6h (parity with the login-guard probe); a
 * transport failure is NEVER cached, so an unreachable edge self-heals on
 * the next scan. Reuses the webhook module's own URL + SSRF gate — no new
 * outbound primitive.
 *
 * @since 10.62.0
 * @return array|string|null
 */
function sn_health_prov_status_probe() {
	if ( ! function_exists( 'sn_prov_worker_url' ) ) {
		return 'unconfigured';
	}
	$url = sn_prov_worker_url();
	if ( '' === $url ) {
		return 'unconfigured';
	}

	$cached = get_transient( 'sn_health_edge_prov_status' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$endpoint = untrailingslashit( $url ) . '/_sn/status';
	if ( function_exists( 'sn_prov_url_allowed' ) && ! sn_prov_url_allowed( $endpoint ) ) {
		return 'unconfigured'; // A blocked URL is a config posture, not an outage.
	}
	$response = wp_remote_get( $endpoint, array( 'timeout' => 6, 'redirection' => 0 ) );
	if ( is_wp_error( $response ) ) {
		return null;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code && 503 !== $code ) {
		return null;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || 'sn-provenance' !== (string) ( $data['worker'] ?? '' ) ) {
		return null;
	}
	set_transient( 'sn_health_edge_prov_status', $data, SN_HEALTH_EDGE_LG_TTL );
	return $data;
}

/**
 * Probe the sn-remote-mcp worker's /_sn/remote-mcp/status. Unlike
 * sn_health_prov_status_probe(), the URL is FIXED on our own zone (not a
 * setting), so there is no 'unconfigured' skip — the probe always runs and
 * a transport/parse failure is a real outage (null). Mirrors the same shape
 * otherwise: 200/503 both parse (a killed door still answers with its own
 * state), cached 6h, and a failure is NEVER cached so an unreachable edge
 * self-heals on the next scan.
 *
 * WORKER-IDENTITY CHECK: this estate has a standing memory about exactly this
 * zone — /_sn/version answers as sn-analytics regardless of which worker's
 * config endpoint was probed, so the body's `worker` field must be read
 * before believing anything else in it. A 200/503 with the right shape but
 * the wrong `worker` value is treated the same as an unparseable body (null),
 * mirroring sn_health_prov_status_probe()'s own `'sn-provenance' !== worker`
 * guard.
 *
 * @since 11.x (H1, R3 §3D Increments 2+4)
 * @return array|null
 */
function sn_health_remote_mcp_status_probe() {
	$cached = get_transient( SN_HEALTH_EDGE_REMOTE_MCP_TRANSIENT );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$url  = SN_HEALTH_EDGE_REMOTE_MCP_URL;
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( ! wp_http_validate_url( $url )
		|| 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
		|| ( function_exists( 'sn_ssrf_host_blocked' ) && sn_ssrf_host_blocked( $host ) )
	) {
		return null;
	}

	$response = wp_remote_get( $url, array( 'timeout' => 6, 'redirection' => 0 ) );
	if ( is_wp_error( $response ) ) {
		return null;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code && 503 !== $code ) {
		return null;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || 'sn-remote-mcp' !== (string) ( $data['worker'] ?? '' ) ) {
		return null;
	}
	set_transient( SN_HEALTH_EDGE_REMOTE_MCP_TRANSIENT, $data, SN_HEALTH_EDGE_PROBE_TTL );
	return $data;
}

/**
 * CHECK 8: edge-worker reachability + login-guard denylist freshness.
 *
 * @since 6.49.0
 * @return array pack_check envelope.
 */
function sn_health_check_edge_workers() {
	$label    = 'Edge workers';
	$fix_hint = 'Reachability + freshness of the five owned Cloudflare Workers (analytics, login-guard, provenance, rights-signals, remote-mcp), read from their status/version endpoints. A DEGRADED provenance pipeline or a DEAD machine-reader sensor is a finding — silence from either falsifies a public promise. An unreachable worker may be undeployed or unreachable from this host (re-run to rule out a transient blip); a stale or empty login-guard denylist means the daily refresh cron has stalled, leaving the edge on an outdated blocklist. sn-remote-mcp flags an outage (unreachable, unconfigured, or a lost bridge_secret_bound readout) and a volume anomaly (counts only, never a caller identity) — but NOT a deliberately killed door, which is a state the owner chose, not a failure. Re-deploy with `npm run deploy` and check Workers Logs / `wrangler tail`.';

	if ( ! apply_filters( 'sn_health_edge_workers_check_enabled', true ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint );
	}

	// Not configured (no derivable collector endpoint) → skip, don't false-flag.
	$endpoint = function_exists( 'sn_worker_version_endpoint_url' ) ? sn_worker_version_endpoint_url() : '';
	if ( '' === $endpoint ) {
		return sn_health_pack_check(
			$label,
			array(),
			'Edge workers not configured (no collector endpoint derivable): skipping. Set the Collector endpoint (Measurement → Analytics) to the Worker origin to enable.'
		);
	}

	// Analytics reachability + self-reported config — reuse the SWR-cached version
	// probe (no new request). config is the presence-only bool map from worker v1.9.0+.
	$analytics        = function_exists( 'sn_worker_version_get' ) ? sn_worker_version_get() : array();
	$analytics_ok     = is_array( $analytics ) && ! empty( $analytics['ok'] );
	$analytics_url    = is_array( $analytics ) ? (string) ( $analytics['url'] ?? $endpoint ) : $endpoint;
	$analytics_config = is_array( $analytics ) && isset( $analytics['data']['config'] ) && is_array( $analytics['data']['config'] )
		? $analytics['data']['config']
		: array();

	// Login-guard status — cache the probe so a scan does not re-hit the edge.
	// Never cache a failure (null), so an unreachable edge self-heals next scan.
	$lg = get_transient( SN_HEALTH_EDGE_LG_TRANSIENT );
	if ( ! is_array( $lg ) ) {
		$probed = function_exists( 'sn_login_defense_status' ) ? sn_login_defense_status() : null;
		if ( is_array( $probed ) ) {
			set_transient( SN_HEALTH_EDGE_LG_TRANSIENT, $probed, SN_HEALTH_EDGE_LG_TTL );
			$lg = $probed;
		} else {
			$lg = null;
		}
	}

	// v10.62.0 — the two new workers. Both degrade to absent-measurement
	// shapes for older deploys; neither adds a new outbound primitive.
	$prov      = sn_health_prov_status_probe();
	$mr_info   = function_exists( 'snt_mr_sensor_info' ) ? snt_mr_sensor_info() : null;
	$mr_sensor = ( is_array( $mr_info ) && isset( $mr_info['sensor'] ) && is_array( $mr_info['sensor'] ) ) ? $mr_info['sensor'] : null;

	// v11.x (H1) — the fifth worker. Unconditional: the URL is fixed on our
	// own zone, so unlike $prov there is no config-skip valve to fall back on.
	$remote_mcp = sn_health_remote_mcp_status_probe();

	$stale_secs = (int) apply_filters( 'sn_health_denylist_stale_secs', SN_HEALTH_DENYLIST_STALE_DAYS * DAY_IN_SECONDS );
	$findings   = sn_health_edge_worker_findings( $analytics_ok, $analytics_url, $lg, time(), $stale_secs, $analytics_config, $prov, $mr_sensor, $remote_mcp );

	return sn_health_pack_check( $label, $findings, $fix_hint );
}
