# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- **The WordPress boot rig serves the plugin's own files again.** `tools/wp-boot/router.php` only served files whose real path was inside the WordPress folder, and the plugin is a link to this checkout, so every plugin script and stylesheet fell through to WordPress and came back as a 301. The editor then loaded none of the plugin's scripts on either version, so the Signal & Noise panel was missing on both and the comparison could not see it. The plugin's own folder is now allowed too, and no path segment starting with a dot is served or run (`.git`, `.env`, `.gitattributes`: the plugin folder is the whole checkout). Found on the 7.2 RC3 pass (2026-11-27), where the panel then showed its 11 fields on 7.1.5 and RC3 alike. Development tooling only; nothing here ships.

## [2026.11.66] - 2026-11-25 — Analytics integrity: a sampling disagreement between the rollup's two queries is logged, not an alert; the wave-4 watch is due 2026-12-26

### Fixed
- **The Health scan's Analytics integrity check no longer flags a sampling disagreement as a rollup bug.** Views and page-viewing visitors come from two Analytics Engine queries, each sampled on its own, and on a one-visitor page one could see the page view while the other did not (2026-11-11 `/r/rylo`, 0 against 2; 2026-11-17 `/resume/`, 0 against 1). The page-view query now also reads the page views it saw (`pv_weight`, the same `sum(_sample_interval)` beside `count(DISTINCT …)` the live dims query runs). When that differs from the row's views, the nightly rollup logs a sampling disagreement and records no alert. Queries that agree and still invert, or a result without `pv_weight`, still raise it. `pv_weight` is never stored. Test: `tests/analytics-rollup.php`.

### Changed
- **The wave-4 tool retirement watch is due again on 2026-12-26.** Its 2026-11-25 read found no tool collapsed: agent sessions still call `get-deploy-status`, `get-cron-history`, `list-cron-events`, `get-analytics-summary` and `get-health-scan`. Test: `tests/watches.php`.

