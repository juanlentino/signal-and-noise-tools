# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.27] - 2026-11-11 — The flagged human visitor-days readout answers

### Fixed
- **The flagged human visitor-days readout answers (`sn-metrics{bot_signals_flagged}`, `signal-noise/bot-signals-flagged`).** On the live site every call failed with "Analytics Engine did not answer": Analytics Engine refused the details read with HTTP 422, because `if(blob1 = 'tm', double2 * _sample_interval, 0)` pairs a Double with an Integer. Time is now summed the way the rollups sum it, `sumIf(double2 * _sample_interval, blob1 = 'tm')`. Test: `tests/analytics-bot-signals-flagged.php` pins the form and refuses an `if()` that pairs a double with `0`; both checks fail on 2026.11.26.

