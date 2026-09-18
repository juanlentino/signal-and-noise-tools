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
- **Check 30 routes to the human instead of hiding behind a floor.** On 16.3.3's first pass Jev's positions were informative (search titles spread from 0.58 to 1.88 of 2, and the bottom four matched a human read) and its confidences were not (one of 69 cleared 0.9), so the 0.9 "act automatically" floor from 16.3.0 hid every reading. A finding is now a position below level one ("names the subject"), whatever the confidence; the note carries the confidence and, under 0.5, says "Jev is unsure; read it yourself", which is the docs' own prescription for that range. Descriptions are held to the same rule. Pinned.

## [16.3.3] - 2026-09-18 — the query half is the question

### Fixed
- **Jev rates the query half, with structured levels.** The first pass on the right scale read 45 of 69 search titles at 0.5 and was sure on none: "Aphorism: plain words" carries the aphorism in front, level zero named an aphorism, and Jev, reading literally, hedged. The state now carries `query_part` (the half after the colon when the front is the note's own title, else the whole search title) and the title question rates that field only, with the description and the title named as context. Every level is a `summary` with `signals` and the noul carries `what`/`examples`, the documented shape for sharpening a rubric that scores between levels. The description levels separate "names the topic" from "states the argument", which the first pass could not (69 of 69 at the top).
- **`signal-noise/jev-pass-now`** (rw door): runs the pass now and returns its counts, so a rubric change is read in minutes rather than a day. Pinned (42; rw door 9).

