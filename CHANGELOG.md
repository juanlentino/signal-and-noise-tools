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
- **Analytics reads that cross the 2.0 clean day read each dataset for its own side.** Split at New York midnight on the clean day (when the worker rotates the visitor hash), so no visitor-day straddles it and sums and distinct counts merge exactly. The over-cap visitor list, which feeds every human/bot filter, is the first read to do so: once the dual-write check turns green it is built from the old dataset before the split and the new one from it, so the stitch is proven live during the seven green days before analytics 2.0.0 stops the old write. A failed half fails the read, never half a list. Nothing changes before that first green verdict.

### Changed
- **AI.md: the rule cites Google's generative AI guidance as an outside reference.** One paragraph under "The rule" links Google's guidance (revised 2026-10-01), which asks for a manual review of AI-generated titles, meta descriptions, structured data and alt text before publishing. It dates the rule here to 2026-07-30, the 10.10.0 entry that first stated human review as a principle, and names the one path that falls short: the background prepopulation that can fill an empty meta description, OG card title or excerpt on a note or page with no review step. Docs only; no test pins AI.md's text.

## [22.1.2] - 2026-10-05 — /stats bars drawn at their exact proportion

### Fixed
- **/stats bars are drawn at their exact proportion.** Every bar took the rounded whole percent, so 356 human views beside 89,578 machine reads drew an empty bar (0.4% rounded to 0). Bars now use the exact share (six decimals, so no positive share draws as 0) while the number beside them keeps its rounding, and a 1px gap inside the outline keeps a hairline fill visible instead of merging into the border.

