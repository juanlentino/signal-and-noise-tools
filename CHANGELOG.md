# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [24.0.0] - 2026-10-11 — the desktop widgets read like the rest of the desktop, and the rollups keep a day they cannot cap

### Fixed
- **The desktop widgets read like the rest of the desktop.** Counts carry thousands separators: SN Provenance's machine reads (107,882, the verified, unverified and unmeasured parts, AI-training reads and rights-file fetches) and SN Systems' cron runs (2,471 runs recorded). SN Queue's times use the 12-hour clock every other card uses ("Tue 1:01 PM", not "Tue 13:01"); the `content-queue` ability's `when` labels change with them. SN Traffic's last-hour bars sit on a hairline baseline with a caption under them ("Last hour, per 5 minutes: peak 4, 10 minutes ago"): one reader in one slot drew a lone full-height bar that read as a stray square. The public /stats strip and the admin Right now panel are unchanged.
- **The rollups no longer store human figures they could not apply the page-view cap to.** On 2026-10-11 one run of the rollup wrote the last week with two visitor-days (368 and 406 page views) counted as human: Oct 6 stored 762 views instead of 10, SN Traffic read 1,031 views over 14 days (US 917) and SN Reading 12% engaged, until the next run 15 minutes later put it back. The rollup now checks that the over-cap visitor-day list read before it replaces anything; when it did not, the stored days are kept, nothing is stamped fresh, and the next poke retries. The nightly session rollup, which writes yesterday once and never revisits it, writes nothing on a failed list, so the day reads as not measured instead of wrong.

