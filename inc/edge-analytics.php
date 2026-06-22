<?php
/**
 * Signal & Noise — Cloudflare GraphQL zone-analytics client (edge analytics).
 *
 * The complement to the Analytics Engine beacon stack: where AE captures what the
 * JS beacon saw (human pageviews + engagement), this reads the GraphQL Analytics
 * API for what actually hit the Cloudflare EDGE — every request incl. bots / RSS /
 * curl / no-JS, cache effectiveness, bandwidth, status codes, and WAF threats. The
 * beacon can't see any of that. Server-to-server (no client cost), cookieless.
 *
 * Two query families, two correctness models:
 *   - httpRequests1dGroups   — pre-aggregated, EXACT, ~1y retention, date-filtered.
 *                              The durable daily rollup workhorse. NO sampling.
 *   - *AdaptiveGroups        — adaptively SAMPLED, datetime-filtered, flexible
 *                              dimensions, with a SHORT per-node retention. Cloudflare
 *                              publishes no fixed Free-tier number for it; the real
 *                              window is discovered at runtime from the settings node
 *                              (sn_edge_adaptive_retention). Every count MUST be
 *                              multiplied by avg.sampleInterval (sn_edge_corrected).
 *
 * Reuses the SN_CF_ANALYTICS_TOKEN (owner adds "Zone Analytics:Read") + the zone id
 * already stored for cache purge. Dormant (sn_edge_config() → null) until both are
 * present, exactly like the AE layer. api.cloudflare.com is a fixed trusted host —
 * no SSRF surface. A failed/rejected query returns null → empty-state, never fatal.
 *
 * ⚠ LIVE GATE: GraphQL field names (esp. the httpRequests1dGroups sum set) cannot be
 * unit-tested against the real schema; the owner verifies once post-deploy. The
 * settings-node retention probe + graceful null-on-error keep a mismatch non-fatal.
 *
 * @package SignalNoiseTools
 * @since 6.26.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_EDGE_GRAPHQL_URL = 'https://api.cloudflare.com/client/v4/graphql';

// Adaptive-dataset retention probe (settings-node notOlderThan), SWR-cached like the
// worker-version probe: a transient guards the network, a separate last-good option
// survives transient eviction. Retention is near-static, so the OK TTL is long.
const SN_EDGE_RETENTION_TRANSIENT = 'sn_edge_retention_probe';
const SN_EDGE_RETENTION_LASTGOOD  = 'sn_edge_adaptive_retention'; // last-known notOlderThan (seconds).
const SN_EDGE_RETENTION_TTL_OK    = 604800; // 7 days — retention rarely changes.
const SN_EDGE_RETENTION_TTL_FAIL  = 21600;  // 6 hours — re-probe sooner after a miss.

/**
 * Resolve credentials: the analytics token (constant-precedence over option, same
 * as sn_analytics_config) + the zone id (reused from the cache-purge config).
 * Returns null — dormant — unless BOTH are present.
 *
 * @return array{token:string, zone:string}|null
 */
function sn_edge_config() {
	$token = ( defined( 'SN_CF_ANALYTICS_TOKEN' ) && '' !== (string) SN_CF_ANALYTICS_TOKEN )
		? (string) SN_CF_ANALYTICS_TOKEN
		: (string) get_option( SN_CF_ANALYTICS_TOKEN_OPT, '' );
	$zone = ( defined( 'SN_CF_ZONE' ) && '' !== (string) SN_CF_ZONE )
		? (string) SN_CF_ZONE
		: (string) get_option( SN_CF_ZONE_OPT, '' );

	if ( '' === $token || '' === $zone ) {
		return null;
	}
	return array( 'token' => $token, 'zone' => $zone );
}

/**
 * POST a GraphQL query to the zone-scoped Analytics API and return the single
 * zone's dataset object (data.viewer.zones[0]), or null on any failure: no config
 * (no network call), transport error, non-200, a GraphQL 200-with-errors[] soft
 * fail, an unparseable body, or an empty zones array (bad zoneTag / no access). The
 * configured zoneTag is auto-injected into $variables, so builders need only the
 * window args.
 *
 * @param string $query     GraphQL document using a $zone variable.
 * @param array  $variables Query variables (zone added automatically).
 * @return array|null
 */
