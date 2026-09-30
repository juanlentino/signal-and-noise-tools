# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.8.1] - 2026-09-30 — September waits


- **Rights evidence holds a month until the owner lifts it; September 2026 is held.** `sn_rights_evidence_run()` now refuses a month listed in the option `sn_rights_evidence_hold` (error `held: YYYY-MM`) before it reads the sensor, takes the lock, composes or posts anything; `rights-evidence-now` runs the same function and is held too. The option is seeded once with `2026-09` and never overwritten, so the 2026-10-01 pass cannot post September's records while their reservation block (it names policy versions from after the month) and truncated rights stream are fixed. A month skipped while held is queued in `sn_rights_evidence_backlog`, so the calendar moving on never drops it: once lifted it goes before the current month, one month per pass, and leaves the queue only when every record posted; a queued month older than the sensor's 90-day window stays listed but is not composed. The `rights-evidence` read now reports `hold` and `backlog`, so both can be checked without running the pass. Pinned by `tests/rights-evidence.php` H1 to H9 and I1 to I6.


