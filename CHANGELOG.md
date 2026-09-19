# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.7.1] - 2026-09-19 — the corpus goes once

### Fixed
- **The lane map sends the corpus once and asks every pair.** Jev's output is free; the input is the state plus every question's text. The 16.4.0 map ran the collision check per note, re-sending the whole corpus 44 times (485k tokens, $0.02) for 946 pair readings. `mode: pairs` (the default) sends the 44 titles and descriptions once per chunk of 300 pair questions, one terse Noul per unordered pair ("Do `notes.nA` and `notes.nB` make the same central argument, so that a reader of one would learn nothing new from the other?"), the rubric riding on the first question of each chunk: four requests, about 30k tokens, a tenth of a cent. The per-note path stays as `mode: notes` on `jev-lane-map` so the two readings can be compared on the same corpus before the old one goes; `jev-lanes` reports `mode` and `requests`. Pinned (43).

