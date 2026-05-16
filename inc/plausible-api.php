<?php
/**
 * Signal & Noise — Plausible Stats API client.
 *
 * Reads the official Plausible plugin's stored settings (domain + Plugin
 * Token) from `plausible_analytics_settings`, makes Stats API calls,
 * and exposes two cache layers — both read-only on the page-render path:
 *
 *   - sn_plausible_dashboard_data()   — 7-day aggregate + top pages + top
 *                                       sources. Freshness target: 5 min.
 *   - sn_plausible_realtime()         — visitor count right now.
 *                                       Freshness target: 30 sec.
 *
 * Architecture (since v7.2.6): stale-while-revalidate via WP-Cron.
 *
 *   - The two accessors above NEVER make network calls. They return
 *     whatever's in the transient (possibly empty on first-ever load,
 *     possibly stale during a refresh in flight) so dashboard render
 *     time is constant — no admin pageview ever blocks on Plausible.
 *
 *   - `admin_init` runs sn_plausible_warm_caches(), which checks the
 *     `fetched` timestamp baked into each cached payload. If the data
 *     is older than its freshness target (or absent), it schedules a
 *     non-blocking single-event WP-Cron job. WP fires spawn_cron() at
 *     wp_loaded (after admin_init), which dispatches a non-blocking
 *     loopback to wp-cron.php — the actual Plausible API calls run in
 *     a separate process while the admin response is already on its
 *     way to the browser.
 *
 *   - Transient retention (DAY_IN_SECONDS for the batch, 5 min for
 *     realtime) is much longer than the freshness window. Stale data
 *     remains visible if the API goes down; the widget footer's
 *     "cached X ago" line surfaces how stale it is.
 *
 * Why this matters: the prior on-render-fetch design blocked the WP
 * dashboard for up to 4 × 6s = 24s on every cache-miss (every 5 min by
 * design). SWR removes that hang completely.
 *
 * Self-hosted Plausible is supported via the same plugin option's
 * `self_hosted_domain` key; falls back to plausible.io otherwise.
 *
 * @package SignalNoise
 * @since 7.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_PLAUSIBLE_BATCH_KEY            = 'sn_plausible_dashboard_v4';
const SN_PLAUSIBLE_BATCH_TTL            = 5 * MINUTE_IN_SECONDS;       // freshness target
const SN_PLAUSIBLE_BATCH_RETENTION      = DAY_IN_SECONDS;              // stale-but-cached survives this long
const SN_PLAUSIBLE_REALTIME_KEY         = 'sn_plausible_realtime_v3';
const SN_PLAUSIBLE_REALTIME_TTL         = 30;                          // freshness target (seconds)
const SN_PLAUSIBLE_REALTIME_RETENTION   = 5 * MINUTE_IN_SECONDS;       // stale-but-cached survives this long
const SN_PLAUSIBLE_TOKEN_OPT            = 'sn_plausible_stats_token';
const SN_PLAUSIBLE_REFRESH_BATCH_HOOK   = 'sn_plausible_refresh_dashboard';
const SN_PLAUSIBLE_REFRESH_REALTIME_HOOK = 'sn_plausible_refresh_realtime';

/**
 * Resolve domain + token + API base. Returns null when not configured.
 *
 * @return array{domain: string, token: string, base: string}|null
 */
