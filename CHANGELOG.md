# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Removed
- **/resume no longer ends with "Beyond the record: the research · the music".** The owner removed it (2026-10-02): the header stays on screen while scrolling, so the line repeated links the reader could already see. The theme drops the same lines from /music and /provenance (theme 14.9.0). Press Generate on the résumé after installing so the page drops it too.

### Added
- **The Resume PDF section names its engine.** One line on both surfaces (Content › Resume and the classic page): the vendored Dompdf version, whether our Cpdf text fix is in (20.3.2; upstream dompdf/dompdf#3771, milestone 3.1.7), and the latest Dompdf release, read from GitHub at most once a day and kept when a read fails. A new watch, `dompdf_bump`, ripens when a newer release exists: bump `lib/pdf`, and drop the patch if upstream's fix shipped. Updating stays a plugin release, not a button: the engine ships with the plugin. Pinned in `tests/pdf-engine-status.php`.

## [20.5.1] - 2026-10-02 — the resume ending sits in the column


- **/resume's closing line sits in the page column.** 20.5.0 appended it after the last band, so it rendered full-bleed with its first letter clipped at the window edge. It is now the last block inside the Skills band, sharing the page's 1320px column and spacing. Press Generate after installing to rewrite the page. Pinned in `tests/resume-sync-engine.php` (the line is inside the last band; red against 20.5.0).


