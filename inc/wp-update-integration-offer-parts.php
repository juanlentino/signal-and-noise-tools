<?php
/**
 * Signal & Noise Tools: WP-native update integration, the update offer (the latest tag, the Check Again test, the update_plugins_github.com filter).
 *
 * Split from inc/wp-update-integration.php (house line cap, 2026-10-15). The
 * file header there describes the module. Loaded by require_once at the end of
 * that file, in the order the code always ran. 2026-11-10: the offer moved from
 * the raw pre_set_site_transient_update_plugins filter to the hook core runs
 * for the plugin's Update URI (tests/wp-update-hostname-offer.php).
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
 * The update offer, through the hook core runs for this plugin's Update URI.
 *
 * The plugin header declares `Update URI: https://github.com/...`, so on every
 * successful check wp_update_plugins() (wp-includes/update.php) calls
 * `update_plugins_github.com` once per plugin on that host. Core then sets
 * `id` and `plugin`, copies `version` to `new_version`, compares it with the
 * header Version read from disk and files the entry under `response` or
 * `no_update`. This callback only answers "what is the latest release": it
 * never touches the transient or another plugin's entry.
 *
 * Core runs the hook after api.wordpress.org answered 200. While that request
 * fails, the last stored offer stays; a record deleted in the meantime gets
 * no offer until the next check that reaches WordPress.org.
 *
 * @param array|false $update      Update data from an earlier callback, or false.
 * @param array       $plugin_data Plugin headers.
 * @param string      $plugin_file Plugin basename.
 * @param string[]    $locales     Installed locales.
 * @return array|false
 */
function sn_plugin_update_offer( $update, $plugin_data, $plugin_file, $locales ) {
	if ( SN_GH_PLUGIN_BASENAME !== $plugin_file ) {
		return $update;
	}

	// Core's Check Again (WP_FORCE_UPDATE_CHECK, or ?force-check=1 from someone
	// who can update plugins) asks GitHub again instead of reading the cached tag.
	$latest_tag = sn_gh_latest_plugin_tag( sn_plugin_update_force_refresh_requested() );
	if ( null === $latest_tag ) {
		return $update;
	}

	$repo_url = 'https://github.com/' . SN_GH_PLUGIN_OWNER . '/' . SN_GH_PLUGIN_REPO;
	// Icons and banners are served from the plugin's own assets/ directory.
	// The Updates screen reads icons svg > 2x > 1x > default, and the Add
	// Plugins list reads `default` without an empty() guard, so every key is
	// set; one SVG serves them all. Banners render only in View Details (the
	// plugins_api filter) and are here for symmetry.
	$icon_url   = plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	$banner_url = plugins_url( 'assets/banner.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	// The Updates screen reads tested, requires and requires_php from the
	// entry; without them each row says "Compatibility: Unknown".
	$compat = sn_gh_plugin_compat();

	return array(
		'slug'         => SN_GH_PLUGIN_SLUG,
		'version'      => ltrim( $latest_tag, 'v' ),
		'url'          => $repo_url,
		'package'      => $repo_url . '/archive/refs/tags/' . $latest_tag . '.zip',
		'icons'        => array(
			'svg'     => $icon_url,
			'2x'      => $icon_url,
			'1x'      => $icon_url,
			'default' => $icon_url,
		),
		'banners'      => array(
			'low'  => $banner_url,
			'high' => $banner_url,
		),
		'tested'       => $compat['tested'],
		'requires'     => $compat['requires'],
		'requires_php' => $compat['requires_php'],
	);
}
add_filter( 'update_plugins_github.com', 'sn_plugin_update_offer', 10, 4 );