function sn_plausible_config() {
	$settings = get_option( 'plausible_analytics_settings', array() );
	if ( ! is_array( $settings ) ) {
		return null;
	}
	$domain = trim( (string) ( $settings['domain_name'] ?? '' ) );

	// Token resolution priority (mirror of inc/cloudflare-purge.php):
	//
	//   1. SN_PLAUSIBLE_STATS_TOKEN constant in wp-config.php — a
	//      dedicated Stats API Key (Plausible → Settings → API Keys).
	//      File-based, can't be edited from the admin UI, follows the
	//      same pattern as SN_GITHUB_TOKEN / SN_CLOUDFLARE_API_TOKEN.
	//
	//   2. SN_PLAUSIBLE_TOKEN_OPT option — admin-saved via the
	//      Appearance → Signal & Noise → Plausible tab. Non-autoloaded
	//      (third arg to update_option = false) so the credential
	//      isn't loaded into PHP memory on every request.
	//
	//   3. The Plausible plugin's stored `api_token` from
	//      `plausible_analytics_settings`. ⚠ This is a *Plugin Token*
	//      (created by the plugin's wizard for /api/plugins/wordpress/*
	//      operations), NOT a Stats API key on Plausible CE — kept as
	//      a last-resort fallback for setups where the namespaces
	//      happen to align (some Plausible Cloud configurations).
	if ( defined( 'SN_PLAUSIBLE_STATS_TOKEN' ) && SN_PLAUSIBLE_STATS_TOKEN ) {
		$token = (string) SN_PLAUSIBLE_STATS_TOKEN;
	} else {
		$option_token = (string) get_option( SN_PLAUSIBLE_TOKEN_OPT, '' );
		$token        = '' !== $option_token ? $option_token : trim( (string) ( $settings['api_token'] ?? '' ) );
	}
	if ( '' === $domain || '' === $token ) {
		return null;
	}
	$self_host = trim( (string) ( $settings['self_hosted_domain'] ?? '' ) );
	// Defensive: the plugin's settings field accepts a hostname or a full
	// URL. If the admin saved just the hostname (common for self-hosted
	// CE setups on Railway/Fly/etc.), wp_remote_get() can't dispatch the
	// request — the resulting URL has no scheme. Prepend https:// when
	// missing so this works regardless of how the plugin field was filled.
	if ( '' !== $self_host && ! preg_match( '#^https?://#i', $self_host ) ) {
		$self_host = 'https://' . $self_host;
	}
	$base = '' !== $self_host ? rtrim( $self_host, '/' ) : 'https://plausible.io';
	return array( 'domain' => $domain, 'token' => $token, 'base' => $base );
}

/**
 * Transient key for the last API error. Stored separately from the data
 * cache so failure context survives the same 5-min window the empty
 * data lives in — admins see *why* the widgets are empty, not just that
 * they are.
 */
const SN_PLAUSIBLE_ERR_KEY = 'sn_plausible_last_error';

/**
 * Record the most recent API failure. Token is never written — only the
 * URL (which doesn't include credentials), HTTP status, and a body
 * excerpt. 5-min TTL matches the data cache so a successful refresh
 * naturally ages the diagnostic out alongside the cached data.
 */
function sn_plausible_record_error( $url, $code, $message ) {
	set_transient( SN_PLAUSIBLE_ERR_KEY, array(
		'url'     => (string) $url,
		'code'    => (int) $code,
		'message' => (string) $message,
		'when'    => time(),
	), 5 * MINUTE_IN_SECONDS );
}

/**
 * Read the most recent recorded API error, if any.
 *
 * @return array{url:string, code:int, message:string, when:int}|null
 */
function sn_plausible_last_error() {
	$err = get_transient( SN_PLAUSIBLE_ERR_KEY );
	return is_array( $err ) ? $err : null;
}

/**
 * GET a Plausible Stats API path.
 *
 * On any failure, captures the URL + HTTP code + body excerpt into the
 * SN_PLAUSIBLE_ERR_KEY transient so the widget can surface *why* the
 * data is missing. Cleared on the next successful call from the same
 * request, so a transient outage that resolves itself doesn't leave a
 * stale "API failed" banner sitting on the dashboard.
 *
 * @param string $path        Path under /api/v1/stats — e.g. 'aggregate'.
 * @param array  $query       Query args; site_id is merged in automatically.
 * @param array  $cfg         Output of sn_plausible_config().
 * @param bool   $expect_json False = body is a bare integer (realtime endpoint).
 * @return mixed|null Decoded `results` payload, raw int, or null on failure.
 */
