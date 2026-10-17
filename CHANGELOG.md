# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.29] - 2026-10-17 — the anchor rule stops flagging citations


- **Docs: the SN Traffic Lanes design record is in the repo.** `docs/plans/traffic-lanes-2026-10-15.md` (the step-2 report, owner decisions, the Phase 1 build contract and three advisor checkpoints) sat on an unmerged branch. It now carries a status line: Phase 1 shipped in 2026.10.18, Phase 2 is not built. No code change.

- **The anchor rule no longer flags citations.** `sn-scan` `anchor_violations` reported 93 findings on the live corpus, and every one was the link label that ends a References item (`Source, date. <a>domain.com</a>`). The sentence splitter ends the sentence at the period before the link, so the label was a sentence of its own by construction. Links inside a list carrying `is-style-references` are now out of scope for `anchor_equals_sentence`, the way links in a heading already belonged to `heading_contains_link`. Prose and ordinary lists are judged as before, and a class that only starts with `is-style-references` is not exempt. `tests/sn-scan-anchor-violations.php` pins both sides (red before the change: 3 failed). No post content was touched.


