# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **Settings › Analytics and Settings › Accounts and connections are grouped into headed rows.** Both panes let their boxes flow into as many columns as fit, so Analytics stretched to six thin columns and Accounts put one tall Zenodo box beside three short ones. Analytics is now three rows of three, by subject: Data collection (Collector endpoint, Roles kept out, Feed tracking), What counts as a read (North star, Session funnels, Publish windows), Signals and scans (Engine tuning, Machine readers, Insights). The split follows Google Analytics' Admin, which files settings under data collection and data display. Accounts keeps Credentials, then Search engines (IndexNow, Search Console) and Records and profiles (Zenodo, Downloads on /provenance, Muso.AI). Search engines groups submission and indexing as Google Search Console and Bing Webmaster Tools do. The downloads line, which shared the Zenodo box, is now a box of its own with the same fields and Save, and the IndexNow box is titled "IndexNow" under its row. No field, action or handler changed. Both twins. A row with none of its modules loaded paints no heading. Tests: `tests/os-leaf-settings-analytics.php`, `tests/os-leaf-monitoring-analytics.php`, `tests/os-leaf-settings-accounts-boxes.php`, `tests/os-leaf-settings-accounts-rows-absent.php` (new).

## [2026.11.63] - 2026-11-25 — Settings regrouped: door switches in their own boxes, the related-notes ranking in Front end, and the AI screen states the ranking readers see

### Changed
- **Settings › AI is regrouped: each door's switch sits in that door's box, and the related-notes ranking moved to Settings › Front end.** 2026.11.62 added the switches as a separate Door switches box and the ranking as a third box in the AI pane's top row, which split each door across two boxes and left gaps beside the tall Models box. Now the Read door and Write door boxes each start with their own on/off switch, the way the Remote door box already did (macOS System Settings › Sharing puts each service's switch with that service). Each switch is its own form and saves only its door. "Rank related notes by" sits in the Reading box beside "Related notes shown" and saves with the rest of that form (WordPress Settings › Reading groups how lists of posts appear). The AI pane's top row is back to Models and budget plus Scheduled reads. Both twins. The boxes are renamed Read door and Write door. Tests: `tests/settings-switches-save.php`, `tests/os-leaf-settings-ai.php`, `tests/os-leaf-ai-mcp-connect.php`, `tests/os-leaf-settings-front-end.php`, `tests/os-leaf-settings-front-end-pane.php`.
- **The AI screen states which ranking the related notes actually use.** Each rebuild of the related-notes lists now records whether it ranked by meaning (embeddings) or by shared words, as a `source` key in `snt_ml_corpus_meta`. The embeddings sentence compares that record with the Related notes setting and says what readers see: ranked by meaning; set to meaning but the last rebuild ranked by shared words; ranked by shared words; or set to shared words while the current lists still rank by meaning until the next rebuild. Before the first rebuild that records it, the sentence is about the next rebuild. Tests: `tests/ml-artifacts.php`, `tests/os-leaf-ai-models-budget.php`.

