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
- **Roadmap board: the static floor resynced to the third live write of 2026-10-16.** Analytics: "A quiet week explained…" moves planned to done, reworded as shipped in 2026.10.24 (the pages and sources that fell furthest against their own usual weeks, or a drop spread thin). The five-row done cap dropped the oldest done row, "Which machines send a reader back", which graduates onto the Analytics maturity page as its seventeenth principle with its "not a ratio" decline intact; the test guarding that decline moved with it. The md5 pin moves to `95715684be34e8e4b8d77acf53cd4f10`, verified against the live page.

## [2026.10.24] - 2026-10-16 — A quiet week names what moved, widgets say what they flag, code cleaned and split

### Added
- **A quiet week explained, not only flagged.** When the cadence watch's views flag fires, the flag now carries `movers`: the top 3 pages (from the path rollup the watch already read) and the top 3 sources (the referrer breakdown, one read per week of the same baseline) by absolute shortfall against their own median over the same trailing post-birth weeks, each as "this week N vs usual M". When the largest named mover is under a quarter of the site's shortfall, it says "No single page explains it: the shortfall is spread across N pages." A surface whose usual weekly visits sit under `SNT_METRICS_FLOOR` (3) is never named; it folds into one "(withheld)" bucket with summed figures. The gate is the usual visits, so a surface that collapsed to zero still explains the week. Deterministic, no model, no new collection, and nothing extra is read unless the flag fires. It rides everywhere the flag does: the Cadence deviations health finding's note, `sn-status{cadence}` (an added key inside the fired flag only; the envelope is byte-identical when nothing fires), and the Insights signal bundle. The remote door is unchanged (`cadence` stays local; `get-health-scan` ships no notes). New `inc/ml-cadence-movers.php`; pinned in `tests/ml-cadence-movers.php` (17 red against the old code) and `tests/insights.php`.

### Changed
- **Six more large modules split under the house line cap** (internal only; nothing changes on screen or in behavior). `inc/schedule-engine.php`, `inc/openstation-host.php`, `inc/provenance-admin.php`, `inc/admin-bar.php`, `inc/abilities-system.php` and `inc/admin-tab-dashboard.php` each become a shell plus three `*-parts.php` files, a pure move: the shell's original lines plus each part minus its header rebuild the old file byte for byte, hooks keep their order, and every function is still declared once. Source-reading tests read through five new by-name helpers in `tests/lib/inc-population.php`, the absence pins among them now fail on an empty read, and the `PreparedSQL` exclusion that named `inc/schedule-engine.php` now names the two parts that need it.
- **Internal only: dead code and stale comments cleared.** Two uncalled helpers removed (`snt_ev_rights_size()`, `snt_mr_strip_figures()`), two unused `admin.css` rules (`.sn-notice-spacing`, `.sn-rt-snippet`), comments that cited a line number now name the function, and `Unreleased` markers on long-released code now carry the version that shipped it. No behavior, markup or copy changes.

### Fixed
- SN Traffic: the change against the prior 14 days no longer paints green (or red) when 25% or more of the window's scored visitor-days are likely automated. It keeps its arrow and figure in the muted text color, so a rise that is half likely automated no longer reads as good news. Under 25%, or when likely automated is not measured, the existing rule stands. The payload carries no human-only change, so the tone follows the share.
- SN Systems: the headline now names what to look at beside the count, using the amber row's own words ("1 to look at: 5xx Oct 15"). With two or more it names the first and adds "+N more".

