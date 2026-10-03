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
- **Two desktop widgets, so analytics is a family of four: Site Views, Audience, Reading, RSS Subscribers.** Site Views was the tallest tile and covered only the overview. **SN Audience** is who reads and from where: top countries, devices, source categories, campaigns (only when a tagged link was followed), the Hacker News stories that link here with points, comments and front-page rank, and Google and Bing clicks and impressions over their own windows. **SN Reading** is what readers do here: the share reaching half and three quarters of the page, median time on page, visits with the one-page share, pages per visit and the typical visit, the top goal events, and each Core Web Vital as its good and poor shares over the loads measured. Both read the last 14 days of rollups the Analytics views already fill; nothing new is collected. A reading that failed or has nothing says so and is never painted as zero. The four register together at the top of the widget picker; RSS Subscribers itself is unchanged.

## [21.1.0] - 2026-10-03 — the notes that predate the keys reach the Internet Archive

### Added
- **The notes that predate the Internet Archive keys can be pushed.** The push only ever fired on a note's first publish, so every note published before the keys were added would never have been asked for. Tools › Provenance now has an Internet Archive section: whether the keys are set, the last push, the failures not since accepted, how many published notes have no push on record, and one button that starts a run over them. One note every five minutes on cron, oldest first, through the same push a first publish uses. The push record on the note is the cursor, so a second press asks for nothing twice. The classic Tools › Provenance page carries the same section and button. Any refused or failed request halts the run until Resume; the note that failed keeps its own single retry and is not picked again. Nothing starts by itself. The same start is the write ability `archive-push-existing`.
- **The Anchors desktop widget says where the Internet Archive stands**: one line under the anchors (not configured, how many notes have never been pushed, the run's progress, or that every note has a push on record).
- **Two reads.** `archive-status` returns the Internet Archive state as data (configured, pending, the run, the last push, the open failures; never a key). `ai-models-status` returns the two status lines the AI settings screens print and the state behind them (when each provider's list was read, why one was not, when prices were read, which are held), because a cron firing that reports success only says the job did not crash.

### Fixed
- **`get-cron-history` errored for a hook that had never fired.** The MCP wrapper cast every empty result to `{}`, including one whose schema says it is a list, so the read failed its own schema exactly when the answer was "none yet". A list-rooted ability with no rows now returns `[]`.

### Changed
- README: the edge cache as it now works, the alerts, the model lists and prices, the Internet Archive push, and the door sizes (49 read, 18 write).

