# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.61] - 2026-11-24 — Notes per page and JSON feed items move to WordPress's Settings › Reading

### Changed
- **Notes per page and JSON feed items moved to WordPress's own Settings › Reading.** The convention: core keeps "Blog pages show at most" and "Syndication feeds show the most recent" on that screen, so the two numbers for `/notes` and the JSON feed now sit below them, in a section headed "Notes", in core's own markup. Core's two are untouched and still set the blog pages and the RSS feeds; the theme reads these only through `sn_notes_per_page` and `sn_json_feed_items`. Storage (`theme.notes_per_page`, `theme.json_feed_items`), bounds (1 to 100, 1 to 50) and defaults (20) are unchanged, so the public pages render exactly as before. New: `inc/reading-settings-screen.php` (the Settings API section; `sn_reading` is allowlisted on the `reading` group and never stored) and `inc/admin-post-actions/reading.php` (one save path for both surfaces, which keeps a value that is missing).
  - **Left S&N Home › Settings › Front end, classic:** the "Notes per page" field and its hint; the "JSON feed items" field and its hint. One line took their place in the Reading box, with a link: "Notes per page and JSON feed items are on Settings › Reading, with WordPress's own."
  - **Native window:** the two fields left the front-end form for a box of their own, "Notes and the JSON feed", with its own Save (action `reading_save`) and a line naming Settings › Reading, because the native window does not paint WordPress's Reading screen.
  - **`sn_handle_save_theme()` writes six settings, not eight.** It no longer reads the two numbers, so a front-end save cannot set them back to 20.
  - Tests: `tests/reading-settings-screen.php` (new; red with the capability check removed); `tests/os-leaf-settings-front-end-pane.php` (red with the reset put back in `save_theme`); `tests/os-leaf-settings-front-end.php`, `tests/front-end-form.php`, `tests/settings-theme.php` and `tests/lib/whole-forms.php` follow.

