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
- **The anti-tell pass: the voice playbook's banned constructions, read on every draft save.** `inc/jev-tells.php` splits a note into its paragraphs (the `<p>` blocks; headings, lists and quotes are not judged; 24 at most, 40 characters or more) and counts the tells a regex can see without a model: em dashes, "quietly", "not just X but Y", hedge clusters (two or more of could/might/may/perhaps/potentially/possibly/seems/arguably in one sentence), and three consecutive sentences within 15% of one length. The tells that need a reading go to Jev in one request per note: three Nouls per paragraph (a three-part list built for rhythm rather than because there are three things; consecutive openings repeated for effect; the "It is not X. It is Y." pair as the point) and one for the closer (does the last paragraph restate the thesis in elevated language rather than end on a point, a question or evidence), each with what and examples on both sides. The rows at or above 0.6 and the regex counts are stored on the post as `_sn_jev_tells`; an unchanged draft is not re-asked; a failed request keeps the previous rows and recomputes the counts. The pre-publish panel warns per row ("Jev reads paragraph 3 as a three-part list built for rhythm (0.81)…") and lists the counts. A corpus pass over the published notes (`jev-tells-pass`, about seventy requests, two cents) is a reading only: notes are never edited after publication. Abilities `jev-tells-check` and `jev-tells-pass` (WRITE, rw door 12 → 14) and `jev-tells` (READ, 42 → 43), pinned; the meter gets a `tells` feature. `tests/jev-tells.php` (30).

### Docs
- Session doc extended: "Every reading moved a line" (16.3.3 through 16.6.0: the floor that routed instead of acted, the query half, the collision gate and the lane map's moving edge, query fit at the site's scale and the literal reading of "crypto", the key and then the transport to Connector for TypeSafe Jev, the meter per credit cycle, AI.md, all ten door tools called, jev-connector#8). Left open updated.

## [16.6.0] - 2026-09-18 — the meter is the site's own ledger

### Added
- **The Jev meter: the site's own priced ledger of Jev use, the way the Claude itemization is.** Every request through `sn_jev_ask()` now names its feature (`notes`, `collision`, `lane_map`, `fit`) and lands in a bucket per feature per **credit cycle** (TypeSafe's credit renews on the 17th, so the bucket is the cycle, not the calendar month; the credit amount and the cycle day are two settings beside the AI budget, defaults $5 and 17), priced from the input tokens each answer reports at the pinned $0.042 per million; output is free. A hit on the connector's one-hour cache is counted as `cached` and costs nothing, so a draft saved five times shows one paid request and four cached; a failure is counted with no tokens. Twelve cycles kept. Painted as **Jev, this cycle** on AI › Models & Budget under the Claude spend: credit, spent, remaining, days left, a bar, one row per feature. The `jev-meter` read ability (door 42) and `sn-status{jev_spend}` hand the same out (local only on the remote door until the shape settles). One-shot seed at install from the passes stored before the meter existed (notes, lane map, fit), marked seeded. Nothing projects: a figure is read or absent, and the TypeSafe console stays the bill. `tests/jev-meter.php` (20).

