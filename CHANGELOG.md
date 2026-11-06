# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.13] - 2026-11-06 — Each stored 5xx says when it happened

### Added
- **Each stored 5xx now says when it happened and from which network.** The daily rollup keeps how many and on which path, so on 2026-11-05 six 522s on the home page had no time to hold up against the origin's firewall. A separate query (a refusal costs the times, never the counts) stores each error group's minute in UTC, Cloudflare colo, asker, status, network number and name, and path, as `err_when` rows. No IP address is stored. It reads back as `errors_5xx.when` on the local `cloudflare-status`, newest first up to 100 with a flag when older rows exist past that, along with the query's last outcome so an empty list is not read as no errors, and whether the day reached the limit of 100 groups. Local door only: the remote contract does not move. It starts with the next daily rollup; earlier days have no times. `inc/edge-error-when.php` (new). Test: `tests/edge-errors-when.php` (new).

