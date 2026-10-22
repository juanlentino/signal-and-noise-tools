<?php
/**
 * Signal & Noise Tools: WP-native update integration, the update offer (the latest tag, the Check Again test, the update_plugins transient filter).
 *
 * Split from inc/wp-update-integration.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch the highest semver-formatted tag from GitHub. Returns the tag
 * string (e.g. "v1.4.0") on success, null on error / no matching tags.
 * Cached for SN_GH_PLUGIN_CACHE_TTL; empty sentinel cached 1h on failure.
 *
 * @param bool $force_refresh When true, bypass the cache and re-fetch.
 *                            Used when WP's "Check Again" button is
 *                            clicked (WP_FORCE_UPDATE_CHECK constant
 *                            or `?force-check=1` query arg).
 */
function sn_gh_latest_plugin_tag( $force_refresh = false ) {
	if ( ! $force_refresh ) {
		$cached = get_site_transient( SN_GH_PLUGIN_CACHE_KEY );
		if ( $cached !== false ) {
			return $cached === '' ? null : $cached;
		}
	}

	$url     = 'https://api.github.com/repos/' . SN_GH_PLUGIN_OWNER . '/' . SN_GH_PLUGIN_REPO . '/tags?per_page=100';
	$headers = array(
		'Accept'     => 'application/vnd.github+json',
		'User-Agent' => 'WordPress; ' . home_url(),
	);
	// v4.5.6: authenticate the tag-fetch when SNT_GITHUB_TOKEN is defined in
	// wp-config.php — raises the GitHub limit from 60/h (unauthenticated, shared
	// per-server-IP) to 5000/h. Without this, a busy/shared IP can exhaust the
	// 60/h pool, the fetch 403s, sn_gh_latest_plugin_tag() returns null, and the
	// Updates page silently shows "no update available" even when one exists.
	// Mirrors inc/github-actions-api.php. Conditional → unauthenticated fallback
	// is unchanged when the constant is absent.
	if ( defined( 'SNT_GITHUB_TOKEN' ) && SNT_GITHUB_TOKEN ) {
		$headers['Authorization'] = 'Bearer ' . SNT_GITHUB_TOKEN;
	}
	$args = array(
		'timeout'     => 5,
		'headers'     => $headers,
		// v8.8.x: forbid redirects — the SNT_GITHUB_TOKEN bearer must never be
		// re-sent to a 3xx target (outbound-hardening convention, v8.7.1).
		'redirection' => 0,
	);

	$response = wp_remote_get( $url, $args );
	$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

	// v9.54.1: ONE retry, transient failures only. During the 2026-07-16 GitHub
	// incident (~35% of REST requests failing, independently) a single retry
	// recovers ~65% of the polls that would otherwise have blinded the cards.
	// Durable failures (401/404) are never retried — the second answer is the
	// first answer, and hammering a dead credential is pure noise.
	if ( 200 !== $code && sn_gh_failure_is_transient( $code ) ) {
		$response = wp_remote_get( $url, $args );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	if ( 200 !== $code ) {
		return sn_gh_record_fetch_failure( sn_gh_fetch_failure_reason( $response ), $code );
	}

	$tags = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $tags ) ) {
		// A 200 whose body isn't a tag list means we reached SOMETHING that
		// wasn't GitHub's API — an intermediary, a captive portal, an incident
		// error page. Far likelier a blip than a permanent contract change, so
		// classify TRANSIENT (0 = "no usable answer"). The reason string still
		// reports the literal 200 we saw; the code only drives retry policy.
		return sn_gh_record_fetch_failure( sn_gh_fetch_failure_reason( $response ), 0 );
	}

	$highest = '';
	foreach ( $tags as $tag ) {
		$name = isset( $tag['name'] ) ? (string) $tag['name'] : '';
		if ( ! preg_match( '/^v\d+\.\d+\.\d+$/', $name ) ) {
			continue;
		}
		if ( $highest === '' || version_compare( ltrim( $name, 'v' ), ltrim( $highest, 'v' ), '>' ) ) {
			$highest = $name;
		}
	}

	if ( $highest === '' ) {
		// Distinct from "no update available": we reached GitHub and it had
		// nothing shaped like a release. Say so — a silent null here reads
		// identically to a dead token on the card.
		// DURABLE: we reached GitHub, it answered correctly, and the repo simply
		// has no vX.Y.Z tags. Re-asking in five minutes gets the same answer.
		return sn_gh_record_fetch_failure(
			__( 'GitHub returned no tags matching vX.Y.Z: nothing to compare against', 'signal-and-noise-tools' ),
			200
		);
	}

	// Success CLEARS the reason. Without this the fix becomes the next bug: a
	// stale error would sit on the card after the token was rotated, and the
	// owner would rotate a working token again.
	delete_site_transient( SN_GH_PLUGIN_ERROR_KEY );
	set_site_transient( SN_GH_PLUGIN_CACHE_KEY, $highest, SN_GH_PLUGIN_CACHE_TTL );
	return $highest;
}

/**
 * Whether this request is allowed to bypass the cached GitHub tag result.
 *
 * WordPress' constant is trusted internal state. The public query flag is
 * honored only for users who can update plugins, preventing anonymous cache
 * churn while preserving the core Updates screen's Check Again flow.
 */
