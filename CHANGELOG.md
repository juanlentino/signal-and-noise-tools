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
- **The test sweep passes on WordPress 7.2 Beta 5 as well as 7.1.4.** In 7.2, core's block parser is built on `WP_Block_Processor`, so a block delimiter inside a plain HTML comment, and attribute JSON that never closes, now read the same to `parse_blocks()` and to the sn_apply delimiter reader. `tests/sn-apply-block-delimiters.php` and `tests/sn-apply-fingerprint-splice.php` now ask which the loaded core does (`tests/lib/parser-walker-agree.php`). Where the two disagree (7.1), the write is refused as before. Where they agree (7.2), the write must splice exactly the block `parse_blocks()` puts at that path and leave every other block as stored. The check that refuses a disagreement stays, because the live site runs 7.1. `tests/run.sh` now says how to sweep 7.2 (`WP_TEST_CORE_REF=trunk` until wordpress-develop has a 7.2 branch). One visible change comes with 7.2 itself: core lets list items through its automatic excerpts (7.1 already listed `core/list`, but its `core/list-item` children were dropped; 7.2 adds `core/list-item` and treats `core/list` as a wrapper), and the social card dek is built on core's `excerpt_remove_blocks()`, so a post with no hand-written excerpt (and a page with no meta description) that opens with a list will start its dek with the list's text. On 7.1 the list is skipped, as before; nothing changes until the site updates. `tests/og-card-dek-fallback.php` reads the loaded core's list and expects what it says. Otherwise test setup and comments only.

### Fixed
- **The alt-text check reads an image's caption and nearby heading with core's HTML API.** `sn_health_caption_after_offset()` and `sn_health_heading_after_offset()` were the last byte regexes in the alt-quality reader; they now walk core's Tag Processor tokens after the image, with the same scoping, the same two-element skip budget and the same 2000-byte distance bound (block-delimiter comments still do not count toward it). A figcaption or heading written inside a comment, a script or a textarea is no longer read as one, a `</figure>` inside a comment no longer ends the figure, a `>` inside an attribute no longer leaks the rest of the tag into the caption or heading text, and a script inside a caption is no longer read as caption text. On real markup the answers are unchanged. Tests: `tests/health-alt-after-offset.php` (pinned from the regex helpers first; each answer that changed names the regex bug).

## [2026.11.23] - 2026-11-10 — Pull-quote suggestions build core's Pull Quote

### Fixed
- **Pull-quote suggestions follow the theme's core pull quote.** Since theme 2026.11.7 the pull-quote pattern is `core/pullquote` with the Pull-quote block style (`is-style-pull-quote`). The pattern-adoption suggestion now builds that markup, copied from the theme's `patterns/pull-quote.php`, instead of the old `sn-pattern-pull-quote` group, and the conventions registry fixture is refreshed from the theme's registry. Pull quotes already written as the old group or the `signal-noise/pull-quote` block are still not candidates, so published notes are neither flagged nor rewritten. Tests: `tests/pattern-adoption-suggest.php`, `tests/pattern-adoption-detect.php`.

