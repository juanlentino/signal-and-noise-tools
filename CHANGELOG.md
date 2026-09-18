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
- **Jev's scores run 0..2, not 1..3.** A score is "a weighted position from 0 to the highest level number" (docs, primitives/score); a three-level rubric scores 0, 1, 2. 16.3.0 drew the finding line at 1.5 as if levels ran 1..3, so level one ("names the subject, phrased differently") counted as low and the first pass read 68 of 69 notes as unsure. The line is 0.5 now (closer to level zero than to level one) and the finding text says "of 2". `jev-notes` also hands out every note's scores and confidences, so the rubric and the floor are tuned on the distribution, not on a count. Pinned.

## [16.3.1] - 2026-09-18 — a title can be its own query

### Fixed
- **A title that is itself the query passes check 28.** "Where AI actually saves time in record production" was flagged with an override equal to itself and again with none; the rule assumed every note title is an aphorism. A title opening with how, why, what, where, when, which or who, six or more words, no colon, no sentence-final punctuation, is a query and needs no second name (word count alone cannot separate it from a seven-word aphorism). Pinned both ways.
- **The Health leaf's table after Re-run scan paints its rows.** An `<os-table>` inserted by an action's re-paint held its row in `data` and showed "No rows." until the leaf was reopened. The host's paint pass now re-assigns the rows of a table in exactly that state (rows held, empty state shown) and touches no other. The fork has issues off; the upstream OpenStation watch (#808/#809) gains this row. Pinned.

