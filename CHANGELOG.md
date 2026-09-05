# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- WP-Cron no longer runs in the request path. Cloudways PHP analytics measured
  `/wp-cron.php` at **62 runs, 10.6s average, 51.7s peak** in 24 hours on a
  2 GB / 2 vCPU box sitting at ~90% memory — and with `DISABLE_WP_CRON` unset,
  that cost lands on whichever visitor's pageview happens to spawn it. Varnish
  answers the requests it cannot place with 503; ten were recorded in the same
  window (#1002). The constant is now defined at plugin load, which is the only
  window that matters since `spawn_cron()` reads it during `init`.

  Safe only because an external driver was verified present first: Cloudways'
  Cron Optimizer already hits `wp-cron.php` every five minutes. Worth knowing —
  that optimizer installs the system cron but leaves `DISABLE_WP_CRON` unset,
  so the site had BOTH the external tick and in-request spawning. Only the
  second one hurt. Turn it off with `snt_offload_wp_cron`, or by defining the
  constant yourself; an existing definition is never overridden, in either
  direction, and it does not fire under WP-CLI. (#1032)

### Internal
- The failure mode here is silent and total — no external cron means nothing
  runs and nothing complains — so the change leans on `snt_cron_health_model()`,
  which already marks a hook whose time has passed as `overdue` and elevates
  cron health to critical. Tests pin that detector still exists rather than
  assuming it, and that the bootstrap requires the offload before any `init`
  hook is registered. (#1032)

## [13.97.1] - 2026-09-05 — never leave an impossible value cached

### Fixed
- An empty plugin registry no longer survives in the object cache after an
  update. Reported after installing v13.97.0: the Plugins window showed an
  empty list as if nothing were installed. That window prints
  `Could not load plugins: <error>` on any failed fetch and printed none, so
  `GET /wp/v2/plugins` answered **200 with zero plugins** — server-side, and
  ours. `sn_plugin_update_version_watchdog()` is the one thing here that
  deliberately drops WP's plugin cache; it fires exactly once on the first
  request after a version change, and since v12.25.0 it runs on `init`, so
  under WP-CLI, cron, the front end and REST as well as wp-admin. Whatever
  rebuilds the cache next does so while the plugin directory may still be
  settling, and `get_plugins()` caches whatever it scanned — including
  nothing. `snt_plugin_registry_repair()` now drops that value whenever the
  registry is empty while `active_plugins` is not, which cannot both be
  legitimately true. It does NOT rebuild: the next read does that, once the
  filesystem has settled. (#1029)

### Changed
- The REST probe added in v13.97.0 now repairs after recording, rather than
  only recording. That release argued repairing inside a read request would
  hide the evidence — true only if repair REPLACES recording, which it does
  not: the observation is written to an option that outlives the request, so
  dropping the poisoned cache afterwards costs no evidence and spares the next
  reader the same wrong answer. A test pins the order. (#1029)

### Internal
- The watchdog calls the repair behind `function_exists`, which would degrade
  to a silent no-op if the probe were ever dropped from the loader — the exact
  shape of failure this issue is about. Tests pin that both files are required
  by the bootstrap and that the watchdog still calls the repair. (#1029)

