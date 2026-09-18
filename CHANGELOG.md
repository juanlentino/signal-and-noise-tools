# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.4.0] - 2026-09-18 — a note does not re-argue a note

### Added
- **The collision gate: a draft is judged against every published note before it can be published.** Notes are never edited after publication, so two notes in the same argument lane is a permanent defect the pre-publish panel should catch, not the reader. On every save of a draft, pending or scheduled note, `inc/jev-collision.php` sends Jev one state (the draft's title, description and first 1,200 characters, plus every published note's title and description) with one Noul per note: "Does `draft` make the same central argument that `notes.nID` makes, so that a reader who had read that note would learn nothing new from `draft`?" The reading (top five notes by probability, the count at or above 0.5) is stored on the post as `_sn_jev_collision`; an unchanged draft is not re-asked. The pre-publish panel reads it and warns per note at or above the line: 'Jev reads this draft as making the argument of "X" (0.72). Notes are never edited after publication: if the lane is the same, merge or hold.' A failed request keeps the previous rows and shows the error. Three abilities: `jev-collision-check` (WRITE, rw door, one note now, `force` to re-ask), `jev-lane-map` (WRITE, rw door, every published note against the others, one request per note, pairs at or above 0.5 stored as the lane map) and `jev-lanes` (READ, the stored map). rw door 9 → 11, read door 39 → 40, both pinned. `tests/jev-collision.php` (34).

