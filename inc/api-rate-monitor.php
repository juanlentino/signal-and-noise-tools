<?php
/**
 * Signal & Noise Tools — Outgoing API rate-limit monitor.
 *
 * Listens to WP's `http_response` filter on every wp_remote_* call,
 * inspects the response URL host, reads relevant rate-limit headers,
 * and stores the latest snapshot in a per-host site transient.
 *
 * Tracked hosts (auto-detected from response URL):
 *   - api.github.com           (GitHub REST API + GHA Actions API)
 *   - api.cloudflare.com       (CF zone purge + management)
 *   - plausible.io             (Plausible stats API)
 *
 * Why http_response and not a counter we maintain ourselves: the server's
 * rate-limit headers are the source of truth. Our counter would drift if
 * any caller bypassed our wrapper (e.g. another plugin hitting the same
 * endpoint, or WP core itself for translation API calls). Reading the
 * server's response means we always have the authoritative number.
 *
 * Verified against WP source: `apply_filters('http_response', $response,
 * $parsed_args, $url)` in wp-includes/class-wp-http.php fires after the
 * request completes successfully. $response is guaranteed array (never
 * WP_Error). accept_args = 3.
 *
 * Email warning: throttled to once-per-day per host, only when remaining
 * drops below SNT_RATE_WARN_THRESHOLD (10%). Uses get_option('admin_email').
 *
 * Added in v1.13.0 (2026-05-16).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_RATE_CACHE_KEY_PREFIX = 'sn_rate_limit_';
const SNT_RATE_CACHE_TTL        = 5 * MINUTE_IN_SECONDS;
const SNT_RATE_WARN_THRESHOLD   = 0.10; // 10% remaining triggers email.
const SNT_RATE_WARN_LOCK_PREFIX = 'sn_rate_warned_';
const SNT_RATE_WARN_LOCK_TTL    = DAY_IN_SECONDS;

/**
 * Map of {host_suffix => header_set} we know how to parse.
 * Host_suffix is matched with str_ends_with — supports subdomain variation.
 * header_set: which header names to read (lowercase per WP's normalization).
 */
function snt_rate_limit_hosts() {
	return array(
		'api.github.com' => array(
			'remaining' => 'x-ratelimit-remaining',
			'limit'     => 'x-ratelimit-limit',
			'reset'     => 'x-ratelimit-reset', // Unix epoch.
			'label'     => 'GitHub API',
		),
		'api.cloudflare.com' => array(
			'remaining' => 'x-ratelimit-remaining',
			'limit'     => 'x-ratelimit-limit',
			'reset'     => 'x-ratelimit-reset',
			'label'     => 'Cloudflare API',
		),
		'plausible.io' => array(
			'remaining' => 'x-ratelimit-remaining-minute',
			'limit'     => 'x-ratelimit-limit-minute',
			'reset'     => 'x-ratelimit-reset',
			'label'     => 'Plausible API',
		),
	);
}

/**
 * http_response filter — record rate-limit snapshot if URL matches a
 * tracked host. Always returns $response unchanged.
 */
add_filter( 'http_response', function( $response, $parsed_args, $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! $host ) {
		return $response;
	}

	$config = null;
	$host_key = '';
	foreach ( snt_rate_limit_hosts() as $suffix => $cfg ) {
		if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
			$config   = $cfg;
			$host_key = $suffix;
			break;
		}
	}
	if ( ! $config ) {
		return $response;
	}

	$remaining = wp_remote_retrieve_header( $response, $config['remaining'] );
	$limit     = wp_remote_retrieve_header( $response, $config['limit'] );
	$reset     = wp_remote_retrieve_header( $response, $config['reset'] );

	// Servers that don't return rate-limit headers (or returned 5xx
	// without them) skip recording — we keep whatever the last good
	// snapshot was.
	if ( $remaining === '' || $limit === '' ) {
		return $response;
	}

	$snapshot = array(
		'remaining'  => (int) $remaining,
		'limit'      => (int) $limit,
		'reset_at'   => $reset !== '' ? (int) $reset : 0,
		'fetched_at' => time(),
		'label'      => $config['label'],
	);

	$cache_key = SNT_RATE_CACHE_KEY_PREFIX . sanitize_key( $host_key );
	set_site_transient( $cache_key, $snapshot, SNT_RATE_CACHE_TTL );

	snt_rate_limit_maybe_warn( $host_key, $snapshot );

	return $response;
}, 10, 3 );

