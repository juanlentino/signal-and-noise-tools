# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.2.1] - 2026-09-18 — a draft belongs to its environment

### Fixed
- **The Zenodo draft slot is per environment.** Flipping from production to sandbox read the production draft ids on sandbox, where "The persistent identifier does not exist" is a 404, and the 16.1.3 rule forgot them: five resumable production drafts became orphans. Production keeps the original meta key (every id stored before this fix is a production id); sandbox has its own, and neither environment reads or forgets the other's. Pinned.
- **The shell toast decodes entities.** The flash strings carry `&rsquo;`, `&mdash;` and `&hellip;` for the HTML notice, and the toast printed them as text ("Zenodo&rsquo;s answer"). Pinned.

