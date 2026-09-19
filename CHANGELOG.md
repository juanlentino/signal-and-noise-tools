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
- **Two watches from the WordPress 7.2 roadmap read.** `connector_key_wipe_65551`: since 16.5.2 the TypeSafe key is Core's connector's, and Core's settings save deletes a key it cannot validate, `null` included (Trac #65551, unmerged); the watch ripens on WordPress 7.2, when the fix is to be verified. `mcp_adapter_read_door`: the plugin hand-rolls its MCP transport and WordPress/mcp-adapter is heading for the directory with the 2026-07-28 revision; the watch ripens when the adapter class is loaded, when the abilities register with it and the read door retires first. Both state-ripe, both pinned. The full read is `docs/ops/wordpress-7-2-roadmap-read.md`.

### Fixed
- **One lane map, one mode.** 16.7.1 kept the per-note path as `mode: notes` for a live comparison on the same corpus in the same hour: 44 requests, 485k tokens, 17 pairs at 0.5, against 4 requests, 55k tokens, 4 pairs. The same four pairs at the top in the same order, values about 0.08 lower, the 0.5-to-0.6 vocabulary band mostly under the line, nine times cheaper. The per-note path is gone; `jev-lane-map` takes no input again and `jev-lanes` reports `requests`. Pinned (39).

## [16.7.1] - 2026-09-19 — the corpus goes once

### Fixed
- **The lane map sends the corpus once and asks every pair.** Jev's output is free; the input is the state plus every question's text. The 16.4.0 map ran the collision check per note, re-sending the whole corpus 44 times (485k tokens, $0.02) for 946 pair readings. `mode: pairs` (the default) sends the 44 titles and descriptions once per chunk of 300 pair questions, one terse Noul per unordered pair ("Do `notes.nA` and `notes.nB` make the same central argument, so that a reader of one would learn nothing new from the other?"), the rubric riding on the first question of each chunk: four requests, about 30k tokens, a tenth of a cent. The per-note path stays as `mode: notes` on `jev-lane-map` so the two readings can be compared on the same corpus before the old one goes; `jev-lanes` reports `mode` and `requests`. Pinned (43).

