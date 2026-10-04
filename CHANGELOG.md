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
- **The Colophon's AI credit links /workflow.** "engineered with Claude (Anthropic) as a pair-programmer" keeps its words; "pair-programmer" now links the /workflow page in the same tab, and only while that page is published, so a withdrawn page leaves plain text rather than a dead link. Owner approved the exact line.

## [21.7.1] - 2026-10-04 — Workflow's map and rules get Resume's repeater

### Changed
- **Content › Workflow: the page fields in cards like Now and Uses, the map and rules in Resume's repeater.** The page title and Dek sit beside the two section headings and the sample's text beside its body, in compact cards two-up. Map and Rules are each a collapsible section holding Resume's repeater: + Add map step / + Add rule, a Remove button on every row, and move handles (Alt+Arrow on a row works too), so a list of eight ordered steps no longer takes eight saves. The form posts rows in screen order and the page shows them in that order. The loose sub-headings are gone, and the field labels say which part they fill (Map step, Sample body). The classic form moves its two heading fields up beside the Dek, so both forms post the same fields in the same order. `resume_section()` moves into the shared Resume parts file, unchanged.

