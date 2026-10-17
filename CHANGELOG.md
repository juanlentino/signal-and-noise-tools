# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **The provenance record reads as an edit list, and every version shows its fingerprint.** Each row of a note's version chain is now an edit-decision-list line: event number, version, the time it was recorded (UTC, from the chain's own `committed_at`, as a `<time>`), its block and the short hash, in fixed monospace columns on wide screens. Under each row, `sn_prov_fingerprint_svg()` draws the full SHA-256 of that version's signed text as 64 bars, one per hex digit, each as tall as the digit plus one. Change one character and every bar moves, so a note's versions visibly disagree. The strip is decorative (`aria-hidden`, the hash text stays the value), one `currentColor` path with no inline style, so it follows the theme, prints and survives forced colors. A row without a full 64-hex hash draws no strip, and an unreadable time shows nothing rather than a wrong one. The panel sits outside the signed region, so no signed text changes. Approved by the owner from renders. `tests/provenance-render.php` pins both helpers and the row (red before the change).

## [2026.10.29] - 2026-10-17 — the anchor rule stops flagging citations


- **Docs: the SN Traffic Lanes design record is in the repo.** `docs/plans/traffic-lanes-2026-10-15.md` (the step-2 report, owner decisions, the Phase 1 build contract and three advisor checkpoints) sat on an unmerged branch. It now carries a status line: Phase 1 shipped in 2026.10.18, Phase 2 is not built. No code change.

- **The anchor rule no longer flags citations.** `sn-scan` `anchor_violations` reported 93 findings on the live corpus, and every one was the link label that ends a References item (`Source, date. <a>domain.com</a>`). The sentence splitter ends the sentence at the period before the link, so the label was a sentence of its own by construction. Links inside a list carrying `is-style-references` are now out of scope for `anchor_equals_sentence`, the way links in a heading already belonged to `heading_contains_link`. Prose and ordinary lists are judged as before, and a class that only starts with `is-style-references` is not exempt. `tests/sn-scan-anchor-violations.php` pins both sides (red before the change: 3 failed). No post content was touched.


