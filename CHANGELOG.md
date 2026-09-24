# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [18.5.0] - 2026-09-24 — the conventions scan reads pages, and the drift check stops asking a refusing provider

### Fixed
- **The editorial-conventions scan now reads pages, for inline SVG figures.** `sn-scan` scan_type `editorial_conventions` walked posts only, so its "zero findings" held for posts while three paper pages (3216, 1561, 1559) carried a checkmark stroked in fixed `#ffffff`, found on 2026-09-24 by a read-only corpus audit. Pages now join the walk for the `svg-figure` check alone (`SNT_EDITORIAL_CONVENTION_PAGE_CHECKS`); lead, correction, references and steps are note forms and stay posts-only, including when a scoped call names a page.

### Changed
- **The drift check stops asking a provider that refuses.** When the first two AI calls of a scan both fail with a provider error and none has succeeded, `drift_time_phrases` stops there instead of asking once per candidate post (12 doomed calls per scan on 2026-09-24, with the AI account unfunded), and its skip reason carries the provider's own message, so `skipped[]` names the cause, not just "failed". A reply that is not JSON never trips it (the model is answering, just badly), and one success earlier in the scan keeps every later post asked. Still a skip, never a pass. Threshold: `SN_HEALTH_DRIFT_BREAKER_AFTER` (2).

