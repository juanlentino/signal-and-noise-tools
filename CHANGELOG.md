# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.110.2] - 2026-09-11 — a dated correction notice is the convention, not date drift

### Fixed
- **`corpus_integrity` no longer reads the correction convention as drift.** A paragraph that is exactly a dated editorial notice — `Correction, September 3, 2026.`, `Updated 2026-09-04`, the shapes the site actually writes after a claim-changing edit — carries a date later than `post_date` by design, and the scan reported both live instances as `date_coherence` findings. `snt_corpus_integrity_is_dated_notice()` recognises the narrow shape (notice word, optional punctuation, a date as the whole remainder — prose that merely contains the word is still judged); notices are counted under a new `counts.corrections` so the practice is visible, never a candidate (`tests/corpus-integrity-scan.php` +9, including four refusals).

### Changed
- README: the write door's eight slugs by name; the Abilities REST route named as a write surface with its guard; a **Public surface** table listing every credential-free route and edge endpoint with who serves it and why it is public (`tests/rest-routes.php` pins the plugin half). `phpstan.neon`'s header no longer says CI runs soft. `.gitattributes` drops an export-ignore for a `.pre-commit-config.yaml` that never existed.

