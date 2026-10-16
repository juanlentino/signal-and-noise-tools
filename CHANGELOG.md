# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.19] - 2026-10-16 — Analytics in S&N Home is one status line, an edge card and a row of cards; rows of related boxes across S&N Home

### Changed
- **Internal only: the settings module's source is split into smaller files** (house line cap). `inc/settings.php` (729 lines) keeps its header, `sn_note_reply_aliases()` and its three constants, then loads three parts files in the order the code always ran, so the `init` hook keeps its place: `settings-defaults-parts.php` (the credential mask, `sn_settings_defaults()`), `-accessors-parts.php` (the `register_setting()` call on `init`, the whole-tree sanitizer, `sn_setting()`, the cache reset, `sn_setting_update()`) and `-save-parts.php` (`sn_settings_save()`, the legacy seed, the lazy migration check). Code moved unchanged; the two long functions (`sn_settings_defaults()`, `sn_settings_save()`) move whole. Five suites that read the module's text now read it through `snt_test_settings_src()` in `tests/lib/inc-population.php`. Nothing changes on screen, every setting reads and saves the same, and no figure is removed.

### Fixed
- **Connections › Scheduled: Cadence and Suggested slots share one row** (owner rule 2026-10-16: rows of related boxes, three across only where three belong). Both are per-item reads that grow with the queue, so they sit side by side and stack to one column on a phone; Runway is one sentence and stays a full-width line above them. Layout only: every sentence, link and figure still paints, in the same order.
- **Measurement › Insights puts three boxes on a row where three belong together.** Run Analysis, Scan status and Settings now share the first row (Scan status stood alone at full width under the other two), and the recommendation cards fit three across on a wide screen instead of a fixed two columns; both rows collapse on a narrow screen. Layout only: every figure, link, button and form still renders. The other Measurement and Tools leaves were already grouped, or hold one section or wide tables, so they keep their layout. Pinned in `tests/os-leaf-monitoring-insights.php`.
- **Content › Vocabulary drift: short year pairs sit three to a row.** A run of pairs that were not measured or held still shared a row two at a time, so a wide window stacked them in long half-empty rows. They now share a row three at a time; a leftover one or two still share theirs, and a pair with movement still spans the window. Layout only: every pair, figure and sentence still renders, in the same order. The other Content and AI leaves were already grouped or are wide tables and forms, so they are unchanged. Pinned in `tests/os-leaf-content-vocabulary.php`.
- **Measurement › Analytics on S&N Home is designed, not stacked.** One Pipeline card leads: the five presence checks as "5 of 5 set" (a missing one named in amber), the Worker version, when the salt rotates and how many devices are yours on one line, with View dashboard on the right. The Edge card puts the 5xx split in its 5xx tile ("503 4,314 · 520 132") and the read time beside Refresh in its footer row (the Token and Firewall boxes on Connections › Cloudflare and Security › Firewall share that footer). Then three cards across: Your devices (open: this browser, who is kept out, each device with Revoke, enroll, This device, Revoke all, with the explanation under How this works), Setup (Credentials, Collector endpoint, Roles kept out, Engine tuning, Session funnels, Edge worker and salt, Configured elsewhere, each a fold whose closed line is the setting as it stands, opening itself when it needs you) and North star. Nothing was removed. The classic page's unlabeled 5xx table now has Status and Count headers and no longer stretches across the card.

