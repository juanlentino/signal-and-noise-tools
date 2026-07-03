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
 * Endpoints (betterstack.com/docs/uptime/api, verified 2026-07-02):
 *   GET https://uptime.betterstack.com/api/v2/monitors
 *   GET https://uptime.betterstack.com/api/v2/heartbeats
 * Both are JSON:API ({data:[{id,type,attributes:{…}}]}) with pagination;
 * first page (50) is plenty for this site's monitor count, so pagination
 * is deliberately not walked.
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
define( 'SN_UPTIME_STATUS_TTL', 90 );
define( 'SN_UPTIME_STATUS_API_BASE', 'https://uptime.betterstack.com/api/v2/' );

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
 * @param string $resource 'monitors' or 'heartbeats'.
 * @return array|WP_Error Decoded {data:[…]} array or WP_Error.
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
 * 30-day availability + incident counts per resource (v8.3.0). One map
 * keyed "kind:id" → {availability:float|null, incidents:int|null}, from
 * the per-resource summary endpoints (monitors use /sla, heartbeats use
 * /availability — same attribute shape, verified 2026-07-02). Cached ONE
 * HOUR (availability barely moves; the status snapshot stays on its own
 * 90s cadence). Failure is SOFT and circuit-broken: on the first failed
 * call the remaining calls are skipped and an all-null map is cached for
 * 10 minutes — statuses must never be held hostage by, or hammer, the
 * summary endpoints.
 *
 * @param array $rows Normalized snapshot rows (kind + id are read).
 * @return array Map of "kind:id" → array|null.
 */
function sn_uptime_status_availability_map( $rows ) {
	$cached = get_transient( SN_UPTIME_STATUS_AVAIL_TRANSIENT );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$from   = gmdate( 'Y-m-d', time() - 30 * 86400 );
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
			? 'monitors/' . rawurlencode( $row['id'] ) . '/sla'
			: 'heartbeats/' . rawurlencode( $row['id'] ) . '/availability'
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

	set_transient( SN_UPTIME_STATUS_AVAIL_TRANSIENT, $map, $broken ? 600 : 3600 );
	return $map;
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

	$monitors = sn_uptime_status_api_get( 'monitors' );
	if ( is_wp_error( $monitors ) ) {
		return $monitors;
	}
	$heartbeats = sn_uptime_status_api_get( 'heartbeats' );
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

	// v8.3.0: merge the (separately cached, soft-failing) 30d availability.
	$avail = sn_uptime_status_availability_map( $rows );
	foreach ( $rows as $i => $row ) {
		$entry                        = $avail[ $row['kind'] . ':' . $row['id'] ] ?? null;
		$rows[ $i ]['availability']   = is_array( $entry ) ? $entry['availability'] : null;
		$rows[ $i ]['incidents_30d']  = is_array( $entry ) ? $entry['incidents'] : null;
	}

	$snapshot = array(
		'fetched_at' => time(),
		'rows'       => $rows,
	);
	set_transient( SN_UPTIME_STATUS_TRANSIENT, $snapshot, SN_UPTIME_STATUS_TTL );
	return $snapshot;
}

/**
 * Ability execute callback. Three states, none of them exceptions:
 * unconfigured (prompt state, not an error), snapshot, unreachable
 * (configured + error message, empty rows).
 *
 * @param array|null $input {force_refresh?:bool}.
 * @return array {configured,fetched_at,rows,error}
 */
function snt_ability_uptime_status( $input = null ) {
	if ( ! sn_uptime_status_configured() ) {
		return array(
			'configured' => false,
			'fetched_at' => 0,
			'rows'       => array(),
			'error'      => '',
		);
	}
	$force = is_array( $input ) && ! empty( $input['force_refresh'] );
	$snap  = sn_uptime_status_fetch( $force );
	if ( is_wp_error( $snap ) ) {
		return array(
			'configured' => true,
			'fetched_at' => 0,
			'rows'       => array(),
			'error'      => $snap->get_error_message(),
		);
	}
	return array(
		'configured' => true,
		'fetched_at' => (int) $snap['fetched_at'],
		'rows'       => $snap['rows'],
		'error'      => '',
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/uptime-status', array(
		'label'               => 'Get Better Stack uptime status',
		'description'         => 'Returns the Better Stack monitor + heartbeat states (name, status, level) plus 30-day availability and incident counts, from server-side caches (90s statuses, 1h availability). Pass force_refresh=true to bypass the status cache. Read-only; safe to call anytime. configured=false means no API token is saved yet (not an error); availability=null means the summary endpoints were unavailable (statuses are still authoritative).',
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
							'availability'  => array( 'type' => array( 'number', 'null' ), 'description' => '30-day availability percentage; null when the summary endpoint was unavailable.' ),
							'incidents_30d' => array( 'type' => array( 'integer', 'null' ) ),
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