function sn_plausible_api( $path, $query, $cfg, $expect_json = true ) {
	$query = wp_parse_args( $query, array( 'site_id' => $cfg['domain'] ) );
	$url   = $cfg['base'] . '/api/v1/stats/' . ltrim( $path, '/' ) . '?' . http_build_query( $query );

	$response = wp_remote_get( $url, array(
		'headers' => array( 'Authorization' => 'Bearer ' . $cfg['token'] ),
		'timeout' => 6,
	) );

	if ( is_wp_error( $response ) ) {
		sn_plausible_record_error( $url, 0, $response->get_error_message() );
		return null;
	}
	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	if ( 200 !== $code ) {
		sn_plausible_record_error( $url, $code, substr( (string) $body, 0, 240 ) );
		return null;
	}

	// Success — clear any stale error from a prior failed batch.
	delete_transient( SN_PLAUSIBLE_ERR_KEY );

	if ( ! $expect_json ) {
		// Realtime endpoint returns a bare integer, not a JSON envelope.
		$trimmed = trim( $body );
		return is_numeric( $trimmed ) ? (int) $trimmed : null;
	}
	$decoded = json_decode( $body, true );
	return $decoded['results'] ?? null;
}

/**
 * Read-only accessor: returns whatever's in the batched cache without
 * ever firing a network call. The widget renders against this and the
 * admin_init warmer below schedules a background refresh when the
 * `fetched` field is older than SN_PLAUSIBLE_BATCH_TTL.
 *
 * Three return shapes:
 *   - null                              → Plausible not configured
 *                                         (widget shows the setup help text)
 *   - array with fetched=0 and empty
 *     aggregate/pages/sources           → configured but the very first
 *                                         background refresh hasn't landed
 *                                         yet (widget shows "—" placeholders
 *                                         and a "first refresh in flight"
 *                                         footer)
 *   - array with fetched>0              → real data, possibly stale but
 *                                         being refreshed in the background
 *
 * @return array|null
 */
function sn_plausible_dashboard_data() {
	$cached = get_transient( SN_PLAUSIBLE_BATCH_KEY );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	if ( ! sn_plausible_config() ) {
		return null;
	}
	return array(
		'aggregate' => array(),
		'pages'     => array(),
		'sources'   => array(),
		'fetched'   => 0,
	);
}

/**
 * Read-only accessor for the realtime visitor count. Like its batched
 * sibling, never makes a network call. Cache shape since v7.2.6 is
 * `array{ value: int, fetched: int }` — the embedded `fetched` is what
 * the warmer reads to decide whether to schedule a 30-sec refresh,
 * since the transient itself is retained for 5 min so stale data
 * survives an API outage.
 *
 * @return int|null Null when no fresh-or-stale value is available
 *                  (first-ever load before warm-up, or after retention
 *                  TTL elapsed). Otherwise the last cached count.
 */
function sn_plausible_realtime() {
	$cached = get_transient( SN_PLAUSIBLE_REALTIME_KEY );
	if ( is_array( $cached ) && isset( $cached['value'] ) && is_int( $cached['value'] ) ) {
		return $cached['value'];
	}
	return null;
}

/**
 * WP-Cron callback: do the actual 7-day batched fetch and write the
 * cache. Runs in a non-blocking loopback request fired by spawn_cron(),
 * never on the admin render path.
 */
function sn_plausible_refresh_dashboard() {
	$cfg = sn_plausible_config();
	if ( ! $cfg ) {
		return;
	}
	$aggregate = sn_plausible_api( 'aggregate', array(
		'period'  => '7d',
		'metrics' => 'visitors,pageviews,bounce_rate,visit_duration',
	), $cfg );
	$pages = sn_plausible_api( 'breakdown', array(
		'period'   => '7d',
		'property' => 'event:page',
		'limit'    => 7,
	), $cfg );
	$sources = sn_plausible_api( 'breakdown', array(
		'period'   => '7d',
		'property' => 'visit:source',
		'limit'    => 7,
	), $cfg );

	// Long retention so stale data survives if the API is unreachable.
	// Freshness is gated by the `fetched` field — see the admin_init
	// warmer below.
	set_transient( SN_PLAUSIBLE_BATCH_KEY, array(
		'aggregate' => is_array( $aggregate ) ? $aggregate : array(),
		'pages'     => is_array( $pages )     ? $pages     : array(),
		'sources'   => is_array( $sources )   ? $sources   : array(),
		'fetched'   => time(),
	), SN_PLAUSIBLE_BATCH_RETENTION );
}
add_action( SN_PLAUSIBLE_REFRESH_BATCH_HOOK, 'sn_plausible_refresh_dashboard' );

