# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.5] - 2026-11-01 — Reverted the per-view scroll count; stored scroll per view is the weighted ratio again

### Fixed
- **Reverted the per-view scroll count from 2026.11.4 (#2340); stored scroll per view is the weighted ratio again.** Read live after the 2026.11.4 install: the stored `/about` on 2026-10-31 went from 162.5% to 0%.

  Analytics Engine samples a heavy visitor-day row by row. The dual-write check shows one human visitor-day's 2026-10-31 rows as 36 stored standing for 81, and its page views as 5 standing for 11. A view's pageview and scroll rows are therefore kept or dropped independently. Joining them by pageview ID drops the scroll of views whose pageview was sampled out, and scores views whose scroll was sampled out as 0, so the per-view figure runs low.

  The weighted ratio, 25 × Σ scroll weights / Σ pageview weights, is the estimator that holds under sampling. On a group of one or two views it can read above 100%, which is sampling noise, not lost pageviews. The daily roll rewrites the trailing seven days to the weighted ratio. Do not run the history recompute for this; no earlier day took the per-view count.

