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
- **Two admin screens now use core's own form and disclosure primitives (core-first audit, 2026-11-10).** The RSS tracker's three writes (Save Settings, Reset to Defaults, Purge now, on Measurement > RSS and in its OpenStation window) post to `admin-post.php` as `sn_rss_save_settings`, `sn_rss_reset_defaults` and `sn_rss_purge_log`, each checked with `check_admin_referer()` against a nonce made for that action alone, then `manage_options`. They used to be read on `admin_init` under one shared nonce checked by hand. A stale form now shows core's standard "link expired" page; before, it returned silently and the change was lost with no message. The option name, its keys and values, the redirect and the `?sn_rss_ok` flash (`purged-N` included) are unchanged. The OpenStation window replays the same hooks through its admin-post pipeline, so its RSS-only pipeline is removed. A tab left open across the update posts the old form and is refused, so reload before saving. On Analytics > Posts, the "Show all notes" panel is now a native `<details>` with the panel header as its `<summary>`, so the browser provides the toggle, the keyboard handling and the expanded state. It used to be a script toggle and a separate "Toggle panel" button. Core's postbox.js was considered and not used: it binds every postbox title on the page, so the other panels would fold too, it saves every panel's state to user meta, and it has no default-closed state. The panel still opens closed, other panels are unchanged, and opening it still fires the lazy-load event. It no longer remembers in the browser whether it was left open, so it opens closed on every visit, like the estate's other folds. Tests: `tests/rss-tracker-admin-post.php` (new), `tests/os-leaf-monitoring-rss.php`, `tests/openstation-host.php`, `tests/analytics-panels.php`, `tests/analytics-clamp-asset.php`.
- **The test sweep passes on WordPress 7.2 Beta 5 as well as 7.1.4.** In 7.2, core's block parser is built on `WP_Block_Processor`, so a block delimiter inside a plain HTML comment, and attribute JSON that never closes, now read the same to `parse_blocks()` and to the sn_apply delimiter reader. `tests/sn-apply-block-delimiters.php` and `tests/sn-apply-fingerprint-splice.php` now ask which the loaded core does (`tests/lib/parser-walker-agree.php`). Where the two disagree (7.1), the write is refused as before. Where they agree (7.2), the write must splice exactly the block `parse_blocks()` puts at that path and leave every other block as stored. The check that refuses a disagreement stays, because the live site runs 7.1. `tests/run.sh` now says how to sweep 7.2 (`WP_TEST_CORE_REF=trunk` until wordpress-develop has a 7.2 branch). One visible change comes with 7.2 itself: core lets list items through its automatic excerpts (7.1 already listed `core/list`, but its `core/list-item` children were dropped; 7.2 adds `core/list-item` and treats `core/list` as a wrapper), and the social card dek is built on core's `excerpt_remove_blocks()`, so a post with no hand-written excerpt (and a page with no meta description) that opens with a list will start its dek with the list's text. On 7.1 the list is skipped, as before; nothing changes until the site updates. `tests/og-card-dek-fallback.php` reads the loaded core's list and expects what it says. Otherwise test setup and comments only.

## [2026.11.23] - 2026-11-10 — Pull-quote suggestions build core's Pull Quote

### Fixed
- **Pull-quote suggestions follow the theme's core pull quote.** Since theme 2026.11.7 the pull-quote pattern is `core/pullquote` with the Pull-quote block style (`is-style-pull-quote`). The pattern-adoption suggestion now builds that markup, copied from the theme's `patterns/pull-quote.php`, instead of the old `sn-pattern-pull-quote` group, and the conventions registry fixture is refreshed from the theme's registry. Pull quotes already written as the old group or the `signal-noise/pull-quote` block are still not candidates, so published notes are neither flagged nor rewritten. Tests: `tests/pattern-adoption-suggest.php`, `tests/pattern-adoption-detect.php`.

