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
- **Content › Workflow is laid out like Now and Uses.** Every field sits in a compact card and the cards pair two-up: the page title and Dek beside the two section headings, the sample's label, title, intro and outcome beside its body, then the map steps and the rules, each list ending in its spare card. The loose sub-headings are gone, and the field labels say which part they fill (Map step, Sample body). The classic form moves its two heading fields up beside the Dek, so both forms post the same fields in the same order. Nothing about the save or the page changes.

## [21.7.0] - 2026-10-04 — a /workflow page, edited on Content › Workflow

### Added
- **A new public page, /workflow, edited on Content > Workflow.** It follows the /now and /uses pattern: the form stores a document in the `sn_workflow_page` option, and every save regenerates a real top-level Page (slug `workflow`, template `page-workflow`) whose title is the stored Title and whose excerpt, and so meta description, is the stored Dek. The page holds a sample (its body shown verbatim in a monospace block, escaped and with `[` `]` encoded so no shortcode runs), a map and a set of rules; empty sections render nothing, and no Page is created until a save has something to show. A map row appears only when its "Show on page" box is checked, unchecked by default, because some rows name work that must never be public: `sn_workflow_public_data()` drops hidden rows before any markup exists, and it is the only thing the generator reads, so every surface that reads the Page sees none of them. If a save leaves nothing public, an existing /workflow Page goes to draft. Classic form and native leaf post the same fields. The theme half (template and CSS) ships separately. Pinned by `tests/workflow-page.php` (including a mutation run with the filter removed) and `tests/os-leaf-content-workflow.php`; the pipeline for all four generated pages is written up in `docs/CONTENT-PAGES.md`. The sample reads Label, Title, Intro, Outcome, Body: the result leads and the long Body follows as its evidence, and the Content tab lists the fields in the same order. The map and the rules each take an optional heading from the form; a heading renders only over rows that are shown. Known limits (hiding is not a retraction; core's capital-P filter; kses for non-administrators) are in docs/CONTENT-PAGES.md.

