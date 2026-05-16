<?php
/**
 * Signal & Noise Tools — WP-native update integration.
 *
 * Hooks into WordPress's standard update system so this plugin appears
 * in wp-admin/update-core.php and wp-admin/plugins.php alongside other
 * plugins. Polls the GitHub Tags API every 12h (cached in a site
 * transient) to compare local version against the latest tagged release.
 *
 * Under normal operation (Phase 2c auto-deploy on tag push), local
 * always matches GitHub within ~30s of a tag push, so the UI shows
 * "up to date." If auto-deploy ever fails or hasn't caught up, the
 * UI shows "update available" — useful deploy-health indicator.
 *
 * "Update Now" is intercepted by `upgrader_pre_install`: we return
 * a WP_Error directing the maintainer to push a git tag (WP's
 * installer would overwrite the .git checkout and break subsequent
 * SSH-based auto-deploys).
 *
 * Added in v1.4.0 (2026-05-16). Mirror of the theme's equivalent
 * inc/wp-update-integration.php in signal-and-noise v8.5.0.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_GH_PLUGIN_OWNER     = 'juanlentino';
const SN_GH_PLUGIN_REPO      = 'signal-and-noise-tools';
const SN_GH_PLUGIN_CACHE_KEY = 'sn_gh_latest_plugin';
const SN_GH_PLUGIN_CACHE_TTL = 12 * HOUR_IN_SECONDS;
const SN_GH_PLUGIN_BASENAME  = 'signal-and-noise-tools/signal-and-noise-tools.php';
const SN_GH_PLUGIN_SLUG      = 'signal-and-noise-tools';

/**
 * Fetch the highest semver-formatted tag from GitHub. Returns the tag
 * string (e.g. "v1.4.0") on success, null on error / no matching tags.
 * Cached for SN_GH_PLUGIN_CACHE_TTL; empty sentinel cached 1h on failure.
 */
function sn_gh_latest_plugin_tag() {
	$cached = get_site_transient( SN_GH_PLUGIN_CACHE_KEY );
	if ( $cached !== false ) {
		return $cached === '' ? null : $cached;
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

	$latest_tag = sn_gh_latest_plugin_tag();
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
 * Intercept "Update Now" for this plugin. Auto-deploy is the only
 * supported installation path; WP's installer would overwrite the
 * .git checkout and break subsequent SSH-based deploys.
 */
add_filter( 'upgrader_pre_install', function( $result, $hook_extra ) {
	$plugin = isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
	if ( $plugin !== SN_GH_PLUGIN_BASENAME ) {
		return $result;
	}
	return new WP_Error(
		'sn_managed_by_auto_deploy',
		sprintf(
			/* translators: %s: linked repo URL */
			'Signal &amp; Noise Tools is managed via SSH-based auto-deploy on git tag push. To install an update, push a tag from %s — the GitHub Actions workflow handles deployment within ~30 seconds. WP\'s installer would overwrite the git checkout and break subsequent auto-deploys.',
			'<a href="https://github.com/' . SN_GH_PLUGIN_OWNER . '/' . SN_GH_PLUGIN_REPO . '">github.com/' . SN_GH_PLUGIN_OWNER . '/' . SN_GH_PLUGIN_REPO . '</a>'
		)
	);
}, 10, 2 );
