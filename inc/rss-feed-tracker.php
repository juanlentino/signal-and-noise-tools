<?php
/**
 * RSS feed-request tracker — companion plugin module.
 *
 * Logs every non-bot RSS feed request to `wp_rss_feed_log` (the source of
 * truth for the widget + activity tab) and fires a first-party "RSS Feed
 * Request" custom event to the SN collector (the Cloudflare Worker's
 * `/_sn/px`) so feed traffic surfaces in Analytics → Events alongside the
 * rest of the first-party analytics. Renders a 30-day count widget on the WP
 * admin dashboard and a full settings/stats tab under
 * Appearance → Signal & Noise → RSS.
 *
 * v6.20.0: repointed from Plausible (`/api/event`) to the first-party
 * collector, finishing the Plausible retirement begun in v6.0.0. The event
 * keeps the name "RSS Feed Request" so it continues the series imported from
 * Plausible. v6.20.1: file renamed `rss-plausible-tracker.php` →
 * `rss-feed-tracker.php` and the dead v1.1.0 MU-twin migration guard removed
 * (the migration completed at theme v8.2.1). Function names, option keys, the
 * `rss_feed_log` table, and the cron hook are unchanged.
 *
 * Migration history: originally shipped as `mu-plugins/rss-plausible-tracker.php`
 * in the Signal & Noise theme repo (v1.2.0 of the MU plugin). Moved to the
 * companion plugin in signal-and-noise-tools v1.1.0; the theme dropped the MU
 * file at v8.2.1. Same DB table, same option keys, same cron hook — only the
 * file location changed (now part of the plugin's `require_once` chain instead
 * of being auto-loaded by WordPress as an MU plugin).
 *
 * "Survives theme switches" property is preserved as long as this plugin
 * stays active.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RSS_TRACKER_DB_VERSION_OPT = 'sn_rss_tracker_db_version';
const SN_RSS_TRACKER_DB_VERSION     = '1.0.0';
const SN_RSS_TRACKER_TABLE          = 'rss_feed_log';
const SN_RSS_TRACKER_SETTINGS_OPT   = 'sn_rss_tracker_settings';
const SN_RSS_TRACKER_NONCE          = 'sn_rss_tracker_action';
const SN_RSS_TRACKER_CRON_HOOK      = 'sn_rss_tracker_daily_prune';
const SN_RSS_TRACKER_ACTION_SAVE    = 'save_settings';
const SN_RSS_TRACKER_ACTION_PURGE   = 'purge_log';
const SN_RSS_TRACKER_ACTION_RESET   = 'reset_defaults';

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
		// SSRF bypass) — matches inc/webhooks.php + inc/uptime-heartbeat.php.
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

/**
 * Multi-window aggregation in one query. UTC throughout — rows are
 * inserted with current_time('mysql', true), so the comparison side must
 * also be UTC. NOW() on Cloudways isn't guaranteed UTC and would
 * silently slide the windows.
 *
 * Days values are clamped + cast to int and interpolated rather than
 * prepared because MySQL INTERVAL doesn't accept a placeholder in a way
 * that composes cleanly with conditional aggregation. The clamp makes
 * the interpolation safe.
 *
 * @param int[] $days_list e.g. array(1, 7, 30)
 * @return array{most_recent: ?string, windows: array<int, array{total:int, uniques:int}>}
 */
