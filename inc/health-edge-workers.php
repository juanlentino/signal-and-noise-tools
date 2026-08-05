<?php
/**
 * Signal & Noise Tools — edge-worker health check (8th Content-Health check).
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
 * @return array[] Finding rows.
 */
function sn_health_edge_worker_findings( $analytics_ok, $analytics_url, $lg, $now, $stale_secs, $analytics_config = array() ) {
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
			'Analytics collector worker is not reachable at its /_sn/version endpoint — it may be undeployed, or this host cannot hairpin to the edge. Re-run the scan to rule out a transient blip.'
		);
	} elseif ( is_array( $analytics_config ) && ! empty( $analytics_config ) ) {
		// Worker is reachable but self-reports a DATA-LOSS misconfiguration. These two
		// keys are the silent-zero-data modes: an unset token rejects every beacon; a
		// missing AE binding throws on write (now fails open, but writes nothing). The
		// readout is presence-only (never the secret), so this is a safe, high-signal alert.
		$broken = array();
		if ( isset( $analytics_config['px_token_set'] ) && ! $analytics_config['px_token_set'] ) {
			$broken[] = 'SN_PX_TOKEN is unset (every beacon is rejected — data drops to zero)';
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

	if ( ! is_array( $lg ) ) {
		$findings[] = $mk(
			'sn-login-guard',
			'',
			'Login-guard worker is not reachable at /_sn/login-guard/status — it may be undeployed, or this host cannot hairpin to the edge. Re-run the scan to confirm.'
		);
		return $findings;
	}

	$count    = (int) ( $lg['denylistCount'] ?? 0 );
	$compiled = (string) ( $lg['compiledAt'] ?? '' );
	$ts       = '' !== $compiled ? strtotime( $compiled ) : false;

	if ( $count <= 0 ) {
		$findings[] = $mk(
			'sn-login-guard',
			'',
			'Login-guard denylist is EMPTY — the edge is currently blocking no IPs. The daily refresh cron may have never populated it (check Workers Logs / wrangler tail).'
		);
	} elseif ( false !== $ts && ( $now - $ts ) > $stale_secs ) {
		$days       = (int) floor( ( $now - $ts ) / DAY_IN_SECONDS );
		$findings[] = $mk(
			'sn-login-guard',
			'',
			sprintf(
				/* translators: 1: denylist range count, 2: ISO timestamp, 3: age in days */
				'Login-guard denylist is STALE: %1$s ranges, last refreshed %2$s (%3$d days ago). The daily refresh cron has stalled — the edge is enforcing an outdated blocklist.',
				number_format_i18n( $count ),
				$compiled,
				$days
			)
		);
	}

	return $findings;
}

/**
 * CHECK 8: edge-worker reachability + login-guard denylist freshness.
 *
 * @since 6.49.0
 * @return array pack_check envelope.
 */
function sn_health_check_edge_workers() {
	$label    = 'Edge workers';
	$fix_hint = 'Reachability + freshness of the two owned Cloudflare Workers, read from their status endpoints. An unreachable worker may be undeployed or unreachable from this host (re-run to rule out a transient blip); a stale or empty login-guard denylist means the daily refresh cron has stalled, leaving the edge on an outdated blocklist. Re-deploy with `npm run deploy` and check Workers Logs / `wrangler tail`.';

	if ( ! apply_filters( 'sn_health_edge_workers_check_enabled', true ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint );
	}

	// Not configured (no derivable collector endpoint) → skip, don't false-flag.
	$endpoint = function_exists( 'sn_worker_version_endpoint_url' ) ? sn_worker_version_endpoint_url() : '';
	if ( '' === $endpoint ) {
		return sn_health_pack_check(
			$label,
			array(),
			'Edge workers not configured (no collector endpoint derivable) — skipping. Set the Collector endpoint (Measurement → Analytics) to the Worker origin to enable.'
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

	$stale_secs = (int) apply_filters( 'sn_health_denylist_stale_secs', SN_HEALTH_DENYLIST_STALE_DAYS * DAY_IN_SECONDS );
	$findings   = sn_health_edge_worker_findings( $analytics_ok, $analytics_url, $lg, time(), $stale_secs, $analytics_config );

	return sn_health_pack_check( $label, $findings, $fix_hint );
}
