<?php
/**
 * Signal & Noise Tools — in-admin Better Stack status panel, data layer
 * (v8.2.0, arc 3).
 *
 * Owner-requested: the Better Stack monitor + heartbeat states rendered
 * NATIVELY inside wp-admin (dashboard widget + Webhooks-tab rail panel).
 * No iframe, no third-party embed, no public route — the public status
 * page stays Better Stack-hosted, and a status page served by the site
 * it reports on would die with the site anyway.
 *
 * Data flow: admin render outputs an instant shell (mount div); JS calls
 * the readonly `signal-noise/uptime-status` ability via sntAbilityRun;
 * the ability calls sn_uptime_status_fetch() here, which GETs the Uptime
 * API (monitors + heartbeats, Bearer token) and caches the normalized
 * snapshot in a 90s transient. Renders are therefore ZERO-COST (the
 * dashboard renders on every admin login — same discipline as
 * inc/site-health-widget.php); the remote round-trip only ever happens
 * inside the ability call. Failures return WP_Error and are NEVER
 * cached, so a Better Stack blip clears on the next panel load.
 *
 * Token: SN_BETTERSTACK_API_TOKEN in wp-config.php wins; otherwise the
 * non-autoloaded sn_betterstack_api_token option, saved from the Uptime
 * monitoring fieldset (mirrors the Cloudflare token idiom in
 * inc/cloudflare-purge.php: obscured display via sn_mask_secret(),
 * paste-to-update, 'clear' to remove; the raw value never renders).
 * A read-scoped Uptime API token is all this needs.
 *
 * Two payload tiers since v8.4.0 (the stats display moved to the
 * Dashboard → Analytics page, owner call):
 *   - LIGHT (sn_uptime_status_fetch): statuses only, 2 calls, 90s cache.
 *     Feeds the S&N Health widget section + the Webhooks rail.
 *   - DETAIL (sn_uptime_status_detail): + 30d/90d availability, avg
 *     response times (24h), and the incidents log. Feeds the Analytics
 *     page monitor. Every stat tier is independently cached, fails SOFT
 *     (null, never an exception), and circuit-breaks on first failure so
 *     a down summary API is neither waited on nor hammered.
 *
 * Endpoints (betterstack.com/docs/uptime/api, verified 2026-07-02):
 *   GET api/v2/monitors · api/v2/heartbeats
 *   GET api/v2/monitors/{id}/sla · api/v2/heartbeats/{id}/availability
 *   GET api/v2/monitors/{id}/response-times   (last 24h, seconds)
 *   GET api/v3/incidents                       (NOTE: v3, not v2)
 * All JSON:API; list pagination deliberately not walked (first page is
 * plenty for this site's monitor count).
 *
 * @package SignalNoiseTools
 * @since 8.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_UPTIME_STATUS_TOKEN_OPT', 'sn_betterstack_api_token' );
define( 'SN_UPTIME_STATUS_TRANSIENT', 'sn_uptime_status_snapshot' );
define( 'SN_UPTIME_STATUS_AVAIL_TRANSIENT', 'sn_uptime_availability' );
define( 'SN_UPTIME_STATUS_AVAIL_90D_TRANSIENT', 'sn_uptime_availability_90d' );
define( 'SN_UPTIME_STATUS_RESPONSE_TRANSIENT', 'sn_uptime_response_times' );
define( 'SN_UPTIME_STATUS_INCIDENTS_TRANSIENT', 'sn_uptime_incidents' );
define( 'SN_UPTIME_STATUS_TTL', 90 );
// Version-less base (v8.4.0): monitors/SLA/response-times live on v2,
// incidents on v3 — callers pass the versioned path.
define( 'SN_UPTIME_STATUS_API_BASE', 'https://uptime.betterstack.com/api/' );

/**
 * Resolve the active Uptime API token. Constant wins over option.
 *
 * @return string Empty string when neither is configured.
 */
