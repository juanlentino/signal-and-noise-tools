# Changelog

All notable changes to Signal & Noise Tools are documented here.

## [1.2.0] - 2026-05-15

### Removed
- "Latest on GitHub" status row + "Check Now" button in admin Dashboard tab (`inc/admin-page.php`).
- "Heal Templates" form handler + UI card in admin Dashboard tab (`inc/admin-page.php`).
- Quick "Check updates" entry in WP admin bar (`inc/admin-bar.php`).
- REST routes `/check-updates` and `/heal-templates` (`inc/rest-api.php`). Their backing theme modules retired in theme v8.3.0.
- `upgrader_process_complete` hook in `inc/cloudflare-purge.php` — replaced by deploy-time REST call from GitHub Actions.

### Changed
- `/full-reset` REST endpoint no longer includes a "heal templates" step. New behavior: purge caches + clear DB template overrides only.

### Notes
- Requires theme v8.3.0+. If installed against an older theme, the plugin still loads cleanly — the removed UI elements were the only readers of the retired contracts.

## Infrastructure — Phase 2c (no version bump)

- `.github/workflows/deploy.yml` added: SSH-based auto-deploy. Tag push to plugin repo → GitHub Actions SSHes into Cloudways as a **dedicated, application-scoped SSH user** (`sn-plugin`, alias for `nffqxsrgxz`) and runs `git fetch && git checkout <tag>` in the plugin directory → POSTs to `/purge-cache` for CF cache invalidation. Same tag-push ritual as the theme repo.
- **Security posture:** the deploy SSH key is bound to `sn-plugin`, a dedicated additional user with access only to this application's filesystem. If the GitHub Actions secret is ever leaked, blast radius is bounded to this WP app's content (same as a compromised WP admin), NOT the whole Cloudways server. Earlier intermediate setup using `master_user` was discarded for this reason.
- **One-time live cutover (2026-05-16):** plugin directory renamed from `signal-and-noise-tools-1.2.0` (artifact of the Upload Plugin flow) to the canonical `signal-and-noise-tools`. Done via WP-CLI `deactivate → mv → git clone → checkout v1.2.0 → activate`, sub-second downtime. Backup retained on the live server at `signal-and-noise-tools-1.2.0-old` and `signal-and-noise-tools-OLD-MASTER`; delete after a few days of stable operation.
- Cloudways → GitHub auth uses a dedicated read-only deploy key (`cloudways-server-readonly`) on this repo; the private key lives in the `sn-plugin` user's writable `~/.openssh/cw-to-gh-deploy_ed25519` (Cloudways convention: `~/.ssh/` is root-owned for additional users, `~/.openssh/` is user-writable). The workflow exports `GIT_SSH_COMMAND` on the remote shell to point git at this key without needing a `~/.ssh/config` file.
- Treated as build infra per CLAUDE.md `.github/workflows/` convention (mirrors theme Phase 2a — no version bump for the workflow file itself).

## [1.1.0] — RSS Plausible Tracker migrated from theme MU plugin

First minor in the 1.x line. Brings the early slice of Phase 4 forward (ahead of Phase 2's updater migration) to resolve the awkward dual-state where `rss-plausible-tracker.php` lived in the theme repo but was distributed manually to `wp-content/mu-plugins/`.

### Added

- **[`inc/rss-plausible-tracker.php`](inc/rss-plausible-tracker.php)** — RSS subscriber tracker (formerly `mu-plugins/rss-plausible-tracker.php` in the theme repo, v1.2.0 of the MU plugin). Same DB table (`wp_rss_feed_log`), same option keys (`sn_rss_tracker_settings`, `sn_rss_tracker_db_version`), same cron hook (`sn_rss_tracker_daily_prune`), same admin tab and dashboard widget. Only the file location and surrounding bootstrap changed.
- **[`tests/bot-detection.php`](tests/bot-detection.php)** — standalone PHP fixture test for `sn_rss_tracker_is_bot()`. Moved from theme repo's `mu-plugins/tests/`. Runnable as `php tests/bot-detection.php`.
- **Pre-flight guard #2** in [`signal-and-noise-tools.php`](signal-and-noise-tools.php) — before `require_once`ing the rss tracker module, check `file_exists( WPMU_PLUGIN_DIR . '/rss-plausible-tracker.php' )`. If the legacy MU plugin file is still on disk under `wp-content/mu-plugins/`, this plugin skips loading its own copy and emits a one-line admin notice asking the maintainer to delete the MU file. MU plugin continues serving tracking; no fatal, no downtime, no data loss.

### Migration order

The dual-existence problem is the same shape as Phase 1's, with the same solution: pre-flight guard means no fatal regardless of order.

1. **Install plugin v1.1.0 first.** Maintainer's WP admin → Plugins → existing *Signal & Noise Tools* listing → manual upgrade via Upload Plugin (until Phase 2's auto-updater lands). Plugin's guard sees `wp-content/mu-plugins/rss-plausible-tracker.php` still present → skips loading the new tracker module → MU plugin continues serving tracking → admin notice instructs maintainer on next step.
2. **Delete the MU file via SFTP:** `wp-content/mu-plugins/rss-plausible-tracker.php`. Or via WP-CLI: `wp mu-plugin delete rss-plausible-tracker` (if available).
3. **Next admin pageview:** guard sees the MU file gone → loads our tracker module → tracking continues seamlessly via the plugin. Admin notice disappears.