function sn_rss_tracker_window_stats_multi( array $days_list ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_RSS_TRACKER_TABLE;

	$safe = array();
	foreach ( $days_list as $d ) {
		$safe[] = max( 1, min( 365, (int) $d ) );
	}
	$safe = array_values( array_unique( $safe ) );
	if ( empty( $safe ) ) {
		return array( 'most_recent' => null, 'windows' => array() );
	}
	$max_d   = max( $safe );
	$selects = array( 'MAX(ts) AS most_recent' );
	foreach ( $safe as $d ) {
		$selects[] = "SUM(ts >= UTC_TIMESTAMP() - INTERVAL {$d} DAY) AS total_{$d}";
		$selects[] = "COUNT(DISTINCT CASE WHEN ts >= UTC_TIMESTAMP() - INTERVAL {$d} DAY THEN ua_hash END) AS uniq_{$d}";
	}

	// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $selects is composed solely of hardcoded aggregate expressions with (int)-cast, 1..365-clamped day intervals; $table is $wpdb->prefix + a plugin constant.
	$row = $wpdb->get_row(
		"SELECT " . implode( ', ', $selects )
		. " FROM {$table}"
		. " WHERE ts >= UTC_TIMESTAMP() - INTERVAL {$max_d} DAY",
		ARRAY_A
	);

	$out = array(
		'most_recent' => $row['most_recent'] ?? null,
		'windows'     => array(),
	);
	foreach ( $safe as $d ) {
		$out['windows'][ $d ] = array(
			'total'   => (int) ( $row[ "total_{$d}" ] ?? 0 ),
			'uniques' => (int) ( $row[ "uniq_{$d}" ] ?? 0 ),
		);
	}
	return $out;
}

function sn_rss_tracker_recent( $limit = 20 ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_RSS_TRACKER_TABLE;
	$limit = max( 1, min( 100, (int) $limit ) );
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT ts, ua_hash, feed_url FROM {$table} ORDER BY id DESC LIMIT %d",
		$limit
	), ARRAY_A );
}

/**
 * RSS dashboard widget removed in v1.13.0. The RSS subscriber count
 * surface now lives exclusively on the SN admin → RSS tab. Reason:
 * dashboard widgets clutter the WP dashboard surface where SN-specific
 * info competes with other plugins' widgets. The SN settings pages
 * are the canonical home for operational info.
 * (See memory: feedback_no_dashboard_widgets.md)
 */

/**
 * Runs on admin_init so it processes POSTs before the tab's render
 * function fires, and so we can redirect with a flash query arg (the
 * standard redirect-after-POST pattern).
 */
function sn_rss_tracker_handle_form() {
	if ( empty( $_POST['sn_rss_action'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Soft nonce check — matches inc/admin-page.php:62 style. Bare
	// check_admin_referer() would die() with a wall-of-text error page
	// on a stale form (12-24h nonce TTL); silently bailing instead lets
	// the user reload and resubmit.
	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), SN_RSS_TRACKER_NONCE ) ) {
		return;
	}

	$action = sanitize_text_field( wp_unslash( $_POST['sn_rss_action'] ) );
	$flash  = '';

	if ( SN_RSS_TRACKER_ACTION_SAVE === $action ) {
		global $wpdb;
		$defaults = sn_rss_tracker_defaults();
		$current  = sn_rss_tracker_settings();
		$new      = array(
			'enabled'            => ! empty( $_POST['enabled'] ),
			// v10.46.0: collector_url is NO LONGER POSTED BY THIS FORM — the field
			// moved to Measurement → Analytics, where the collector is what the
			// screen is about. The fallback had to change from $defaults to
			// $current at the same time: this branch rebuilds the WHOLE settings
			// array from $_POST, so a defaults fallback would silently reset a
			// customised collector back to home_url('/_sn/px') on EVERY RSS save.
			// Falling back to the stored value keeps the two write surfaces
			// disjoint. Pinned by tests/rss-collector-move.php.
			'collector_url'      => esc_url_raw( wp_unslash( $_POST['collector_url'] ?? ( $current['collector_url'] ?? $defaults['collector_url'] ) ) ),
			'event_name'         => sanitize_text_field( wp_unslash( $_POST['event_name'] ?? $defaults['event_name'] ) ),
			'log_retention_days' => max( 7, min( 365, (int) wp_unslash( $_POST['log_retention_days'] ?? $defaults['log_retention_days'] ) ) ),
		);
		$ok = update_option( SN_RSS_TRACKER_SETTINGS_OPT, $new );
		// update_option returns false on both real-failure and value-
		// unchanged. Distinguish: if false AND wpdb has a non-empty
		// last_error, it's a real failure. Otherwise it's a no-op and
		// the user's "change" was identical to what was already stored.
		if ( false === $ok && ! empty( $wpdb->last_error ) ) {
			error_log( 'sn_rss_tracker: settings save failed: ' . $wpdb->last_error );
			$flash = 'save-error';
		} elseif ( false === $ok ) {
			$flash = 'unchanged';
		} else {
			$flash = 'saved';
		}
	} elseif ( SN_RSS_TRACKER_ACTION_PURGE === $action ) {
		global $wpdb;
		$days    = max( 7, min( 365, (int) wp_unslash( $_POST['purge_days'] ?? 90 ) ) );
		$result  = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}" . SN_RSS_TRACKER_TABLE . "
			   WHERE ts < ( UTC_TIMESTAMP() - INTERVAL %d DAY )",
			$days
		) );
		// (int)false === 0, so without this branch a SQL error would
		// render "Purged 0 entries" inside notice-success — fake
		// success on a real failure. Distinguish explicitly.
		if ( false === $result ) {
			error_log( 'sn_rss_tracker: manual purge failed: ' . $wpdb->last_error );
			$flash = 'purge-error';
		} else {
			$flash = 'purged-' . (int) $result;
		}
	} elseif ( SN_RSS_TRACKER_ACTION_RESET === $action ) {
		delete_option( SN_RSS_TRACKER_SETTINGS_OPT );
		$flash = 'reset';
	}

	wp_safe_redirect( add_query_arg(
		array(
			'page'      => 'sn-theme-options',
			'tab'       => 'rss',
			'sn_rss_ok' => $flash,
		),
		admin_url( 'themes.php' )
	) );
	exit;
}
add_action( 'admin_init', 'sn_rss_tracker_handle_form' );

