# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

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