function sn_uptime_status_token() {
	if ( defined( 'SN_BETTERSTACK_API_TOKEN' ) && SN_BETTERSTACK_API_TOKEN ) {
		return (string) SN_BETTERSTACK_API_TOKEN;
	}
	return (string) get_option( SN_UPTIME_STATUS_TOKEN_OPT, '' );
}

/**
 * True when a token is available (panel surfaces render their mount).
 *
 * @return bool
 */
function sn_uptime_status_configured() {
	return '' !== sn_uptime_status_token();
}

/**
 * Map a Better Stack status string to a display level.
 * up → ok (green) · down → alert (red) · paused / pending /
 * maintenance / validating → warn (amber, "attention but not on fire").
 *
 * @param string $status Raw API status.
 * @return string ok|warn|alert
 */
function sn_uptime_status_level( $status ) {
	if ( 'up' === $status ) {
		return 'ok';
	}
	if ( 'down' === $status ) {
		return 'alert';
	}
	return 'warn';
}

/**
 * One authed GET against the Uptime API. Fixed host (not admin-settable),
 * https, no redirects — so no SSRF surface; the token never appears in
 * errors or logs.
 *
 * @param string $resource Versioned path, e.g. 'v2/monitors' or 'v3/incidents'.
 * @return array|WP_Error Decoded {data:…} array or WP_Error.
 */
function sn_uptime_status_api_get( $resource ) {
	$resp = wp_remote_get( SN_UPTIME_STATUS_API_BASE . $resource, array(
		'timeout'     => 5,
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array(
			'Authorization' => 'Bearer ' . sn_uptime_status_token(),
			'Accept'        => 'application/json',
		),
	) );
	if ( is_wp_error( $resp ) ) {
		return new WP_Error( 'unreachable', 'Better Stack request failed (' . $resource . ').' );
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( 200 !== $code ) {
		return new WP_Error( 'unreachable', 'Better Stack returned HTTP ' . $code . ' (' . $resource . ').' );
	}
	$decoded = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
		return new WP_Error( 'unreachable', 'Better Stack response was not JSON:API (' . $resource . ').' );
	}
	return $decoded;
}

/**
 * Normalize one JSON:API resource into a display row. The id rides along
 * (v8.3.0) so the availability layer can join its per-resource summaries.
 *
 * @param array  $item JSON:API resource ({id,type,attributes}).
 * @param string $kind 'monitor' or 'heartbeat'.
 * @return array {kind,id,name,status,level,checked_at}
 */
function sn_uptime_status_row( $item, $kind ) {
	$attrs  = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
	$name   = (string) ( $attrs['pronounceable_name'] ?? $attrs['name'] ?? $item['id'] ?? '' );
	$status = (string) ( $attrs['status'] ?? 'pending' );
	return array(
		'kind'       => $kind,
		'id'         => (string) ( $item['id'] ?? '' ),
		'name'       => $name,
		'status'     => $status,
		'level'      => sn_uptime_status_level( $status ),
		'checked_at' => isset( $attrs['last_checked_at'] ) ? (string) $attrs['last_checked_at'] : null,
	);
}

/**
 * Windowed availability + incident counts per resource (v8.3.0; window
 * parametrized v8.4.0). One map keyed "kind:id" → {availability:float|null,
 * incidents:int|null}, from the per-resource summary endpoints (monitors
 * use /sla, heartbeats use /availability — same attribute shape). Each
 * window caches on its own transient (30d: 1h; 90d: 6h — the wider the
 * window, the slower it moves). Failure is SOFT and circuit-broken: on the
 * first failed call the remaining calls are skipped and an all-null map is
 * cached for 10 minutes — statuses must never be held hostage by, or
 * hammer, the summary endpoints.
 *
 * @param array  $rows      Normalized snapshot rows (kind + id are read).
 * @param int    $days      Window size in days.
 * @param string $transient Transient key for this window's map.
 * @param int    $ttl       Success TTL in seconds.
 * @return array Map of "kind:id" → array|null.
 */
