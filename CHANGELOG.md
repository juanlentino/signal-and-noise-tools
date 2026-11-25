# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.63] - 2026-11-25 — Settings regrouped: door switches in their own boxes, the related-notes ranking in Front end, and the AI screen states the ranking readers see

### Changed
- **Settings › AI is regrouped: each door's switch sits in that door's box, and the related-notes ranking moved to Settings › Front end.** 2026.11.62 added the switches as a separate Door switches box and the ranking as a third box in the AI pane's top row, which split each door across two boxes and left gaps beside the tall Models box. Now the Read door and Write door boxes each start with their own on/off switch, the way the Remote door box already did (macOS System Settings › Sharing puts each service's switch with that service). Each switch is its own form and saves only its door. "Rank related notes by" sits in the Reading box beside "Related notes shown" and saves with the rest of that form (WordPress Settings › Reading groups how lists of posts appear). The AI pane's top row is back to Models and budget plus Scheduled reads. Both twins. The boxes are renamed Read door and Write door. Tests: `tests/settings-switches-save.php`, `tests/os-leaf-settings-ai.php`, `tests/os-leaf-ai-mcp-connect.php`, `tests/os-leaf-settings-front-end.php`, `tests/os-leaf-settings-front-end-pane.php`.
- **The AI screen states which ranking the related notes actually use.** Each rebuild of the related-notes lists now records whether it ranked by meaning (embeddings) or by shared words, as a `source` key in `snt_ml_corpus_meta`. The embeddings sentence compares that record with the Related notes setting and says what readers see: ranked by meaning; set to meaning but the last rebuild ranked by shared words; ranked by shared words; or set to shared words while the current lists still rank by meaning until the next rebuild. Before the first rebuild that records it, the sentence is about the next rebuild. Tests: `tests/ml-artifacts.php`, `tests/os-leaf-ai-models-budget.php`.

