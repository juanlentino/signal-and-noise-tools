# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [22.9.0] - 2026-10-06 — an inconclusive contrast run reads unknown, docs match the code

### Fixed
- **The Health contrast line no longer reads an inconclusive run as ok.** The theme's `contrast.yml` turns a run that could not measure (exit 2) into a green job with a warning and no `contrast-summary`; `snt_contrast_rendered_evaluate()` read any green run as ok. Green now counts only with a well-formed summary that measured at least one page; without it the line says the run could not measure and links it, rather than blaming the GitHub API. The cache key moved to `snt_contrast_rendered_v2`, so an `ok` cached under the old reading is never served.

### Changed
- **README and AI.md match the code.** A 34-check scan (was 33), 6 desktop widgets (was 10; Traffic and Reading for analytics), a 50-slug read door (was 49), the eighth tab is Integrity, ~40 leaves, the provenance contract row names the render functions the plugin owns, the default text model is Sonnet 5.5 with Sonnet 5 as the net, and alt text runs on Gemini.
- **No links to private repositories in the docs.** The archived changelog and the sn-provenance Context7 audit name the private Worker repositories instead of linking them.