function sn_edge_query( $query, $variables = array() ) {
	$cfg = sn_edge_config();
	if ( ! $cfg ) {
		return null;
	}
	$variables          = is_array( $variables ) ? $variables : array();
	$variables['zone']  = $cfg['zone'];

	$res = wp_remote_post( SN_EDGE_GRAPHQL_URL, array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $cfg['token'],
			'Content-Type'  => 'application/json',
		),
		'body'    => wp_json_encode( array( 'query' => (string) $query, 'variables' => $variables ) ),
		'timeout' => 15,
	) );

	if ( is_wp_error( $res ) ) {
		return null;
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	$json = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $json ) || ! empty( $json['errors'] ) ) {
		return null; // GraphQL returns HTTP 200 even on failure — errors[] is the real signal.
	}
	$zones = $json['data']['viewer']['zones'] ?? null;
	if ( ! is_array( $zones ) || empty( $zones ) ) {
		return null;
	}
	return $zones[0];
}

/**
 * EXACT daily rollup (httpRequests1dGroups) — requests, cache, bandwidth, threats,
 * status map, country map — over an inclusive [date_geq, date_leq] window. Date-type
 * filter; pre-aggregated so NO sampleInterval. The ~1y-retention workhorse: the
 * first run back-fills history, later runs re-pull the trailing window idempotently.
 *
 * @return string GraphQL document.
 */
function sn_edge_daily_query() {
	return 'query($zone:string!,$from:Date!,$to:Date!){viewer{zones(filter:{zoneTag:$zone}){'
		. 'httpRequests1dGroups(limit:1000,filter:{date_geq:$from,date_leq:$to},orderBy:[date_ASC]){'
		. 'dimensions{date}'
		. 'sum{requests cachedRequests bytes cachedBytes threats pageViews '
		. 'responseStatusMap{edgeResponseStatus requests}'
		. 'countryMap{clientCountryName requests bytes}}'
		. 'uniq{uniques}}}}}';
}

/**
 * WAF / threats (firewallEventsAdaptiveGroups) over the trailing window — by action
 * / rule / source / country. Adaptive → SAMPLED: callers must sn_edge_corrected()
 * each row's count. Retention is per-node, not a fixed Free-tier window (discovered
 * via the settings node — sn_edge_adaptive_retention); the cron polls daily to keep
 * a fresh "today" snapshot, NOT to beat a 24h deadline.
 *
 * @return string GraphQL document.
 */
function sn_edge_firewall_query() {
	return 'query($zone:string!,$from:Time!){viewer{zones(filter:{zoneTag:$zone}){'
		. 'firewallEventsAdaptiveGroups(limit:100,filter:{datetime_geq:$from},orderBy:[count_DESC]){'
		. 'count avg{sampleInterval}'
		. 'dimensions{action source ruleId clientCountryName}}}}}';
}

/**
 * Per-colo (edge POP) breakdown (httpRequestsAdaptiveGroups) over the trailing
 * window. Adaptive → SAMPLED: sn_edge_corrected() each row. The trailing window is a
 * daily snapshot (its width is clamped to the node's discovered retention, not an
 * assumed 24h) → a current snapshot, not a long trend.
 *
 * @return string GraphQL document.
 */
function sn_edge_colo_query() {
	return 'query($zone:string!,$from:Time!){viewer{zones(filter:{zoneTag:$zone}){'
		. 'httpRequestsAdaptiveGroups(limit:200,filter:{datetime_geq:$from},orderBy:[count_DESC]){'
		. 'count avg{sampleInterval}sum{edgeResponseBytes}'
		. 'dimensions{coloCode}}}}}';
}