/**
 * WP-Cron callback: refresh the realtime count. Only writes the cache
 * on a successful fetch; on null/error we leave any prior value to age
 * out via SN_PLAUSIBLE_REALTIME_RETENTION rather than poison the
 * transient with `null` (which `get_transient` can't distinguish from
 * "expired").
 */
function sn_plausible_refresh_realtime() {
	$cfg = sn_plausible_config();
	if ( ! $cfg ) {
		return;
	}
	$value = sn_plausible_api( 'realtime/visitors', array(), $cfg, false );
	if ( is_int( $value ) ) {
		set_transient( SN_PLAUSIBLE_REALTIME_KEY, array(
			'value'   => $value,
			'fetched' => time(),
		), SN_PLAUSIBLE_REALTIME_RETENTION );
	}
}
add_action( SN_PLAUSIBLE_REFRESH_REALTIME_HOOK, 'sn_plausible_refresh_realtime' );

/**
 * Admin warmer: on every admin pageview, check the cache freshness and
 * schedule a background refresh if either dataset is stale or missing.
 *
 * Hooked at admin_init priority 5 so the scheduling happens BEFORE
 * wp_loaded fires — that way wp_cron() picks up the just-scheduled
 * event in the same request and dispatches the non-blocking loopback
 * (spawn_cron uses wp_remote_post with blocking=false, timeout=0.01).
 * The actual Plausible API calls then run in a parallel process while
 * the admin response is already on its way to the browser.
 *
 * Capability gate matches the widget registration in
 * inc/plausible-widget.php so we don't warm caches for users who can
 * never see the widgets anyway.
 */
function sn_plausible_warm_caches() {
	if ( ! current_user_can( 'view_stats' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! sn_plausible_config() ) {
		return;
	}

	// Batched 7-day data: refresh if the payload is missing or its
	// `fetched` timestamp is older than the freshness target. The
	// wp_next_scheduled gate prevents stacking multiple events for the
	// same hook when several admins hit the dashboard within the same
	// freshness window.
	$batch     = get_transient( SN_PLAUSIBLE_BATCH_KEY );
	$batch_age = ( is_array( $batch ) && isset( $batch['fetched'] ) )
		? ( time() - (int) $batch['fetched'] )
		: PHP_INT_MAX;
	if ( $batch_age > SN_PLAUSIBLE_BATCH_TTL && ! wp_next_scheduled( SN_PLAUSIBLE_REFRESH_BATCH_HOOK ) ) {
		wp_schedule_single_event( time(), SN_PLAUSIBLE_REFRESH_BATCH_HOOK );
	}

	// Realtime: same SWR pattern as the batch, with shorter freshness
	// (30s, the "right now" cadence the widget advertises) but longer
	// retention (5 min, so a transient API blip doesn't blank the
	// widget). Freshness is gated by the embedded `fetched` field, not
	// the transient TTL.
	$rt     = get_transient( SN_PLAUSIBLE_REALTIME_KEY );
	$rt_age = ( is_array( $rt ) && isset( $rt['fetched'] ) )
		? ( time() - (int) $rt['fetched'] )
		: PHP_INT_MAX;
	if ( $rt_age > SN_PLAUSIBLE_REALTIME_TTL && ! wp_next_scheduled( SN_PLAUSIBLE_REFRESH_REALTIME_HOOK ) ) {
		wp_schedule_single_event( time(), SN_PLAUSIBLE_REFRESH_REALTIME_HOOK );
	}
}
add_action( 'admin_init', 'sn_plausible_warm_caches', 5 );