function sn_rss_tracker_render_flash( $flash ) {
	if ( 'saved' === $flash ) {
		echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
	} elseif ( 'unchanged' === $flash ) {
		echo '<div class="notice notice-info is-dismissible"><p>Settings unchanged — submitted values matched what was already stored.</p></div>';
	} elseif ( 'save-error' === $flash ) {
		echo '<div class="notice notice-error is-dismissible"><p>Settings could not be saved. Check the PHP error log for the database error.</p></div>';
	} elseif ( 'reset' === $flash ) {
		echo '<div class="notice notice-success is-dismissible"><p>Settings reset to defaults.</p></div>';
	} elseif ( 'purge-error' === $flash ) {
		echo '<div class="notice notice-error is-dismissible"><p>Purge failed — no rows were deleted. Check the PHP error log for the database error.</p></div>';
	} elseif ( 0 === strpos( $flash, 'purged-' ) ) {
		$n = (int) substr( $flash, 7 );
		echo '<div class="notice notice-success is-dismissible"><p>Purged ' . esc_html( number_format_i18n( $n ) ) . ' log entries.</p></div>';
	}
}

/**
 * First-glance activity cards for sn_admin_glance_grid(). Pure — takes the
 * window stats, returns the card array (total → value, uniques → meta). v6.47.0:
 * converged off the bespoke .sn-rss-activity-card vocabulary onto the shared
 * token-driven glance grid, matching Cron / Health / Tags / Audit-log.
 *
 * @param array $stats sn_rss_tracker_window_stats_multi() result.
 * @return array<int,array<string,mixed>>
 */
function snt_rss_glance_cards( $stats ) {
	$cards = array();
	foreach ( array( 1 => '24 hours', 7 => '7 days', 30 => '30 days' ) as $days => $label ) {
		$w       = $stats['windows'][ $days ] ?? array( 'total' => 0, 'uniques' => 0 );
		$cards[] = array(
			'label'     => $label,
			'value'     => number_format_i18n( $w['total'] ),
			'meta_html' => esc_html( number_format_i18n( $w['uniques'] ) . ' unique' ),
		);
	}
	return $cards;
}

