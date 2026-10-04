# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [21.4.0] - 2026-10-04 — the reads follow a verified dataset, and a capture is confirmed

### Added
- **The Internet Archive's own answer.** A push records that the Archive took the job, not that it captured the page. An hourly pass now asks the Archive about a few requested notes (its job status is public, so no key is sent) and records the outcome on the note: captured with the capture's timestamp, failed with the Archive's reason, or unconfirmed after two days without one. Tools › Provenance, the Anchors widget and `archive-status` say how many are captured, still waiting, or failed.
- **SN Reading shows how deep sessions go.** The session rollup now stores how many sessions saw exactly two pages and how many saw three or more, so the widget shows both beside the one-page share. Days rolled up before this are not counted as zero: the shares are of the days that measured them.

### Fixed
- SN Reading painted a missing or corrupt rollup table as "0%" or "none". The custom events and Core Web Vitals groups now say they could not be read.
- The Core Web Vitals, scroll and time percentiles covered UTC days while everything beside them covers the site's days. The window is now the site's days, sent to Analytics Engine as UTC instants.

### Changed
- **The analytics reads move to the second-generation datasets, each one when it safely can.** The worker writes every beacon to the old dataset and to two new ones. A daily check compares them before the rollup, event by event and only on complete days; a day counts as a match only when every event type was counted exactly on both sides (a day Analytics Engine sampled proves nothing either way), and reads of custom events wait for a custom event that matched exactly. Equal counts are not enough: the visitor hashes behind each event type must agree too (visits and sessions are counted from them), rows on one side with none on the other are a mismatch even on a sampled day, and property rows in the pageviews dataset break the match. The on-demand rollup follows the same fresh verdict as the daily one, and a flipped verdict drops the live snapshot along with the cached visitor list. Once a complete day matches, a read uses the new datasets whenever its window starts on or after 2026-10-05, the first full day both hold, and the old dataset otherwise. A mismatch is remembered: reads whose window still holds that day stay on the old dataset. So today's views and the live visitor count move first, the 7-day rollups about a week later, the 14-day bot-signal read after that. A failed check changes nothing. All 21 statements were ported: the timezone column and the custom-event columns sit elsewhere in the new rows, custom events and their properties are read from the events dataset, and campaign attribution is grouped on source, medium and campaign in the query itself, no longer unpacked afterwards. No stored table changes shape and no displayed figure is dropped. One internal reading shifts: the ingest health check's "rows in the last day" no longer counts custom-event property rows once it switches, because those live only in the events dataset; it is a liveness signal and its pageview count is unchanged. `analytics-dual-write` now also returns the stored verdict the reads follow.