function sn_uptime_status_availability_map( $rows, $days = 30, $transient = SN_UPTIME_STATUS_AVAIL_TRANSIENT, $ttl = 3600 ) {
	$cached = get_transient( $transient );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$from   = gmdate( 'Y-m-d', time() - $days * 86400 );
	$to     = gmdate( 'Y-m-d' );
	$map    = array();
	$broken = false;

	foreach ( $rows as $row ) {
		$key = $row['kind'] . ':' . $row['id'];
		if ( $broken || '' === $row['id'] ) {
			$map[ $key ] = null;
			continue;
		}
		$path = ( 'monitor' === $row['kind']
			? 'v2/monitors/' . rawurlencode( $row['id'] ) . '/sla'
			: 'v2/heartbeats/' . rawurlencode( $row['id'] ) . '/availability'
		) . '?from=' . $from . '&to=' . $to;
		$resp = sn_uptime_status_api_get( $path );
		if ( is_wp_error( $resp ) || ! isset( $resp['data']['attributes'] ) || ! is_array( $resp['data']['attributes'] ) ) {
			$broken      = true;
			$map[ $key ] = null;
			continue;
		}
		$attrs       = $resp['data']['attributes'];
		$map[ $key ] = array(
			'availability' => isset( $attrs['availability'] ) ? (float) $attrs['availability'] : null,
			'incidents'    => isset( $attrs['number_of_incidents'] ) ? (int) $attrs['number_of_incidents'] : null,
		);
	}

	set_transient( $transient, $map, $broken ? 600 : $ttl );
	return $map;
}

/**
 * Average response time per MONITOR (heartbeats are inbound; they have no
 * response times) from the 24h response-times endpoint. The response nests
 * per-region sample arrays ({at, response_time} in SECONDS); the average
 * here is across every sample of every region, in whole milliseconds —
 * a glanceable number, not a percentile study (the Better Stack console
 * owns the charts). 15 min TTL; same circuit-break discipline as the
 * availability maps.
 *
 * @param array $rows Normalized snapshot rows.
 * @return array Map of "monitor:{id}" → int ms | null.
 */
function sn_uptime_status_response_map( $rows ) {
	$cached = get_transient( SN_UPTIME_STATUS_RESPONSE_TRANSIENT );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$map    = array();
	$broken = false;

	foreach ( $rows as $row ) {
		if ( 'monitor' !== $row['kind'] || '' === $row['id'] ) {
			continue;
		}
		$key = 'monitor:' . $row['id'];
		if ( $broken ) {
			$map[ $key ] = null;
			continue;
		}
		$resp = sn_uptime_status_api_get( 'v2/monitors/' . rawurlencode( $row['id'] ) . '/response-times' );
		if ( is_wp_error( $resp ) || ! isset( $resp['data']['attributes']['regions'] ) || ! is_array( $resp['data']['attributes']['regions'] ) ) {
			$broken      = true;
			$map[ $key ] = null;
			continue;
		}
		$sum   = 0.0;
		$count = 0;
		foreach ( $resp['data']['attributes']['regions'] as $region ) {
			foreach ( (array) ( $region['response_times'] ?? array() ) as $sample ) {
				if ( isset( $sample['response_time'] ) && is_numeric( $sample['response_time'] ) ) {
					$sum += (float) $sample['response_time'];
					$count++;
				}
			}
		}
		$map[ $key ] = $count > 0 ? (int) round( 1000 * $sum / $count ) : null;
	}

	set_transient( SN_UPTIME_STATUS_RESPONSE_TRANSIENT, $map, $broken ? 600 : 900 );
	return $map;
}

/**
 * Recent incidents (v3 endpoint — the one resource NOT on v2), normalized
 * and sorted newest first. resolved_at null = ongoing. 5 min TTL so an
 * active incident shows promptly; failures return null (renderers show an
 * "unavailable" line) and are never cached.
 *
 * @return array|null List of {name,cause,started_at,resolved_at,ongoing,duration_s} or null.
 */
