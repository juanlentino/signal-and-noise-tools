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
- **Content › Tags carries Jev's tag fit, and the Claude tag suggester is retired.** "Jev: tag fit" on both the native leaf and the classic page: a Read tags now button (the pass, about seventy requests, a cent or two), then per flagged note a *Remove* box for each attached tag Jev read as attached for reach (with its score) and an *Add* box for each tag a reader would expect (with its probability), unchecked, one Apply. `tag_fit_apply` allow-lists every pair against the stored pass (a missing tag may only be added, a misfit only removed, on a Note the user can edit; forged ids are dropped), appends rather than replaces, and applied pairs leave the stored pass so the leaf does not re-list them. The Claude "AI: suggest tags for untagged Notes" section, its two handlers and `inc/ai-tag-suggest.php` are gone: it saw only untagged notes and only proposed. `signal-noise/suggest-tags` keeps its contract and now reads the stored pass (existing terms only, with Jev's probability). Handler map still 65; leaf parity pinned on both surfaces.

## [16.8.2] - 2026-09-19 — an advisory is not a fault

## [16.8.1] - 2026-09-19 — an edge reading is taken twice

### Fixed
- **Tag fit rides the advisory tier, not the fault count.** 16.8.0 put check 31 on the health surface, against the closed Health arc (v11.13.0: a check earns the red number only if its finding is a defect, can reach zero and stay there, and no other surface owns the list). A tag a note should carry never reaches zero; the names already itemize on `jev-tags`. Same move as `tag_hygiene` in v13.24.0: advisory tier, worklist surface, count in wp-admin, names on the door. The Health number reads 17 checks again. Pinned.

