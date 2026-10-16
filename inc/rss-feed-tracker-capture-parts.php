<?php
/**
 * Signal & Noise Tools: rss-feed-tracker.php, settings and tokens, the bot
 * check, the event send, the request capture, install and the prune cron, with
 * their hooks.
 *
 * Split from inc/rss-feed-tracker.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default settings. The collector URL defaults to this site's own first-party
 * endpoint (the Cloudflare Worker route `/_sn/px`), so a fresh install works
 * before anyone visits the settings tab — admins can override per-host via the
 * UI (e.g. to a workers.dev URL if the origin doesn't hairpin to the edge).
 */
function sn_rss_tracker_defaults() {
	return array(
		'enabled'            => true,
		'collector_url'      => home_url( '/_sn/px' ),
		'event_name'         => 'RSS Feed Request',
		'log_retention_days' => 90,
	);
}

/**
 * Reset the RSS tracker's own settings to their defaults, KEEPING the
 * collector endpoint. It shares this option but belongs to Measurement ›
 * Analytics since v10.46.0, and the Worker-version, salt-window and
 * login-guard probes all read its base: deleting the option snapped a
 * workers.dev override back to home_url( '/_sn/px' ) (2026-10-12 audit).
 *
 * @return void
 */
function sn_rss_tracker_reset_settings() {
	$stored = get_option( SN_RSS_TRACKER_SETTINGS_OPT, array() );
	$kept   = is_array( $stored ) ? (string) ( $stored['collector_url'] ?? '' ) : ''; // the stored one; the default needs no keeping
	delete_option( SN_RSS_TRACKER_SETTINGS_OPT );
	if ( '' !== $kept && home_url( '/_sn/px' ) !== $kept ) {
		update_option( SN_RSS_TRACKER_SETTINGS_OPT, array( 'collector_url' => $kept ) );
	}
}

function sn_rss_tracker_settings() {
	$stored = get_option( SN_RSS_TRACKER_SETTINGS_OPT, array() );
	return wp_parse_args( is_array( $stored ) ? $stored : array(), sn_rss_tracker_defaults() );
}

/**
 * The shared collector token. Same source as the theme's front-end beacon
 * (the SN_BEACON_TOKEN wp-config constant + the sn_beacon_token filter) so the
 * plugin, the browser beacon, and the Worker (SN_PX_TOKEN) all agree. Empty
 * when unset → the tracker skips the collector POST (the local log still runs).
 */
function sn_rss_tracker_token() {
	$token = defined( 'SN_BEACON_TOKEN' ) ? (string) SN_BEACON_TOKEN : '';
	return (string) apply_filters( 'sn_beacon_token', $token );
}

/**
 * The PRIVATE server token (`SN_SRV_TOKEN`), sent as `sk` so the Worker can
 * require it before trusting `srv:1` as a human hit. Unlike SN_BEACON_TOKEN
 * (which is embedded in the public theme JS), this never appears in any
 * client-delivered page — so a hostile client holding only the public token
 * cannot forge a human-classed server event. Empty when unset → the Worker
 * falls back to public-token-only trust (migration-safe; see worker v1.5.0).
 *
 * @since 6.22.0
 * @return string
 */
function sn_rss_tracker_server_token() {
	$token = defined( 'SN_SRV_TOKEN' ) ? (string) SN_SRV_TOKEN : '';
	return (string) apply_filters( 'sn_server_token', $token );
}

/**
 * Bot detection. Matches search crawlers, preview-card bots, and uptime
 * monitors — never aggregators (Feedly, NewsBlur, Inoreader, etc.) since
 * those are the requests we want to count. An earlier revision used
 * `fetch` as a substring catch-all and ended up filtering Feedly (UA
 * contains "FeedFetcher-Google") and NewsBlur ("Page Fetcher"). The
 * current pattern uses specific tool names; tests in
 * tests/bot-detection.php enforce both directions.
 * v8.1.3 adds fediverse fetcher UAs (Mastodon/http.rb, Pleroma, Akkoma,
 * Misskey, Friendica) — fediverse servers fetch pages and feeds
 * server-to-server (preview cards etc.) whenever anyone shares a URL;
 * machine clients, never subscribers.
 * v8.1.6 (Better Stack migration): both Better Stack probe UA generations
 * ("Better Stack Better Uptime Bot …" / "Better Uptime Bot …") are
 * covered by the bare `bot` alternative — pinned in tests/bot-detection.php
 * so that coverage is deliberate, not incidental.
 */
