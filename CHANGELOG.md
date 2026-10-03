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
- **Breeze's timed purge is off.** Its "Purge Cache After" field will not take 0: the form saved 1440, and 1 would have purged every minute. So the setting cannot turn it off, and the plugin unhooks Breeze's `breeze_purge_cache` handler and its rescheduler and clears the event, as 20.7.0 did for Breeze's update purge (owner, 2026-10-03). Pages purge on save and once per update; the nightly run only emptied a correct cache. A Breeze settings save can schedule it again, and the next request clears it. The purge ledger's `breeze_nightly` reads `off`. Pinned in `tests/purge-ledger.php`.

## [20.7.0] - 2026-10-03 — purges stop being a ritual

### Fixed
- **Purges stop being a ritual (owner, 2026-10-03).** One plugin install fired three full purges within minutes (Breeze's own update hook, the theme's update purge, this plugin's version-change rollover), each emptying all of Redis: Core's update check and every stored reading went blank, Cloudways refused the overlapping purges, and the Caches card then said "purge needed".
  - **A purge log.** `inc/purge-ledger.php` keeps the last 50 purges: when, what asked (manual, update, publish, rollover, styles, cron:<hook>), whether it emptied Redis, and what Cloudways answered. On the Caches card ("Purges this week") and in `sn-status{cache}` as `purges`.
  - **One purge per update.** The version-change rollover skips when an update purge ran in the last 15 minutes; it stays for deploys that bypass the updater, and never flushes Redis.
  - **The Cloudways app purge runs only on a purge-everything.** It clears Varnish and all of Redis; Varnish never served this site's pages (measured: every repeat request read `x-cache: MISS`). An update, a styles save or Breeze's nightly run stands it down, and the row says so.
  - **Breeze's own update purge is removed** (owner's call): it ended in `wp_cache_flush()`. The theme's update purge (14.10.0) replaces it for every plugin and theme update, without the Redis flush. Install theme 15.0.1 first.
  - Codex review, before the cut: the card checks the stylesheet even when both renders agree (a missing current hash is broken too); the rollover re-checks for an update purge when its cron actually runs; ledger writes hold a lock so overlapping purges keep both rows; the weekly count says "at least" once the 50-row ring fills inside the week; "refreshing" counts from the last purge that cleared the edge.
  - Codex's second pass: the ledger never writes or releases without holding its lock; `edge` is recorded only when the Cloudflare leg actually dispatched; a Cloudways timeout counts as a possible Redis flush, not as none; the rollover skips only versions the updater installed (a deploy that bypassed it always rolls over, even at run time); an unconfigured Cloudways still leaves a row. Third pass: a Cloudflare zone purge called on its own (the admin-bar button, a first publish, a schedule, the probe) writes its own row; "installed by the updater" means recorded in the last 15 minutes, so a rollback to an older version still rolls over. Fourth pass: a confirmed manual purge marks its edge; the native dashboard's card gets the last-purge time from the same builder as the classic page (`snt_freshness_payload()`); Breeze's update purge is removed only when the active theme is Signal & Noise 15.0.0+, whose update purge replaces it.
  - **The Caches card stops asking for purges.** A page whose cached render is older but still loads its stylesheet (old stylesheets stay a week) reads "older render · refreshes on its own", or "refreshing after the last purge" within 10 minutes of one, in the info tone. "Purge needed" is left for a page whose stylesheet is gone.

