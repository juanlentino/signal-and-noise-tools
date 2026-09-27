# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.4.3] - 2026-09-27 — the rebuild cannot die silently

### Fixed
- The analytics history recompute can no longer die without saying so. On the live site the first tick of a run ended with the status still at 0 of 90 days, nothing scheduled, and nothing in the error log. One cron request was doing every rollup family plus up to seven session days, and the slowest wp-cron request that day took 57 seconds. Each tick now runs one unit (one rollup family for one 7-day batch, or one session day) and schedules the next, with a cursor kept in the progress option.
- The tick names its unit in the progress option before running it, and a shutdown handler turns a fatal error, a time limit, or any tick that never reaches its end marker into a partial run that says which unit it died in and why. Strict mode and the rollup window are reset on that path too.
- A run that stops moving for 15 minutes now reads "stalled at <unit> since <time>" and the button is enabled again. A new Resume button continues a stalled or partial run from its cursor on both the classic page and the native window; starting fresh still works.

### Added
- `sn-status` gains a `recompute` section (state, stalled, done and total, step, unit, error, last tick, and the status line), so the run can be read without a browser. It stays off the remote door.

