# Signal & Noise Tools

Companion plugin for the [Signal & Noise theme](https://github.com/juanlentino/signal-and-noise). Holds the operational tooling that lives outside theme presentation: REST surface, Plausible integration, Cloudflare purge, security headers, admin UI.

## Status

Phase 1 of a 4-phase split from the theme repo. See the theme's `docs/superpowers/specs/2026-05-15-companion-plugin-phase-1-design.md` for the full architecture spec.

## Installation (Phase 1, manual)

**Order matters.** The companion theme (Signal & Noise) must be at v8.2.0+ before this plugin can load. v8.2.0 is the theme release that deleted the 9 module files from the theme's `inc/`; without that deletion, both packages declare the same function names and PHP fatals. Since v1.0.1, the plugin's bootstrap detects this situation and bails out with an admin notice instead of fataling — but the maintainer still needs to ship the theme update to actually use the plugin.

1. Update the Signal & Noise theme to v8.2.0+ (WP admin → Dashboard → Updates → click *Update* on the theme tile, or visit `…/wp-admin/update-core.php?force-check=1` to surface it faster).
2. Download a release zip from this repo's *Releases* tab (or `https://github.com/juanlentino/signal-and-noise-tools/archive/refs/tags/v1.0.1.zip`).
3. WP admin → Plugins → Add New → Upload Plugin → choose the zip → Install Now → Activate.
4. If WP unzips to `wp-content/plugins/signal-and-noise-tools-1.0.1/` (with the version suffix), rename via SFTP to `wp-content/plugins/signal-and-noise-tools/`.

Phase 2 will add a GitHub-poll self-updater that handles install/update automatically and removes the manual zip step.

## Cross-package contracts

This plugin coordinates with the theme via WP hooks.

| Hook | Direction | Purpose |
| --- | --- | --- |
| `sn_purge_all_caches_result` | Plugin → Theme | Trigger theme's cache-purge function, get count back |
| `sn_self_heal_force_run_result` | Plugin → Theme | Trigger theme's template self-heal, get result array back |
| `sn_updater_branch` | Plugin → Theme | Read the theme updater's tracked branch |
| `sn_updater_force_check` | Plugin → Theme | Force the theme updater to re-poll GitHub |
| `sn_updater_clear_error` | Plugin → Theme | Dismiss the theme updater's error notice |

## Modules in this plugin

| Module | What it does |
| --- | --- |
| `inc/seo.php` | Meta description filter, Breeze cache excludes |
| `inc/security-headers.php` | HTTP security headers + WP hardening |
| `inc/cloudflare-purge.php` | Auto-purge CF edge cache on save_post / theme update |
| `inc/plausible-api.php` | Plausible Stats API client + SWR cache |
| `inc/plausible-admin.php` | Plausible settings tab |
| `inc/plausible-widget.php` | Dashboard Plausible widgets (snapshot + realtime + pages + sources) |
| `inc/admin-bar.php` | Top-bar quick-action dropdown |
| `inc/admin-page.php` | *Appearance → Signal & Noise* options page |
| `inc/rest-api.php` | `signal-noise/v1` REST surface |
| `inc/rss-plausible-tracker.php` | RSS subscriber tracking via Plausible + `wp_rss_feed_log` table (added v1.1.0, migrated from theme MU plugin) |

## License

GPL-2.0-or-later — same as the theme.
