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
- **The editorial-conventions scan now reads pages, for inline SVG figures.** `sn-scan` scan_type `editorial_conventions` walked posts only, so its "zero findings" held for posts while three paper pages (3216, 1561, 1559) carried a checkmark stroked in fixed `#ffffff`, found on 2026-09-24 by a read-only corpus audit. Pages now join the walk for the `svg-figure` check alone (`SNT_EDITORIAL_CONVENTION_PAGE_CHECKS`); lead, correction, references and steps are note forms and stay posts-only, including when a scoped call names a page.

### Changed
- **The drift check stops asking a provider that refuses.** When the first two AI calls of a scan both fail with a provider error and none has succeeded, `drift_time_phrases` stops there instead of asking once per candidate post (12 doomed calls per scan on 2026-09-24, with the AI account unfunded), and its skip reason carries the provider's own message, so `skipped[]` names the cause, not just "failed". A reply that is not JSON never trips it (the model is answering, just badly), and one success earlier in the scan keeps every later post asked. Still a skip, never a pass. Threshold: `SN_HEALTH_DRIFT_BREAKER_AFTER` (2).

## [18.4.0] - 2026-09-24 — the health scan names what it skipped

### Added
- **The health scan names what it skipped, and why.** `get-health-scan` (and its remote twin) now carries `skipped[]`: one `{check, label, reason}` per check counted in `checks_skipped`, from the same `sn_health_skipped_checks()` the Health tab renders. On 2026-09-24 the phone read `checks_skipped: 1` and answering "which one?" took a read of the plugin source. `count( skipped ) === checks_skipped`; a clean scan reports `[]`. Remote contract '8' → '9' (hash RED-then-pinned); sn-remote-mcp-worker bumps in step.

### Changed
- **The plugin package is ~2.6 MB lighter.** The résumé PDF sets Lato everywhere and uses DejaVu Sans only for the regular-weight ◆ bullet (Lato has no U+25C6), so dompdf's bundled DejaVu Bold, Oblique and BoldOblique faces and their metrics no longer ship (`export-ignore` in `.gitattributes`; the repo keeps them). Regular DejaVu Sans and all four Lato faces still ship; `tests/export-ignore.php` pins both sides.

