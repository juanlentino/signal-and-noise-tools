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
- **A release's new card layout shows at once.** SN Traffic's and SN Reading's payload caches were keyed by the day only, so 23.3.0's Countries and Sources pair waited out the old build's 15 minutes. The keys now carry the plugin version.
- **The share bars are dimmer.** They keep the card's accent, at lower strength, so a share (89% one page only, 88% direct) no longer reads like SN Systems' warnings. Core Web Vitals keep their full good, needs work and poor colors.
- **SN Deploy Status fits when placed fresh.** Its default height is 470 (about 450 measured with the groups and the workers' deploy line); a saved layout keeps its own height.

## [23.3.0] - 2026-10-08 — the desktop cards draw shares, and Deploy Status groups and dates the workers

### Added
- **SN Deploy Status groups its rows and dates the workers' deploys.** WordPress (Core alone), Site (Theme and Plugin, with their last deploy) and Workers, each under its own heading, with the version column lined up down the card. The Workers group has its own last deploy line: the time the five-minute version check first read a worker's new version (`inc/deploy-workers-seen.php`, option `snt_deploy_workers_seen`), accurate to five minutes and counted once two checks in a row agree (a rollout answering old and new in turn never moves it). The first version read is a baseline, so until a worker changes the line says "No worker deploy seen since" the day the log started. `get-deploy-status` carries it as `last_worker_deploy` on the local door only (the remote contract is unchanged).

### Changed
- **The docs describe the live arc.** The README's Analytics section explains the live figures, /stats strip, admin Right now panel, Arrived from and the live-surge signal (and why it is analytics, not ML); the public routes table says what `/live` carries and what only `live/admin` does; `docs/ai-abilities-catalog.md` lists `signal-noise/live-now`.
- **The desktop cards draw shares, and SN Traffic is shorter on a quiet day.** A shared card kit (`assets/desktop-mode-card-kit.js`) draws a thin share bar, the dot that ties a row to its segment, and two groups on one row. SN Traffic: Today so far and Reading now are one list; the hour bars and Top now show only when there is a reader to show; Countries and Sources sit side by side, each over its share bar, and Devices has its own. SN Reading: one page, two, and three or more as a bar under One page only; each Core Web Vital as a good, needs work, poor bar. SN Provenance: verified, named-not-verified and not-measured machine reads as a bar under Machine reads. Every row and figure is still printed; the marks are decoration, hidden from assistive tech, and a card without the kit draws plain rows. Pairing This week with Reach, Uptime with Cron, Edge with Cache and Internet Archive with Provenance was tried and left out: their rows are too long to share a 312px card.

