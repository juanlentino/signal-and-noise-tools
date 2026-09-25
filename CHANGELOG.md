# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [18.6.0] - 2026-09-25 — the desktop widgets poll like a desktop app: full rate while focused, idle while not

### Changed
- **The desktop widgets poll at full rate only while the window has focus.** The deploy, queue, cache and uptime widgets already paused while the tab was hidden; a PWA left open on a screen while its owner worked elsewhere still polled at 60 s (the 2026-09-25 edge log: ~650 calls a day per widget, one visible window for ~11 h). A shared cadence (`assets/snt-poll-cadence.js`) now keeps each widget's own rate while focused, stretches it to 5 minutes while only visible, and refreshes at once on return when the data went stale. Focus is read from the top document, so working in an OpenStation iframe window counts as working in the desktop. A failure's backoff still wins when longer, the cache card keeps its 15 s poll during a purge, and a widget without the helper keeps its fixed rate. Simulated on the real widget files: 30 minutes visible-but-unfocused went from 31 calls to 7 per 60 s widget; 10 minutes focused is unchanged.

