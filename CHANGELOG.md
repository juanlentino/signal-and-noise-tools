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
- **SN Queue (desktop) and Connections › Scheduled: a suggested publishing slot per draft** (queue calendar phase 2, item 2; owner-approved 2026-10-12). A suggestion only: nothing is scheduled, and the only action is the draft's edit link. Each listed draft gets the next open day after today that keeps the cadence rule (never under 2 days from a note, never in a planned gap), filling the earliest gap over 7 days first, else the usual spacing after the last note, at the site's usual publish time (the median of the scheduled notes, site time zone). On the card each draft row says it in words, "suggested: Fri, Jan 29, 2027", with the reason as the row's tooltip; the row gains no height, so the card's 640 default holds. Both Scheduled twins gain a **Suggested slots** box with the rule and the same sentence per draft ("Suggested: Mon, Oct 26 at 9:00 AM; fills the Oct 25 to Nov 4 gap. A suggestion only: nothing is scheduled."), or "No drafts, so no slot to suggest.", "Drafts not read, so no slot is suggested.", and "No slot suggested: fewer than two notes to read the rhythm from." when the rhythm cannot be read. The `content-queue` ability's draft rows gain `slot`. No figure removed.

### Changed
- **SN Systems (desktop), Uptime: one figure per row** (owner, 2026-10-14: the Monitors line was too long). "3 of 3 up · 100% over 30 days · medians 20 ms to 38 ms over 24 h" is now three rows: "Monitors 3 of 3 up", "Availability · 30 days 100%" and "Response · 24 h medians 20 ms to 38 ms" ("median 21 ms" for one monitor or equal medians). A figure no monitor reports now says "not read" in its row; before, it was left off the line. Incidents and Slowest are unchanged. The card's default height grows from 580 to 600 for the extra row. No figure removed.

## [2026.10.11] - 2026-10-14 — uptime reads as a median, with the slowest check named

### Changed
- **The uptime response time is the median over 24 hours, plus the slowest check, not an average** (owner-approved 2026-10-13). The figure was a plain mean of every Better Stack sample in the last 24 hours, so seven checks near the 30 s timeout out of about 510 turned a 21 ms median into a 282 ms "average". **Figure removed: the 24-hour average response time.** In its place:
  - **SN Systems (desktop), Uptime:** the Monitors line reads "median 21 ms over 24 h" (several monitors: "medians 120 ms to 340 ms over 24 h", one value when they are equal, never a mean of medians; a figure past a second reads in seconds, as "1.5 s"). The Slowest line is now the slowest single check, for example "28 s from Asia · 5h ago" (with the monitor's name when there are several), and shows with one monitor too. It was the monitor with the highest average, shown only with two or more.
  - **S&N Analytics, Uptime monitor details (classic page and native window, one script):** the "Resp." column is now "Median", a new "Slowest" column reads "28 s from Asia, 5h ago" (both in seconds past one second; hours counted down, as on the desktop card; an unreadable time is left out), and the footer says "median and slowest response over 24 h". Anything not read says "not read", never 0.
  - **The `uptime-status` ability and `sn-status{uptime}`:** `response_ms` keeps its name and now carries the median (its description says it was the mean until today); each row gains `median_ms`, `max_ms`, `max_region` (in words: the US, Europe, Asia, Australia; any other code as given) and `max_at` (when, as Better Stack reports it). All null on the light tier and for heartbeats.
  - **Remote MCP door:** `remote-uptime-status` mirrors the new keys (always null remotely: only the admin detail tier reads them), so the payload contract moves 16 to 17. The worker's own CONTRACT_VERSION bump is a separate PR.
  - The 15-minute response cache moved to a new key (`sn_uptime_response_stats`), so a mean cached by the old code is never read as the new figures. The read now lives in `inc/uptime-status-response.php`.