function sn_rss_tracker_is_bot( $ua ) {
	if ( '' === $ua ) {
		return true;
	}
	$pattern = '/bot|crawl|spider|slurp|mediapartners|googlebot|bingbot|yandex|baidu|duckduckbot|facebookexternalhit|twitterbot|linkedinbot|pinterestbot|applebot|ahrefsbot|semrushbot|mj12bot|dotbot|petalbot|seznambot|uptimerobot|pingdom|statuscake|sitelock|mastodon|pleroma|akkoma|misskey|friendica|http\.rb\/|curl\/|wget\/|python-requests|go-http-client|httpie|java\//i';
	return (bool) preg_match( $pattern, $ua );
}

function sn_rss_tracker_hash_ua( $ua ) {
	return substr( hash( 'sha256', (string) $ua ), 0, 16 );
}

/**
 * Fire-and-forget POST of an "RSS Feed Request" custom event to the first-party
 * collector (the Worker's `/_sn/px`), replacing the legacy Plausible POST. The
 * `srv:1` flag tells the Worker to trust this as a real (human) hit — server
 * events come from the WP host's datacenter ASN, which the Worker's classifier
 * would otherwise tag 'suspect' and the human-only events rollup would drop.
 * Authenticated with the shared SN_BEACON_TOKEN. Non-blocking + 2s timeout so
 * the feed response is never delayed. The local `wp_rss_feed_log` row is the
 * source of truth, so a collector outage loses nothing.
 *
 * @param array  $settings   Tracker settings (collector_url, event_name).
 * @param string $feed_path  The feed request path (query already stripped).
 */
function sn_rss_tracker_send_event( $settings, $feed_path ) {
	$token = sn_rss_tracker_token();
	if ( '' === $token ) {
		return; // no shared token configured → don't POST unauthenticated.
	}
	$endpoint = (string) ( $settings['collector_url'] ?? '' );
	// SSRF guard: collector_url is admin-settable, so validate like the other
	// outbound modules — https only, plus wp_http_validate_url() (RFC-1918,
	// loopback, IPv6, userinfo) plus the shared sn_ssrf_host_blocked(), which
	// RESOLVES the host first so the encoded-IP metadata bypasses (decimal/hex/
	// octal of 169.254.169.254) are caught. Default is this site's own /_sn/px
	// on the public, Cloudflare-fronted domain.
	if ( '' === $endpoint
		|| ! wp_http_validate_url( $endpoint )
		|| 'https' !== wp_parse_url( $endpoint, PHP_URL_SCHEME )
		|| sn_ssrf_host_blocked( wp_parse_url( $endpoint, PHP_URL_HOST ) )
	) {
		return;
	}
	$payload = array(
		'k'   => $token,
		'e'   => 'ce',
		'n'   => (string) $settings['event_name'],
		'u'   => $feed_path,
		'srv' => 1,
	);
	// Two-key hardening (v6.22.0): include the PRIVATE server token (sk) when
	// configured, so the Worker can require it before trusting srv:1 as 'human'.
	// Absent → the Worker falls back to public-token-only trust (migration-safe).
	$server_token = sn_rss_tracker_server_token();
	if ( '' !== $server_token ) {
		$payload['sk'] = $server_token;
	}
	wp_remote_post( $endpoint, array(
		'timeout'     => 2,
		'blocking'    => false,
		// Don't follow a redirect off the validated host (redirect-to-internal
		// SSRF bypass) — matches inc/webhooks.php.
		'redirection' => 0,
		'headers'     => array( 'Content-Type' => 'application/json' ),
		'body'        => wp_json_encode( $payload ),
	) );
}

/**
 * Local log — the source of truth for the widget + activity tab. The
 * first-party collector event is best-effort on top of this, so a collector
 * outage (or the edge not hairpinning) never blanks the trend data. Insert
 * failures go to the PHP error log — silent loss here would defeat the whole
 * point of the local table.
 */
