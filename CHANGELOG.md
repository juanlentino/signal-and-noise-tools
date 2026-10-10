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
- **The local door takes the machine-readers series too: `sn-metrics{machine_readers}` with `series: "day"`.** The daily split shipped on the remote twin only (23.5.0); the local `sn` door's `machine_readers` section had no way to ask for it. `series` is now an `sn-metrics` argument (enum `day`, machine_readers only) passed beside `days`; omitted, the section receives exactly what it did before. Any other value fails the call, as a bad query does, rather than reading as an outage. Pinned by `tests/abilities-sn-metrics.php`.

## [23.6.0] - 2026-10-10 — the desktop widgets stay current without a reload

### Changed
- **The desktop widgets stay current without a reload.** A post scheduled in another window reached SN Queue only after the PWA was reloaded (owner, 2026-10-10). One pulse now serves every widget: `GET /signal-noise/v1/desktop/pulse` (owner only, `private, no-store`, one grouped read of posts and pages and two option reads) returns a `content` and a `deploy` stamp, and `assets/snt-pulse.js` reads it every 20 s while the window is focused, every 5 min while it is only visible, never while hidden, and at once when the window comes back into focus or view. SN Queue and SN Provenance re-read when the content stamp moves; SN Deploy Status when the deploy stamp moves (a plugin, theme, core or recorded worker deploy). SN Reading and SN Provenance, which read once at mount, now re-read every 5 min on the same focus-aware cadence (a background re-read keeps the last reading on screen and never shows the waiting state; with nothing on screen yet it reads as the first read did, so an error still shows; SN Reading repaints only when its figures change, and its read time updates outside the live region's announcements); SN Systems' health, cron, edge and cache lines, read once from the page-load localize, now come back with its 2-minute poll through `GET /signal-noise/v1/desktop/systems` (owner only, the same functions and caches). Pinned by `tests/desktop-live.php` (owner-only routes, which changes move which stamp, opaque stamps) and `tests/js/pulse.cjs` (baseline, per-stamp fire, cadence, focus return, the 5 s floor, hidden pause, backoff, teardown); `tests/desktop-status-resilience.cjs` checks a systems re-read repaints the edge and cron lines.

