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
- **Query-to-page fit: Jev judges the queries Google already sends to each note.** Search Console says which queries land on which note; the impressions cannot say whether the note answers them. `inc/jev-query-fit.php` makes one page × query read of the 28-day window (a new two-dimension read; `snt_gsc_query` rows now carry every dimension under `keys`, additively), keeps queries with 20 or more impressions, top eight per note, and sends Jev one request per note with one Score per query: "Does `note` answer `queries.qN`, the words a person typed into a search engine before landing on it?" with three levels (does not answer it, the query landed on shared vocabulary / touches it, the reader would have to dig / answers it directly). Weekly (`sn_jev_fit_weekly`, in the cron registries and the deactivation list), stored in `sn_jev_query_fit`. Two readings: **gaps** (score under 1 of 2, by impressions; a query people already bring that no note answers, the raw material for the next note) and **stray traffic** (score under 0.5 with clicks; a title chasing the wrong search). Painted as the third band of Analytics › Search after Google and Bing, one panel, two tables, each note linked to its editor. Abilities `jev-fit-now` (WRITE, rw door 11 → 12) and `jev-query-fit` (READ, read door 40 → 41), pinned. Nothing writes back to a note. `tests/jev-query-fit.php` (23).

## [16.4.1] - 2026-09-18 — the panel warns at 0.6

### Fixed
- **The pre-publish panel warns at 0.6, not 0.5.** The first live lane map (16.4.0, 44 published notes, 44 requests, 26 s, 485k input tokens) put 19 pairs at or above 0.5 and 9 at or above 0.6; the 0.5..0.6 band was shared vocabulary ("the master", "the field", "the absence") rather than a shared argument, and a draft near that cluster would have drawn four warnings. The panel's line is now 0.6; the record's `collisions` count and the lane map keep 0.5 so the map still shows the band. Pinned.

