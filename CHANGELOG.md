# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.9.3] - 2026-09-19 — the rule is the description

### Fixed
- **The tag rule is the description; the count is a nudge.** /notes/tags promises "each one says what it covers, so you can tell before you click", which makes a tag on a note right when the note covers what its description says, the thing Jev's pass measures. 16.9.2's ceiling of four listed five notes on Content › Tags and in tag hygiene, and every one of them carried five fitting tags (zero misfits in the pass); 35 of 44 notes carry two or more tags from one group of the page's four, so no structural count hides in the taxonomy either. The `over_ceiling` rows are gone from tag hygiene, `sn-scan{tag_hygiene}` and both Tags surfaces (`sn_tag_notes_over_ceiling()` with them). The pre-publish gate keeps its prompt at five tags or more, reworded to the rule ("5 tags. Each one promises the note covers its description; drop any it only brushes."); `SN_TAG_CEILING` now lives in `inc/pre-publish-gate.php`, its only reader.

