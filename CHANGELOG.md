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
- **SN Deploy Status groups its rows and dates the workers' deploys.** WordPress (Core alone), Site (Theme and Plugin, with their last deploy) and Workers, each under its own heading, with the version column lined up down the card. The Workers group has its own last deploy line: the time the five-minute version check first read a worker's new version (`inc/deploy-workers-seen.php`, option `snt_deploy_workers_seen`), accurate to five minutes and counted once two checks in a row agree (a rollout answering old and new in turn never moves it). The first version read is a baseline, so until a worker changes the line says "No worker deploy seen since" the day the log started. `get-deploy-status` carries it as `last_worker_deploy` on the local door only (the remote contract is unchanged).

### Changed
- **The docs describe the live arc.** The README's Analytics section explains the live figures, /stats strip, admin Right now panel, Arrived from and the live-surge signal (and why it is analytics, not ML); the public routes table says what `/live` carries and what only `live/admin` does; `docs/ai-abilities-catalog.md` lists `signal-noise/live-now`.
- **The desktop cards draw shares, and SN Traffic is shorter on a quiet day.** A shared card kit (`assets/desktop-mode-card-kit.js`) draws a thin share bar, the dot that ties a row to its segment, and two groups on one row. SN Traffic: Today so far and Reading now are one list; the hour bars and Top now show only when there is a reader to show; Countries and Sources sit side by side, each over its share bar, and Devices has its own. SN Reading: one page, two, and three or more as a bar under One page only; each Core Web Vital as a good, needs work, poor bar. SN Provenance: verified, named-not-verified and not-measured machine reads as a bar under Machine reads. Every row and figure is still printed; the marks are decoration, hidden from assistive tech, and a card without the kit draws plain rows. Pairing This week with Reach, Uptime with Cron, Edge with Cache and Internet Archive with Provenance was tried and left out: their rows are too long to share a 312px card.

## [23.2.0] - 2026-10-08 — live visits, the right-now panel and a live-surge signal

### Added
- **The admin Right now panel, redesigned around the live figures.** Under Active visitors and Views today (both kept): the last hour as 5-minute bars, then Being read now and Arrived from side by side, and the time of the last read, all refreshed in place every 30 s. Classic and native share one block (`inc/analytics-live-admin.php`). Arrived from is admin only: where readers whose pageview landed in the last 5 minutes came from, in the dashboard's source names; a click inside the site is not a source. The Traffic desktop widget gains the bars and a Top now line; its Today so far stays.
- **Is right now unusual? A live-surge signal.** The refresh keeps a log of completed 5-minute slots (eight days, from the hour it already reads; nothing new collected) and judges the last one against the same time of day on earlier days, by median and MAD with a one-reader noise floor and a three-reader minimum. Under four days of history it says it is still learning. The admin panel says it in words; a surge raises a neutral Attention row (Live traffic). Filed as an analytics signal, deliberately not an ML pipeline: the ML family's public promise is that reader data never enters it. The Analytics Maturity page gains the matching principle.
- **Agents can read the site right now.** `signal-noise/live-now` (read door 50 to 51) and `sn-status{live}`: live counts per class, today, the hour, pages, sources and the surge verdict, from the realtime cache only. Local only for now: like the last new payload, it gets a remote twin once its shape has settled.
- **The last hour under Reading now, on /stats.** Twelve 5-minute bars of distinct human readers (the same rule as Reading now), the current slot in red, scaled to the hour's own peak, with the peak said in words underneath for anyone not reading the chart. One more grouped query per realtime refresh (`inc/analytics-live-hour.php`), grouped by a SELECT alias since Analytics Engine refuses a function in GROUP BY. A slot with no readers is a real 0; an hour not read yet leaves the chart empty. Redrawn every 60 s, never animated. Cookieless as before; nothing new collected.

### Fixed
- **The red "now" bar no longer lags.** An hour read from a cache a few seconds old could end on the previous slot and still paint it red; the bar is red only while it still is the current slot.

