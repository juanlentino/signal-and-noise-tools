# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [18.4.0] - 2026-09-24 — the health scan names what it skipped

### Added
- **The health scan names what it skipped, and why.** `get-health-scan` (and its remote twin) now carries `skipped[]`: one `{check, label, reason}` per check counted in `checks_skipped`, from the same `sn_health_skipped_checks()` the Health tab renders. On 2026-09-24 the phone read `checks_skipped: 1` and answering "which one?" took a read of the plugin source. `count( skipped ) === checks_skipped`; a clean scan reports `[]`. Remote contract '8' → '9' (hash RED-then-pinned); sn-remote-mcp-worker bumps in step.

### Changed
- **The plugin package is ~2.6 MB lighter.** The résumé PDF sets Lato everywhere and uses DejaVu Sans only for the regular-weight ◆ bullet (Lato has no U+25C6), so dompdf's bundled DejaVu Bold, Oblique and BoldOblique faces and their metrics no longer ship (`export-ignore` in `.gitattributes`; the repo keeps them). Regular DejaVu Sans and all four Lato faces still ship; `tests/export-ignore.php` pins both sides.

