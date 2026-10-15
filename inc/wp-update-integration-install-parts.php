<?php
/**
 * Signal & Noise Tools: WP-native update integration, the install (the stale-offer guard, the folder rename, the version watchdog, the View Details filter).
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
 * Read-time guard: never report S&N as updatable to the version that is
 * already installed.
 *
 * The install request runs the OLD code to its end (SNT_VERSION is still the
 * previous release), and anything in it that rebuilds `update_plugins` writes
 * "new version available" back into the record. The version watchdog below
 * clears that on the first new-code request, but the desk fires many requests
 * at once after an install: a new-code request can run the watchdog before
 * the slow install request finishes writing, and the stale entry lands after
 * it, for good. The admin bar then showed one update until the Plugins screen
 * made WordPress re-check (owner, 2026-10-08: "since always").
 *
 * Checking on READ makes every reader right whoever wrote the record: an
 * entry whose new_version is not newer than the running SNT_VERSION moves from
 * `response` to `no_update`. PURE (no I/O); the object is never mutated, a
 * copy is returned.
 *
 * @param mixed $transient The update_plugins site transient as read.
 * @return mixed
 */
function sn_plugin_update_drop_stale( $transient ) {
	if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) || ! defined( 'SNT_VERSION' ) ) {
		return $transient;
	}
	$entry = $transient->response[ SN_GH_PLUGIN_BASENAME ] ?? null;
	if ( ! is_object( $entry ) || ! isset( $entry->new_version ) || version_compare( (string) $entry->new_version, SNT_VERSION, '>' ) ) {
		return $transient;
	}
	$out = clone $transient;
	unset( $out->response[ SN_GH_PLUGIN_BASENAME ] );
	$out->no_update                           = isset( $out->no_update ) && is_array( $out->no_update ) ? $out->no_update : array();
	$out->no_update[ SN_GH_PLUGIN_BASENAME ] = $entry;
	return $out;
}
add_filter( 'site_transient_update_plugins', 'sn_plugin_update_drop_stale' );

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
 * - WP UI install completes → next pageview clears the cache
 * - workflow_dispatch deploy lands → next pageview clears the cache
 * - `wp plugin install --force` lands → next WP-CLI command clears the cache
 *
 * Costs one get_option() call per request. Negligible — the option is
 * autoloaded, so the read is an array lookup, and the body only does work on
 * the single request that observes the version change.
 *
 * HOOK CHOICE — `init`, NOT `admin_init` (v12.25.0, 2026-08-24)
 *
 * This was registered on `admin_init` from v1.11.1 until v12.25.0, which meant
 * it only ever fired for a logged-in wp-admin pageview. WP-CLI is not an admin
 * request and neither is wp-cron, so the two contexts that most need the
 * invalidation never got it: a maintainer updating from the CLI read whatever
 * the object cache last held (up to SN_GH_PLUGIN_CACHE_TTL for the tag, up to
 * 12h for `update_plugins`), and the cron-driven update poll likewise.
 *
 * That is not academic on a site with a persistent object cache: site
 * transients live in Redis rather than wp_options, so `wp transient delete
 * --all` cannot clear them either — it deletes only DB-backed transients by
 * design. The self-healing existed but was unreachable from the way the plugin
 * is actually operated. Diagnosed 2026-08-24.
 *
 * `init` fires in wp-admin, on the front end, under wp-cron and under WP-CLI.
 * Admin requests fire `init` *before* `admin_init`, so this strictly dominates
 * the old registration — nothing that worked before stops working.
 *
 * Added in v1.11.1 (2026-05-16). Moved to `init` + named in v12.25.0.
 */
function sn_plugin_update_version_watchdog() {
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
		// wp_clean_plugins_cache() lives in wp-admin/includes/plugin.php, which
		// is not loaded outside wp-admin. Now that this watchdog runs under
		// WP-CLI, wp-cron and the front end too, pull the include in rather
		// than silently skipping the header-cache clear in those contexts —
		// skipping it would be worse than the bug this fixes, because the
		// last-seen option is written either way and the next admin request
		// would see no version change left to act on.
		if ( ! function_exists( 'wp_clean_plugins_cache' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache();
			// v13.97.1 (#1029): and never leave an EMPTY plugin list behind.
			// This watchdog is the one thing in the plugin that deliberately
			// drops WP's plugin cache, it fires exactly once on the first
			// request after a version change, and since v12.25.0 it runs on
			// `init` - so under WP-CLI, cron, the front end and REST as well as
			// wp-admin. Whichever call rebuilds the cache next does so while
			// the plugin directory may still be settling, and `get_plugins()`
			// caches whatever it scanned, including nothing. A persistent
			// object cache then serves "no plugins installed" as a healthy 200
			// until something evicts it - which is what the OpenStation
			// Plugins window rendered as an empty list after an update.
			//
			// The repair is a no-op unless the registry is empty AND
			// active_plugins is not, which cannot both be legitimately true.
			if ( function_exists( 'snt_plugin_registry_repair' ) ) {
				snt_plugin_registry_repair();
			}
		}
		// v2.1.2: also clear the plugin_information_<slug> transient
		// which caches the plugins_api response (used by the View
		// Details modal). Without this, the modal would keep showing
		// the previous version's metadata even after install.
		delete_site_transient( 'plugin_information_' . SN_GH_PLUGIN_SLUG );
		update_option( SN_GH_PLUGIN_LAST_SEEN_OPT, $current );
	}
}
add_action( 'init', 'sn_plugin_update_version_watchdog' );

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
	$compat                  = sn_gh_plugin_compat();
	$info->requires          = $compat['requires'];
	$info->tested            = $compat['tested'];
	$info->requires_php      = $compat['requires_php'];
	$info->download_link     = $latest_tag ? $repo_url . '/archive/refs/tags/' . $latest_tag . '.zip' : '';
	$info->short_description = 'Operational + content tooling that powers juanlentino.com. SEO emission, cache controls, RSS subscriber tracking, OG card generation, GitHub-Actions deploy status, AI-assisted meta descriptions (WP 7.0+), and a WordPress/desktop-mode integration with on-desktop widgets.';
	$info->sections          = array(
		'description' => '<p>Companion plugin to the <a href="https://github.com/juanlentino/signal-and-noise">Signal &amp; Noise</a> brutalist block theme. Owns everything operational + content-authoring-related so the theme can stay focused on presentation.</p>'
			. '<p><strong>SEO</strong>: meta tags, JSON-LD <code>@graph</code>, sitemap routing, <code>&lt;title&gt;</code> emission, <code>Last-Modified</code> header + <code>If-Modified-Since</code> 304 (post-Phase-13 cutover; The SEO Framework dropped as a dependency).</p>'
			. '<p><strong>Ops tooling</strong>. Cloudflare cache purge, custom login URL, RSS subscriber tracking, API rate-limit monitor, OG card generator, GitHub Actions deploy status.</p>'
			. '<p><strong>WP 7.0 readiness</strong>. AI-assisted meta description (Phase 12 scaffold, dormant on 6.x), Abilities API registration (4 abilities: <code>purge-all-caches</code>, <code>regenerate-og-card</code>, <code>get-deploy-status</code>, <code>clear-template-overrides</code>).</p>'
			. '<p><strong>Desktop-mode integration</strong>: dock entry with 8-tab submenu, three desktop widgets (deploy status, quick actions, RSS subscribers), 13 ⌘K commands.</p>',
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