/**
 * Public helper. Returns the latest snapshot for a tracked host or null.
 *
 * @param string $host  e.g. 'api.github.com'
 * @return array|null   ['remaining', 'limit', 'reset_at', 'fetched_at', 'label']
 */
function snt_rate_limit_status( $host ) {
	$snap = get_site_transient( SNT_RATE_CACHE_KEY_PREFIX . sanitize_key( $host ) );
	return is_array( $snap ) ? $snap : null;
}

/**
 * All known tracked hosts with their latest snapshots (null entry if
 * we haven't seen a request yet). Used by the deploy widget.
 */
function snt_rate_limit_all_statuses() {
	$out = array();
	foreach ( snt_rate_limit_hosts() as $host => $cfg ) {
		$out[ $host ] = array(
			'label'    => $cfg['label'],
			'snapshot' => snt_rate_limit_status( $host ),
		);
	}
	return $out;
}

/**
 * Compute a state bucket for UI coloring.
 *   ok    = >= 25% remaining
 *   warn  = 10-25% remaining
 *   crit  = < 10% remaining (also triggers email)
 *   unknown = no snapshot yet, or limit was zero/missing
 */
function snt_rate_limit_state( $snapshot ) {
	if ( ! is_array( $snapshot ) || empty( $snapshot['limit'] ) ) {
		return 'unknown';
	}
	$pct = $snapshot['remaining'] / $snapshot['limit'];
	if ( $pct < SNT_RATE_WARN_THRESHOLD ) {
		return 'crit';
	}
	if ( $pct < 0.25 ) {
		return 'warn';
	}
	return 'ok';
}

/**
 * Send a throttled wp_mail() warning when a tracked host crosses
 * below the SNT_RATE_WARN_THRESHOLD. Throttle: once per day per host
 * (transient lock).
 *
 * Doesn't fire on 'unknown' state (no snapshot or zero limit) — we
 * only warn on hard data.
 */
function snt_rate_limit_maybe_warn( $host, $snapshot ) {
	if ( 'crit' !== snt_rate_limit_state( $snapshot ) ) {
		return;
	}

	$lock_key = SNT_RATE_WARN_LOCK_PREFIX . sanitize_key( $host );
	if ( get_site_transient( $lock_key ) ) {
		return; // Already warned today.
	}
	set_site_transient( $lock_key, 1, SNT_RATE_WARN_LOCK_TTL );

	$admin_email = (string) get_option( 'admin_email' );
	if ( ! $admin_email || ! is_email( $admin_email ) ) {
		return;
	}

	$pct          = (int) round( ( $snapshot['remaining'] / max( 1, $snapshot['limit'] ) ) * 100 );
	$reset_human  = $snapshot['reset_at'] ? human_time_diff( time(), $snapshot['reset_at'] ) : 'unknown';
	$site_name    = (string) wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

	$subject = sprintf( '[%s] %s rate limit low: %d%% remaining', $site_name, $snapshot['label'], $pct );
	$body    = sprintf(
		"Signal & Noise rate-limit monitor:\n\n" .
		"Host: %s (%s)\n" .
		"Remaining: %d / %d (%d%%)\n" .
		"Window resets in: %s\n" .
		"Triggered at: %s UTC\n\n" .
		"This is the daily-throttled warning. You will not receive another email for this host for 24h, even if remaining drops further.\n\n" .
		"Mitigation:\n" .
		"  - GitHub: define SNT_GITHUB_TOKEN in wp-config.php to raise the 60/h unauthenticated limit to 5000/h.\n" .
		"  - Plausible/Cloudflare: investigate which caller is spending the budget; the deploy widget's API limits section shows recent activity.\n",
		$snapshot['label'],
		$host,
		(int) $snapshot['remaining'],
		(int) $snapshot['limit'],
		$pct,
		$reset_human,
		gmdate( 'Y-m-d H:i:s' )
	);

	wp_mail( $admin_email, $subject, $body );
}
