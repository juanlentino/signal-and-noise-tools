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
- **Release cuts skip the security scan; nothing else does.** A cut PR only moves CHANGELOG text and bumps the header's `Version:` line, and the code it ships was scanned on its own PR, yet each cut waited ~10 minutes for a second scan. `.github/scripts/scan-scope.sh` now decides: skip only when every file is docs/Markdown or the plugin header whose ONLY changed line is `Version:`. A code file or a header edit riding a cut is scanned; any doubt scans. The script runs from the base commit, never the PR head, so a PR cannot rewrite it to waive its own scan. Pinned by `tests/ci-scan-scope.php`.

## [19.1.2] - 2026-09-27 — retired tags' later pages redirect

### Fixed
- **Retired tags' later pages redirect.** `/tag/<retired>/page/N/` answered 404 (Google still holds `/tag/provenance/page/2/`). The resolver now matches `/page/N/` and sends it to the target's first page.

### Changed
- **One retired-tag map.** The theme kept its own list (`provenance`, `cryptography`, `music-identification`) beside the plugin's 61, and ran first, so a change in one was silently overridden by the other. All three were already in `inc/tag-retired-map.php` with the same targets; a test now pins them there, and the theme drops its copy in its next release.

