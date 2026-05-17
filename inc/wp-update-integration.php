<?php
/**
 * Signal & Noise Tools — WP-native update integration.
 *
 * Hooks into WordPress's standard update system so this plugin appears
 * in wp-admin/update-core.php and wp-admin/plugins.php alongside other
 * plugins. Polls the GitHub Tags API every 12h (cached in a site
 * transient) to compare local version against the latest tagged release.
 *
 * When a new tag is available, WP UI shows "Update Available" — the
 * maintainer clicks Update Now and WP downloads + installs from
 * GitHub's auto-generated tag archive (`/archive/refs/tags/<tag>.zip`).
 *
 * The unpacked archive's top-level directory is `signal-and-noise-tools-<version>/`
 * (GitHub strips the leading 'v' from the tag in the dir name); the
 * `upgrader_source_selection` filter renames it to `signal-and-noise-tools/`
 * so WP installs to the slug that matches SN_GH_PLUGIN_BASENAME.
 *
 * Added in v1.4.0 (2026-05-16). Rewritten in v1.10.1 (2026-05-16):
 * the original implementation intercepted "Update Now" with a
 * WP_Error because the legacy auto-deploy-on-tag-push pipeline would
 * .git-checkout the new tag and overwrite the WP installer's work.
 * v1.10.1 moves to WP-UI-driven updates and disables the tag-push
 * auto-deploy (see .github/workflows/deploy.yml). v1.11.1 fixed the
 * 12h cache that was hiding new tags from the WP updater. v1.11.2
 * adds inc/wp-update-git-preservation.php which backs up + restores
 * the .git directory through the install — closing the footgun where
 * WP's clear_destination() would otherwise destroy the .git checkout
 * that the canonical workflow_dispatch deploy depends on. Both install
 * paths (gh workflow run AND wp-admin Update Now) now coexist safely.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_GH_PLUGIN_OWNER          = 'juanlentino';
const SN_GH_PLUGIN_REPO           = 'signal-and-noise-tools';
const SN_GH_PLUGIN_CACHE_KEY      = 'sn_gh_latest_plugin';
const SN_GH_PLUGIN_CACHE_TTL      = HOUR_IN_SECONDS; // v1.11.1: 12h → 1h
const SN_GH_PLUGIN_BASENAME       = 'signal-and-noise-tools/signal-and-noise-tools.php';
const SN_GH_PLUGIN_SLUG           = 'signal-and-noise-tools';
const SN_GH_PLUGIN_LAST_SEEN_OPT  = 'sn_last_seen_plugin_version';

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

	$url      = 'https://api.github.com/repos/' . SN_GH_PLUGIN_OWNER . '/' . SN_GH_PLUGIN_REPO . '/tags?per_page=100';
	$response = wp_remote_get( $url, array(
		'timeout' => 8,
		'headers' => array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'WordPress; ' . home_url(),
		),
	) );

	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		set_site_transient( SN_GH_PLUGIN_CACHE_KEY, '', HOUR_IN_SECONDS );
		return null;
	}

	$tags = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $tags ) ) {
		set_site_transient( SN_GH_PLUGIN_CACHE_KEY, '', HOUR_IN_SECONDS );
		return null;
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
		set_site_transient( SN_GH_PLUGIN_CACHE_KEY, '', HOUR_IN_SECONDS );
		return null;
	}

	set_site_transient( SN_GH_PLUGIN_CACHE_KEY, $highest, SN_GH_PLUGIN_CACHE_TTL );
	return $highest;
}

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
	$force_refresh = ( defined( 'WP_FORCE_UPDATE_CHECK' ) && WP_FORCE_UPDATE_CHECK )
		|| ( isset( $_GET['force-check'] ) && $_GET['force-check'] );

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

/**
 * Rename the unpacked source directory so WP installs to the correct
 * plugin slug.
 *
 * GitHub's auto-generated tag archive (`/archive/refs/tags/v1.10.1.zip`)
 * unpacks to `signal-and-noise-tools-1.10.1/` — with the version suffix
 * but without the leading 'v'. WP's installer uses the dir name to
 * decide where to install, which would end up as
 * `wp-content/plugins/signal-and-noise-tools-1.10.1/` (wrong slug,
 * the plugin would deactivate on update because SN_GH_PLUGIN_BASENAME
 * no longer resolves).
 *
 * The filter receives `$source` (path to the unpacked dir) and renames
 * it to drop the version suffix. Standard pattern for GitHub-hosted
 * plugins that ship via auto-generated tag archives.
 */
add_filter( 'upgrader_source_selection', function( $source, $remote_source, $upgrader, $hook_extra ) {
	$plugin = isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
	if ( $plugin !== SN_GH_PLUGIN_BASENAME ) {
		return $source;
	}

	$source         = trailingslashit( $source );
	$desired_source = trailingslashit( dirname( $source ) ) . SN_GH_PLUGIN_SLUG . '/';

	if ( $source === $desired_source ) {
		return $source;
	}

	global $wp_filesystem;
	if ( ! $wp_filesystem || ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired_source ) ) ) {
		return new WP_Error(
			'sn_rename_source_failed',
			'Could not rename the unpacked plugin directory from "' . esc_html( basename( $source ) ) . '" to "' . SN_GH_PLUGIN_SLUG . '". Manual install via SFTP may be required.'
		);
	}

	return $desired_source;
}, 10, 4 );

/**
 * On every admin pageview, check whether the on-disk plugin version
 * differs from the last-seen version. If it does, clear the update
 * transient — the cached "latest" was relative to the previous
 * version and is now stale.
 *
 * Handles the upgrade-just-happened case automatically:
 * - WP UI install completes → next admin pageview clears the cache
 * - workflow_dispatch deploy lands → next admin pageview clears the cache
 *
 * Costs one get_option() call per admin pageview. Negligible.
 *
 * Added in v1.11.1 (2026-05-16).
 */
add_action( 'admin_init', function() {
	$last_seen = (string) get_option( SN_GH_PLUGIN_LAST_SEEN_OPT, '' );
	$current   = defined( 'SNT_VERSION' ) ? SNT_VERSION : '';
	if ( $current && $last_seen !== $current ) {
		delete_site_transient( SN_GH_PLUGIN_CACHE_KEY );
		// Also clear WP's own plugin update transient so the next poll
		// re-fetches fresh data (covers the case where WP cached our
		// pre-update version as "latest").
		delete_site_transient( 'update_plugins' );
		// v1.15.1: also clear the parsed-plugins-headers cache so the
		// Plugins screen renders the current plugin header (Name,
		// Description, Author) instead of cached pre-update values.
		// Required because our SSH-checkout deploy path doesn't trigger
		// WP's installer (which would call wp_clean_plugins_cache
		// automatically). Bug surfaced when "Signal & Noise Tools"
		// displayed as the literal text "Signal &amp; Noise Tools" in
		// the plugins list (header was already plain `&`, but the cache
		// retained an old double-escaped value across SSH deploys).
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache();
		}
		update_option( SN_GH_PLUGIN_LAST_SEEN_OPT, $current );
	}
} );
