# Signal & Noise Tools

Companion plugin for the [Signal & Noise theme](https://github.com/juanlentino/signal-and-noise). Holds the operational tooling that lives outside theme presentation: REST surface, Plausible integration, Cloudflare purge, security headers, admin UI.

## Status

Phase 1 of a 4-phase split from the theme repo. See the theme's `docs/superpowers/specs/2026-05-15-companion-plugin-phase-1-design.md` for the full architecture spec.

## Installation (Phase 1, manual)

1. Download a release zip from this repo's *Releases* tab (or `https://github.com/juanlentino/signal-and-noise-tools/archive/refs/tags/v1.0.0.zip`).
2. WP admin → Plugins → Add New → Upload Plugin → choose the zip → Install Now → Activate.
3. If WP unzips to `wp-content/plugins/signal-and-noise-tools-1.0.0/` (with the version suffix), rename via SFTP to `wp-content/plugins/signal-and-noise-tools/`.

Phase 2 will add a GitHub-poll self-updater that handles install/update automatically.

## Cross-package contracts

This plugin coordinates with the theme via WP hooks.

| Hook | Direction | Purpose |
| --- | --- | --- |
| `sn_purge_all_caches_result` | Plugin → Theme | Trigger theme's cache-purge function, get count back |
| `sn_self_heal_force_run_result` | Plugin → Theme | Trigger theme's template self-heal, get result array back |
| `sn_updater_branch` | Plugin → Theme | Read the theme updater's tracked branch |
| `sn_updater_force_check` | Plugin → Theme | Force the theme updater to re-poll GitHub |
| `sn_updater_clear_error` | Plugin → Theme | Dismiss the theme updater's error notice |

## License

GPL-2.0-or-later — same as the theme.
