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
- **A new public page, /workflow, edited on Content > Workflow.** It follows the /now and /uses pattern: the form stores a document in the `sn_workflow_page` option, and every save regenerates a real top-level Page (slug `workflow`, template `page-workflow`) whose title is the stored Title and whose excerpt, and so meta description, is the stored Dek. The page holds a sample (its body shown verbatim in a monospace block, escaped and with `[` `]` encoded so no shortcode runs), a map and a set of rules; empty sections render nothing, and no Page is created until a save has something to show. A map row appears only when its "Show on page" box is checked, unchecked by default, because some rows name work that must never be public: `sn_workflow_public_data()` drops hidden rows before any markup exists, and it is the only thing the generator reads, so every surface that reads the Page sees none of them. If a save leaves nothing public, an existing /workflow Page goes to draft. Classic form and native leaf post the same fields. The theme half (template and CSS) ships separately. Pinned by `tests/workflow-page.php` (including a mutation run with the filter removed) and `tests/os-leaf-content-workflow.php`; the pipeline for all four generated pages is written up in `docs/CONTENT-PAGES.md`. The sample reads Label, Title, Intro, Outcome, Body: the result leads and the long Body follows as its evidence, and the Content tab lists the fields in the same order. The map and the rules each take an optional heading from the form; a heading renders only over rows that are shown. Known limits (hiding is not a retraction; core's capital-P filter; kses for non-administrators) are in docs/CONTENT-PAGES.md.

## [21.6.1] - 2026-10-04 — Deploy Status reads a new version within minutes

### Fixed
- **SN Deploy Status no longer shows the previous version for up to an hour after a release.** The newest release from GitHub is cached for an hour, so right after a cut or a worker deploy the widget compared against a "latest" from before that release existed. When the running version is newer than the cached newest tag, which can only mean the cache predates the release, the tag is read again at once (at most once per five minutes per worker, and the same for the plugin). The five-minute background refresh now probes every worker whatever its cache says, so a deploy shows within about five minutes; the ten-minute cache stays as slack for a late run.