function sn_rss_tracker_render_stats( $stats ) {
	echo '<h2 class="sn-section-h">Activity</h2>';
	if ( function_exists( 'sn_admin_glance_grid' ) ) {
		echo '<section aria-label="RSS activity at a glance">';
		sn_admin_glance_grid( snt_rss_glance_cards( $stats ) );
		echo '</section>';
	}

	if ( ! empty( $stats['most_recent'] ) ) {
		echo '<p class="sn-rss-meta">Most recent feed request: <code>' . esc_html( $stats['most_recent'] ) . '</code> UTC</p>';
	} else {
		echo '<p class="sn-rss-meta"><em>No feed requests logged yet.</em></p>';
	}
}

function sn_rss_tracker_render_settings_form( $settings ) {
	echo '<h2 class="sn-section-h">Settings</h2>';
	// Empty action attr = POST to current URL. Page lives under themes.php
	// (add_theme_page); easier to self-post and let admin_init route than
	// to maintain a URL that has to match the registration site exactly.
	echo '<form method="post" class="sn-rss-settings">';
	wp_nonce_field( SN_RSS_TRACKER_NONCE );

	// Stacked .sn-field rows instead of two-column .form-table — fits the
	// narrow right column of the 2-col layout much better.
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label sn-field-label--inline" for="sn_rss_enabled">';
	echo '<input type="checkbox" id="sn_rss_enabled" name="enabled" value="1"' . checked( ! empty( $settings['enabled'] ), true, false ) . '> Enable feed-request tracking';
	echo '</label>';
	echo '<p class="sn-field-helper">When off, the plugin still loads but skips all DB writes and collector POSTs.</p>';
	echo '</div>';

	// v10.46.0: the Collector endpoint field moved to Measurement → Analytics.
	// It was never an RSS setting — EVERY beacon on the site posts to that URL,
	// and this leaf is one of its callers, not its owner. A read-only pointer
	// stays because this form's POSTs do go there, so seeing the target while
	// configuring the tracker is genuinely useful; the write surface is single
	// and lives on the Analytics leaf (the same one-writer rule
	// snt_analytics_render_mirrors() enforces in the other direction).
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label">Collector endpoint</label>';
	echo '<p class="sn-field-helper"><code>' . esc_html( (string) $settings['collector_url'] ) . '</code><br>';
	echo 'Where this tracker POSTs feed requests. Configured on <a href="' . esc_url( admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=analytics' ) ) . '">Measurement &rarr; Analytics</a>, alongside the credentials that read the same pipeline.</p>';
	echo '</div>';

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn_rss_event_name">Event name</label>';
	echo '<input type="text" id="sn_rss_event_name" name="event_name" class="large-text" value="' . esc_attr( $settings['event_name'] ) . '" required>';
	echo '<p class="sn-field-helper">Custom event name recorded in first-party analytics — surfaces under Analytics &rarr; Events. Kept as <code>RSS Feed Request</code> to continue the series imported from Plausible.</p>';
	echo '</div>';

	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_rss_retention">Log retention (days)</label>';
	echo '<input type="number" id="sn_rss_retention" name="log_retention_days" class="small-text" min="7" max="365" value="' . esc_attr( (int) $settings['log_retention_days'] ) . '">';
	echo '<p class="sn-field-helper">How long to keep rows in <code>' . esc_html( $GLOBALS['wpdb']->prefix . SN_RSS_TRACKER_TABLE ) . '</code>. A daily WP-Cron job prunes rows older than this threshold; the manual button below forces a prune right now.</p>';
	echo '</div>';

	echo '<p class="submit">';
	echo '<button type="submit" name="sn_rss_action" value="' . esc_attr( SN_RSS_TRACKER_ACTION_SAVE ) . '" class="button button-primary">Save Settings</button> ';
	// v4.1.1 (U-01): replaced onclick="return confirm(...)" with data-snt-confirm.
	echo '<button type="submit" name="sn_rss_action" value="' . esc_attr( SN_RSS_TRACKER_ACTION_RESET ) . '" class="button" data-snt-confirm="' . esc_attr__( 'All RSS tracker settings (window threshold, log retention, etc.) will be restored to defaults.', 'signal-and-noise-tools' ) . '" data-snt-confirm-title="' . esc_attr__( 'Reset RSS tracker to defaults?', 'signal-and-noise-tools' ) . '" data-snt-confirm-label="' . esc_attr__( 'Reset', 'signal-and-noise-tools' ) . '">Reset to Defaults</button>';
	echo '</p>';
	echo '</form>';
}

function sn_rss_tracker_render_recent_table( $recent ) {
	echo '<h2 class="sn-section-h">Recent requests</h2>';
	if ( empty( $recent ) ) {
		echo '<p class="description"><em>No requests logged yet.</em></p>';
		return;
	}
	echo '<div class="sn-rss-recent">';
	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat striped">';
	echo '<thead><tr><th class="column-primary">Time (UTC)</th><th>Feed URL</th><th>Client</th></tr></thead><tbody>';
	foreach ( $recent as $row ) {
		echo '<tr>';
		echo '<td><code class="sn-mono">' . esc_html( $row['ts'] ) . '</code></td>';
		echo '<td><code class="sn-mono">' . esc_html( $row['feed_url'] ) . '</code></td>';
		echo '<td><code class="sn-mono">' . esc_html( $row['ua_hash'] ) . '</code></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
	echo '</div>';
}

function sn_rss_tracker_render_maintenance_form( $settings ) {
	// Lives inside the right column, separated from Settings by a single
	// border-top via .sn-rss-maintenance — no extra card chrome.
	echo '<div class="sn-rss-maintenance">';
	echo '<h2 class="sn-section-h">Maintenance</h2>';
	echo '<form method="post">';
	wp_nonce_field( SN_RSS_TRACKER_NONCE );
	echo '<p class="sn-field-helper">Delete rows older than the threshold below. First-party collector events are unaffected — only the local <code>' . esc_html( $GLOBALS['wpdb']->prefix . SN_RSS_TRACKER_TABLE ) . '</code> table is touched. The daily cron runs the same query against the configured retention setting.</p>';
	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_rss_purge_days">Older than (days)</label>';
	echo '<input type="number" id="sn_rss_purge_days" name="purge_days" class="small-text" min="7" max="365" value="' . esc_attr( (int) $settings['log_retention_days'] ) . '">';
	echo '</div>';
	echo '<p class="submit sn-submit--tight">';
	// v4.1.1 (U-01): replaced onclick="return confirm(...)" with data-snt-confirm.
	echo '<button type="submit" name="sn_rss_action" value="' . esc_attr( SN_RSS_TRACKER_ACTION_PURGE ) . '" class="button" data-snt-confirm="' . esc_attr__( 'Log entries older than the configured retention threshold will be permanently deleted.', 'signal-and-noise-tools' ) . '" data-snt-confirm-title="' . esc_attr__( 'Purge old log entries?', 'signal-and-noise-tools' ) . '" data-snt-confirm-label="' . esc_attr__( 'Purge', 'signal-and-noise-tools' ) . '" data-snt-confirm-danger="1">Purge now</button>';
	echo '</p>';
	echo '</form>';
	echo '</div>';
}

function sn_rss_tracker_render_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = sn_rss_tracker_settings();
	$stats    = sn_rss_tracker_window_stats_multi( array( 1, 7, 30 ) );
	$recent   = sn_rss_tracker_recent( 20 );
	$flash    = isset( $_GET['sn_rss_ok'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_rss_ok'] ) ) : '';

	sn_rss_tracker_render_flash( $flash );

	// v1.14.0: Activity stats are full-width on top (3 boxes naturally
	// form one row). Below, 2-col content-driven split via .sn-2col:
	// wide left for the table-heavy Recent column, narrow right for
	// the form+maintenance config column. Stacks at <960px.
	sn_rss_tracker_render_stats( $stats );

	echo '<div class="sn-2col">';

	echo '<div class="sn-2col__col">';
	sn_rss_tracker_render_recent_table( $recent );
	echo '</div>';

	echo '<div class="sn-2col__col">';
	sn_rss_tracker_render_settings_form( $settings );
	sn_rss_tracker_render_maintenance_form( $settings );
	echo '</div>';

	echo '</div>';
}
add_action( 'sn_admin_rss_tab', 'sn_rss_tracker_render_admin_tab' );
