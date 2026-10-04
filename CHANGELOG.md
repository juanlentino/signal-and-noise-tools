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
- **A claim shown to nobody can be forgotten.** Integrity › Citations kept every webmention claim forever, so a test claim (an `example.com` page that never linked here) sat in the list with no way out. Under the table, on the classic page and the native leaf alike, one small form now lists the claims in the two tiers the site shows to nobody (asserted, unverified) and removes the chosen one. A citation the site displays (verified, unattributed) is never offered, and the handler reads the tier from the stored row, not from the form, so a crafted request cannot remove one either. If the source sends its webmention again, it is a new claim.

### Changed
- **Only the owner changes what the MCP doors expose.** An ability reaches a door only by being on one of three lists in code (read, write, remote). A new test pins those lists whole against `tests/fixtures/mcp-door-manifest.json` and fails when a new file names the filters the lists pass through. A new required check, `door-owner`, fails a pull request that touches anything but prose (`docs/` and the top-level Markdown files) unless the owner was the last to push to its branch (Dependabot's own bumps aside); the owner takes over someone else's pull request by pushing to it. Everything else is owned because an exposed ability's behavior lives wherever its callback reaches: server code, the scripts that run in the owner's browser, data files, the served skill files, tests, tools and the repository's automation. The check runs from `main`, never from the pull request's copy, checks nothing out, reads the last pusher from the branch's activity log, and fails when it cannot read the changed files. The owner's pull requests merge as before, with no approval step. Nothing a door exposes changed.
- **The desktop widgets say more with less.** SN Audience opens with its visitor-days and SN Reading with the engaged share and its change, the way SN Site Views opens with its views; both end with how old the reading is. SN Machine Readers opens with who the readers are (verified by Cloudflare, named and not verified, not measured), and the per-surface list under the declared AI-training reads is the three largest plus one line for the rest; the direct rights-file row stays. SN Quick Actions no longer carries Full reset: a purge of every cache is not one click from the desktop, and it stays under Maintenance on the Dashboard, behind its confirm.

### Fixed
- **SN Health names a check that could not run.** The card read "17/18" beside a green dot and named nothing, because the eighteenth check was skipped, not failed. It now lists the check with its reason on hover, and the dot is amber while one exists.
- **SN Cron no longer reads "due" for a job that is on time.** WP-Cron runs on a page load, so a five-minute job is past its time for seconds at every load. It reads "running now", and only past ten minutes does it read late and turn amber.
- **SN Anchors paints the Internet Archive as rows**, the run and the captures, not a three-line sentence. Each run state reads in its own words (not configured, running, halted, not pushed yet, every note asked), a request past its cutoff with no answer is counted apart from one still waiting, and a halted run, a failed capture or an unanswered one is amber.
- **SN Reading's custom events carry their units**: "3 events · 3 visitor-days", and "1 visitor-day" when it is one.
- **The Dashboard's Full reset asked the wrong question.** Its confirm said every setting would be reset to default; the action clears template overrides and purges caches and changes no setting. The confirm now says that.

## [21.4.0] - 2026-10-04 — the reads follow a verified dataset, and a capture is confirmed

### Added
- **The Internet Archive's own answer.** A push records that the Archive took the job, not that it captured the page. An hourly pass now asks the Archive about a few requested notes (its job status is public, so no key is sent) and records the outcome on the note: captured with the capture's timestamp, failed with the Archive's reason, or unconfirmed after two days without one. Tools › Provenance, the Anchors widget and `archive-status` say how many are captured, still waiting, or failed.
- **SN Reading shows how deep sessions go.** The session rollup now stores how many sessions saw exactly two pages and how many saw three or more, so the widget shows both beside the one-page share. Days rolled up before this are not counted as zero: the shares are of the days that measured them.

### Fixed
- SN Reading painted a missing or corrupt rollup table as "0%" or "none". The custom events and Core Web Vitals groups now say they could not be read.
- The Core Web Vitals, scroll and time percentiles covered UTC days while everything beside them covers the site's days. The window is now the site's days, sent to Analytics Engine as UTC instants.

### Changed
- **The analytics reads move to the second-generation datasets, each one when it safely can.** The worker writes every beacon to the old dataset and to two new ones. A daily check compares them before the rollup, event by event and only on complete days; a day counts as a match only when every event type was counted exactly on both sides (a day Analytics Engine sampled proves nothing either way), and reads of custom events wait for a custom event that matched exactly. Equal counts are not enough: the visitor hashes behind each event type must agree too (visits and sessions are counted from them), rows on one side with none on the other are a mismatch even on a sampled day, and property rows in the pageviews dataset break the match. The on-demand rollup follows the same fresh verdict as the daily one, and a flipped verdict drops the live snapshot along with the cached visitor list. Once a complete day matches, a read uses the new datasets whenever its window starts on or after 2026-10-05, the first full day both hold, and the old dataset otherwise. A mismatch is remembered: reads whose window still holds that day stay on the old dataset. So today's views and the live visitor count move first, the 7-day rollups about a week later, the 14-day bot-signal read after that. A failed check changes nothing. All 21 statements were ported: the timezone column and the custom-event columns sit elsewhere in the new rows, custom events and their properties are read from the events dataset, and campaign attribution is grouped on source, medium and campaign in the query itself, no longer unpacked afterwards. No stored table changes shape and no displayed figure is dropped. One internal reading shifts: the ingest health check's "rows in the last day" no longer counts custom-event property rows once it switches, because those live only in the events dataset; it is a liveness signal and its pageview count is unchanged. `analytics-dual-write` now also returns the stored verdict the reads follow.

