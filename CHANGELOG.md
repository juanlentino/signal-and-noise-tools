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
- **Internal only: dead code and stale comments cleared.** Two uncalled helpers removed (`snt_ev_rights_size()`, `snt_mr_strip_figures()`), two unused `admin.css` rules (`.sn-notice-spacing`, `.sn-rt-snippet`), comments that cited a line number now name the function, and `Unreleased` markers on long-released code now carry the version that shipped it. No behavior, markup or copy changes.

### Fixed

- SN Traffic: the change against the prior 14 days no longer paints green (or red) when 25% or more of the window's scored visitor-days are likely automated. It keeps its arrow and figure in the muted text color, so a rise that is half likely automated no longer reads as good news. Under 25%, or when likely automated is not measured, the existing rule stands. The payload carries no human-only change, so the tone follows the share.
- SN Systems: the headline now names what to look at beside the count, using the amber row's own words ("1 to look at: 5xx Oct 15"). With two or more it names the first and adds "+N more".

## [2026.10.23] - 2026-10-16 — Widgets grow to fit their content, roadmap board kept in sync, OpenStation 1.1.15 adopted

### Changed
- **Roadmap board: the static floor resynced to the live board of 2026-10-16.** `sn_maturity_roadmap_static_board()` now equals the owner-approved live override byte for byte (md5 of `json_encode`, `d89246b35fae77f59cb739135e4a811c`), pinned by a new test so the floor cannot drift silently. Analytics: "The questions search asked…" and "Coverage history kept…" move planned to done, reworded to what shipped (Analytics planned is now empty); the oldest done row, "AI attention in the weekly digest", graduates onto the Analytics maturity page as its sixteenth principle (the five-row done cap). AI: "The refusals, counted" moves considering to done, reworded. Analytics and AI done now sit at the five-row ceiling, so the board's one-row headroom canary is relaxed to the validator's ceiling; the next done row in either family needs a graduation first.
- **Roadmap board: the static floor resynced to the second live write of 2026-10-16.** Analytics: "A quiet week explained…" moves considering to planned, reworded with its gate (landing with the next cadence arc), and a new planned row, "An unanswered question, followed…", enters. Analytics planned is no longer empty. The md5 pin moves to `5989691e97733a88417641f0d37e0e65`, verified against the live page.
- **Desktop cards grow to their content (OpenStation 1.1.15, WordPress/openstation #988/#989).** The six SN cards (Traffic, Reading, Queue, Systems, Deploy Status, Provenance) set `fit_content`: a floating card whose content needs more room than its height grows to fit, capped at the bottom of the work area (SN sets no `max_height`), shrinks back when the content does but never below the height the owner saved, and scrolls only past that cap. `default_height` stays the starting size. A merged card no longer needs one resize by hand. Pinned in `tests/desktop-mode-integration.php`: every registration carries `fit_content` (6 red with it off).
- **Content › Resume: each row's buttons name the row** ("Remove <the role title> (1 of 3)" rather than "Remove role 1 of 3"). `<os-repeater row-label-from>` (OpenStation 1.1.15, #946/#976) matches a field's `name`, `data-field` or `id` by exact value, and every classic name carries its row index (`resume[stats][0][label]`), so a name could match one row only; the labeling field of each list carries a shared `data-field` instead (stats: label, employers: org, roles, education, affiliations and publications: title, skills: category), in the template's blank row too. An empty field falls back to `row-label` ("Remove role 2 of 3"), upstream's own rule. Field names and the post are unchanged. Pinned in `tests/os-leaf-content-resume.php` (19 red against the old leaf).
- **Health › Re-run scan's table re-nudge stays, with its cause written down.** `renudgeTables()` in `assets/os-host.js` (16.3.1) answers a table whose tag was already defined and whose render lagged its rows, not the pre-upgrade shadowing OpenStation 1.1.15 fixed (#1000/#1001): its own fix, `t.data = t.data.slice()`, is a plain write that a shadowing own property would absorb, so it could only ever have worked through the setter. Kept until Health › Re-run scan is seen painting its rows without it. `assets/snt-link.css` and `assets/snt-confirm-ink.css` each note why they still apply after upstream #997 (links are written only when no desktop theme is worn; the danger label's ink is left alone).

### Removed
- **The kit-prop repair in `assets/os-kit.js` (2026.10.6).** `reassignProps()` and its `whenDefined` wait on `snt:paint` moved an `os-prop-*` value assigned before its tag was defined through the class setter (Connections › Credentials, all three tables). OpenStation 1.1.15 does this itself (#1000/#1001: the kit's base `Component` lifts such an own property and replays it through the accessor on connect), so on 1.1.15 the repair found nothing to do. The plugin now relies on OpenStation 1.1.15 for it. `tests/os-kit-prop-upgrade.cjs` and its runner are removed with it; `tests/openstation-host-assets.php` pins the repair's absence.

