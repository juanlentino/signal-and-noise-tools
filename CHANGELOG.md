# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [14.3.0] - 2026-09-12 — a Provenance column in the native Posts window

### Added
- **A Provenance column in OpenStation's native Posts window.** `assets/os-posts-provenance.js` registers on the shell's `openstation.postsWindow.columns` filter (1.1.8, #779) and paints the Explorer's anchor-status badge — dot + `v{n}`, title with the status label — in the table, the writing-desk cards and the inspector, one node per render as the workspace requires. It reads the `sn_provenance` REST field the plugin already exposes on posts, so the value rides the `/wp/v2/posts` request the window already makes: no extra fetch, no PHP. An unsigned Note paints an empty node, never a gray badge (absent is not zero). Its own handle on every shell request with `wp-hooks` as the sole dependency — not the lazily-loaded Explorer bundle, which would leave the column absent until the Explorer had been opened. Idempotent against the filter running on every paint. The first Signal & Noise view moved onto the shell's surface; `statusSegments` was ruled out for "Needs attention" (its value is sent verbatim as `?status=`, and our queue is not a post status). Guarded in `tests/openstation-preferences.php` (100 → 108; the idempotence pin mutation-checked red).

