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
- **Two ledger reads on the read door: `get-machine-readers-crosstab` and `get-rights-reads`.** The summary collapses the sensor's rows into per-family and per-purpose totals, so "which purpose did each family read for" and "who fetched the rights files, when, and how regularly" needed shell access to the origin. The crosstab folds the aggregate rows into family x purpose x agent cells (hits, distinct days seen, hits per surface), with `taxonomy_absent` when the edge sent no taxonomy and `truncated` when the read hit the edge's row cap. The rights reads carry every fetch of `/.well-known/tdmrep.json`, `/license.xml` and `/tdm-policy` from the full-fidelity stream (observed_at, family, vendor, purpose, path, hits; the user-agent string and Accept header never leave the plugin), a cadence fold per family and path (median interval, regularity as the coefficient of variation of the gaps, `poller` at three reads or more within 0.5), and `ai_rights` counted twice, from the aggregate and from the stream, because the two are written by different paths at the edge and a gap between them is a sensor finding. Both are folds over `snt_mr_fetch()`; nothing new is fetched, no remote twin's shape moves, and the durable snapshot gains `by_family_purpose` (family => purpose => hits) beside `by_family`. Read door 44 to 46. `inc/machine-readers-ledger.php`, `inc/abilities-machine-readers-ledger.php`, `tests/machine-readers-ledger.php` (40).

## [16.9.3] - 2026-09-19 — the rule is the description

### Fixed
- **The tag rule is the description; the count is a nudge.** /notes/tags promises "each one says what it covers, so you can tell before you click", which makes a tag on a note right when the note covers what its description says, the thing Jev's pass measures. 16.9.2's ceiling of four listed five notes on Content › Tags and in tag hygiene, and every one of them carried five fitting tags (zero misfits in the pass); 35 of 44 notes carry two or more tags from one group of the page's four, so no structural count hides in the taxonomy either. The `over_ceiling` rows are gone from tag hygiene, `sn-scan{tag_hygiene}` and both Tags surfaces (`sn_tag_notes_over_ceiling()` with them). The pre-publish gate keeps its prompt at five tags or more, reworded to the rule ("5 tags. Each one promises the note covers its description; drop any it only brushes."); `SN_TAG_CEILING` now lives in `inc/pre-publish-gate.php`, its only reader.