function sn_rss_tracker_log_request( $feed_url, $ua_hash ) {
	global $wpdb;
	$result = $wpdb->insert(
		$wpdb->prefix . SN_RSS_TRACKER_TABLE,
		array(
			'ts'       => current_time( 'mysql', true ),
			'ua_hash'  => $ua_hash,
			'feed_url' => mb_substr( $feed_url, 0, 255 ),
		),
		array( '%s', '%s', '%s' )
	);
	if ( false === $result ) {
		error_log( 'sn_rss_tracker: wp_rss_feed_log insert failed: ' . $wpdb->last_error );
	}
}

/**
 * Priority 1 so we run before any feed-rendering shortcircuits (some
 * caching plugins hook template_redirect at the default 10).
 */
function sn_rss_tracker_capture() {
	if ( ! is_feed() ) {
		return;
	}
	$settings = sn_rss_tracker_settings();
	if ( empty( $settings['enabled'] ) ) {
		return;
	}
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
	if ( sn_rss_tracker_is_bot( $ua ) ) {
		return;
	}

	// REQUEST_URI is attacker-controlled; strip the query string so we
	// don't log arbitrary user-supplied parameters to wp_rss_feed_log.
	// Real RSS aggregators never use query strings on /feed/ URLs, so
	// the trimmed value is what we want for unique-feed bucketing anyway.
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/feed/';
	$request_uri = strtok( $request_uri, '?' );
	$feed_url    = home_url( $request_uri );
	$ua_hash     = sn_rss_tracker_hash_ua( $ua );

	sn_rss_tracker_log_request( $feed_url, $ua_hash );
	if ( function_exists( 'snt_feed_subs_capture' ) ) {
		snt_feed_subs_capture( $ua, $ua_hash, $request_uri ); // parsed fields only (inc/feed-subscribers.php)
	}
	// Local log stores the full URL; the collector event takes the path.
	sn_rss_tracker_send_event( $settings, $request_uri );
}
add_action( 'template_redirect', 'sn_rss_tracker_capture', 1 );

function sn_rss_tracker_install() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_RSS_TRACKER_TABLE;
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		ts DATETIME NOT NULL,
		ua_hash CHAR(16) NOT NULL,
		feed_url VARCHAR(255) NOT NULL DEFAULT '',
		PRIMARY KEY (id),
		KEY ts (ts)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( SN_RSS_TRACKER_DB_VERSION_OPT, SN_RSS_TRACKER_DB_VERSION );
}

/**
 * Hooked on init (not admin_init) so the table exists before any front-
 * end feed request hits template_redirect on a cold install. The
 * version-option gate keeps the steady-state cost to a single autoloaded
 * option compare per pageview.
 */
function sn_rss_tracker_maybe_install() {
	if ( get_option( SN_RSS_TRACKER_DB_VERSION_OPT ) !== SN_RSS_TRACKER_DB_VERSION ) {
		sn_rss_tracker_install();
	}
}
add_action( 'init', 'sn_rss_tracker_maybe_install' );

/**
 * Daily cron: enforce the configured retention window. The settings tab
 * has a manual "Purge now" button too, but without this scheduled job
 * the log_retention_days setting was a promise the code never kept.
 */
function sn_rss_tracker_schedule_cron() {
	if ( ! wp_next_scheduled( SN_RSS_TRACKER_CRON_HOOK ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', SN_RSS_TRACKER_CRON_HOOK );
	}
}
// v4.1.1 (B-04): hook on `init` (not `admin_init`) — see cron-history.php for rationale.
add_action( 'init', 'sn_rss_tracker_schedule_cron' );

function sn_rss_tracker_cron_prune() {
	global $wpdb;
	// Guard against the partial-restore case: DB version option exists
	// (so maybe_install short-circuits) but the table was dropped by an
	// older backup overwrite. Re-run the installer once before deleting.
	sn_rss_tracker_maybe_install();

	$settings = sn_rss_tracker_settings();
	$days     = max( 7, min( 365, (int) $settings['log_retention_days'] ) );
	$result   = $wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}" . SN_RSS_TRACKER_TABLE . "
		   WHERE ts < ( UTC_TIMESTAMP() - INTERVAL %d DAY )",
		$days
	) );
	if ( false === $result ) {
		error_log( 'sn_rss_tracker: daily prune failed: ' . $wpdb->last_error );
	}
}
add_action( SN_RSS_TRACKER_CRON_HOOK, 'sn_rss_tracker_cron_prune' );