function sn_uptime_status_incidents() {
	$cached = get_transient( SN_UPTIME_STATUS_INCIDENTS_TRANSIENT );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$resp = sn_uptime_status_api_get( 'v3/incidents?per_page=25' );
	if ( is_wp_error( $resp ) ) {
		return null;
	}

	$incidents = array();
	foreach ( (array) $resp['data'] as $item ) {
		$attrs = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
		$start = isset( $attrs['started_at'] ) ? (string) $attrs['started_at'] : '';
		$end   = isset( $attrs['resolved_at'] ) && null !== $attrs['resolved_at'] ? (string) $attrs['resolved_at'] : null;
		$s_ts  = $start ? strtotime( $start ) : false;
		$e_ts  = $end ? strtotime( $end ) : false;
		$incidents[] = array(
			'name'        => (string) ( $attrs['name'] ?? $attrs['url'] ?? '' ),
			'cause'       => (string) ( $attrs['cause'] ?? '' ),
			'started_at'  => $start,
			'resolved_at' => $end,
			'ongoing'     => null === $end,
			'duration_s'  => ( false !== $s_ts && false !== $e_ts ) ? max( 0, $e_ts - $s_ts ) : null,
		);
	}
	usort( $incidents, function ( $a, $b ) {
		return strcmp( $b['started_at'], $a['started_at'] ); // ISO 8601 sorts lexically
	} );
	$incidents = array_slice( $incidents, 0, 10 );

	set_transient( SN_UPTIME_STATUS_INCIDENTS_TRANSIENT, $incidents, 300 );
	return $incidents;
}

/**
 * Fetch the normalized status snapshot: monitors first, then heartbeats.
 * Serves the 90s transient unless $force; failures are returned as
 * WP_Error and never cached (next panel load retries).
 *
 * @param bool $force Bypass the transient.
 * @return array|WP_Error {fetched_at:int, rows:array} or WP_Error.
 */