### Data continuity

- **`wp_rss_feed_log` table:** untouched. Plugin reads/writes the same rows.
- **Options:** `sn_rss_tracker_settings`, `sn_rss_tracker_db_version` — same keys, same values.
- **Cron event:** `sn_rss_tracker_daily_prune` — same hook name. When the MU plugin stops loading, the cron event remains scheduled but its handler is now in the active plugin (function name `sn_rss_tracker_cron_prune` is identical). WP fires the event → plugin's handler runs. Seamless.

### Why minor

New module added, new bootstrap guard, theme repo cleanup in coordinated theme v8.2.1 release — meaningful capability shift in plugin scope (it now owns RSS analytics, not just admin/REST/security tooling). No breaking change. First minor in the 1.x line.

### Coordinated theme release

Ships alongside theme `v8.2.1` (docs-only-ish), which removes `mu-plugins/rss-plausible-tracker.php` from the theme repo and updates the WORDPRESS-REFERENCE §10.0 phase plan. Theme update can ship before, during, or after the plugin update — the plugin's guard handles all orderings.

## [1.0.1] — Pre-flight legacy-theme guard

Patch fix for an order-of-operations footgun discovered during the v1.0.0 install on the live site.

### Why this exists

The Phase 1 split was designed as a coordinated release: install plugin v1.0.0 first, then click the theme update to v8.2.0. The original CHANGELOG entry framed the "duplication window" between these two steps as a cosmetic issue ("WP registers hooks twice"). That framing was wrong.

The actual failure mode: if the plugin loads while the theme is still at v8.1.x, both packages have `function sn_purge_all_caches()`, `function sn_handle_quick_purge_caches()`, and the seven other moved-function declarations on disk. PHP fatals at parse time with "Cannot redeclare function sn_*", WordPress catches it during plugin activation, and the user sees *"Plugin could not be activated because it triggered a fatal error."* It's a hard fatal, not a hook-collision cosmetic.

WordPress hooks ARE idempotent (the `add_action` layer); PHP function declarations are NOT. These are two different layers of WordPress, and the original spec conflated them.

### Fixed

- **[`signal-and-noise-tools.php`](signal-and-noise-tools.php) — pre-flight check at bootstrap.** Before the `require_once` chain runs, the plugin checks whether `wp-content/themes/signal-and-noise/inc/admin-page.php` exists on disk. If it does, the theme is still at v8.1.x and the require chain would fatal. The plugin returns early (skipping module loading entirely) and surfaces an admin notice asking the maintainer to update the theme first. After the theme update lands, the next admin pageview sees the file gone, the guard passes, and modules load normally.

### Behavior contract

- **Theme is at v8.2.0+ (legacy files deleted):** plugin loads modules as usual. No-op cost.
- **Theme is at v8.1.x (legacy files still present):** plugin bootstrap bails before any function is declared; admin notice tells the maintainer to update the theme. No fatal, no broken admin.
- **A non–Signal & Noise theme is active:** guard is skipped (no conflict possible); plugin loads normally.
- **Theme is downgraded back to v8.1.x while the plugin is active:** next request, the guard re-runs and bails. Plugin functions stop being declared; the theme reclaims ownership. No fatal.

### Why patch

No new feature; no breaking change. One pre-flight check added to the bootstrap; the rest of the plugin is byte-identical to v1.0.0. Patch bump per SemVer.

## [1.0.0] — Phase 1: scaffold + easy moves

First release. Nine modules moved from the theme repo via the WP action/filter contract pattern.

### Added

- Plugin bootstrap (`signal-and-noise-tools.php`) with standard WP plugin header.
- 9 modules under `inc/`, mirroring the theme's flat module structure: `seo.php`, `security-headers.php`, `cloudflare-purge.php`, `plausible-api.php`, `plausible-admin.php`, `plausible-widget.php`, `admin-bar.php`, `admin-page.php`, `rest-api.php`.
- Cross-package contracts: three filters (`sn_purge_all_caches_result`, `sn_self_heal_force_run_result`, `sn_updater_branch`) and two actions (`sn_updater_force_check`, `sn_updater_clear_error`).
- GitHub Actions lint workflow (`php -l` on every PHP file).

### Coordination

Ships alongside theme Signal & Noise `v8.2.0`, which deletes the original copies of these 9 modules and registers the listener side of the contracts. Install plugin first, then ship the theme update.

### Spec + plan (from theme repo)

- `docs/superpowers/specs/2026-05-15-companion-plugin-phase-1-design.md`
- `docs/superpowers/plans/2026-05-15-companion-plugin-phase-1.md`
