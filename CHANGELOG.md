# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Security
- **PHP under `lib/` is denied over HTTP.** The vendored Dompdf tree (3.1.6, patched against every published advisory) has no ABSPATH guards, and `lib/pdf/vendor/autoload.php` answered a direct request with 200. Its files only define classes, so nothing ran, but nothing needs them over the web either: `lib/.htaccess` denies `*.php`. Found in the 2026-09-27 security audit.

## [19.3.1] - 2026-09-27 — the audit pass

### Fixed
- **A retired `/notes/tag/` link no longer lands on a 404.** 19.1.0 kept the request's form, so `/notes/tag/ai-tools/` went to `/notes/tag/music-production/`, which live tags never serve. Every retired tag now redirects to the canonical `/tag/<slug>/` (or the hub, or `/notes/`).
- **The nightly scheduled reads see a failed section.** Since 19.3.0 the run calls `sn-status` / `sn-metrics`, which degrade one failed source to `{error:"unavailable"}` inside a successful call; the run only checked the call, so a broken reader recorded a clean night. A failed section now records the read as failed.
- **The Home briefing says "all" only when it is all.** It printed "all N notes anchored" whenever any note was confirmed, including 40 of 41; short of the total it now says "40 of 41 notes anchored".

### Changed
- **One reading rule.** The north star's tally and its calibration each kept a copy of "a page read is max scroll or summed dwell, on a viewed page". Both now call `snt_nsm_page_metrics()` and `snt_nsm_is_read()`, and a test pins the two counts equal on the same visits.
- **The retired-tag map is guarded as a whole.** Tests now check every entry: no retired tag points at another retired tag, no target is an absolute URL, keys are slugs, no prefix or case matching. These lived in the theme's tests until its map was folded in (theme 14.4.1).
- Docs: `tag-merge-map.md` no longer says it was never applied (it ran 2026-08-15); wave 4 gets its verdict sheet, `docs/mcp-consolidation/retirement-verdicts-2026-09-27.md`.