function sn_uptime_status_fetch( $force = false ) {
	if ( ! sn_uptime_status_configured() ) {
		return new WP_Error( 'not_configured', 'No Better Stack API token configured.' );
	}

	if ( ! $force ) {
		$cached = get_transient( SN_UPTIME_STATUS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$monitors = sn_uptime_status_api_get( 'v2/monitors' );
	if ( is_wp_error( $monitors ) ) {
		return $monitors;
	}
	$heartbeats = sn_uptime_status_api_get( 'v2/heartbeats' );
	if ( is_wp_error( $heartbeats ) ) {
		return $heartbeats;
	}

	$rows = array();
	foreach ( $monitors['data'] as $item ) {
		$rows[] = sn_uptime_status_row( $item, 'monitor' );
	}
	foreach ( $heartbeats['data'] as $item ) {
		$rows[] = sn_uptime_status_row( $item, 'heartbeat' );
	}

	// v8.4.0: statuses ONLY — the widget/rail path stays at two calls. The
	// stat merges (availability windows, response times) live in
	// sn_uptime_status_detail(), which the Analytics page requests.

	$snapshot = array(
		'fetched_at' => time(),
		'rows'       => $rows,
	);
	set_transient( SN_UPTIME_STATUS_TRANSIENT, $snapshot, SN_UPTIME_STATUS_TTL );
	return $snapshot;
}

/**
 * The full monitor payload for the Analytics page (v8.4.0): status rows
 * enriched with 30d + 90d availability, incident counts, and average
 * response times, plus the recent-incidents log. Composes the independent
 * cache tiers — each fails soft to null, so a partial Better Stack outage
 * degrades the table, never the page.
 *
 * @param bool $force Bypass the STATUS transient (stat tiers keep their own cadences).
 * @return array|WP_Error {fetched_at, rows, incidents:array|null} or WP_Error.
 */
function sn_uptime_status_detail( $force = false ) {
	$snap = sn_uptime_status_fetch( $force );
	if ( is_wp_error( $snap ) ) {
		return $snap;
	}

	$rows = $snap['rows'];
	$a30  = sn_uptime_status_availability_map( $rows, 30, SN_UPTIME_STATUS_AVAIL_TRANSIENT, 3600 );
	$a90  = sn_uptime_status_availability_map( $rows, 90, SN_UPTIME_STATUS_AVAIL_90D_TRANSIENT, 21600 );
	$rt   = sn_uptime_status_response_map( $rows );

	foreach ( $rows as $i => $row ) {
		$key                             = $row['kind'] . ':' . $row['id'];
		$e30                             = isset( $a30[ $key ] ) && is_array( $a30[ $key ] ) ? $a30[ $key ] : null;
		$e90                             = isset( $a90[ $key ] ) && is_array( $a90[ $key ] ) ? $a90[ $key ] : null;
		$rows[ $i ]['availability']      = $e30 ? $e30['availability'] : null;
		$rows[ $i ]['incidents_30d']     = $e30 ? $e30['incidents'] : null;
		$rows[ $i ]['availability_90d']  = $e90 ? $e90['availability'] : null;
		$rows[ $i ]['response_ms']       = $rt[ $key ] ?? null;
	}

	return array(
		'fetched_at' => $snap['fetched_at'],
		'rows'       => $rows,
		'incidents'  => sn_uptime_status_incidents(),
	);
}

/**
 * Ability execute callback. Two payload tiers (v8.4.0): light (statuses,
 * stat keys null — the widget/rail path) and detail=true (full monitor:
 * stats populated + the incidents log — the Analytics page path). Three
 * states either way, none of them exceptions: unconfigured (prompt state,
 * not an error), payload, unreachable (configured + error message).
 *
 * @param array|null $input {force_refresh?:bool, detail?:bool}.
 * @return array {configured,fetched_at,rows,incidents,error}
 */
function snt_ability_uptime_status( $input = null ) {
	if ( ! sn_uptime_status_configured() ) {
		return array(
			'configured' => false,
			'fetched_at' => 0,
			'rows'       => array(),
			'incidents'  => null,
			'error'      => '',
		);
	}
	$force  = is_array( $input ) && ! empty( $input['force_refresh'] );
	$detail = is_array( $input ) && ! empty( $input['detail'] );

	$payload = $detail ? sn_uptime_status_detail( $force ) : sn_uptime_status_fetch( $force );
	if ( is_wp_error( $payload ) ) {
		return array(
			'configured' => true,
			'fetched_at' => 0,
			'rows'       => array(),
			'incidents'  => null,
			'error'      => $payload->get_error_message(),
		);
	}

	$rows = $payload['rows'];
	if ( ! $detail ) {
		// Stable row shape across tiers: stat keys present, just null.
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['availability']     = null;
			$rows[ $i ]['incidents_30d']    = null;
			$rows[ $i ]['availability_90d'] = null;
			$rows[ $i ]['response_ms']      = null;
		}
	}

	return array(
		'configured' => true,
		'fetched_at' => (int) $payload['fetched_at'],
		'rows'       => $rows,
		'incidents'  => $detail ? $payload['incidents'] : null,
		'error'      => '',
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/uptime-status', array(
		'label'               => 'Get Better Stack uptime status',
		'description'         => 'Returns the Better Stack monitor + heartbeat states (name, status, level) from a 90s server-side cache. Pass detail=true for the full monitor payload: 30d + 90d availability, incident counts, average response times (24h), and the recent-incidents log (independently cached tiers: 1h/6h/15min/5min). Pass force_refresh=true to bypass the status cache. Read-only; safe to call anytime. configured=false means no API token is saved yet (not an error); null stats mean that summary tier was unavailable (statuses are still authoritative).',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_uptime_status',
		'input_schema'        => array(
			// null accepted: readonly abilities (GET) receive null when the
			// caller omits ?input= (see the purge-all-caches comment).
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'force_refresh' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Bypass the 90s snapshot transient and hit the Uptime API fresh.',
				),
				'detail'        => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Return the full monitor payload: availability windows, response times, and the incidents log (the Analytics page tier).',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'configured' => array( 'type' => 'boolean' ),
				'fetched_at' => array( 'type' => 'integer' ),
				'rows'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'kind'          => array( 'type' => 'string', 'enum' => array( 'monitor', 'heartbeat' ) ),
							'id'            => array( 'type' => 'string' ),
							'name'          => array( 'type' => 'string' ),
							'status'        => array( 'type' => 'string' ),
							'level'         => array( 'type' => 'string', 'enum' => array( 'ok', 'warn', 'alert' ) ),
							'checked_at'    => array( 'type' => array( 'string', 'null' ) ),
							'availability'  => array( 'type' => array( 'number', 'null' ), 'description' => '30-day availability percentage; null on the light tier or when the summary endpoint was unavailable.' ),
							'incidents_30d' => array( 'type' => array( 'integer', 'null' ) ),
							'availability_90d' => array( 'type' => array( 'number', 'null' ) ),
							'response_ms'   => array( 'type' => array( 'integer', 'null' ), 'description' => 'Average response time over the last 24h in ms (monitors only, detail tier).' ),
						),
					),
				),
				'incidents'  => array(
					'type'        => array( 'array', 'null' ),
					'description' => 'Recent incidents, newest first (detail tier only; null on the light tier or when the incidents endpoint was unavailable).',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array( 'type' => 'string' ),
							'cause'       => array( 'type' => 'string' ),
							'started_at'  => array( 'type' => 'string' ),
							'resolved_at' => array( 'type' => array( 'string', 'null' ) ),
							'ongoing'     => array( 'type' => 'boolean' ),
							'duration_s'  => array( 'type' => array( 'integer', 'null' ) ),
						),
					),
				),
				'error'      => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );
} );

