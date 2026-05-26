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
 * Replaces the third-party `wps-hide-login` plugin (~80 LOC vs theirs ~700).
 *
 * Defensive pre-flight: if `wps-hide-login` is still active, this module
 * stands down to avoid conflicting rewrite rules / URL filters.
 *
 * Added in v1.5.0 (Phase 8 absorption, 2026-05-16).
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
// every time, and this module would silently never register its
// rewrite rule. Symptom: /sn-login 404s indefinitely.
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

/**
 * Get the configured custom login slug.
 */
function sn_login_get_slug() {
	// Constant override has highest priority — for wp-config.php-based
	// emergency unlocks and per-environment overrides.
	if ( defined( 'SN_LOGIN_SLUG' ) && SN_LOGIN_SLUG ) {
		return trim( (string) SN_LOGIN_SLUG, '/' );
	}
	// Otherwise the configured setting (defaults to 'sn-login').
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
 * Register the rewrite rule so /<custom-slug> resolves to wp-login.php.
 */
add_action( 'init', function() {
	add_rewrite_rule(
		'^' . preg_quote( sn_login_get_slug(), '/' ) . '/?$',
		'wp-login.php',
		'top'
	);
} );

/**
 * One-time rewrite flush so the new rule resolves immediately on first
 * activation, and again whenever SN_LOGIN_SLUG changes. Keyed by current
 * slug so a constant change triggers a single re-flush.
 */
add_action( 'init', function() {
	$current = sn_login_get_slug();
	$flushed = get_option( 'sn_login_rewrites_flushed' );

	// v4.2.0: verify-before-trust — sentinel can desync from the
	// rewrite_rules option (silent update_option failure, another
	// plugin wiping the option, WP's deferred-flush failure mode).
	// Re-check that our rule is actually present before trusting the
	// sentinel. If desynced, re-flush to self-heal. This fixed the
	// production /backend 404 bug where the sentinel said "done" but
	// rewrite_rules didn't have the rule.
	$pattern      = '^' . preg_quote( $current, '/' ) . '/?$';
	$rules_option = get_option( 'rewrite_rules' );
	$rule_present = is_array( $rules_option ) && isset( $rules_option[ $pattern ] );

	if ( $flushed !== $current || ! $rule_present ) {
		flush_rewrite_rules( false );
		update_option( 'sn_login_rewrites_flushed', $current );
	}
}, 99 );

/**
 * Intercept direct visits to /wp-login.php and unauthenticated /wp-admin
 * requests. Both return 404. The custom slug path is exempt (it's how
 * legitimate logins reach the login form).
 */
add_action( 'wp_loaded', function() {
	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}
	$request_uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
	$slug        = sn_login_get_slug();

	// Allow the custom login slug.
	if ( strpos( $request_uri, '/' . $slug ) === 0 ) {
		return;
	}
	// Allow admin-ajax.php (used by both logged-out and logged-in flows).
	if ( strpos( $request_uri, 'admin-ajax.php' ) !== false ) {
		return;
	}
	// Allow async upload + WP-Cron + REST + RSS endpoints.
	$allowed = array( 'async-upload.php', 'wp-cron.php', '/wp-json/', '/feed' );
	foreach ( $allowed as $needle ) {
		if ( strpos( $request_uri, $needle ) !== false ) {
			return;
		}
	}

	// 404 direct visits to /wp-login.php.
	if ( strpos( $request_uri, 'wp-login.php' ) !== false ) {
		if ( function_exists( 'snt_audit_increment_counter_impl' ) ) {
			snt_audit_increment_counter_impl( 'wp_login_404', $_SERVER['REMOTE_ADDR'] ?? null );
		}
		status_header( 404 );
		nocache_headers();
		include get_query_template( '404' );
		exit;
	}

	// 404 unauthenticated visits to /wp-admin.
	if ( strpos( $request_uri, '/wp-admin' ) === 0 && ! is_user_logged_in() ) {
		if ( function_exists( 'snt_audit_increment_counter_impl' ) ) {
			snt_audit_increment_counter_impl( 'wp_admin_unauth_404', $_SERVER['REMOTE_ADDR'] ?? null );
		}
		status_header( 404 );
		nocache_headers();
		include get_query_template( '404' );
		exit;
	}
} );
