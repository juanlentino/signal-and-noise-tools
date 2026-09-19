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
- **The lane map sends the corpus once and asks every pair.** Jev's output is free; the input is the state plus every question's text. The 16.4.0 map ran the collision check per note, re-sending the whole corpus 44 times (485k tokens, $0.02) for 946 pair readings. `mode: pairs` (the default) sends the 44 titles and descriptions once per chunk of 300 pair questions, one terse Noul per unordered pair ("Do `notes.nA` and `notes.nB` make the same central argument, so that a reader of one would learn nothing new from the other?"), the rubric riding on the first question of each chunk: four requests, about 30k tokens, a tenth of a cent. The per-note path stays as `mode: notes` on `jev-lane-map` so the two readings can be compared on the same corpus before the old one goes; `jev-lanes` reports `mode` and `requests`. Pinned (43).

## [16.7.0] - 2026-09-19 — the tell is read before the note ships

### Added
- **The anti-tell pass: the voice playbook's banned constructions, read on every draft save.** `inc/jev-tells.php` splits a note into its paragraphs (the `<p>` blocks; headings, lists and quotes are not judged; 24 at most, 40 characters or more) and counts the tells a regex can see without a model: em dashes, "quietly", "not just X but Y", hedge clusters (two or more of could/might/may/perhaps/potentially/possibly/seems/arguably in one sentence), and three consecutive sentences within 15% of one length. The tells that need a reading go to Jev in one request per note: three Nouls per paragraph (a three-part list built for rhythm rather than because there are three things; consecutive openings repeated for effect; the "It is not X. It is Y." pair as the point) and one for the closer (does the last paragraph restate the thesis in elevated language rather than end on a point, a question or evidence), each with what and examples on both sides. The rows at or above 0.6 and the regex counts are stored on the post as `_sn_jev_tells`; an unchanged draft is not re-asked; a failed request keeps the previous rows and recomputes the counts. The pre-publish panel warns per row ("Jev reads paragraph 3 as a three-part list built for rhythm (0.81)…") and lists the counts. A corpus pass over the published notes (`jev-tells-pass`, about seventy requests, two cents) is a reading only: notes are never edited after publication. Abilities `jev-tells-check` and `jev-tells-pass` (WRITE, rw door 12 → 14) and `jev-tells` (READ, 42 → 43), pinned; the meter gets a `tells` feature. `tests/jev-tells.php` (30).

### Docs
- Session doc extended: "Every reading moved a line" (16.3.3 through 16.6.0: the floor that routed instead of acted, the query half, the collision gate and the lane map's moving edge, query fit at the site's scale and the literal reading of "crypto", the key and then the transport to Connector for TypeSafe Jev, the meter per credit cycle, AI.md, all ten door tools called, jev-connector#8). Left open updated.

