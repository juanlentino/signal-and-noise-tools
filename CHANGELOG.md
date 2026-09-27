# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.2.0] - 2026-09-27 — pages are their own category

### Added
- **Pages are their own category.** Attention has a **Pages** pill: a row about a signed page (the `/provenance/` hub, an essay) files there, whatever signal raised it, and its badge still names the signal. The SN Anchors widget counts pages apart from notes ("N of N pages anchored" under the notes line), and a page's in-flight version lists under recording or pending as "Page: …". `signal-noise/anchor-status` gains `pages {confirmed, total}` and a `type` on each in-flight row; `confirmed`/`total` stay notes-only.

### Fixed
- **Old edge verdicts retire after a full purge.** An Attention Edge row showed a page's last probe verdict until the page was saved again, so ten readings from Sep 24, each already escalated to a zone purge, sat in the queue for days. The last full zone purge is now stored on its own (`sn_cf_last_zone_purge`, since per-post purges overwrite `sn_cf_last_purge`), and a stale reading that a zone purge replaced more than a minute later drops off. The probe's own escalation, seconds after the reading, does not count.

### Changed
- **Release cuts skip the security scan; nothing else does.** A cut PR only moves CHANGELOG text and bumps the header's `Version:` line, and the code it ships was scanned on its own PR, yet each cut waited ~10 minutes for a second scan. `.github/scripts/scan-scope.sh` now decides: skip only when every file is docs/Markdown or the plugin header whose ONLY changed line is `Version:`. A code file or a header edit riding a cut is scanned; any doubt scans. The script runs from the base commit, never the PR head, so a PR cannot rewrite it to waive its own scan. Pinned by `tests/ci-scan-scope.php`.

