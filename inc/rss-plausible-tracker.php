<?php
/**
 * RSS Plausible Tracker — companion plugin module.
 *
 * Fires a server-side Plausible event on every non-bot RSS feed request
 * and logs each request to `wp_rss_feed_log` as a fallback trend source
 * if Plausible is unreachable. Renders a 30-day count widget on the WP
 * admin dashboard and a full settings/stats tab under
 * Appearance → Signal & Noise → RSS.
 *
 * Migration history: originally shipped as `mu-plugins/rss-plausible-tracker.php`
 * in the Signal & Noise theme repo (v1.2.0 of the MU plugin). Moved to the
 * companion plugin in signal-and-noise-tools v1.1.0 as an early slice of
 * the Phase 4 mu-plugins migration. Same DB table, same option keys, same
 * cron hook — the only thing that changed is the file location and the
 * surrounding bootstrap (now part of the plugin's `require_once` chain
 * instead of being auto-loaded by WordPress as an MU plugin).
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
 * Default settings. Host-specific values (Plausible URL, domain) live here
 * as fallbacks so a fresh install on a different host still works before
 * anyone visits the settings tab — admins can override per-host via the
 * UI without code changes.
 */
function sn_rss_tracker_defaults() {
	return array(
		'enabled'            => true,
		'plausible_url'      => 'https://analytics.juanlentino.com/api/event',
		'plausible_domain'   => 'juanlentino.com',
		'event_name'         => 'RSS Feed Request',
		'log_retention_days' => 90,
	);
}

function sn_rss_tracker_settings() {
	$stored = get_option( SN_RSS_TRACKER_SETTINGS_OPT, array() );
	return wp_parse_args( is_array( $stored ) ? $stored : array(), sn_rss_tracker_defaults() );
}

/**
 * Build the Plausible dashboard deep-link from the *currently configured*
 * endpoint. Earlier revisions hardcoded the host here too, which meant
 * the "Open in Plausible" link kept pointing at the original host even
 * after the admin moved the endpoint to a different one. parse_url'ing
 * the configured event endpoint guarantees the link tracks the setting.
 */
function sn_rss_tracker_plausible_dashboard_url( $settings ) {
	$parts = wp_parse_url( $settings['plausible_url'] );
	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	return $parts['scheme'] . '://' . $parts['host']
		. '/' . rawurlencode( $settings['plausible_domain'] )
		. '?f=is,goal,' . rawurlencode( $settings['event_name'] );
}

/**
 * Bot detection. Matches search crawlers, preview-card bots, and uptime
 * monitors — never aggregators (Feedly, NewsBlur, Inoreader, etc.) since
 * those are the requests we want to count. An earlier revision used
 * `fetch` as a substring catch-all and ended up filtering Feedly (UA
 * contains "FeedFetcher-Google") and NewsBlur ("Page Fetcher"). The
 * current pattern uses specific tool names; tests in
 * mu-plugins/tests/bot-detection.php enforce both directions.
 */
function sn_rss_tracker_is_bot( $ua ) {
	if ( '' === $ua ) {
		return true;
	}
	$pattern = '/bot|crawl|spider|slurp|mediapartners|googlebot|bingbot|yandex|baidu|duckduckbot|facebookexternalhit|twitterbot|linkedinbot|pinterestbot|applebot|ahrefsbot|semrushbot|mj12bot|dotbot|petalbot|seznambot|uptimerobot|pingdom|statuscake|sitelock|curl\/|wget\/|python-requests|go-http-client|httpie|java\//i';
	return (bool) preg_match( $pattern, $ua );
}

function sn_rss_tracker_hash_ua( $ua ) {
	return substr( hash( 'sha256', (string) $ua ), 0, 16 );
}

/**
 * Best-effort client IP. Trusts Cloudflare's CF-Connecting-IP first
 * (juanlentino.com is fronted by Cloudflare; that header is set by the
 * edge), falls back to X-Forwarded-For's first hop, then REMOTE_ADDR.
 * Forwarded to Plausible for geo lookup; never persisted locally.
 */
function sn_rss_tracker_client_ip() {
	foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}
		$value = (string) wp_unslash( $_SERVER[ $key ] );
		$ip    = trim( explode( ',', $value )[0] );
		if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}
	return '0.0.0.0';
}

/**
 * Fire-and-forget POST to Plausible. Non-blocking + 2s timeout means the
 * feed response itself is never delayed by analytics — at worst a
 * Plausible outage adds a sub-millisecond syscall to spawn the request.
 * Forwards the original client UA and IP so Plausible's own server-side
 * bot detection and geo lookup function correctly.
 */
function sn_rss_tracker_send_plausible( $settings, $feed_url, $ua, $ua_hash, $client_ip ) {
	wp_remote_post( $settings['plausible_url'], array(
		'timeout'  => 2,
		'blocking' => false,
		'headers'  => array(
			'Content-Type'    => 'application/json',
			'User-Agent'      => $ua,
			'X-Forwarded-For' => $client_ip,
		),
		'body'     => wp_json_encode( array(
			'name'   => $settings['event_name'],
			'url'    => $feed_url,
			'domain' => $settings['plausible_domain'],
			'props'  => array( 'ua_hash' => $ua_hash ),
		) ),
	) );
}