function sn_plugin_update_force_refresh_requested() {
	if ( defined( 'WP_FORCE_UPDATE_CHECK' ) && WP_FORCE_UPDATE_CHECK ) {
		return true;
	}

	return ! empty( $_GET['force-check'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorization-gated read-only cache bypass.
		&& current_user_can( 'update_plugins' );
}

/**
 * When core drops its update record, drop our cached tag with it. A refresh
 * that is not core's Check Again (the Plugins screen's Refresh in the mobile
 * app: wp_clean_plugins_cache() then wp_update_plugins()) otherwise rebuilt
 * update_plugins from a tag cached for up to an hour, and core then kept that
 * answer for 12h. Core fires this action before it deletes; the routine
 * twice-daily check only sets the transient, so it never refetches GitHub.
 * The theme key is the theme's own (see snt_cmd_impl_force_check()).
 */
add_action( 'delete_site_transient_update_plugins', static fn() => delete_site_transient( SN_GH_PLUGIN_CACHE_KEY ) );
add_action( 'delete_site_transient_update_themes', static fn() => delete_site_transient( 'sn_gh_latest_theme' ) );

/**
 * Register the plugin with WP's update transient. WP renders it on
 * wp-admin/update-core.php and Plugins → Installed Plugins from this data.
 *
 * Plugin update transient shape differs from themes: keys are basenames
 * (dir/file.php), entries are stdClass with slug/plugin/new_version/url/package.
 */
add_filter( 'pre_set_site_transient_update_plugins', function( $transient ) {
	if ( empty( $transient ) || ! is_object( $transient ) ) {
		$transient = new stdClass();
	}

	// v1.11.1: honor WP's "Check Again" button. WP sets the WP_FORCE_UPDATE_CHECK
	// constant during the wp-admin/update-core.php?force-check=1 flow.
	// Without this, our 12h-cached value persists even when the user
	// explicitly asks for a fresh check.
	$force_refresh = sn_plugin_update_force_refresh_requested();

	$latest_tag = sn_gh_latest_plugin_tag( $force_refresh );
	if ( $latest_tag === null ) {
		return $transient;
	}

	$latest_version  = ltrim( $latest_tag, 'v' );
	$current_version = defined( 'SNT_VERSION' ) ? SNT_VERSION : '0.0.0';

	$plugin_data              = new stdClass();
	$plugin_data->slug        = SN_GH_PLUGIN_SLUG;
	$plugin_data->plugin      = SN_GH_PLUGIN_BASENAME;
	$plugin_data->new_version = $latest_version;
	$plugin_data->url         = 'https://github.com/' . SN_GH_PLUGIN_OWNER . '/' . SN_GH_PLUGIN_REPO;
	$plugin_data->package     = 'https://github.com/' . SN_GH_PLUGIN_OWNER . '/' . SN_GH_PLUGIN_REPO . '/archive/refs/tags/' . $latest_tag . '.zip';
	// v2.1.2: brand assets — icons + banners served from our own
	// assets/ directory. WP core reads these from the update transient to
	// render the plugin icon on Dashboard → Updates list (verified
	// against wp-admin/update-core.php list_plugin_updates() which
	// checks svg > 2x > 1x > default in that priority order). Without
	// this, the default puzzle-piece dashicon renders.
	//
	// IMPORTANT: `default` key must always be set — class-wp-plugin-
	// install-list-table.php reads `$plugin['icons']['default']`
	// without an `! empty()` guard, so unsetting it would throw a PHP
	// notice on the Plugins → Add New screen. We point every key at the
	// same SVG; browsers render SVG inside <img> tags fine, and the SVG
	// scales to any DPR without a separate retina file.
	$icon_url                 = plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	$banner_low_url           = plugins_url( 'assets/banner.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	$plugin_data->icons       = array(
		'svg'     => $icon_url,
		'2x'      => $icon_url,
		'1x'      => $icon_url,
		'default' => $icon_url,
	);
	// banners only render in the "View Details" modal which goes
	// through the plugins_api filter below — included here for
	// symmetry/scanner-tool compatibility but inert on the update list.
	$plugin_data->banners     = array(
		'low'  => $banner_low_url,
		'high' => $banner_low_url,
	);

	// v2.1.4: compatibility metadata. wp-admin/update-core.php
	// list_plugin_updates() reads $plugin_data->update->tested at
	// line 527 (verified against WP 6.9.4 source). Without it, every
	// upgrade row renders "Compatibility with WordPress N.N.N: Unknown".
	// list_plugin_updates() also reads ->requires_php at line 545 to
	// gate the "this requires PHP X" notice, and ->requires at line ~550
	// for the core-version requirement notice. Setting all three keeps
	// the Updates page green instead of falling through to the
	// uncertainty messaging.
	//
	// `tested` must satisfy version_compare( $tested, $cur_wp_version, '>=' )
	// — bumping it as we test against newer WP. `requires` + `requires_php`
	// mirror the values in the plugin file header for consistency with
	// what the View Details modal renders (plugins_api filter below).
	$compat                    = sn_gh_plugin_compat();
	$plugin_data->tested       = $compat['tested'];
	$plugin_data->requires     = $compat['requires'];
	$plugin_data->requires_php = $compat['requires_php'];

	if ( version_compare( $latest_version, $current_version, '>' ) ) {
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ SN_GH_PLUGIN_BASENAME ] = $plugin_data;
	} else {
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}
		$transient->no_update[ SN_GH_PLUGIN_BASENAME ] = $plugin_data;
	}

	return $transient;
} );