/**
 * Settings-node probe: the zone's per-dataset query limits, including notOlderThan —
 * how far back (seconds) the adaptive dataset can actually be read. Cloudflare's
 * settings node carries every dataset as a sub-field; we probe ONLY
 * httpRequestsAdaptiveGroups (the colo snapshot's dataset) because GraphQL fails the
 * WHOLE query on a single unknown field — one node keeps the live-gate blast radius
 * minimal. notOlderThan/maxDuration are integer seconds. See
 * https://developers.cloudflare.com/analytics/graphql-api/features/discovery/settings/
 *
 * @since 6.34.0
 * @return string GraphQL document.
 */
function sn_edge_settings_query() {
	return 'query($zone:string!){viewer{zones(filter:{zoneTag:$zone}){'
		. 'settings{httpRequestsAdaptiveGroups{enabled notOlderThan maxDuration}}}}}';
}

/**
 * Sampling correction for an adaptive row: the representative true value is
 * count × sampleInterval. A missing or <1 sampleInterval is floored to 1 (never
 * zeroes a real count). Pre-aggregated (1dGroups) rows skip this entirely.
 *
 * @param array $row An adaptive group row with count + avg.sampleInterval.
 * @return int
 */
function sn_edge_corrected( $row ) {
	$count = (int) ( $row['count'] ?? 0 );
	$si    = (float) ( $row['avg']['sampleInterval'] ?? 1 );
	if ( $si < 1 ) {
		$si = 1;
	}
	return (int) round( $count * $si );
}

/**
 * Discover the REAL retention window (seconds) for the sampled adaptive datasets by
 * reading httpRequestsAdaptiveGroups.notOlderThan off the GraphQL settings node —
 * there is no documented fixed Free-tier number, so the only honest source is the
 * runtime value. SWR-cached exactly like sn_worker_version_get(): a transient guards
 * the network (a SHORT TTL after a miss so we retry sooner), and a separate last-good
 * option survives transient eviction so a momentary blip never blanks the figure.
 * Dormant when unconfigured (returns null, writes nothing) — matching the layer.
 *
 * @since 6.34.0
 * @param bool $force Bypass the transient and probe live (cache-control seam).
 * @return int|null notOlderThan in seconds, or null when unknown/unconfigured.
 */
function sn_edge_adaptive_retention( $force = false ) {
	if ( ! sn_edge_config() ) {
		return null; // dormant — no probe, no cache write.
	}
	if ( ! $force ) {
		$cached = get_transient( SN_EDGE_RETENTION_TRANSIENT );
		if ( is_array( $cached ) ) {
			return isset( $cached['seconds'] ) ? (int) $cached['seconds'] : null;
		}
	}

	$seconds = null;
	$zone    = sn_edge_query( sn_edge_settings_query() );
	if ( is_array( $zone ) ) {
		$node = $zone['settings']['httpRequestsAdaptiveGroups'] ?? null;
		if ( is_array( $node ) && (int) ( $node['notOlderThan'] ?? 0 ) > 0 ) {
			$seconds = (int) $node['notOlderThan'];
		}
	}

	$ok = ( null !== $seconds );
	if ( $ok ) {
		update_option( SN_EDGE_RETENTION_LASTGOOD, $seconds, false );
		$resolved = $seconds;
	} else {
		// Probe failed: fall back to the last-known-good (never overwritten by a
		// failure), else null. Cache the RESOLVED value so a warm read agrees with
		// this cold one instead of flickering to null inside the fail window.
		$last     = (int) get_option( SN_EDGE_RETENTION_LASTGOOD, 0 );
		$resolved = $last > 0 ? $last : null;
	}
	set_transient(
		SN_EDGE_RETENTION_TRANSIENT,
		array( 'seconds' => $resolved ),
		$ok ? SN_EDGE_RETENTION_TTL_OK : SN_EDGE_RETENTION_TTL_FAIL
	);
	return $resolved;
}

/**
 * The discovered adaptive-dataset retention expressed as whole days, for display.
 * 0 when unknown or dormant (never negative, never fatal).
 *
 * @since 6.34.0
 * @return int
 */
function sn_edge_adaptive_retention_days() {
	$seconds = (int) sn_edge_adaptive_retention();
	return $seconds > 0 ? (int) floor( $seconds / 86400 ) : 0; // 86400 = seconds per day.
}
