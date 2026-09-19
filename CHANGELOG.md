# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- **The tag-fit lines, moved off the first live pass.** 69 notes, 216 attached tags: 27 of the 40 misfits sat at 0.5 to 0.99 with confidence 0 to 0.26, shrugs painted as verdicts, and the 171 adds at 0.6 were four umbrella tags (Authorship, Content Authenticity, Creation-Time Capture, Verification Limits) suggested on 15 to 25 notes each. A misfit now needs a score under 1 of 2 AT confidence 0.5 or better (`sn_jev_tag_is_misfit`); an add starts at 0.8 (`sn_jev_tag_is_add`); the pass still keeps candidates from 0.6, and a tag Jev would add to a third of the notes read or more is an umbrella tag (`sn_jev_tags_umbrellas`), one line at the top of the section on both surfaces with how many notes carry it, never a row per note. The same two predicates drive the Tags leaf, the classic page, the apply allow-list, check 31 and `jev-tags` (which gains `umbrellas`) and `suggest-tags`, so no surface can list what another refuses. On the live pass that is about 14 misfits and 38 adds instead of 40 and 171, and the four umbrellas named once. Same move as 16.3.4 and 16.5.1: the first live reading moves the line.

## [16.9.0] - 2026-09-19 — the tag leaf reads what Jev read

### Added
- **Content › Tags carries Jev's tag fit, and the Claude tag suggester is retired.** "Jev: tag fit" on both the native leaf and the classic page: a Read tags now button (the pass, about seventy requests, a cent or two), then per flagged note a *Remove* box for each attached tag Jev read as attached for reach (with its score) and an *Add* box for each tag a reader would expect (with its probability), unchecked, one Apply. `tag_fit_apply` allow-lists every pair against the stored pass (a missing tag may only be added, a misfit only removed, on a Note the user can edit; forged ids are dropped), appends rather than replaces, and applied pairs leave the stored pass so the leaf does not re-list them. The Claude "AI: suggest tags for untagged Notes" section, its two handlers and `inc/ai-tag-suggest.php` are gone: it saw only untagged notes and only proposed. `signal-noise/suggest-tags` keeps its contract and now reads the stored pass (existing terms only, with Jev's probability). Handler map still 65; leaf parity pinned on both surfaces.

