# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- S&N Home Composite Settings: introduced responsive two-column `.snt-2up` layout for `Measurement > Analytics`, `Monitoring > Machine Readers`, and `Connections > Cloudflare`, separating writable configurations from read-only operational references.
- S&N Analytics Multi-Column Reports: placed `Traffic quality` and `Bot confidence` tables in `Quality` view, and `Catalog` and `Evergreen vs spike` tables in `Posts` view, into balanced side-by-side `.snt-report-columns`.
- Wide Leaf & Container Queries: uncapped `.snt-leaf` max-width to 100% for all two-column and multi-column settings leaves while preserving responsive `@container (max-width: 860px)` and mobile PWA 1-column collapse.

## [13.106.6] - 2026-09-07 — Fix duplicate dock app and refine S&N Home UI/UX


### Fixed
- OpenStation Dock: resolved duplicate dock tile issue when disabling native windows in OpenStation Settings (`assets/os-settings-tab.js`) by dynamically invoking `removeSystemItem` and removing stale tiles on toggle and `os-registry-changed`.
- S&N Home UI: fixed raw unescaped HTML entities in Site Pulse and Needs Attention items (`pulse_item_html()` and `home_attention_html()` in `apps/sn-dashboard/parts/leaves/dashboard.php`).
- S&N Home Layout: eliminated double-scrollbar bug by locking `.snt-home__rail` to `overflow: hidden`, aligned quick action buttons with OpenStation's `station-home.css` specs, and styled subordinate fleet systems.
- S&N Dashboard Form Leaves: bounded form containers to `820px` max-width with subtle surface wash body styling (`--os-ui-surface-subtle`), eliminating empty void spaces on wide screens while maintaining 100% width for data tables and logs.

