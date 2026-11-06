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
- **Each stored 5xx now says when it happened and from which network.** The daily rollup keeps how many and on which path, so on 2026-11-05 six 522s on the home page had no time to hold up against the origin's firewall. A separate query (a refusal costs the times, never the counts) stores each error group's minute in UTC, Cloudflare colo, asker, status, network number and name, and path, as `err_when` rows. No IP address is stored. It reads back as `errors_5xx.when` on the local `cloudflare-status`, with the query's last outcome so an empty list is not read as no errors, and whether the day reached the limit of 100 groups. Local door only: the remote contract does not move. It starts with the next daily rollup; earlier days have no times. `inc/edge-error-when.php` (new). Test: `tests/edge-errors-when.php` (new).

## [2026.11.12] - 2026-11-06 — A purge that lost its verify gets a new one

### Fixed
- **A purge can no longer sit on "verifying" until the next one.** On 2026-11-05 an automatic update's purge lost its deferred verify: no run in the cron history and nothing queued, so the Cache line stayed on "Purge dispatched, verifying". The hourly run now checks the last purge, and one still without a verdict after ten minutes gets a new verify from the theme's scheduler, which refuses a duplicate for the same epoch. A verdict of any kind, stale included, is left alone. `inc/purge-verify-heal.php` (new). Test: `tests/purge-verify-heal.php` (new).

