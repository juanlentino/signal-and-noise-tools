# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.97.3] - 2026-09-05 — a number worth going back for

### Added
- A watch on the origin 503 count, due 2026-09-12. The baseline is **10 per 24h**,
  measured BEFORE v13.97.2 stopped a visitor's pageview paying for a cron run
  averaging 10.6s. If the count has not fallen, the remaining cause is the
  FPM pool's size rather than the work removed from it — and a server resize
  would then be buying a bigger pool, not more RAM. Resizing before that
  reading spends against an untested guess.
  Registered `date_only`, which this file reserves for "a re-read of a number
  that will not announce itself" — the 503s are counted by the HOST, so there
  is no state here for the site to notice on its own. Read it from Cloudways
  app traffic → `top_statuses`, and compare against 10. (#1002)

