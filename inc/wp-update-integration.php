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
	$response = wp_remote_get( $url, array(
		'timeout'     => 8,
		'headers'     => $headers,
		// v8.8.x: forbid redirects — the SNT_GITHUB_TOKEN bearer must never be
		// re-sent to a 3xx target (outbound-hardening convention, v8.7.1).
		'redirection' => 0,
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
		|| ! empty( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache-buster; presence-only boolean, no state change.

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
	$plugin_data->tested       = '7.0';
	$plugin_data->requires     = '7.0';
	$plugin_data->requires_php = '8.0';

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
		// v2.1.2: also clear the plugin_information_<slug> transient
		// which caches the plugins_api response (used by the View
		// Details modal). Without this, the modal would keep showing
		// the previous version's metadata even after install.
		delete_site_transient( 'plugin_information_' . SN_GH_PLUGIN_SLUG );
		update_option( SN_GH_PLUGIN_LAST_SEEN_OPT, $current );
	}
} );

/**
 * Filter plugins_api to provide the View Details modal data for our
 * self-hosted plugin.
 *
 * WP core's plugin_install.php fires plugins_api( 'plugin_information', $args )
 * when a user clicks "View details" on any plugin row. For
 * wordpress.org plugins, the WP.org API server populates the response.
 * For self-hosted, that API returns nothing, so the modal shows a
 * "Plugin not found" error — unless we filter and supply the response
 * ourselves.
 *
 * v2.1.2: added so the View Details modal renders correctly + the
 * banner + the inline description. Result cached in the
 * `plugin_information_<slug>` site transient (24h TTL by WP default);
 * the version-change watchdog above clears it on each upgrade.
 *
 * Verified shape against wp-admin/includes/plugin-install.php
 * install_plugin_information() lines ~872-891 (banner rendering) and
 * wp-admin/includes/class-wp-plugin-install-list-table.php display_rows()
 * lines ~445-475 (icon rendering).
 */
add_filter( 'plugins_api', function( $result, $action, $args ) {
	if ( 'plugin_information' !== $action ) {
		return $result;
	}
	if ( ! isset( $args->slug ) || SN_GH_PLUGIN_SLUG !== $args->slug ) {
		return $result;
	}

	$icon_url       = plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	$banner_url     = plugins_url( 'assets/banner.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	$latest_tag     = sn_gh_latest_plugin_tag();
	$latest_version = $latest_tag ? ltrim( $latest_tag, 'v' ) : ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '' );
	$repo_url       = 'https://github.com/' . SN_GH_PLUGIN_OWNER . '/' . SN_GH_PLUGIN_REPO;

	$info                    = new stdClass();
	$info->name              = 'Signal & Noise Tools';
	$info->slug              = SN_GH_PLUGIN_SLUG;
	$info->version           = $latest_version;
	$info->author            = '<a href="https://juanlentino.com">Juan Lentino</a>';
	$info->author_profile    = 'https://juanlentino.com';
	$info->homepage          = $repo_url;
	$info->requires          = '7.0';
	$info->tested            = '7.0';
	$info->requires_php      = '8.0';
	$info->download_link     = $latest_tag ? $repo_url . '/archive/refs/tags/' . $latest_tag . '.zip' : '';
	$info->short_description = 'Operational + content tooling that powers juanlentino.com — SEO emission, cache controls, RSS subscriber tracking, OG card generation, GitHub-Actions deploy status, AI-assisted meta descriptions (WP 7.0+), and a WordPress/desktop-mode integration with on-desktop widgets.';
	$info->sections          = array(
		'description' => '<p>Companion plugin to the <a href="https://github.com/juanlentino/signal-and-noise">Signal &amp; Noise</a> brutalist block theme. Owns everything operational + content-authoring-related so the theme can stay focused on presentation.</p>'
			. '<p><strong>SEO</strong> — meta tags, JSON-LD <code>@graph</code>, sitemap routing, <code>&lt;title&gt;</code> emission, <code>Last-Modified</code> header + <code>If-Modified-Since</code> 304 (post-Phase-13 cutover; The SEO Framework dropped as a dependency).</p>'
			. '<p><strong>Ops tooling</strong> — Cloudflare cache purge, custom login URL, RSS subscriber tracking, API rate-limit monitor, OG card generator, GitHub Actions deploy status.</p>'
			. '<p><strong>WP 7.0 readiness</strong> — AI-assisted meta description (Phase 12 scaffold, dormant on 6.x), Abilities API registration (4 abilities: <code>purge-all-caches</code>, <code>regenerate-og-card</code>, <code>get-deploy-status</code>, <code>clear-template-overrides</code>).</p>'
			. '<p><strong>Desktop-mode integration</strong> — dock entry with 8-tab submenu, three desktop widgets (deploy status, quick actions, RSS subscribers), 13 ⌘K commands.</p>',
		'changelog'   => '<p>See the full <a href="' . esc_url( $repo_url . '/blob/main/CHANGELOG.md' ) . '">CHANGELOG on GitHub</a>.</p>',
	);
	$info->icons             = array(
		'svg'     => $icon_url,
		'2x'      => $icon_url,
		'1x'      => $icon_url,
		'default' => $icon_url,
	);
	$info->banners           = array(
		'low'  => $banner_url,
		'high' => $banner_url,
	);

	return $info;
}, 10, 3 );
