<?php
/**
 * Signal & Noise Tools — Custom login URL.
 *
 * Renames /wp-login.php to a custom slug (default: /sn-login).
 * Direct visits to /wp-login.php return 404. Unauthenticated
 * /wp-admin requests also return 404. Login URL appears in
 * password-reset emails, logout links, etc. via filter rewrites
 * of site_url() / wp_redirect() output.
 *
 * Configuration via wp-config.php constants:
 *   SN_LOGIN_SLUG    — custom login slug (default 'sn-login')
 *   SN_LOGIN_BYPASS  — set true to disable this module entirely
 *                      (emergency unlock if you ever lock yourself out)
 *
 * Replaces the third-party `wps-hide-login` plugin. Now intentionally
 * mirrors its proven architecture (see Routing approach below).
 *
 * Defensive pre-flight: if `wps-hide-login` OR `rename-wp-login` is still
 * active, this module stands down to avoid conflicting URL filters.
 *
 * ---
 *
 * Routing approach (v4.2.1+): `plugins_loaded` priority 2 inspects
 * `$_SERVER['REQUEST_URI']`. If the path matches the custom slug, we set
 * `$pagenow = 'wp-login.php'` directly — no rewrite rule needed. The
 * `wp_loaded` handler then either `require_once`s ABSPATH . 'wp-login.php'
 * (for the custom-slug match) or 404s direct /wp-login.php and
 * unauthenticated /wp-admin requests. This is the proven wps-hide-login
 * approach (millions of installs) and is bulletproof against
 * rewrite-engine fragility: Cloudways/Nginx routing quirks, missing
 * .htaccess directives, CDN edge cache, WordPress's deferred-flush
 * failures, plugin conflicts wiping rewrite_rules option, etc.
 *
 * v1.5.0–v4.2.0 used `add_rewrite_rule` which depended on the
 * `rewrite_rules` option being populated and Apache routing through
 * `index.php`. v4.2.0 added a verify-before-trust self-heal to address
 * sentinel-desync symptoms, but the underlying architectural dependency
 * on the rewrite engine was the root cause of fragility. v4.2.1 removes
 * the rewrite-rule path entirely. The orphan `sn_login_rewrites_flushed`
 * option may linger in the DB for sites that ran v1.5.0–v4.2.0 — it's
 * harmless (a single autoloaded string), and any future SN cleanup can
 * remove it via `delete_option()`.
 *
 * Added in v1.5.0 (Phase 8 absorption, 2026-05-16).
 * Refactored in v4.2.1 (2026-05-26) to the wps-hide-login intercept
 * pattern after the production /backend 404 incident.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Emergency bypass — restore default /wp-login.php behaviour.
if ( defined( 'SN_LOGIN_BYPASS' ) && SN_LOGIN_BYPASS ) {
	return;
}

// Pre-flight: bail if wps-hide-login is still GENUINELY active to avoid
// conflicts. "Genuinely" means BOTH active in the WP options table AND
// the plugin file exists on disk. WP's is_plugin_active() is a pure
// option lookup (wp-admin/includes/plugin.php) — it never checks the
// filesystem. If wps-hide-login was deleted by removing the file
// directly (instead of going through WP's Deactivate flow), the slug
// stays in active_plugins as an orphan forever, our check would bail
// every time, and this module would silently never serve the slug.
// Symptom (in the old architecture): /sn-login 404s indefinitely.
//
// v2.1.1 hardening (surfaced by a real login lockout in production):
// require both signals. If the file is gone, the ghost option entry is
// no longer authoritative — our module activates and serves the slug.
if ( ! function_exists( 'is_plugin_active' ) ) {
	include_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$wps_basename = 'wps-hide-login/wps-hide-login.php';
$wps_file     = WP_PLUGIN_DIR . '/' . $wps_basename;
if ( is_plugin_active( $wps_basename ) && file_exists( $wps_file ) ) {
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-info"><p><strong>Signal &amp; Noise Tools:</strong> the built-in custom login URL module is dormant because <code>wps-hide-login</code> is still active. Deactivate that plugin to switch over.</p></div>';
	} );
	return;
}
unset( $wps_basename, $wps_file );

// v4.2.1: same pre-flight for `rename-wp-login` (the other widely-deployed
// plugin in this category). Matches the conflict-check that wps-hide-login
// itself does. If both are running, URL filter behavior is undefined.
$rwl_basename = 'rename-wp-login/rename-wp-login.php';
$rwl_file     = WP_PLUGIN_DIR . '/' . $rwl_basename;
if ( is_plugin_active( $rwl_basename ) && file_exists( $rwl_file ) ) {
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-info"><p><strong>Signal &amp; Noise Tools:</strong> the built-in custom login URL module is dormant because <code>rename-wp-login</code> is still active. Deactivate that plugin to switch over.</p></div>';
	} );
	return;
}
unset( $rwl_basename, $rwl_file );

/**
 * Get the configured custom login slug.
 *
 * Constant override (`SN_LOGIN_SLUG` in wp-config.php) takes priority over
 * the saved setting — used for emergency unlocks and per-environment
 * overrides. Otherwise falls back to the `login.slug` setting (default
 * 'sn-login').
 */
function sn_login_get_slug() {
	if ( defined( 'SN_LOGIN_SLUG' ) && SN_LOGIN_SLUG ) {
		return trim( (string) SN_LOGIN_SLUG, '/' );
	}
	$slug = sn_setting( 'login.slug', 'sn-login' );
	return $slug ? $slug : 'sn-login';
}

