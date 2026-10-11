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
- **SN Systems no longer says a watch is due when it cannot tell.** The General-save guard watch reads whether `admin_email` sits in Core's General settings group, which Core registers only on `admin_init` and `rest_api_init`. The OpenStation shell's page load runs neither, so the group read empty and the card said "1 watch due" while the REST read said not (seen live on 23.8.0). A request where Core registered no settings now answers "cannot tell". Pinned by `tests/watches.php`.
- **The Cutover check says what it measured.** "2 days clean · since Oct 7" read as if every day since were checked, but a sampled day (Oct 9) is neither counted nor a reset. It now reads "clean since Oct 7 · last exact day Oct 8". Pinned by `tests/desktop-status-extra.php` and `tests/desktop-status-resilience.cjs`.

### Added
- **SN Queue: a gap can be marked planned.** The holidays are on purpose, so the gap line has a **Planned** button: it records that gap (`POST /signal-noise/v1/desktop/queue-gap-planned`, `manage_options`, option `sn_content_queue_planned_gaps`, last 20 kept) and the line goes; a different gap still shows. Pinned by `tests/abilities-content-queue.php` and `tests/desktop-status-resilience.cjs`.

## [23.8.0] - 2026-10-11 — the desktop widgets say more in about the same height

### Changed
- **The desktop widgets say more in about the same height (owner inventory, 2026-10-10).** Short groups now share a line, as Countries and Sources do: Edge beside Cache (SN Systems), Archive beside DOIs (SN Provenance), "N scheduled" beside "N drafts" (SN Queue). The card kit's pair lets a short column sit at its own width, so a pair stacks only when the content really does not fit.
  - **SN Systems:** the cron headline counts the plugin's own jobs ("S&N jobs · 30 of 85 scheduled"); Edge says the day's 5xx, the visitors among them and the day before in short rows (a day with no 5xx no longer reads "visitors 0 of 0"); a **Cutover check** line reads the stored analytics 2.0 verdict (never the live check); a **watch that has come due** and a **credential that failed or a Cloudflare token near expiry** appear only when there is one, each linked to its fix.
  - **SN Traffic:** the forecast is painted, as the per-day estimate it is ("In 7 days: about 20 views a day (estimate, ±25%)"), only when the fit beats its own baseline; a **surge line** shows only during a surge and hides whenever a read carries no verdict; the search row says its window ("Search · 28 days to Oct 7"); Hacker News says "1 point"; the north-star row reads "Engaged visitor-days · 7d", so it never reads as SN Reading's share of views; a "Read at" foot. The payload stops computing the bot share, the top mover and a second copy of the sources list, which no card painted (the owner's 2026-10-04 pick to keep the first two off the card stands).
  - **SN Provenance:** failed Zenodo deposits are said only when there is one, linked to Connections › Zenodo; the top crawler family's change says "points"; a "Read at" foot.
  - **SN Queue:** the drafts count, and a **gap** line ("Gap: no note Oct 22 to Oct 26") when two scheduled notes sit further apart than the cadence check's own threshold (3 spreads above the expected gap over the last 20 notes); no new threshold.
  - Pinned by `tests/desktop-status-resilience.cjs`, `tests/desktop-status-extra.php`, `tests/abilities-content-queue.php`, `tests/desktop-mode-widget-views-delta.php` (with `tests/js/live-now-surge.js`), `tests/desktop-mode-integration.php`, `tests/widget-card-tokens.php` and `tests/archive-push-existing.php`.

