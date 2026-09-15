# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [15.3.0] - 2026-09-15 — the readings where their questions are asked

### Added
- **Security › Firewall.** What Cloudflare stopped before WordPress ran, on the tab that asks what is being kept out: events by action, top rules, the log's top paths and countries, the raw-dataset note, its own Refresh. The first live read showed the WAF rule blocking the abilities route 487 times in a day, which is the witness the health check now reads; that belongs next to Login defense, not under a credentials tab. Native leaf `security-firewall.php` through the monitor's shared part; classic leaf in `inc/cloudflare-readings-admin.php`. Parity suite: 11.

### Changed
- **Edge, 7 days moved to Measurement › Analytics**, a section above the hub's two columns, next to the analytics it belongs with (the Edge view already reads the same zone): the five figures, the 5xx split, the monitor's Refresh. Painted only once the monitor has a configured record, so a site without Cloudflare keeps the hub as it was. Classic card the same, after the pipeline strip. Pinned on both leaves.
- **Connections › Cloudflare is wiring and cache.** Credentials (read-only, from the keyring), Token with the monitor's Refresh, Cache. Edge and Firewall left for the tabs above; one stored record feeds all three leaves, the daily cron and the ability unchanged.