/**
 * The async mount shell shared by the widget and the rail panel. Its own
 * container by design — .sn-status-box is a sealed two-child flex row
 * (see the open-wide redesign), so the panel never nests inside one.
 *
 * @return string
 */
function sn_uptime_status_mount_html() {
	return '<div class="sn-uptime-status" data-sn-uptime-status>'
		. '<p class="sn-uw-loading">' . esc_html__( 'Checking Better Stack…', 'signal-noise-tools' ) . '</p>'
		. '</div>';
}

/**
 * The API-token field for the Uptime monitoring fieldset. Mirrors the
 * Cloudflare token field: constant-locked → disabled input naming the
 * wp-config constant; otherwise obscured value, paste a fresh token to
 * update, type 'clear' to remove. The raw token never renders.
 *
 * @return string
 */
function sn_uptime_status_token_field_html() {
	$html = '<div class="sn-field sn-field-w-lg">';
	$html .= '<label class="sn-field-label" for="sn_betterstack_token">' . esc_html__( 'Better Stack API token (optional)', 'signal-noise-tools' ) . '</label>';
	if ( defined( 'SN_BETTERSTACK_API_TOKEN' ) && SN_BETTERSTACK_API_TOKEN ) {
		$html .= '<input type="text" id="sn_betterstack_token" value="' . esc_attr( '••••' ) . '" disabled class="sn-mono">';
		$html .= '<p class="sn-field-helper"><strong>' . esc_html__( 'Locked.', 'signal-noise-tools' ) . '</strong> ' . esc_html__( 'Set via', 'signal-noise-tools' ) . ' <code>SN_BETTERSTACK_API_TOKEN</code> ' . esc_html__( 'in', 'signal-noise-tools' ) . ' <code>wp-config.php</code>.</p>';
	} else {
		$obscured = sn_mask_secret( (string) get_option( SN_UPTIME_STATUS_TOKEN_OPT, '' ) );
		$html .= '<input type="text" id="sn_betterstack_token" name="sn_betterstack_token" value="' . esc_attr( $obscured ) . '" placeholder="' . esc_attr__( 'Paste a fresh token to update; type \'clear\' to remove', 'signal-noise-tools' ) . '" class="sn-mono">';
		$html .= '<p class="sn-field-helper">' . esc_html__( 'Uptime API token (read scope is enough). Powers the in-admin status panel: the dashboard widget and the rail on this tab. Leave the obscured value alone to keep the existing token.', 'signal-noise-tools' ) . '</p>';
	}
	$html .= '</div>';
	return $html;
}
