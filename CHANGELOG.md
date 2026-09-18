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
- **Jev rates the query half, with structured levels.** The first pass on the right scale read 45 of 69 search titles at 0.5 and was sure on none: "Aphorism: plain words" carries the aphorism in front, level zero named an aphorism, and Jev, reading literally, hedged. The state now carries `query_part` (the half after the colon when the front is the note's own title, else the whole search title) and the title question rates that field only, with the description and the title named as context. Every level is a `summary` with `signals` and the noul carries `what`/`examples`, the documented shape for sharpening a rubric that scores between levels. The description levels separate "names the topic" from "states the argument", which the first pass could not (69 of 69 at the top).
- **`signal-noise/jev-pass-now`** (rw door): runs the pass now and returns its counts, so a rubric change is read in minutes rather than a day. Pinned (42; rw door 9).

## [16.3.2] - 2026-09-18 — the scale starts at zero

### Fixed
- **Jev's scores run 0..2, not 1..3.** A score is "a weighted position from 0 to the highest level number" (docs, primitives/score); a three-level rubric scores 0, 1, 2. 16.3.0 drew the finding line at 1.5 as if levels ran 1..3, so level one ("names the subject, phrased differently") counted as low and the first pass read 68 of 69 notes as unsure. The line is 0.5 now (closer to level zero than to level one) and the finding text says "of 2". `jev-notes` also hands out every note's scores and confidences, so the rubric and the floor are tuned on the distribution, not on a count. Pinned.