/**
 * Local fallback log. Plausible is the primary destination but the DB
 * row is the source of truth for the widget + activity tab so a
 * Plausible outage doesn't blank the trend data. Insert failures go to
 * the PHP error log — silent loss here would defeat the whole point of
 * having a fallback in the first place.
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
	// the trimmed value is what we want for unique-feed bucketing in
	// Plausible anyway.
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/feed/';
	$request_uri = strtok( $request_uri, '?' );
	$feed_url    = home_url( $request_uri );
	$ua_hash     = sn_rss_tracker_hash_ua( $ua );

	sn_rss_tracker_log_request( $feed_url, $ua_hash );
	sn_rss_tracker_send_plausible( $settings, $feed_url, $ua, $ua_hash, sn_rss_tracker_client_ip() );
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
add_action( 'admin_init', 'sn_rss_tracker_schedule_cron' );

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
		$new      = array(
			'enabled'            => ! empty( $_POST['enabled'] ),
			'plausible_url'      => esc_url_raw( wp_unslash( $_POST['plausible_url'] ?? $defaults['plausible_url'] ) ),
			'plausible_domain'   => sanitize_text_field( wp_unslash( $_POST['plausible_domain'] ?? $defaults['plausible_domain'] ) ),
			'event_name'         => sanitize_text_field( wp_unslash( $_POST['event_name'] ?? $defaults['event_name'] ) ),
			'log_retention_days' => max( 7, min( 365, (int) ( $_POST['log_retention_days'] ?? $defaults['log_retention_days'] ) ) ),
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
		$days    = max( 7, min( 365, (int) ( $_POST['purge_days'] ?? 90 ) ) );
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

function sn_rss_tracker_render_stats( $stats, $dashboard_url ) {
	echo '<h2 class="sn-section-h" style="font-size:1.1em;margin-top:0;margin-bottom:0.8em;">Activity</h2>';
	echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:1.5em;">';
	foreach ( array( 1 => '24 hours', 7 => '7 days', 30 => '30 days' ) as $days => $label ) {
		$w = $stats['windows'][ $days ] ?? array( 'total' => 0, 'uniques' => 0 );
		echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:14px 18px;min-width:160px;">';
		echo '<p style="margin:0;color:#646970;font-size:0.8em;text-transform:uppercase;letter-spacing:0.05em;">' . esc_html( $label ) . '</p>';
		echo '<p style="font-size:1.8rem;font-weight:600;margin:4px 0 2px;line-height:1;">' . esc_html( number_format_i18n( $w['total'] ) ) . '</p>';
		echo '<p style="margin:0;color:#646970;font-size:0.85em;">' . esc_html( number_format_i18n( $w['uniques'] ) ) . ' unique</p>';
		echo '</div>';
	}
	echo '</div>';

	if ( ! empty( $stats['most_recent'] ) ) {
		echo '<p style="color:#646970;font-size:0.85em;margin:-0.5em 0 1.5em;">Most recent feed request: <code>' . esc_html( $stats['most_recent'] ) . '</code> UTC';
		if ( '' !== $dashboard_url ) {
			echo ' &middot; <a href="' . esc_url( $dashboard_url ) . '" target="_blank" rel="noopener">Open in Plausible &rarr;</a>';
		}
		echo '</p>';
	} else {
		echo '<p style="color:#646970;font-size:0.85em;margin:-0.5em 0 1.5em;"><em>No feed requests logged yet.</em></p>';
	}
}

function sn_rss_tracker_render_settings_form( $settings ) {
	echo '<h2 class="sn-section-h" style="font-size:1.1em;margin-top:0;margin-bottom:0.8em;">Settings</h2>';
	// Empty action attr = POST to current URL. Page lives under themes.php
	// (add_theme_page); easier to self-post and let admin_init route than
	// to maintain a URL that has to match the registration site exactly.
	echo '<form method="post">';
	wp_nonce_field( SN_RSS_TRACKER_NONCE );
	echo '<table class="form-table">';

	echo '<tr><th scope="row"><label for="sn_rss_enabled">Tracking</label></th><td>';
	echo '<label><input type="checkbox" id="sn_rss_enabled" name="enabled" value="1"' . checked( ! empty( $settings['enabled'] ), true, false ) . '> Enable feed-request tracking</label>';
	echo '<p class="description">When off, the plugin still loads but skips all DB writes and Plausible POSTs.</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="sn_rss_plausible_url">Plausible event endpoint</label></th><td>';
	echo '<input type="url" id="sn_rss_plausible_url" name="plausible_url" class="regular-text" value="' . esc_attr( $settings['plausible_url'] ) . '" required>';
	echo '<p class="description">Full URL of your Plausible CE <code>/api/event</code> endpoint.</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="sn_rss_plausible_domain">Plausible site domain</label></th><td>';
	echo '<input type="text" id="sn_rss_plausible_domain" name="plausible_domain" class="regular-text" value="' . esc_attr( $settings['plausible_domain'] ) . '" required>';
	echo '<p class="description">The <code>domain</code> field as configured in your Plausible site settings — usually the bare hostname.</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="sn_rss_event_name">Event name</label></th><td>';
	echo '<input type="text" id="sn_rss_event_name" name="event_name" class="regular-text" value="' . esc_attr( $settings['event_name'] ) . '" required>';
	echo '<p class="description">Custom event name sent to Plausible. Configure a matching goal in Plausible to surface it in the dashboard.</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="sn_rss_retention">Log retention (days)</label></th><td>';
	echo '<input type="number" id="sn_rss_retention" name="log_retention_days" min="7" max="365" value="' . esc_attr( (int) $settings['log_retention_days'] ) . '">';
	echo '<p class="description">How long to keep rows in <code>' . esc_html( $GLOBALS['wpdb']->prefix . SN_RSS_TRACKER_TABLE ) . '</code>. A daily WP-Cron job prunes rows older than this threshold; the manual button below forces a prune right now.</p>';
	echo '</td></tr>';

	echo '</table>';
	echo '<p class="submit">';
	echo '<button type="submit" name="sn_rss_action" value="' . esc_attr( SN_RSS_TRACKER_ACTION_SAVE ) . '" class="button button-primary">Save Settings</button> ';
	echo '<button type="submit" name="sn_rss_action" value="' . esc_attr( SN_RSS_TRACKER_ACTION_RESET ) . '" class="button" onclick="return confirm(\'Reset all RSS tracker settings to defaults?\');">Reset to Defaults</button>';
	echo '</p>';
	echo '</form>';
}

function sn_rss_tracker_render_recent_table( $recent ) {
	echo '<h2 class="sn-section-h" style="font-size:1.1em;margin-top:1.5em;margin-bottom:0.8em;">Recent requests</h2>';
	if ( empty( $recent ) ) {
		echo '<p style="color:#646970;"><em>No requests logged yet.</em></p>';
		return;
	}
	echo '<table class="widefat striped">';
	echo '<thead><tr><th style="width:170px;">Time (UTC)</th><th>Feed URL</th><th style="width:140px;">Client</th></tr></thead><tbody>';
	foreach ( $recent as $row ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( $row['ts'] ) . '</code></td>';
		echo '<td><code>' . esc_html( $row['feed_url'] ) . '</code></td>';
		echo '<td><code>' . esc_html( $row['ua_hash'] ) . '</code></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

function sn_rss_tracker_render_maintenance_form( $settings ) {
	echo '<h2 class="sn-section-h" style="font-size:1.1em;margin-top:1.5em;margin-bottom:0.8em;">Maintenance</h2>';
	echo '<form method="post" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;">';
	wp_nonce_field( SN_RSS_TRACKER_NONCE );
	echo '<strong style="display:block;margin-bottom:4px;">Purge old log entries</strong>';
	echo '<p style="color:#666;font-size:0.85em;margin:0 0 12px;">Delete rows older than the threshold below. Plausible events are unaffected — only the local <code>' . esc_html( $GLOBALS['wpdb']->prefix . SN_RSS_TRACKER_TABLE ) . '</code> table is touched. The daily cron runs the same query against the configured retention setting.</p>';
	echo '<label>Older than <input type="number" name="purge_days" min="7" max="365" value="' . esc_attr( (int) $settings['log_retention_days'] ) . '" style="width:80px;"> days</label> ';
	echo '<button type="submit" name="sn_rss_action" value="' . esc_attr( SN_RSS_TRACKER_ACTION_PURGE ) . '" class="button" onclick="return confirm(\'Delete log entries older than the specified threshold?\');">Purge now</button>';
	echo '</form>';
}

function sn_rss_tracker_render_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings      = sn_rss_tracker_settings();
	$stats         = sn_rss_tracker_window_stats_multi( array( 1, 7, 30 ) );
	$recent        = sn_rss_tracker_recent( 20 );
	$dashboard_url = sn_rss_tracker_plausible_dashboard_url( $settings );
	$flash         = isset( $_GET['sn_rss_ok'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_rss_ok'] ) ) : '';

	sn_rss_tracker_render_flash( $flash );

	// v1.13.0: 2-column layout reduces vertical scroll. CSS in
	// assets/admin.css → .sn-rss-grid (stacks on narrow screens).
	// LEFT (60%): Activity stats + Recent requests (read-heavy)
	// RIGHT (40%): Settings form + Maintenance (write/config-heavy)
	echo '<div class="sn-rss-grid">';

	echo '<div class="sn-rss-col sn-rss-col--main">';
	sn_rss_tracker_render_stats( $stats, $dashboard_url );
	sn_rss_tracker_render_recent_table( $recent );
	echo '</div>';

	echo '<div class="sn-rss-col sn-rss-col--side">';
	sn_rss_tracker_render_settings_form( $settings );
	sn_rss_tracker_render_maintenance_form( $settings );
	echo '</div>';

	echo '</div>';
}
add_action( 'sn_admin_rss_tab', 'sn_rss_tracker_render_admin_tab' );
