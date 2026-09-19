# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.9.0] - 2026-09-19 — the tag leaf reads what Jev read

### Added
- **Content › Tags carries Jev's tag fit, and the Claude tag suggester is retired.** "Jev: tag fit" on both the native leaf and the classic page: a Read tags now button (the pass, about seventy requests, a cent or two), then per flagged note a *Remove* box for each attached tag Jev read as attached for reach (with its score) and an *Add* box for each tag a reader would expect (with its probability), unchecked, one Apply. `tag_fit_apply` allow-lists every pair against the stored pass (a missing tag may only be added, a misfit only removed, on a Note the user can edit; forged ids are dropped), appends rather than replaces, and applied pairs leave the stored pass so the leaf does not re-list them. The Claude "AI: suggest tags for untagged Notes" section, its two handlers and `inc/ai-tag-suggest.php` are gone: it saw only untagged notes and only proposed. `signal-noise/suggest-tags` keeps its contract and now reads the stored pass (existing terms only, with Jev's probability). Handler map still 65; leaf parity pinned on both surfaces.

