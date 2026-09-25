# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [18.6.2] - 2026-09-25 — the resume download row is the button alone

### Changed
- **The resume hero's download row is the Download PDF button alone.** The filename link beside it read RESUME (the saved PDF link label), repeating the page's own title right above. The page builder now writes core/file's own no-filename shape: one button, no dangling `aria-describedby`. The PDF link label field is gone from both resume forms, the document schema and the seed; a label saved before is ignored. The live page picks this up on the next resume save or PDF generation, since the page is rebuilt from the form.