/**
 * Rewrite '/wp-login.php' in any URL produced by site_url(),
 * network_site_url(), or wp_redirect() to the custom slug. Affects
 * password-reset emails, logout-redirect URLs, etc.
 */
function sn_login_filter_url( $url, $path = '' ) {
	if ( strpos( $url, 'wp-login.php' ) === false ) {
		return $url;
	}
	return str_replace( '/wp-login.php', '/' . sn_login_get_slug(), $url );
}
add_filter( 'site_url', 'sn_login_filter_url', 10, 2 );
add_filter( 'network_site_url', 'sn_login_filter_url', 10, 2 );
add_filter( 'wp_redirect', 'sn_login_filter_url', 10, 2 );

/**
 * Decide whether the current request should be routed to the wp-login.php
 * include path (custom slug match), or to the 404 path (direct
 * wp-login.php access). Mirrors wps-hide-login's plugins_loaded handler
 * (WPS_Hide_Login::plugins_loaded at L351-384 of the reference impl).
 *
 * Runs at priority 2 so it executes BEFORE WP's rewrite engine parses
 * the URL — the routing decision lives entirely at the PHP layer,
 * independent of `rewrite_rules`, `.htaccess`, and CDN behavior.
 *
 * Returns one of three states via $GLOBALS (read by the wp_loaded
 * handler):
 *   - 'serve_form' — request matches our custom slug; include wp-login.php
 *   - 'block_wp_login' — request hits /wp-login.php directly; 404 it
 *   - (neither) — request is unrelated; pass through unchanged
 *
 * Allowlisted endpoints (admin-ajax.php, async-upload.php, wp-cron.php,
 * /wp-json/, /feed) short-circuit BEFORE any state is set, so those
 * requests are never touched.
 */
function sn_login_intercept_request() {
	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$request_uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] );

	// Allowlist (must check FIRST so wp_loaded never touches these requests).
	if ( strpos( $request_uri, 'admin-ajax.php' ) !== false ) {
		return;
	}
	foreach ( array( 'async-upload.php', 'wp-cron.php', '/wp-json/', '/feed' ) as $needle ) {
		if ( strpos( $request_uri, $needle ) !== false ) {
			return;
		}
	}

	// /wp-login.php direct access → flag for the 404 path in wp_loaded.
	// `is_admin()` is false during plugins_loaded for non-admin requests,
	// but we still gate to avoid touching legit admin-area wp-login calls
	// (none exist in core, but plugins might).
	if ( strpos( $request_uri, 'wp-login.php' ) !== false && ! is_admin() ) {
		$GLOBALS['sn_login_block_wp_login'] = true;
		return;
	}

	// Custom slug → set $pagenow + flag so wp_loaded includes wp-login.php.
	$parsed = wp_parse_url( $request_uri );
	$path   = isset( $parsed['path'] ) ? untrailingslashit( (string) $parsed['path'] ) : '';
	$slug   = sn_login_get_slug();

	if ( $path === '/' . $slug ) {
		$GLOBALS['sn_login_serve_form'] = true;
		// Setting $pagenow tells WP core "this request is wp-login.php"
		// even though the actual URL doesn't reach the wp-login.php file.
		// Things downstream (theme code, wp-admin checks, conditional
		// tags) that inspect $pagenow see the canonical value.
		global $pagenow;
		$pagenow = 'wp-login.php';
	}
}
add_action( 'plugins_loaded', 'sn_login_intercept_request', 2 );

/**
 * Respond per the routing decision made in plugins_loaded.
 *
 * Runs at default priority on wp_loaded, AFTER auth cookies have been
 * validated (so `is_user_logged_in()` is accurate for the /wp-admin
 * unauth check).
 *
 * Three branches:
 *   1. serve_form flag → `require_once ABSPATH . 'wp-login.php'` + die
 *   2. block_wp_login flag → audit-log counter + 404 + die
 *   3. /wp-admin path AND not logged in → audit-log counter + 404 + die
 *
 * The audit counters (`wp_login_404`, `wp_admin_unauth_404`) feed into
 * the Security → Audit log tab's attacker-attempt visualisations. The
 * IP is passed through `snt_audit_increment_counter_impl()` which
 * hashes it ephemerally — no raw or hashed IPs are stored long-term
 * (per inc/audit-log.php docblock).
 */
function sn_login_handle_request() {
	// Branch 1: serve the login form for custom-slug matches.
	if ( ! empty( $GLOBALS['sn_login_serve_form'] ) ) {
		require_once ABSPATH . 'wp-login.php';
		die;
	}

	// Branch 2: 404 direct /wp-login.php access.
	if ( ! empty( $GLOBALS['sn_login_block_wp_login'] ) ) {
		if ( function_exists( 'snt_audit_increment_counter_impl' ) ) {
			snt_audit_increment_counter_impl( 'wp_login_404', $_SERVER['REMOTE_ADDR'] ?? null );
		}
		status_header( 404 );
		nocache_headers();
		include get_query_template( '404' );
		exit;
	}

	// Branch 3: 404 unauthenticated /wp-admin requests.
	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}
	$request_uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
	if ( strpos( $request_uri, '/wp-admin' ) === 0 && ! is_user_logged_in() ) {
		if ( function_exists( 'snt_audit_increment_counter_impl' ) ) {
			snt_audit_increment_counter_impl( 'wp_admin_unauth_404', $_SERVER['REMOTE_ADDR'] ?? null );
		}
		status_header( 404 );
		nocache_headers();
		include get_query_template( '404' );
		exit;
	}
}
add_action( 'wp_loaded', 'sn_login_handle_request' );
