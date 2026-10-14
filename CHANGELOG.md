# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.11] - 2026-10-14 — uptime reads as a median, with the slowest check named

### Changed
- **The uptime response time is the median over 24 hours, plus the slowest check, not an average** (owner-approved 2026-10-13). The figure was a plain mean of every Better Stack sample in the last 24 hours, so seven checks near the 30 s timeout out of about 510 turned a 21 ms median into a 282 ms "average". **Figure removed: the 24-hour average response time.** In its place:
  - **SN Systems (desktop), Uptime:** the Monitors line reads "median 21 ms over 24 h" (several monitors: "medians 120 ms to 340 ms over 24 h", one value when they are equal, never a mean of medians; a figure past a second reads in seconds, as "1.5 s"). The Slowest line is now the slowest single check, for example "28 s from Asia · 5h ago" (with the monitor's name when there are several), and shows with one monitor too. It was the monitor with the highest average, shown only with two or more.
  - **S&N Analytics, Uptime monitor details (classic page and native window, one script):** the "Resp." column is now "Median", a new "Slowest" column reads "28 s from Asia, 5h ago" (both in seconds past one second; hours counted down, as on the desktop card; an unreadable time is left out), and the footer says "median and slowest response over 24 h". Anything not read says "not read", never 0.
  - **The `uptime-status` ability and `sn-status{uptime}`:** `response_ms` keeps its name and now carries the median (its description says it was the mean until today); each row gains `median_ms`, `max_ms`, `max_region` (in words: the US, Europe, Asia, Australia; any other code as given) and `max_at` (when, as Better Stack reports it). All null on the light tier and for heartbeats.
  - **Remote MCP door:** `remote-uptime-status` mirrors the new keys (always null remotely: only the admin detail tier reads them), so the payload contract moves 16 to 17. The worker's own CONTRACT_VERSION bump is a separate PR.
  - The 15-minute response cache moved to a new key (`sn_uptime_response_stats`), so a mean cached by the old code is never read as the new figures. The read now lives in `inc/uptime-status-response.php`.

