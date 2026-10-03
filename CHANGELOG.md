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
- **Purges stop being a ritual (owner, 2026-10-03).** One plugin install fired three full purges within minutes (Breeze's own update hook, the theme's update purge, this plugin's version-change rollover), each emptying all of Redis: Core's update check and every stored reading went blank, Cloudways refused the overlapping purges, and the Caches card then said "purge needed".
  - **A purge log.** `inc/purge-ledger.php` keeps the last 50 purges: when, what asked (manual, update, rollover, styles, cron:<hook>), whether it emptied Redis, and what Cloudways answered. On the Caches card ("Purges this week") and in `sn-status{cache}` as `purges`.
  - **One purge per update.** The version-change rollover skips when an update purge ran in the last 15 minutes; it stays for deploys that bypass the updater, and never flushes Redis.
  - **The Cloudways app purge runs only on a purge-everything.** It clears Varnish and all of Redis; Varnish never served this site's pages (measured: every repeat request read `x-cache: MISS`). An update, a styles save or Breeze's nightly run stands it down, and the row says so.
  - **Breeze's own update purge is removed** (owner's call): it ended in `wp_cache_flush()`. The theme's update purge (14.10.0) replaces it for every plugin and theme update, without the Redis flush. Install theme 14.10.0 with this.
  - **The Caches card stops asking for purges.** A page whose cached render is older but still loads its stylesheet (old stylesheets stay a week) reads "older render · refreshes on its own", or "refreshing after the last purge" within 10 minutes of one, in the info tone. "Purge needed" is left for a page whose stylesheet is gone.

## [20.6.0] - 2026-10-03 — the PDF engine says its version

### Removed
- **/resume no longer ends with "Beyond the record: the research · the music".** The owner removed it (2026-10-02): the header stays on screen while scrolling, so the line repeated links the reader could already see. The theme drops the same lines from /music and /provenance (theme 14.9.0). Press Generate on the résumé after installing so the page drops it too.

### Added
- **The Resume PDF section names its engine.** One line on both surfaces (Content › Resume and the classic page): the vendored Dompdf version, whether our Cpdf text fix is in (20.3.2; upstream dompdf/dompdf#3771, milestone 3.1.7), and the latest Dompdf release, read by a daily cron (`snt_pdf_engine_check`, with `SNT_GITHUB_TOKEN` when defined) and kept when a read fails; the line and the watch only read the stored answer. A new watch, `dompdf_bump`, ripens when a newer release exists: bump `lib/pdf`, and drop the patch if upstream's fix shipped. Updating stays a plugin release, not a button: the engine ships with the plugin. Pinned in `tests/pdf-engine-status.php`.

